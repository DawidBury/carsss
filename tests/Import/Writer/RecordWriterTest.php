<?php

declare(strict_types=1);

namespace App\Tests\Import\Writer;

use App\Import\Writer\RecordWriter;
use App\Source\Contract\Drive;
use App\Source\Contract\FuelType;
use App\Source\Contract\Gearbox;
use App\Source\Contract\SourceRecord;
use App\Source\Contract\SourceTaxonomy;
use App\Source\Contract\SourceVariant;
use App\Tests\Import\CreatesRecordWriter;
use App\Tests\Import\InMemoryLogger;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class RecordWriterTest extends KernelTestCase
{
    use CreatesRecordWriter;
    use ResetsImportSchema;

    private Connection $connection;
    private InMemoryLogger $logger;
    private RecordWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetImportSchema($this->connection);
        $this->logger = new InMemoryLogger();
        $this->writer = $this->createRecordWriter($this->logger);
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testWritesBrandModelVersionAndVariantInOneGo(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $this->writer->write($runId, $this->record());

        $brand = self::databaseRow(
            $this->connection->fetchAssociative('SELECT * FROM brand WHERE source_ref = :ref', ['ref' => 'audi']),
        );
        self::assertSame('Audi', $brand['name']);

        $model = self::databaseRow(
            $this->connection->fetchAssociative('SELECT * FROM car_model WHERE source_ref = :ref', ['ref' => 'audi/a4']),
        );
        self::assertSame(self::databaseInt($brand['id']), self::databaseInt($model['brand_id']));

        $version = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));
        self::assertSame('petrol', $version['fuel_type']);
        self::assertSame('211.0', $version['power_hp']);

        $variant = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM variant WHERE version_id = :versionId',
            ['versionId' => $version['id']],
        ));
        self::assertSame('manual', $variant['gearbox']);
        self::assertSame('front', $variant['drive']);
        self::assertNull($variant['gearbox_unmapped_label']);
    }

    public function testWritingTheSameRecordTwiceIsIdempotentAndKeepsStableIdentifiers(): void
    {
        $firstRun = $this->createRunningImportRun($this->connection);
        $this->writer->write($firstRun, $this->record());

        $before = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));

        $secondRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $this->writer->write($secondRun, $this->record());

        self::assertSame(1, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM brand')));
        self::assertSame(1, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM car_model')));
        self::assertSame(1, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM version')));

        $after = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));

        self::assertSame($before['public_id'], $after['public_id']);
        self::assertSame($before['first_seen_run_id'], $after['first_seen_run_id']);
        self::assertSame($secondRun, self::databaseInt($after['last_seen_run_id']));
    }

    public function testSuccessfulWriteLogsVersionWritten(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $this->writer->write($runId, $this->record());

        $records = $this->logger->recordsWithMessage('import.version_written');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame([
            'runId' => $runId,
            'ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/',
            'variantCount' => 1,
        ], $records[0]['context']);
    }

    public function testEmptyVariantListSkipsTheWriteAndLogsTheReason(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $this->writer->write($runId, $this->record(variants: []));

        self::assertSame(0, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM version')));
        self::assertSame(0, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM brand')));

        $records = $this->logger->recordsWithReason('no_usable_variants');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame([
            'runId' => $runId,
            'ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/',
            'reason' => 'no_usable_variants',
        ], $records[0]['context']);
        self::assertSame('import.record_skipped', $records[0]['message']);
    }

    public function testFailedWriteDoesNotLogAnythingItself(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $baseRecord = $this->record();
        $conflictingRecord = new SourceRecord(
            ref: $baseRecord->ref,
            taxonomy: new SourceTaxonomy(
                brandRef: $baseRecord->taxonomy->brandRef,
                brandName: $baseRecord->taxonomy->brandName,
                modelRef: $baseRecord->taxonomy->modelRef,
                modelName: $baseRecord->taxonomy->modelName,
                versionName: $baseRecord->taxonomy->versionName,
                generation: 'II',
                bodyStyle: null,
                unresolvedSegment: 'ambiguous',
            ),
            fuelType: $baseRecord->fuelType,
            powerHp: $baseRecord->powerHp,
            engineDisplacementCcm: $baseRecord->engineDisplacementCcm,
            bootCapacityL: $baseRecord->bootCapacityL,
            variants: $baseRecord->variants,
        );

        try {
            $this->writer->write($runId, $conflictingRecord);
            self::fail('Expected the conflicting taxonomy columns to violate a database CHECK constraint.');
        } catch (Throwable) {
        }

        self::assertSame([], $this->logger->records);
    }

    public function testSecondWriteWithEmptyVariantsDoesNotTouchPreviouslyWrittenData(): void
    {
        $firstRun = $this->createRunningImportRun($this->connection);
        $this->writer->write($firstRun, $this->record());

        $secondRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $this->writer->write($secondRun, $this->record(variants: []));

        $version = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));
        self::assertSame($firstRun, self::databaseInt($version['last_seen_run_id']));
        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            'SELECT count(*) FROM variant WHERE version_id = :id',
            ['id' => $version['id']],
        )));
    }

    public function testUnrecognizedDictionaryValuesAreStoredAsUnmappedLabelsWithoutThrowing(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $variant = new SourceVariant(
            ref: '1',
            acceleration0To100S: 9.0,
            fuelConsumptionL100Km: null,
            fuelTankL: null,
            gearbox: null,
            gearboxUnmappedLabel: 'półautomatyczna',
            drive: null,
            driveUnmappedLabel: 'na cztery koła i pół',
        );

        $this->writer->write($runId, $this->record(variants: [$variant]));

        $stored = self::databaseRow($this->connection->fetchAssociative(
            'SELECT v.* FROM variant v JOIN version ver ON ver.id = v.version_id WHERE ver.source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));

        self::assertNull($stored['gearbox']);
        self::assertSame('półautomatyczna', $stored['gearbox_unmapped_label']);
        self::assertNull($stored['drive']);
        self::assertSame('na cztery koła i pół', $stored['drive_unmapped_label']);
    }

    public function testVariantSyncDeletesStaleVariantsAndKeepsTheOnesStillPresent(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $variantA = $this->variant('a', 9.0);
        $variantB = $this->variant('b', 10.0);
        $this->writer->write($runId, $this->record(variants: [$variantA, $variantB]));

        $versionId = self::databaseInt($this->connection->fetchOne(
            'SELECT id FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));
        self::assertSame(2, self::databaseInt($this->connection->fetchOne(
            'SELECT count(*) FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        )));

        $nextRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $this->writer->write($nextRun, $this->record(variants: [$variantB]));

        $remaining = $this->connection->fetchAllAssociative(
            'SELECT source_ref FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        );
        self::assertSame(['b'], array_column($remaining, 'source_ref'));
    }

    public function testVariantWithAPurelyNumericSourceRefSurvivesTheFullWritePathAndResync(): void
    {
        $runId = $this->createRunningImportRun($this->connection);

        $numericVariant = $this->variant('9332', 6.7);
        $this->writer->write($runId, $this->record(variants: [$numericVariant]));

        $versionId = self::databaseInt($this->connection->fetchOne(
            'SELECT id FROM version WHERE source_ref = :ref',
            ['ref' => 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/'],
        ));
        $stored = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        ));
        self::assertSame('9332', $stored['source_ref']);

        $nextRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $this->writer->write($nextRun, $this->record(variants: [$numericVariant]));

        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            'SELECT count(*) FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        )));
        $afterResync = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        ));
        self::assertSame($stored['public_id'], $afterResync['public_id']);

        $otherVariant = $this->variant('b', 10.0);
        $thirdRun = $this->createRunningImportRun($this->connection, 'fake-3');
        $this->writer->write($thirdRun, $this->record(variants: [$otherVariant]));

        $remaining = $this->connection->fetchAllAssociative(
            'SELECT source_ref FROM variant WHERE version_id = :id',
            ['id' => $versionId],
        );
        self::assertSame(['b'], array_column($remaining, 'source_ref'));
    }

    public function testMarkSeenReturnsAffectedRowCountAndZeroWhenTheVersionIsMissing(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $this->writer->write($runId, $this->record());

        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        self::assertSame(1, $this->writer->markSeen($runId, $ref));
        self::assertSame(0, $this->writer->markSeen($runId, 'https://www.autocentrum.pl/dane-techniczne/nowhere/'));
    }

    /**
     * @param list<SourceVariant> $variants
     */
    private function record(?array $variants = null): SourceRecord
    {
        return new SourceRecord(
            ref: 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/',
            taxonomy: new SourceTaxonomy(
                brandRef: 'audi',
                brandName: 'Audi',
                modelRef: 'audi/a4',
                modelName: 'A4',
                versionName: '2.0 TFSI 211KM',
                generation: null,
                bodyStyle: null,
                unresolvedSegment: null,
            ),
            fuelType: FuelType::Petrol,
            powerHp: 211.0,
            engineDisplacementCcm: 1984,
            bootCapacityL: 480,
            variants: $variants ?? [$this->variant('1', 6.7)],
        );
    }

    private function variant(string $ref, float $acceleration): SourceVariant
    {
        return new SourceVariant(
            ref: $ref,
            acceleration0To100S: $acceleration,
            fuelConsumptionL100Km: 7.5,
            fuelTankL: 64,
            gearbox: Gearbox::Manual,
            gearboxUnmappedLabel: null,
            drive: Drive::Front,
            driveUnmappedLabel: null,
        );
    }
}
