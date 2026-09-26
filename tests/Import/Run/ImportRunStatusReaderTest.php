<?php

declare(strict_types=1);

namespace App\Tests\Import\Run;

use App\Import\Run\ImportRunStatusReader;
use App\Source\Contract\FuelType;
use App\Source\Contract\SourceRecord;
use App\Source\Contract\SourceTaxonomy;
use App\Source\Contract\SourceVariant;
use App\Tests\Import\CreatesRecordWriter;
use App\Tests\Import\InMemoryLogger;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportRunStatusReaderTest extends KernelTestCase
{
    use CreatesRecordWriter;
    use ResetsImportSchema;

    private Connection $connection;
    private ImportRunStatusReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetImportSchema($this->connection);
        $this->reader = new ImportRunStatusReader($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testReturnsNullWhenNoRunHasEverStarted(): void
    {
        self::assertNull($this->reader->latest());
    }

    public function testPrefersARunningRunOverAnOlderCompletedOne(): void
    {
        $completedRun = $this->createRunningImportRun($this->connection, 'autocentrum');
        $this->connection->executeStatement(
            "UPDATE import_run SET status = 'completed', finished_at = now() WHERE id = :id",
            ['id' => $completedRun],
        );

        $runningRun = $this->createRunningImportRun($this->connection, 'autocentrum');

        $snapshot = $this->reader->latest();

        self::assertNotNull($snapshot);
        self::assertSame($runningRun, $snapshot->id);
        self::assertTrue($snapshot->isRunning());
    }

    public function testReturnsTheMostRecentCompletedRunWhenNoneIsRunning(): void
    {
        $olderRun = $this->createRunningImportRun($this->connection, 'autocentrum');
        $this->connection->executeStatement(
            "UPDATE import_run SET status = 'completed', finished_at = now() WHERE id = :id",
            ['id' => $olderRun],
        );

        $newerRun = $this->createRunningImportRun($this->connection, 'autocentrum');
        $this->connection->executeStatement(
            "UPDATE import_run SET status = 'failed', finished_at = now() WHERE id = :id",
            ['id' => $newerRun],
        );

        $snapshot = $this->reader->latest();

        self::assertNotNull($snapshot);
        self::assertSame($newerRun, $snapshot->id);
        self::assertSame('failed', $snapshot->status);
        self::assertNotNull($snapshot->finishedAt);
    }

    public function testCountsDistinguishTotalsFromNewAndTouchedThisRun(): void
    {
        $logger = new InMemoryLogger();
        $writer = $this->createRecordWriter($logger);

        $firstRun = $this->createRunningImportRun($this->connection, 'autocentrum');
        $writer->write($firstRun, $this->record('https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/', 'audi', 'audi/a4'));
        $writer->write($firstRun, $this->record('https://www.autocentrum.pl/dane-techniczne/audi/a6/silnik-benzynowy-1/', 'audi', 'audi/a6'));
        $this->connection->executeStatement(
            "UPDATE import_run SET status = 'completed', finished_at = now() WHERE id = :id",
            ['id' => $firstRun],
        );

        $secondRun = $this->createRunningImportRun($this->connection, 'autocentrum');
        $writer->markSeen($secondRun, 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/');
        $writer->write($secondRun, $this->record('https://www.autocentrum.pl/dane-techniczne/bmw/seria-3/silnik-benzynowy-1/', 'bmw', 'bmw/seria-3'));

        $snapshot = $this->reader->latest();

        self::assertNotNull($snapshot);
        self::assertSame($secondRun, $snapshot->id);
        self::assertSame(2, $snapshot->brandTotal);
        self::assertSame(1, $snapshot->brandNewThisRun);
        self::assertSame(3, $snapshot->modelTotal);
        self::assertSame(1, $snapshot->modelNewThisRun);
        self::assertSame(3, $snapshot->versionTotal);
        self::assertSame(1, $snapshot->versionNewThisRun);
        self::assertSame(2, $snapshot->versionTouchedThisRun);
        self::assertSame(3, $snapshot->variantTotal);
        self::assertSame(1, $snapshot->variantNewThisRun);
    }

    private function record(string $ref, string $brandRef, string $modelRef): SourceRecord
    {
        return new SourceRecord(
            ref: $ref,
            taxonomy: new SourceTaxonomy(
                brandRef: $brandRef,
                brandName: ucfirst($brandRef),
                modelRef: $modelRef,
                modelName: $modelRef,
                versionName: 'Test Version',
                generation: null,
                bodyStyle: null,
                unresolvedSegment: null,
            ),
            fuelType: FuelType::Petrol,
            powerHp: null,
            engineDisplacementCcm: null,
            bootCapacityL: null,
            variants: [
                new SourceVariant(
                    ref: '1',
                    acceleration0To100S: 8.0,
                    fuelConsumptionL100Km: null,
                    fuelTankL: null,
                    gearbox: null,
                    gearboxUnmappedLabel: null,
                    drive: null,
                    driveUnmappedLabel: null,
                ),
            ],
        );
    }
}
