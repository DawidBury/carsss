<?php

declare(strict_types=1);

namespace App\Tests\Import\Console;

use App\Import\Console\FinishImportCommand;
use App\Import\Run\ImportRunLifecycle;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FinishImportCommandTest extends KernelTestCase
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

    public function testMarksARunningRunAsCompletedByDefault(): void
    {
        $runId = $this->lifecycle->start('autocentrum');

        $tester = new CommandTester(new FinishImportCommand($this->lifecycle));
        $exitCode = $tester->execute(['runId' => (string) $runId]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('completed', $this->connection->fetchOne(
            'SELECT status FROM import_run WHERE id = :id',
            ['id' => $runId],
        ));
    }

    public function testMarksARunningRunAsFailedWhenTheFlagIsPassed(): void
    {
        $runId = $this->lifecycle->start('autocentrum');

        $tester = new CommandTester(new FinishImportCommand($this->lifecycle));
        $exitCode = $tester->execute(['runId' => (string) $runId, '--failed' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('failed', $this->connection->fetchOne(
            'SELECT status FROM import_run WHERE id = :id',
            ['id' => $runId],
        ));
    }

    public function testReportsClearlyWhenTheRunIsNotCurrentlyRunning(): void
    {
        $tester = new CommandTester(new FinishImportCommand($this->lifecycle));
        $exitCode = $tester->execute(['runId' => '999999999']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not currently running', $tester->getDisplay());
    }
}
