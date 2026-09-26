<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Import\ResetsImportSchema;
use Doctrine\DBAL\Connection;
use Throwable;
use UnexpectedValueException;

trait TaxonomyFixtures
{
    use ResetsImportSchema;

    private function resetTaxonomySchema(Connection $connection): void
    {
        $this->resetImportSchema($connection);
    }

    private function createRun(Connection $connection, string $source = 'test'): int
    {
        return self::asInt($connection->fetchOne(
            "INSERT INTO import_run (source, status, finished_at) VALUES (:source, 'completed', now()) RETURNING id",
            ['source' => $source],
        ));
    }

    private function insertBrand(Connection $connection, int $runId, string $ref, string $name): int
    {
        $connection->executeStatement(
            'INSERT INTO brand (source_ref, name, first_seen_run_id) VALUES (:ref, :name, :run)',
            ['ref' => $ref, 'name' => $name, 'run' => $runId],
        );

        return self::asInt($connection->fetchOne('SELECT id FROM brand WHERE source_ref = :ref', ['ref' => $ref]));
    }

    private function insertModel(Connection $connection, int $runId, int $brandId, string $ref, string $name): int
    {
        $connection->executeStatement(
            'INSERT INTO car_model (brand_id, source_ref, name, first_seen_run_id) VALUES (:brand, :ref, :name, :run)',
            ['brand' => $brandId, 'ref' => $ref, 'name' => $name, 'run' => $runId],
        );

        return self::asInt($connection->fetchOne('SELECT id FROM car_model WHERE source_ref = :ref', ['ref' => $ref]));
    }

    /**
     * @param array{
     *     generation?: ?string,
     *     bodyStyle?: ?string,
     *     unresolvedSegment?: ?string,
     *     powerHp?: ?float,
     *     engineDisplacementCcm?: ?int,
     *     bootCapacityL?: ?int,
     * } $attributes
     * @param list<array{ref: string, acceleration: float, fuelConsumption?: ?float, fuelTankL?: ?int, gearbox?: ?string, drive?: ?string}> $variants
     */
    private function insertVersionWithVariants(
        Connection $connection,
        int $runId,
        int $modelId,
        string $ref,
        string $name,
        string $fuelType,
        array $attributes,
        array $variants,
    ): int {
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'INSERT INTO version (
                    car_model_id, source_ref, name, fuel_type, generation, body_style, unresolved_segment,
                    power_hp, engine_displacement_ccm, boot_capacity_l, first_seen_run_id, last_seen_run_id
                ) VALUES (
                    :model, :ref, :name, :fuelType, :generation, :bodyStyle, :unresolvedSegment,
                    :powerHp, :engineDisplacementCcm, :bootCapacityL, :run, :run
                )',
                [
                    'model' => $modelId,
                    'ref' => $ref,
                    'name' => $name,
                    'fuelType' => $fuelType,
                    'generation' => $attributes['generation'] ?? null,
                    'bodyStyle' => $attributes['bodyStyle'] ?? null,
                    'unresolvedSegment' => $attributes['unresolvedSegment'] ?? null,
                    'powerHp' => $attributes['powerHp'] ?? null,
                    'engineDisplacementCcm' => $attributes['engineDisplacementCcm'] ?? null,
                    'bootCapacityL' => $attributes['bootCapacityL'] ?? null,
                    'run' => $runId,
                ],
            );

            $versionId = self::asInt($connection->fetchOne('SELECT id FROM version WHERE source_ref = :ref', ['ref' => $ref]));

            foreach ($variants as $variant) {
                $connection->executeStatement(
                    'INSERT INTO variant (
                        version_id, source_ref, acceleration_0_100_s, fuel_consumption_l_100km, fuel_tank_l,
                        gearbox, drive, first_seen_run_id
                    ) VALUES (
                        :version, :ref, :acceleration, :fuelConsumption, :fuelTankL, :gearbox, :drive, :run
                    )',
                    [
                        'version' => $versionId,
                        'ref' => $variant['ref'],
                        'acceleration' => $variant['acceleration'],
                        'fuelConsumption' => $variant['fuelConsumption'] ?? null,
                        'fuelTankL' => $variant['fuelTankL'] ?? null,
                        'gearbox' => $variant['gearbox'] ?? null,
                        'drive' => $variant['drive'] ?? null,
                        'run' => $runId,
                    ],
                );
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $versionId;
    }

    private static function publicId(Connection $connection, string $table, int $id): string
    {
        return self::asString($connection->fetchOne("SELECT public_id FROM {$table} WHERE id = :id", ['id' => $id]));
    }

    private static function asInt(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a numeric identifier.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a string value.');
        }

        return $value;
    }
}
