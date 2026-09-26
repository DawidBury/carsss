<?php

declare(strict_types=1);

namespace App\Tests\Import\Console;

use App\Import\Console\StartImportCommand;
use App\Import\Message\ImportRecord;
use App\Import\Run\ImportRunLifecycle;
use App\Tests\Import\MessageHandler\FakeSourceCatalog;
use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StartImportCommandTest extends KernelTestCase
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

    public function testQueuesOneMessagePerRecordAndCreatesARunningRun(): void
    {
        $catalog = new FakeSourceCatalog(refs: ['a', 'b', 'c'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $bus->dispatched);
        self::assertContainsOnlyInstancesOf(ImportRecord::class, $bus->dispatched);

        $runId = self::databaseInt($this->connection->fetchOne("SELECT id FROM import_run WHERE source = 'autocentrum'"));

        foreach ($bus->dispatched as $message) {
            self::assertSame($runId, $message->runId);
        }
        self::assertSame(['a', 'b', 'c'], array_map(static fn (ImportRecord $m): string => $m->ref, $bus->dispatched));
    }

    public function testRefusesToStartASecondRunForTheSameSourceAndReportsItClearly(): void
    {
        $this->lifecycle->start('autocentrum');

        $catalog = new FakeSourceCatalog(refs: ['a'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $bus->dispatched);
        self::assertStringContainsString('already running', $tester->getDisplay());
    }

    public function testLimitOptionQueuesOnlyTheFirstNRecords(): void
    {
        $catalog = new FakeSourceCatalog(refs: ['a', 'b', 'c', 'd'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute(['--limit' => '2']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $bus->dispatched);
        self::assertContainsOnlyInstancesOf(ImportRecord::class, $bus->dispatched);
        self::assertSame(['a', 'b'], array_map(static fn (ImportRecord $m): string => $m->ref, $bus->dispatched));
    }

    public function testOmittingLimitQueuesEveryRecord(): void
    {
        $catalog = new FakeSourceCatalog(refs: ['a', 'b', 'c'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $bus->dispatched);
    }

    public function testLimitGreaterThanTheAvailableRecordsQueuesAllOfThem(): void
    {
        $catalog = new FakeSourceCatalog(refs: ['a', 'b'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute(['--limit' => '50']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $bus->dispatched);
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitFailsClearlyWithoutStartingARunOrQueueingAnything(string $limit): void
    {
        $catalog = new FakeSourceCatalog(refs: ['a', 'b'], catalogName: 'autocentrum');
        $bus = new InMemoryMessageBus();
        $tester = new CommandTester(new StartImportCommand($catalog, $this->lifecycle, $bus));

        $exitCode = $tester->execute(['--limit' => $limit]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $bus->dispatched);
        self::assertStringContainsString('limit', $tester->getDisplay());
        self::assertSame(0, self::databaseInt($this->connection->fetchOne('SELECT count(*) FROM import_run')));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not numeric' => ['abc'];
        yield 'decimal' => ['1.5'];
    }
}
