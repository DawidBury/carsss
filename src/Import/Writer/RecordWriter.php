<?php

declare(strict_types=1);

namespace App\Import\Writer;

use App\Entity\CarModel;
use App\Entity\Drive as EntityDrive;
use App\Entity\FuelType as EntityFuelType;
use App\Entity\Gearbox as EntityGearbox;
use App\Entity\ImportRun;
use App\Entity\Variant;
use App\Entity\Version;
use App\Source\Contract\Drive as SourceDrive;
use App\Source\Contract\FuelType as SourceFuelType;
use App\Source\Contract\Gearbox as SourceGearbox;
use App\Source\Contract\SourceRecord;
use App\Source\Contract\SourceVariant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

#[WithMonologChannel('import')]
final readonly class RecordWriter
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function write(int $runId, SourceRecord $record): void
    {
        if ([] === $record->variants) {
            $this->logger->info('import.record_skipped', [
                'runId' => $runId,
                'ref' => $record->ref,
                'reason' => 'no_usable_variants',
            ]);

            return;
        }

        $this->connection->beginTransaction();

        try {
            $brandId = $this->getOrCreateBrand($runId, $record->taxonomy->brandRef, $record->taxonomy->brandName);
            $modelId = $this->getOrCreateModel($runId, $brandId, $record->taxonomy->modelRef, $record->taxonomy->modelName);

            $version = $this->upsertVersion($runId, $modelId, $record);
            $this->syncVariants($runId, $version, $record->variants);

            $this->entityManager->flush();
            $this->connection->commit();

            $this->logger->info('import.version_written', [
                'runId' => $runId,
                'ref' => $record->ref,
                'variantCount' => count($record->variants),
            ]);
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->managerRegistry->resetManager();

            throw $exception;
        }

        $this->entityManager->clear();
    }

    public function markSeen(int $runId, string $ref): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE version SET last_seen_run_id = :runId WHERE source_ref = :ref',
            ['runId' => $runId, 'ref' => $ref],
        );
    }

    private function getOrCreateBrand(int $runId, string $ref, string $name): int
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO brand (source_ref, name, first_seen_run_id) VALUES (:ref, :name, :runId) '
            . 'ON CONFLICT (source_ref) DO NOTHING RETURNING id',
            ['ref' => $ref, 'name' => $name, 'runId' => $runId],
        );

        if (false !== $id) {
            return self::requireId($id);
        }

        return self::requireId($this->connection->fetchOne(
            'SELECT id FROM brand WHERE source_ref = :ref',
            ['ref' => $ref],
        ));
    }

    private function getOrCreateModel(int $runId, int $brandId, string $ref, string $name): int
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO car_model (brand_id, source_ref, name, first_seen_run_id) VALUES (:brandId, :ref, :name, :runId) '
            . 'ON CONFLICT (source_ref) DO NOTHING RETURNING id',
            ['brandId' => $brandId, 'ref' => $ref, 'name' => $name, 'runId' => $runId],
        );

        if (false !== $id) {
            return self::requireId($id);
        }

        return self::requireId($this->connection->fetchOne(
            'SELECT id FROM car_model WHERE source_ref = :ref',
            ['ref' => $ref],
        ));
    }

    private function upsertVersion(int $runId, int $modelId, SourceRecord $record): Version
    {
        $taxonomy = $record->taxonomy;

        $version = $this->entityManager->getRepository(Version::class)->findOneBy(['sourceRef' => $record->ref]);
        $carModel = $this->reference(CarModel::class, $modelId);
        $run = $this->reference(ImportRun::class, $runId);
        $powerHp = self::decimalString($record->powerHp);

        if (null === $version) {
            $version = new Version(
                $carModel,
                $record->ref,
                $taxonomy->versionName,
                self::entityFuelType($record->fuelType),
                $taxonomy->generation,
                $taxonomy->bodyStyle,
                $taxonomy->unresolvedSegment,
                $powerHp,
                $record->engineDisplacementCcm,
                $record->bootCapacityL,
                $run,
            );

            $this->entityManager->persist($version);

            return $version;
        }

        $version->applyRecord(
            $carModel,
            $taxonomy->versionName,
            self::entityFuelType($record->fuelType),
            $taxonomy->generation,
            $taxonomy->bodyStyle,
            $taxonomy->unresolvedSegment,
            $powerHp,
            $record->engineDisplacementCcm,
            $record->bootCapacityL,
        );
        $version->markSeen($run);

        return $version;
    }

    /**
     * @param list<SourceVariant> $variants
     */
    private function syncVariants(int $runId, Version $version, array $variants): void
    {
        $run = $this->reference(ImportRun::class, $runId);

        /** @var array<array-key, true> $incomingRefs */
        $incomingRefs = [];
        foreach ($variants as $variant) {
            $incomingRefs[$variant->ref] = true;
        }

        foreach ($version->variants() as $ref => $existingVariant) {
            if (!isset($incomingRefs[$ref])) {
                $version->removeVariant($existingVariant);
            }
        }

        foreach ($variants as $payload) {
            $ref = $payload->ref;
            $existing = $version->variants()->get($ref);

            if (null === $existing) {
                $version->addVariant(new Variant(
                    $version,
                    $ref,
                    self::requiredDecimalString($payload->acceleration0To100S),
                    self::decimalString($payload->fuelConsumptionL100Km),
                    $payload->fuelTankL,
                    self::entityGearbox($payload->gearbox),
                    $payload->gearboxUnmappedLabel,
                    self::entityDrive($payload->drive),
                    $payload->driveUnmappedLabel,
                    $run,
                ));
                continue;
            }

            $existing->update(
                self::requiredDecimalString($payload->acceleration0To100S),
                self::decimalString($payload->fuelConsumptionL100Km),
                $payload->fuelTankL,
                self::entityGearbox($payload->gearbox),
                $payload->gearboxUnmappedLabel,
                self::entityDrive($payload->drive),
                $payload->driveUnmappedLabel,
            );
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private function reference(string $className, int $id): object
    {
        $reference = $this->entityManager->getReference($className, $id);

        if (!$reference instanceof $className) {
            throw new UnexpectedValueException(sprintf('Expected a reference for %s#%d to exist.', $className, $id));
        }

        return $reference;
    }

    private static function entityFuelType(SourceFuelType $fuelType): EntityFuelType
    {
        return EntityFuelType::from($fuelType->value);
    }

    private static function entityGearbox(?SourceGearbox $gearbox): ?EntityGearbox
    {
        return null !== $gearbox ? EntityGearbox::from($gearbox->value) : null;
    }

    private static function entityDrive(?SourceDrive $drive): ?EntityDrive
    {
        return null !== $drive ? EntityDrive::from($drive->value) : null;
    }

    private static function decimalString(?float $value): ?string
    {
        return null !== $value ? number_format($value, 1, '.', '') : null;
    }

    private static function requiredDecimalString(float $value): string
    {
        return number_format($value, 1, '.', '');
    }

    private static function requireId(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a numeric identifier.');
        }

        return (int) $value;
    }
}
