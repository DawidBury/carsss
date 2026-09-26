<?php

declare(strict_types=1);

namespace App\Tests\Import\MessageHandler;

use App\Import\Message\ImportRecord;
use App\Import\MessageHandler\ImportRecordHandler;
use App\Source\Contract\FetchMode;
use App\Source\Contract\FuelType;
use App\Source\Contract\SourceFetchResult;
use App\Source\Contract\SourceRecord;
use App\Source\Contract\SourceTaxonomy;
use App\Source\Contract\SourceVariant;
use App\Tests\Import\CreatesRecordWriter;
use App\Tests\Import\InMemoryLogger;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class ImportRecordHandlerTest extends KernelTestCase
{
    use CreatesRecordWriter;
    use ResetsImportSchema;

    private Connection $connection;
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetImportSchema($this->connection);
        $this->logger = new InMemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testRecordResultIsWritten(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';
        $catalog = new FakeSourceCatalog([SourceFetchResult::record($this->record($ref))]);

        $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));

        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            'SELECT count(*) FROM version WHERE source_ref = :ref',
            ['ref' => $ref],
        )));

        $records = $this->logger->recordsWithMessage('import.version_written');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame([
            'runId' => $runId,
            'ref' => $ref,
            'variantCount' => 1,
        ], $records[0]['context']);
    }

    public function testAbsentResultIsLoggedAndNothingIsWritten(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $catalog = new FakeSourceCatalog([SourceFetchResult::absent()]);
        $ref = 'https://www.autocentrum.pl/gone/';

        $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));

        self::assertSame(0, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM version')));

        $records = $this->logger->recordsWithMessage('import.record_skipped');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame([
            'runId' => $runId,
            'ref' => $ref,
            'reason' => 'absent',
        ], $records[0]['context']);
    }

    public function testUnchangedResultMarksTheExistingVersionAsSeen(): void
    {
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';
        $firstRun = $this->createRunningImportRun($this->connection);
        $this->createRecordWriter($this->logger)->write($firstRun, $this->record($ref));

        $secondRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $catalog = new FakeSourceCatalog([SourceFetchResult::unchanged($ref)]);

        $this->handler($catalog)->__invoke(new ImportRecord($secondRun, $ref));

        $version = self::databaseRow(
            $this->connection->fetchAssociative('SELECT * FROM version WHERE source_ref = :ref', ['ref' => $ref]),
        );
        self::assertSame($secondRun, self::databaseInt($version['last_seen_run_id']));
        self::assertCount(1, $catalog->calls);
        self::assertSame(FetchMode::Incremental, $catalog->calls[0]['mode']);

        $records = $this->logger->recordsWithMessage('import.version_unchanged');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame([
            'runId' => $secondRun,
            'ref' => $ref,
        ], $records[0]['context']);
    }

    public function testUnchangedResultWithNoMatchingVersionRowForcesAFullRefetch(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $catalog = new FakeSourceCatalog([
            SourceFetchResult::unchanged($ref),
            SourceFetchResult::record($this->record($ref)),
        ]);

        $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));

        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            'SELECT count(*) FROM version WHERE source_ref = :ref',
            ['ref' => $ref],
        )));

        self::assertCount(2, $catalog->calls);
        self::assertSame(FetchMode::Incremental, $catalog->calls[0]['mode']);
        self::assertSame(FetchMode::Full, $catalog->calls[1]['mode']);
        self::assertSame($ref, $catalog->calls[1]['ref']);

        $refetchRecords = $this->logger->recordsWithMessage('import.refetch_forced');
        self::assertCount(1, $refetchRecords);
        self::assertSame('info', $refetchRecords[0]['level']);
        self::assertSame([
            'runId' => $runId,
            'ref' => $ref,
        ], $refetchRecords[0]['context']);

        self::assertCount(1, $this->logger->recordsWithMessage('import.version_written'));
    }

    public function testUnchangedResultWithNoMatchingVersionRowAndFullRefetchAbsentIsLoggedAsAbsent(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $catalog = new FakeSourceCatalog([
            SourceFetchResult::unchanged($ref),
            SourceFetchResult::absent(),
        ]);

        $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));

        self::assertSame(0, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM version')));

        self::assertCount(1, $this->logger->recordsWithMessage('import.refetch_forced'));

        $skipped = $this->logger->recordsWithMessage('import.record_skipped');
        self::assertCount(1, $skipped);
        self::assertSame([
            'runId' => $runId,
            'ref' => $ref,
            'reason' => 'absent',
        ], $skipped[0]['context']);
    }

    public function testFullModeReturningUnchangedIsARejectedContractViolation(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $catalog = new FakeSourceCatalog([
            SourceFetchResult::unchanged($ref),
            SourceFetchResult::unchanged($ref),
        ]);

        try {
            $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));
            self::fail('Expected a LogicException to propagate out of __invoke().');
        } catch (LogicException) {
        }

        $records = $this->logger->recordsWithMessage('import.record_failed');
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame($runId, $records[0]['context']['runId']);
        self::assertSame($ref, $records[0]['context']['ref']);
        self::assertSame(LogicException::class, self::exceptionContext($records[0]['context'])['class']);
    }

    public function testWriterFailureIsLoggedAsRecordFailedAndRethrownWithoutADoubleLog(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $conflictingRecord = new SourceRecord(
            ref: $ref,
            taxonomy: new SourceTaxonomy(
                brandRef: 'audi',
                brandName: 'Audi',
                modelRef: 'audi/a4',
                modelName: 'A4',
                versionName: '2.0 TFSI 211KM',
                generation: 'II',
                bodyStyle: null,
                unresolvedSegment: 'ambiguous',
            ),
            fuelType: FuelType::Petrol,
            powerHp: 211.0,
            engineDisplacementCcm: 1984,
            bootCapacityL: 480,
            variants: [
                new SourceVariant(
                    ref: '1',
                    acceleration0To100S: 6.7,
                    fuelConsumptionL100Km: 7.5,
                    fuelTankL: 64,
                    gearbox: null,
                    gearboxUnmappedLabel: null,
                    drive: null,
                    driveUnmappedLabel: null,
                ),
            ],
        );

        $catalog = new FakeSourceCatalog([SourceFetchResult::record($conflictingRecord)]);

        try {
            $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));
            self::fail('Expected the conflicting taxonomy columns to violate a database CHECK constraint.');
        } catch (Throwable) {
        }

        $records = $this->logger->recordsWithMessage('import.record_failed');
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame($runId, $records[0]['context']['runId']);
        self::assertSame($ref, $records[0]['context']['ref']);

        $exceptionContext = self::exceptionContext($records[0]['context']);
        self::assertTrue(class_exists($exceptionContext['class']));
        self::assertNotSame('', $exceptionContext['message']);

        $errorRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => 'error' === $record['level'],
        ));
        self::assertCount(1, $errorRecords);
        self::assertSame([], $this->logger->recordsWithMessage('import.version_written'));
    }

    public function testFetchFailureBeforeAnyResultIsLoggedWithTheMessageReference(): void
    {
        $runId = $this->createRunningImportRun($this->connection);
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $catalog = new FakeSourceCatalog([]);

        try {
            $this->handler($catalog)->__invoke(new ImportRecord($runId, $ref));
            self::fail('Expected FakeSourceCatalog to throw when it has no queued results.');
        } catch (LogicException) {
        }

        $records = $this->logger->recordsWithMessage('import.record_failed');
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame($runId, $records[0]['context']['runId']);
        self::assertSame($ref, $records[0]['context']['ref']);

        $exceptionContext = self::exceptionContext($records[0]['context']);
        self::assertSame(LogicException::class, $exceptionContext['class']);
        self::assertSame('FakeSourceCatalog has no more queued results.', $exceptionContext['message']);
    }

    public function testUnchangedResultUsesTheCanonicalReferenceFromTheResultNotTheMessage(): void
    {
        $messageRef = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limuzyna/';
        $canonicalRef = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limousine/';

        $firstRun = $this->createRunningImportRun($this->connection);
        $this->createRecordWriter($this->logger)->write($firstRun, $this->record($canonicalRef));

        $secondRun = $this->createRunningImportRun($this->connection, 'fake-2');
        $catalog = new FakeSourceCatalog([SourceFetchResult::unchanged($canonicalRef)]);

        $this->handler($catalog)->__invoke(new ImportRecord($secondRun, $messageRef));

        $version = self::databaseRow($this->connection->fetchAssociative(
            'SELECT * FROM version WHERE source_ref = :ref',
            ['ref' => $canonicalRef],
        ));
        self::assertSame($secondRun, self::databaseInt($version['last_seen_run_id']));
        self::assertCount(1, $catalog->calls);
        self::assertSame($messageRef, $catalog->calls[0]['ref']);
    }

    private function handler(FakeSourceCatalog $catalog): ImportRecordHandler
    {
        return new ImportRecordHandler($catalog, $this->createRecordWriter($this->logger), $this->logger);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array{class: string, message: string}
     */
    private static function exceptionContext(array $context): array
    {
        $exception = $context['exception'] ?? null;
        if (!is_array($exception)) {
            self::fail('Expected the log context to carry an "exception" array.');
        }

        $class = $exception['class'] ?? null;
        $message = $exception['message'] ?? null;
        if (!is_string($class) || !is_string($message)) {
            self::fail('Expected the exception context to carry a string "class" and "message".');
        }

        return ['class' => $class, 'message' => $message];
    }

    private function record(string $ref): SourceRecord
    {
        return new SourceRecord(
            ref: $ref,
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
            variants: [
                new SourceVariant(
                    ref: '1',
                    acceleration0To100S: 6.7,
                    fuelConsumptionL100Km: 7.5,
                    fuelTankL: 64,
                    gearbox: null,
                    gearboxUnmappedLabel: null,
                    drive: null,
                    driveUnmappedLabel: null,
                ),
            ],
        );
    }
}
