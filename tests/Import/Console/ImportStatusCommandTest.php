<?php

declare(strict_types=1);

namespace App\Tests\Import\Console;

use App\Import\Console\ImportStatusCommand;
use App\Import\Run\ImportRunStatusReader;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportStatusCommandTest extends KernelTestCase
{
    use ResetsImportSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetImportSchema($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetImportSchema($this->connection);
        parent::tearDown();
    }

    public function testReportsThatNoRunHasEverStarted(): void
    {
        $tester = new CommandTester($this->command(new FakeCountingTransport(0), new FakeCountingTransport(0)));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No import run has ever started', $tester->getDisplay());
    }

    public function testReportsQueueDepthAndFailedCountWhenTheTransportsSupportCounting(): void
    {
        $this->createRunningImportRun($this->connection, 'autocentrum');

        $tester = new CommandTester($this->command(new FakeCountingTransport(150), new FakeCountingTransport(3)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('running', $display);
        self::assertStringContainsString('150 message(s) remaining', $display);
        self::assertStringContainsString('3 message(s) in the failed transport', $display);
    }

    public function testReportsUnavailableWhenTheTransportDoesNotSupportCounting(): void
    {
        $this->createRunningImportRun($this->connection, 'autocentrum');

        $tester = new CommandTester($this->command(new FakeUncountableTransport(), new FakeUncountableTransport()));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Queue depth: unavailable', $display);
        self::assertStringContainsString('Failed message count: unavailable', $display);
        self::assertStringContainsString('Estimated total and progress percentage: unavailable', $display);
    }

    public function testShowsTheMostRecentlyCompletedRunWhenNothingIsRunning(): void
    {
        $runId = $this->createRunningImportRun($this->connection, 'autocentrum');
        $this->connection->executeStatement(
            "UPDATE import_run SET status = 'completed', finished_at = now() WHERE id = :id",
            ['id' => $runId],
        );

        $tester = new CommandTester($this->command(new FakeCountingTransport(0), new FakeCountingTransport(0)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('run #%d', $runId), $display);
        self::assertStringContainsString('completed', $display);
    }

    private function command(FakeCountingTransport|FakeUncountableTransport $importTransport, FakeCountingTransport|FakeUncountableTransport $failedTransport): ImportStatusCommand
    {
        return new ImportStatusCommand(new ImportRunStatusReader($this->connection), $importTransport, $failedTransport);
    }
}
