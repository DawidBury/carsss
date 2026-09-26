<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Console;

use App\Catalog\Console\RefreshSearchIndexCommand;
use App\Catalog\Criteria\SearchCriteria;
use App\Catalog\Index\SearchIndexRefresher;
use App\Catalog\Reader\SearchReader;
use App\Tests\Catalog\RefreshesSearchIndex;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshSearchIndexCommandTest extends KernelTestCase
{
    use RefreshesSearchIndex;
    use TaxonomyFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->refreshSearchIndex($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        $this->refreshSearchIndex($this->connection);
        parent::tearDown();
    }

    public function testMakesFreshlyImportedRowsSearchable(): void
    {
        $reader = new SearchReader($this->connection);

        $run = $this->createRun($this->connection);
        $audi = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $this->insertModel($this->connection, $run, $audi, 'audi/a4', 'A4'),
            'audi/a4/b8/sedan/2-0-tdi',
            '2.0 TDI 140KM',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan'],
            [['ref' => 'manual-front', 'acceleration' => 9.3, 'gearbox' => 'manual', 'drive' => 'front']],
        );

        self::assertSame([], $reader->search(new SearchCriteria('audi a4', 5)));

        $tester = new CommandTester(new RefreshSearchIndexCommand(new SearchIndexRefresher($this->connection)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Search index refreshed', $tester->getDisplay());
        self::assertCount(1, $reader->search(new SearchCriteria('audi a4', 5)));
    }

    public function testDropsRowsThatDisappearedFromTheTaxonomy(): void
    {
        $reader = new SearchReader($this->connection);

        $run = $this->createRun($this->connection);
        $audi = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $this->insertModel($this->connection, $run, $audi, 'audi/a4', 'A4'),
            'audi/a4/b8/sedan/2-0-tdi',
            '2.0 TDI 140KM',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan'],
            [['ref' => 'manual-front', 'acceleration' => 9.3, 'gearbox' => 'manual', 'drive' => 'front']],
        );
        $this->refreshSearchIndex($this->connection);
        self::assertCount(1, $reader->search(new SearchCriteria('audi a4', 5)));

        $this->connection->executeStatement('DELETE FROM version');
        new CommandTester(new RefreshSearchIndexCommand(new SearchIndexRefresher($this->connection)))->execute([]);

        self::assertSame([], $reader->search(new SearchCriteria('audi a4', 5)));
    }

    public function testTheCommandIsRegisteredUnderItsName(): void
    {
        $command = new Application(self::bootKernel())->find('app:search:refresh');

        self::assertSame('app:search:refresh', $command->getName());
    }
}
