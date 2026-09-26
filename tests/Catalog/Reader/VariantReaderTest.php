<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Reader;

use App\Catalog\Criteria\Cursor;
use App\Catalog\Criteria\Drive;
use App\Catalog\Criteria\FuelType;
use App\Catalog\Criteria\Gearbox;
use App\Catalog\Criteria\VariantIdCriteria;
use App\Catalog\Criteria\VariantListCriteria;
use App\Catalog\Criteria\VariantSort;
use App\Catalog\Reader\VariantReader;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UnexpectedValueException;

final class VariantReaderTest extends KernelTestCase
{
    use TaxonomyFixtures;

    private Connection $connection;
    private VariantReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->reader = new VariantReader($this->connection, self::getContainer()->get(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        parent::tearDown();
    }

    public function testListsVariantsOrderedByAccelerationAscendingWithNestedTaxonomy(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan', 'powerHp' => 140.0, 'engineDisplacementCcm' => 1968, 'bootCapacityL' => 480],
            [
                ['ref' => 'a', 'acceleration' => 9.3, 'fuelConsumption' => 6.3, 'fuelTankL' => 65, 'gearbox' => 'manual', 'drive' => 'front'],
                ['ref' => 'b', 'acceleration' => 7.1],
            ],
        );

        $page = $this->reader->list(new VariantListCriteria(limit: 50));

        self::assertCount(2, $page->items);
        self::assertSame([7.1, 9.3], array_map(static fn ($v) => (float) $v->acceleration0To100S, $page->items));

        $fast = $page->items[0];
        self::assertNull($fast->fuelConsumptionL100Km);
        self::assertNull($fast->fuelTankL);
        self::assertNull($fast->gearbox);
        self::assertNull($fast->drive);

        $slow = $page->items[1];
        self::assertSame('6.3', $slow->fuelConsumptionL100Km);
        self::assertSame(65, $slow->fuelTankL);
        self::assertSame('manual', $slow->gearbox);
        self::assertSame('front', $slow->drive);
        self::assertSame('diesel', $slow->version->fuelType);
        self::assertSame('b8', $slow->version->generation);
        self::assertSame('sedan', $slow->version->bodyStyle);
        self::assertNull($slow->version->unresolvedSegment);
        self::assertSame('140.0', $slow->version->powerHp);
        self::assertSame(1968, $slow->version->engineDisplacementCcm);
        self::assertSame(480, $slow->version->bootCapacityL);
        self::assertSame('A4', $slow->version->model->name);
        self::assertSame('Audi', $slow->version->model->brand->name);
    }

    public function testUnresolvedSegmentIsExposedAndExcludesGenerationAndBodyStyle(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v2',
            'e-tron',
            'electric',
            ['unresolvedSegment' => 'ii'],
            [['ref' => 'a', 'acceleration' => 5.5]],
        );

        $page = $this->reader->list(new VariantListCriteria(limit: 50));

