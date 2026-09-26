<?php

declare(strict_types=1);

namespace App\Tests\Import\Run;

use App\Import\Run\ImportAlreadyRunningException;
use App\Import\Run\ImportRunLifecycle;
use App\Import\Run\ImportRunNotRunningException;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportRunLifecycleTest extends KernelTestCase
{
    use ResetsImportSchema;

    private Connection $connection;
    private ImportRunLifecycle $lifecycle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetImportSchema($this->connection);
        $this->lifecycle = new ImportRunLifecycle($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testStartCreatesARunningRowAndReturnsItsId(): void
    {
        $runId = $this->lifecycle->start('autocentrum');

        $row = $this->connection->fetchAssociative('SELECT * FROM import_run WHERE id = :id', ['id' => $runId]);
        self::assertNotFalse($row);
        self::assertSame('autocentrum', $row['source']);
        self::assertSame('running', $row['status']);
        self::assertNull($row['finished_at']);
    }

    public function testStartingASecondRunForTheSameSourceIsRejected(): void
    {
        $this->lifecycle->start('autocentrum');

        $this->expectException(ImportAlreadyRunningException::class);
        $this->lifecycle->start('autocentrum');
    }

    public function testStartingASecondRunForADifferentSourceIsAllowed(): void
    {
        $this->lifecycle->start('autocentrum');
        $otherRunId = $this->lifecycle->start('vendor-x');

        self::assertGreaterThan(0, $otherRunId);
    }

    public function testCompleteSetsStatusAndFinishedAt(): void
    {
        $runId = $this->lifecycle->start('autocentrum');

        $this->lifecycle->complete($runId);

        $row = $this->connection->fetchAssociative('SELECT * FROM import_run WHERE id = :id', ['id' => $runId]);
        self::assertNotFalse($row);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['finished_at']);
    }

    public function testFailSetsStatusAndFinishedAt(): void
    {
        $runId = $this->lifecycle->start('autocentrum');

        $this->lifecycle->fail($runId);

        $row = $this->connection->fetchAssociative('SELECT * FROM import_run WHERE id = :id', ['id' => $runId]);
        self::assertNotFalse($row);
        self::assertSame('failed', $row['status']);
        self::assertNotNull($row['finished_at']);
    }

    public function testCompletingAnAlreadyFinishedRunIsRejected(): void
    {
        $runId = $this->lifecycle->start('autocentrum');
        $this->lifecycle->complete($runId);

        $this->expectException(ImportRunNotRunningException::class);
        $this->lifecycle->complete($runId);
    }

    public function testFinishingANonExistentRunIsRejected(): void
    {
        $this->expectException(ImportRunNotRunningException::class);
        $this->lifecycle->complete(999999999);
    }

    public function testAFailedRunCanBeStartedAgainForTheSameSource(): void
    {
        $firstRunId = $this->lifecycle->start('autocentrum');
        $this->lifecycle->fail($firstRunId);

        $secondRunId = $this->lifecycle->start('autocentrum');

        self::assertGreaterThan($firstRunId, $secondRunId);
    }
}
