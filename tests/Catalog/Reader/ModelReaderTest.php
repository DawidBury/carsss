<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Reader;

use App\Catalog\Criteria\ModelListCriteria;
use App\Catalog\Reader\ModelReader;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ModelReaderTest extends KernelTestCase
{
    use TaxonomyFixtures;

    private Connection $connection;
    private ModelReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->reader = new ModelReader($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        parent::tearDown();
    }

    public function testListsModelsWithNestedBrand(): void
    {
        $run = $this->createRun($this->connection);
        $audiId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $this->insertModel($this->connection, $run, $audiId, 'audi/a4', 'A4');

        $page = $this->reader->list(new ModelListCriteria(limit: 50));

        self::assertCount(1, $page->items);
        self::assertSame('A4', $page->items[0]->name);
        self::assertSame('Audi', $page->items[0]->brand->name);
    }

    public function testFiltersByBrandId(): void
    {
        $run = $this->createRun($this->connection);
        $audiId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $bmwId = $this->insertBrand($this->connection, $run, 'bmw', 'BMW');
        $this->insertModel($this->connection, $run, $audiId, 'audi/a4', 'A4');
        $this->insertModel($this->connection, $run, $bmwId, 'bmw/3', '3 Series');

        $audiPublicId = $this->publicId($this->connection, 'brand', $audiId);

        $page = $this->reader->list(new ModelListCriteria(limit: 50, brandId: $audiPublicId));

        self::assertCount(1, $page->items);
        self::assertSame('A4', $page->items[0]->name);
    }

    public function testUnknownBrandIdFilterReturnsEmptyCollectionNotAnError(): void
    {
        $page = $this->reader->list(new ModelListCriteria(
            limit: 50,
            brandId: '00000000-0000-0000-0000-000000000000',
        ));

        self::assertSame([], $page->items);
    }
}
