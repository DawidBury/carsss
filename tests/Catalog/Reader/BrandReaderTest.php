<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Reader;

use App\Catalog\Criteria\BrandListCriteria;
use App\Catalog\Criteria\Cursor;
use App\Catalog\Reader\BrandReader;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BrandReaderTest extends KernelTestCase
{
    use TaxonomyFixtures;

    private Connection $connection;
    private BrandReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->reader = new BrandReader($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        parent::tearDown();
    }

    public function testListsBrandsOrderedByNameThenPublicId(): void
    {
        $run = $this->createRun($this->connection);
        $this->insertBrand($this->connection, $run, 'skoda', 'Skoda');
        $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $this->insertBrand($this->connection, $run, 'bmw', 'BMW');

        $page = $this->reader->list(new BrandListCriteria(limit: 50));

        self::assertSame(['Audi', 'BMW', 'Skoda'], array_map(static fn ($brand) => $brand->name, $page->items));
        self::assertFalse($page->hasMore);
    }

    public function testReturnsEmptyPageWhenThereAreNoBrands(): void
    {
        $page = $this->reader->list(new BrandListCriteria(limit: 50));

        self::assertSame([], $page->items);
        self::assertFalse($page->hasMore);
    }

    public function testPaginatesByNameAndPublicIdWithoutSkippingOrDuplicating(): void
    {
        $run = $this->createRun($this->connection);
        foreach (['Audi', 'BMW', 'Citroen', 'Dacia', 'Fiat'] as $name) {
            $this->insertBrand($this->connection, $run, strtolower($name), $name);
        }

        $collected = [];
        $cursor = null;

        do {
            $page = $this->reader->list(new BrandListCriteria(limit: 2, cursor: $cursor));
            foreach ($page->items as $brand) {
                $collected[] = $brand->name;
            }

            if ($page->hasMore) {
                $last = $page->items[\count($page->items) - 1];
                $cursor = new Cursor($last->name, $last->id);
            } else {
                $cursor = null;
            }
        } while ($page->hasMore);

        self::assertSame(['Audi', 'BMW', 'Citroen', 'Dacia', 'Fiat'], $collected);
    }

    public function testHasMoreIsFalseWhenExactlyLimitRowsExist(): void
    {
        $run = $this->createRun($this->connection);
        $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $this->insertBrand($this->connection, $run, 'bmw', 'BMW');

        $page = $this->reader->list(new BrandListCriteria(limit: 2));

        self::assertCount(2, $page->items);
        self::assertFalse($page->hasMore);
    }
}
