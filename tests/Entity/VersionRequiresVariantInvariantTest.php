<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CarModel;
use App\Entity\FuelType;
use App\Entity\ImportRun;
use App\Entity\Variant;
use App\Entity\Version;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class VersionRequiresVariantInvariantTest extends KernelTestCase
{
    use ResetsImportSchema;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ManagerRegistry $managerRegistry;
    private CarModel $carModel;
    private ImportRun $importRun;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->managerRegistry = self::getContainer()->get(ManagerRegistry::class);
        $this->resetImportSchema($this->connection);

        $runId = $this->createRunningImportRun($this->connection);
        $brandId = self::databaseInt($this->connection->fetchOne(
            "INSERT INTO brand (source_ref, name, first_seen_run_id) VALUES ('invariant-brand', 'Invariant Brand', :runId) RETURNING id",
            ['runId' => $runId],
        ));
        $modelId = self::databaseInt($this->connection->fetchOne(
            "INSERT INTO car_model (brand_id, source_ref, name, first_seen_run_id) VALUES (:brandId, 'invariant-brand/model', 'Model', :runId) RETURNING id",
            ['brandId' => $brandId, 'runId' => $runId],
        ));

        $carModel = $this->entityManager->getReference(CarModel::class, $modelId);
        self::assertInstanceOf(CarModel::class, $carModel);
        $this->carModel = $carModel;

        $importRun = $this->entityManager->getReference(ImportRun::class, $runId);
        self::assertInstanceOf(ImportRun::class, $importRun);
        $this->importRun = $importRun;
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testVersionWithoutAnyVariantIsRejectedAtCommitInsideAnExplicitTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->entityManager->persist($this->version('https://example.test/empty/'));

        try {
            $this->entityManager->flush();
            $this->connection->commit();
            self::fail('Expected the deferred trigger to reject a version without a variant.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('has no variant', $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->managerRegistry->resetManager();
        }

        self::assertSame(0, self::databaseInt($this->connection->fetchOne(
            "SELECT count(*) FROM version WHERE source_ref = 'https://example.test/empty/'",
        )));
    }

    public function testFlushWithoutAnExplicitTransactionOpensAndCommitsItsOwnAndIsRejected(): void
    {
        $this->entityManager->persist($this->version('https://example.test/no-explicit-tx/'));

        try {
            $this->entityManager->flush();
            self::fail('Expected flush() without an explicit transaction to be rejected at its own commit.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('has no variant', self::rootCause($exception)->getMessage());
        } finally {
            $this->managerRegistry->resetManager();
        }

        self::assertSame(0, self::databaseInt($this->connection->fetchOne(
            "SELECT count(*) FROM version WHERE source_ref = 'https://example.test/no-explicit-tx/'",
        )));
    }

    private static function rootCause(Throwable $exception): Throwable
    {
        $previous = $exception->getPrevious();

        return null !== $previous ? self::rootCause($previous) : $exception;
    }

    public function testVersionAndVariantInOneFlushInsideOneTransactionCommits(): void
    {
        $version = $this->version('https://example.test/one-flush/');

        $this->connection->beginTransaction();
        $this->entityManager->persist($version);
        $version->addVariant($this->variant($version, '1'));
        $this->entityManager->flush();
        $this->connection->commit();
        $this->entityManager->clear();

        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            "SELECT count(*) FROM version WHERE source_ref = 'https://example.test/one-flush/'",
        )));
    }

    public function testVersionThenVariantInTwoFlushesInsideOneTransactionCommits(): void
    {
        $version = $this->version('https://example.test/two-flushes/');

        $this->connection->beginTransaction();
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $version->addVariant($this->variant($version, '1'));
        $this->entityManager->flush();

        $this->connection->commit();
        $this->entityManager->clear();

        self::assertSame(1, self::databaseInt($this->connection->fetchOne(
            "SELECT count(*) FROM version WHERE source_ref = 'https://example.test/two-flushes/'",
        )));
    }

    private function version(string $sourceRef): Version
    {
        return new Version(
            $this->carModel,
            $sourceRef,
            'Invariant Test Version',
            FuelType::Petrol,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->importRun,
        );
    }

    private function variant(Version $version, string $ref): Variant
    {
        return new Variant(
            $version,
            $ref,
            '6.7',
            null,
            null,
            null,
            null,
            null,
            null,
            $this->importRun,
        );
    }
}
