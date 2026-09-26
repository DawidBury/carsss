<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Reader;

use App\Catalog\Criteria\SearchCriteria;
use App\Catalog\Reader\SearchReader;
use App\Tests\Catalog\RefreshesSearchIndex;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchReaderTest extends KernelTestCase
{
    use RefreshesSearchIndex;
    use TaxonomyFixtures;

    private Connection $connection;
    private SearchReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->refreshSearchIndex($this->connection);
        $this->reader = new SearchReader($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        $this->refreshSearchIndex($this->connection);
        parent::tearDown();
    }

    public function testReturnsEveryLevelOfTheTaxonomyInOneHit(): void
    {
        $this->givenAudiA4();

        $hits = $this->reader->search(new SearchCriteria('audi a4 tdi', 5));

        self::assertCount(1, $hits);
        self::assertSame('Audi', $hits[0]->brandName);
        self::assertSame('A4', $hits[0]->modelName);
        self::assertSame('2.0 TDI 140KM', $hits[0]->versionName);
        self::assertSame('diesel', $hits[0]->fuelType);
        self::assertSame('b8', $hits[0]->generation);
        self::assertSame('sedan', $hits[0]->bodyStyle);
        self::assertNull($hits[0]->unresolvedSegment);
        self::assertSame('manual', $hits[0]->gearbox);
        self::assertSame('front', $hits[0]->drive);
    }

    public function testMatchesAPartiallyTypedLastToken(): void
    {
        $this->givenAudiA4();

        self::assertCount(1, $this->reader->search(new SearchCriteria('audi a4 td', 5)));
        self::assertCount(1, $this->reader->search(new SearchCriteria('aud', 5)));
    }

    public function testCorrectsATypoInABrandName(): void
    {
        $this->givenAudiA4();

        $hits = $this->reader->search(new SearchCriteria('audy a4', 5));

        self::assertCount(1, $hits);
        self::assertSame('Audi', $hits[0]->brandName);
    }

    public function testCorrectsATypoInABodyStyle(): void
    {
        $this->givenAudiA4();

        $hits = $this->reader->search(new SearchCriteria('audi sedn', 5));

        self::assertCount(1, $hits);
        self::assertSame('A4', $hits[0]->modelName);
    }

    public function testDoesNotCorrectAPrefixOfAKnownWord(): void
    {
        $this->givenAudiA4();

        $hits = $this->reader->search(new SearchCriteria('audi sed', 5));

        self::assertCount(1, $hits);
        self::assertSame('sedan', $hits[0]->bodyStyle);
    }

    public function testExactMatchesComeBeforeCorrectedOnes(): void
    {
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
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $this->insertModel($this->connection, $run, $audi, 'audi/a5', 'A5'),
            'audi/a5/b8/coupe/2-0-tdi',
            '2.0 TDI 170KM',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'coupe'],
            [['ref' => 'manual-front', 'acceleration' => 8.1, 'gearbox' => 'manual', 'drive' => 'front']],
        );
        $this->refreshSearchIndex($this->connection);

        $hits = $this->reader->search(new SearchCriteria('audi coupe', 5));

        self::assertNotSame([], $hits);
        self::assertSame('A5', $hits[0]->modelName);
    }

    public function testHonoursTheLimit(): void
    {
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
            [
                ['ref' => 'manual-front', 'acceleration' => 9.3, 'gearbox' => 'manual', 'drive' => 'front'],
                ['ref' => 'automatic-front', 'acceleration' => 9.1, 'gearbox' => 'automatic', 'drive' => 'front'],
                ['ref' => 'automatic-all', 'acceleration' => 8.8, 'gearbox' => 'automatic', 'drive' => 'all'],
            ],
        );
        $this->refreshSearchIndex($this->connection);

        self::assertCount(3, $this->reader->search(new SearchCriteria('audi a4', 5)));
        self::assertCount(2, $this->reader->search(new SearchCriteria('audi a4', 2)));
    }

    public function testUnknownPhraseReturnsAnEmptyList(): void
    {
        $this->givenAudiA4();

        self::assertSame([], $this->reader->search(new SearchCriteria('ferrari testarossa', 5)));
    }

    public function testPhraseWithoutUsableTokensReturnsAnEmptyList(): void
    {
        $this->givenAudiA4();

        self::assertSame([], $this->reader->search(new SearchCriteria('!@#$%^', 5)));
    }

    public function testUnmappedGearboxLabelIsNeverExposed(): void
    {
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
            [['ref' => 'unknown', 'acceleration' => 9.3]],
        );
        $this->connection->executeStatement(
            "UPDATE variant SET gearbox_unmapped_label = 'automatyczna dwusprzęgłowa'",
        );
        $this->refreshSearchIndex($this->connection);

        $hits = $this->reader->search(new SearchCriteria('audi a4', 5));

        self::assertCount(1, $hits);
        self::assertNull($hits[0]->gearbox);
        self::assertNull($hits[0]->drive);
    }

    private function givenAudiA4(): void
    {
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
    }
}