        self::assertSame('ii', $page->items[0]->version->unresolvedSegment);
        self::assertNull($page->items[0]->version->generation);
        self::assertNull($page->items[0]->version->bodyStyle);
    }

    public function testFiltersByFuelType(): void
    {
        $this->seedTwoFuelTypes();

        $page = $this->reader->list(new VariantListCriteria(limit: 50, fuelType: FuelType::Electric));

        self::assertCount(1, $page->items);
        self::assertSame('electric', $page->items[0]->version->fuelType);
    }

    public function testFiltersByGearboxAndDrive(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            [],
            [
                ['ref' => 'a', 'acceleration' => 9.0, 'gearbox' => 'manual', 'drive' => 'front'],
                ['ref' => 'b', 'acceleration' => 8.0, 'gearbox' => 'automatic', 'drive' => 'all'],
            ],
        );

        $manual = $this->reader->list(new VariantListCriteria(limit: 50, gearbox: Gearbox::Manual));
        self::assertCount(1, $manual->items);
        self::assertSame('manual', $manual->items[0]->gearbox);

        $all = $this->reader->list(new VariantListCriteria(limit: 50, drive: Drive::All));
        self::assertCount(1, $all->items);
        self::assertSame('all', $all->items[0]->drive);
    }

    public function testFiltersByAccelerationRange(): void
    {
        $this->seedAccelerationSpread();

        $page = $this->reader->list(new VariantListCriteria(
            limit: 50,
            accelerationMin: '7.0',
            accelerationMax: '9.0',
        ));

        self::assertSame(['7.1', '8.0', '9.0'], array_map(static fn ($v) => $v->acceleration0To100S, $page->items));
    }

    public function testDescendingSortReversesOrder(): void
    {
        $this->seedAccelerationSpread();

        $page = $this->reader->list(new VariantListCriteria(limit: 50, sort: VariantSort::AccelerationDescending));

        $values = array_map(static fn ($v) => (float) $v->acceleration0To100S, $page->items);
        $sorted = $values;
        rsort($sorted);
        self::assertSame($sorted, $values);
    }

    public function testKeysetPaginationAscendingCoversAllRowsExactlyOnce(): void
    {
        $this->seedAccelerationSpread();

        $collected = [];
        $cursor = null;

        do {
            $page = $this->reader->list(new VariantListCriteria(limit: 2, cursor: $cursor));
            foreach ($page->items as $variant) {
                $collected[] = $variant->acceleration0To100S;
            }

            if ($page->hasMore) {
                $last = $page->items[\count($page->items) - 1];
                $cursor = new Cursor($last->acceleration0To100S, $last->id);
            } else {
                $cursor = null;
            }
        } while ($page->hasMore);

        self::assertSame(['5.5', '7.1', '8.0', '9.0', '12.0'], $collected);
    }

    public function testKeysetPaginationDescendingCoversAllRowsExactlyOnce(): void
    {
        $this->seedAccelerationSpread();

        $collected = [];
        $cursor = null;

        do {
            $page = $this->reader->list(new VariantListCriteria(limit: 2, sort: VariantSort::AccelerationDescending, cursor: $cursor));
            foreach ($page->items as $variant) {
                $collected[] = $variant->acceleration0To100S;
            }

            if ($page->hasMore) {
                $last = $page->items[\count($page->items) - 1];
                $cursor = new Cursor($last->acceleration0To100S, $last->id);
            } else {
                $cursor = null;
            }
        } while ($page->hasMore);

        self::assertSame(['12.0', '9.0', '8.0', '7.1', '5.5'], $collected);
    }

    public function testFindByIdReturnsMatchingVariantWithNestedTaxonomyAndNumericStrings(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan', 'powerHp' => 140.0, 'engineDisplacementCcm' => 1968, 'bootCapacityL' => 480],
            [['ref' => 'a', 'acceleration' => 9.3, 'fuelConsumption' => 6.3, 'fuelTankL' => 65, 'gearbox' => 'manual', 'drive' => 'front']],
        );

        $publicId = self::asString($this->connection->fetchOne('SELECT public_id FROM variant'));

        $variant = $this->reader->findById(new VariantIdCriteria($publicId));

        self::assertNotNull($variant);
        self::assertSame($publicId, $variant->id);
        self::assertSame('9.3', $variant->acceleration0To100S);
        self::assertSame('6.3', $variant->fuelConsumptionL100Km);
        self::assertSame(65, $variant->fuelTankL);
        self::assertSame('manual', $variant->gearbox);
        self::assertSame('front', $variant->drive);
        self::assertSame('diesel', $variant->version->fuelType);
        self::assertSame('b8', $variant->version->generation);
        self::assertSame('sedan', $variant->version->bodyStyle);
        self::assertNull($variant->version->unresolvedSegment);
        self::assertSame('140.0', $variant->version->powerHp);
        self::assertSame(1968, $variant->version->engineDisplacementCcm);
        self::assertSame(480, $variant->version->bootCapacityL);
        self::assertSame('A4', $variant->version->model->name);
        self::assertSame('Audi', $variant->version->model->brand->name);
    }

    public function testFindByIdReturnsNullForUnknownPublicId(): void
    {
        $variant = $this->reader->findById(new VariantIdCriteria('00000000-0000-0000-0000-000000000000'));

        self::assertNull($variant);
    }

    public function testFindByIdIssuesExactlyOneSqlStatement(): void
    {
        self::assertTrue(
            self::getContainer()->getParameter('kernel.debug'),
            'This test relies on the Doctrine debug data holder, which is only wired when kernel.debug is true.',
        );

        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            [],
            [['ref' => 'a', 'acceleration' => 9.3]],
        );

        $publicId = self::asString($this->connection->fetchOne('SELECT public_id FROM variant'));

        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $variant = $this->reader->findById(new VariantIdCriteria($publicId));

        self::assertNotNull($variant);

        $queries = self::capturedQueries($debugDataHolder);

        self::assertCount(
            1,
            $queries,
            sprintf(
                "Expected VariantReader::findById() to issue exactly one SQL statement, got %d:\n%s",
                count($queries),
                implode("\n", $queries),
            ),
        );
    }

    private function seedTwoFuelTypes(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/diesel',
            '2.0 TDI',
            'diesel',
            [],
            [['ref' => 'a', 'acceleration' => 9.0]],
        );
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/electric',
            'e-tron',
            'electric',
            [],
            [['ref' => 'a', 'acceleration' => 5.0]],
        );
    }

    private function seedAccelerationSpread(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            [],
            [
                ['ref' => 'a', 'acceleration' => 9.0],
                ['ref' => 'b', 'acceleration' => 7.1],
                ['ref' => 'c', 'acceleration' => 12.0],
                ['ref' => 'd', 'acceleration' => 5.5],
                ['ref' => 'e', 'acceleration' => 8.0],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private static function capturedQueries(BacktraceDebugDataHolder $debugDataHolder): array
    {
        $queries = $debugDataHolder->getData()['default'] ?? [];

        $sql = [];
        foreach ($queries as $query) {
            if (!isset($query['sql']) || !\is_string($query['sql'])) {
                throw new UnexpectedValueException('Expected each captured query to carry a SQL string.');
            }

            $sql[] = $query['sql'];
        }

        return $sql;
    }
}
