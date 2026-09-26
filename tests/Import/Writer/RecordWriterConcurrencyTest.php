<?php

declare(strict_types=1);

namespace App\Tests\Import\Writer;

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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordWriterConcurrencyTest extends KernelTestCase
{
    use CreatesRecordWriter;
    use ResetsImportSchema;

    private const string RACE_SCRIPT_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        $pdo = new PDO(
            'pgsql:host=__HOST__;port=__PORT__;dbname=__DBNAME__',
            '__USER__',
            '__PASSWORD__',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->beginTransaction();

        $brandStatement = $pdo->query(
            "INSERT INTO brand (source_ref, name, first_seen_run_id) VALUES ('race-brand', 'Race Brand', __RUN_ID__) "
            . "ON CONFLICT (source_ref) DO UPDATE SET name = EXCLUDED.name RETURNING id",
        );
        $brandId = $brandStatement->fetchColumn();

        $modelStatement = $pdo->prepare(
            'INSERT INTO car_model (brand_id, source_ref, name, first_seen_run_id) VALUES (:brandId, :ref, :name, :runId) '
            . 'ON CONFLICT (source_ref) DO UPDATE SET name = EXCLUDED.name RETURNING id',
        );
        $modelStatement->execute([
            'brandId' => $brandId,
            'ref' => 'race-brand/model',
            'name' => 'Race Model',
            'runId' => __RUN_ID__,
        ]);
        $modelId = $modelStatement->fetchColumn();

        $versionStatement = $pdo->prepare(
            'INSERT INTO version (car_model_id, source_ref, name, fuel_type, first_seen_run_id, last_seen_run_id) '
            . 'VALUES (:modelId, :ref, :name, :fuelType, :runId, :runId) RETURNING id',
        );
        $versionStatement->execute([
            'modelId' => $modelId,
            'ref' => '__REF__',
            'name' => 'Race Version',
            'fuelType' => 'petrol',
            'runId' => __RUN_ID__,
        ]);
        $versionId = $versionStatement->fetchColumn();

        $variantStatement = $pdo->prepare(
            'INSERT INTO variant (version_id, source_ref, acceleration_0_100_s, first_seen_run_id) '
            . 'VALUES (:versionId, :ref, :acceleration, :runId)',
        );
        $variantStatement->execute([
            'versionId' => $versionId,
            'ref' => 'race-variant',
            'acceleration' => '6.6',
            'runId' => __RUN_ID__,
        ]);

        file_put_contents('__MARKER__', 'ready');

        usleep(800_000);

        $pdo->commit();

        PHP;

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

    public function testLosingAConcurrentInsertOfTheSameVersionThrowsAndTheManagerRecoversForTheNextWrite(): void
    {
        $ref = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';
        $runId = $this->createRunningImportRun($this->connection);

        $marker = sys_get_temp_dir() . '/' . uniqid('record_writer_race_', true);
        $scriptPath = $marker . '.php';
        file_put_contents($scriptPath, $this->raceScript($ref, $runId, $marker));

        $process = proc_open(
            ['php', $scriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('Could not start the competing process.');
        }

        register_shutdown_function(self::terminateIfStillRunning(...), $process);

        try {
            $deadline = microtime(true) + 5.0;
            while (!file_exists($marker)) {
                if (microtime(true) > $deadline) {
                    self::fail('Timed out waiting for the competing process to insert its row: '
                        . stream_get_contents($pipes[2]));
                }

                usleep(20_000);
            }

            $writer = $this->createRecordWriter($this->logger);

            try {
                $writer->write($runId, $this->record($ref));
                self::fail('Expected the losing writer to receive a unique constraint violation.');
            } catch (UniqueConstraintViolationException) {
            }

            $secondRef = 'https://www.autocentrum.pl/dane-techniczne/audi/a6/silnik-benzynowy-1/';
            $writer->write($runId, $this->record($secondRef, modelRef: 'audi/a6'));

            self::assertSame(1, self::databaseInt($this->connection->fetchOne(
                'SELECT count(*) FROM version WHERE source_ref = :ref',
                ['ref' => $secondRef],
            )));
            self::assertSame(1, self::databaseInt($this->connection->fetchOne(
                'SELECT count(*) FROM version WHERE source_ref = :ref',
                ['ref' => $ref],
            )));
        } finally {
            self::terminateIfStillRunning($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($scriptPath);
            @unlink($marker);
        }
    }

    private static function terminateIfStillRunning(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
        }
    }

    private function raceScript(string $ref, int $runId, string $marker): string
    {
        $params = $this->connection->getParams();

        $replacements = [
            '__HOST__' => self::stringifyParam($params['host'] ?? null, ''),
            '__PORT__' => self::stringifyParam($params['port'] ?? null, '5432'),
            '__DBNAME__' => self::stringifyParam($params['dbname'] ?? null, ''),
            '__USER__' => self::stringifyParam($params['user'] ?? null, ''),
            '__PASSWORD__' => self::stringifyParam($params['password'] ?? null, ''),
            '__RUN_ID__' => (string) $runId,
            '__REF__' => $ref,
            '__MARKER__' => $marker,
        ];

        return strtr(self::RACE_SCRIPT_TEMPLATE, $replacements);
    }

    private static function stringifyParam(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function record(string $ref, string $modelRef = 'audi/a4'): SourceRecord
    {
        return new SourceRecord(
            ref: $ref,
            taxonomy: new SourceTaxonomy(
                brandRef: 'audi',
                brandName: 'Audi',
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
                    acceleration0To100S: 6.7,
                    fuelConsumptionL100Km: null,
                    fuelTankL: null,
                    gearbox: Gearbox::Manual,
                    gearboxUnmappedLabel: null,
                    drive: Drive::Front,
                    driveUnmappedLabel: null,
                ),
            ],
        );
    }
}
