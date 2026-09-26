<?php

declare(strict_types=1);

namespace App\Catalog\Reader;

use App\Catalog\Criteria\VariantIdCriteria;
use App\Catalog\Criteria\VariantListCriteria;
use App\Catalog\Criteria\VariantSort;
use App\Catalog\Result\Brand;
use App\Catalog\Result\Model;
use App\Catalog\Result\Page;
use App\Catalog\Result\Variant;
use App\Catalog\Result\Version;
use App\Entity\Variant as VariantEntity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type VariantRow array{
 *     variant_public_id: string,
 *     acceleration_0_100_s: string,
 *     fuel_consumption_l_100km: ?string,
 *     fuel_tank_l: ?int,
 *     gearbox: ?string,
 *     drive: ?string,
 *     version_public_id: string,
 *     version_name: string,
 *     fuel_type: string,
 *     generation: ?string,
 *     body_style: ?string,
 *     unresolved_segment: ?string,
 *     power_hp: ?string,
 *     engine_displacement_ccm: ?int,
 *     boot_capacity_l: ?int,
 *     model_public_id: string,
 *     model_name: string,
 *     brand_public_id: string,
 *     brand_name: string,
 * }
 */
final readonly class VariantReader
{
    private const string SELECT = <<<'SQL'
        SELECT
            va.public_id AS variant_public_id,
            va.acceleration_0_100_s,
            va.fuel_consumption_l_100km,
            va.fuel_tank_l,
            va.gearbox,
            va.drive,
            v.public_id AS version_public_id,
            v.name AS version_name,
            v.fuel_type,
            v.generation,
            v.body_style,
            v.unresolved_segment,
            v.power_hp,
            v.engine_displacement_ccm,
            v.boot_capacity_l,
            cm.public_id AS model_public_id,
            cm.name AS model_name,
            b.public_id AS brand_public_id,
            b.name AS brand_name
        FROM variant va
        JOIN version v ON v.id = va.version_id
        JOIN car_model cm ON cm.id = v.car_model_id
        JOIN brand b ON b.id = cm.brand_id
        SQL;

    private const string FIND_BY_ID_DQL = <<<'DQL'
        SELECT va, ve, m, b
        FROM App\Entity\Variant va
        JOIN va.version ve
        JOIN ve.carModel m
        JOIN m.brand b
        WHERE va.publicId = :publicId
        DQL;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Page<Variant>
     */
    public function list(VariantListCriteria $criteria): Page
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($criteria->brandId !== null) {
            $conditions[] = 'b.public_id = :brandId';
            $params['brandId'] = $criteria->brandId;
        }

        if ($criteria->modelId !== null) {
            $conditions[] = 'cm.public_id = :modelId';
            $params['modelId'] = $criteria->modelId;
        }

        if ($criteria->versionId !== null) {
            $conditions[] = 'v.public_id = :versionId';
            $params['versionId'] = $criteria->versionId;
        }

        if ($criteria->fuelType !== null) {
            $conditions[] = 'v.fuel_type = :fuelType';
            $params['fuelType'] = $criteria->fuelType->value;
        }

        if ($criteria->gearbox !== null) {
            $conditions[] = 'va.gearbox = :gearbox';
            $params['gearbox'] = $criteria->gearbox->value;
        }

        if ($criteria->drive !== null) {
            $conditions[] = 'va.drive = :drive';
            $params['drive'] = $criteria->drive->value;
        }

        if ($criteria->accelerationMin !== null) {
            $conditions[] = 'va.acceleration_0_100_s >= :accelerationMin';
            $params['accelerationMin'] = $criteria->accelerationMin;
        }

        if ($criteria->accelerationMax !== null) {
            $conditions[] = 'va.acceleration_0_100_s <= :accelerationMax';
            $params['accelerationMax'] = $criteria->accelerationMax;
        }

        $descending = $criteria->sort === VariantSort::AccelerationDescending;

        if ($criteria->cursor !== null) {
            $operator = $descending ? '<' : '>';
            $conditions[] = "ROW(va.acceleration_0_100_s, va.public_id) {$operator} ROW(:afterValue, :afterPublicId)";
            $params['afterValue'] = $criteria->cursor->afterValue;
            $params['afterPublicId'] = $criteria->cursor->afterPublicId;
        }

        $sql = self::SELECT;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $direction = $descending ? 'DESC' : 'ASC';
        $sql .= " ORDER BY va.acceleration_0_100_s {$direction}, va.public_id {$direction} LIMIT :fetchLimit";
        $params['fetchLimit'] = $criteria->limit + 1;
        $types['fetchLimit'] = ParameterType::INTEGER;

        /** @var list<VariantRow> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $hasMore = count($rows) > $criteria->limit;
        $rows = \array_slice($rows, 0, $criteria->limit);

        $items = array_map(self::hydrate(...), $rows);

        return new Page($items, $hasMore);
    }

    public function findById(VariantIdCriteria $criteria): ?Variant
    {
        $result = $this->entityManager->createQuery(self::FIND_BY_ID_DQL)
            ->setParameter('publicId', $criteria->publicId, 'uuid')
            ->getOneOrNullResult();

        if (!$result instanceof VariantEntity) {
            return null;
        }

        return self::hydrateFromEntity($result);
    }

    /**
     * @param VariantRow $row
     */
    private static function hydrate(array $row): Variant
    {
        $brand = new Brand($row['brand_public_id'], $row['brand_name']);

        $model = new Model($row['model_public_id'], $row['model_name'], $brand);

        $version = new Version(
            $row['version_public_id'],
            $row['version_name'],
            $row['fuel_type'],
            $row['generation'],
            $row['body_style'],
            $row['unresolved_segment'],
            $row['power_hp'],
            $row['engine_displacement_ccm'],
            $row['boot_capacity_l'],
            $model,
        );

        return new Variant(
            $row['variant_public_id'],
            $row['acceleration_0_100_s'],
            $row['fuel_consumption_l_100km'],
            $row['fuel_tank_l'],
            $row['gearbox'],
            $row['drive'],
            $version,
        );
    }

    private static function hydrateFromEntity(VariantEntity $variant): Variant
    {
        $versionEntity = $variant->version;
        $modelEntity = $versionEntity->getCarModel();
        $brandEntity = $modelEntity->brand;

        $brand = new Brand($brandEntity->publicId->toRfc4122(), $brandEntity->name);

        $model = new Model($modelEntity->publicId->toRfc4122(), $modelEntity->name, $brand);

        $version = new Version(
            $versionEntity->publicId->toRfc4122(),
            $versionEntity->getName(),
            $versionEntity->getFuelType()->value,
            $versionEntity->getGeneration(),
            $versionEntity->getBodyStyle(),
            $versionEntity->getUnresolvedSegment(),
            $versionEntity->getPowerHp(),
            $versionEntity->getEngineDisplacementCcm(),
            $versionEntity->getBootCapacityL(),
            $model,
        );

        return new Variant(
            $variant->publicId->toRfc4122(),
            $variant->getAcceleration0To100S(),
            $variant->getFuelConsumptionL100Km(),
            $variant->getFuelTankL(),
            $variant->getGearbox()?->value,
            $variant->getDrive()?->value,
            $version,
        );
    }
}
