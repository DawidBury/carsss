<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Autocentrum\DriveResolver;
use App\Source\Autocentrum\GearboxResolver;
use App\Source\Autocentrum\RawValue;
use App\Source\Contract\SourceVariant;
use Symfony\Component\DomCrawler\Crawler;

final readonly class ConfigBoxParser
{
    private const string ACCELERATION_LABEL = 'Przyspieszenie (od 0 do 100km/h)';
    private const string FUEL_CONSUMPTION_LABEL = 'Średnie spalanie (cykl mieszany)';
    private const string FUEL_TANK_LABEL = 'Pojemność zbiornika paliwa';
    private const string GEARBOX_LABEL = 'Rodzaj skrzyni';
    private const string DRIVE_LABEL = 'Rodzaj napędu';

    public function __construct(
        private DtRowReader $dtRowReader,
        private RawValue $rawValue,
        private GearboxResolver $gearboxResolver,
        private DriveResolver $driveResolver,
    ) {
    }

    public function parse(Crawler $configBox, string $ref): ?SourceVariant
    {
        $rows = $this->dtRowReader->withinConfigBox($configBox);

        $accelerationRaw = $rows[self::ACCELERATION_LABEL] ?? null;
        $acceleration = null !== $accelerationRaw ? $this->rawValue->decimal($accelerationRaw) : null;
        if (null === $acceleration) {
            return null;
        }

        $fuelConsumptionRaw = $rows[self::FUEL_CONSUMPTION_LABEL] ?? null;
        $fuelConsumption = null !== $fuelConsumptionRaw
            ? $this->rawValue->fuelConsumptionLPer100Km($fuelConsumptionRaw)
            : null;

        $fuelTankRaw = $rows[self::FUEL_TANK_LABEL] ?? null;
        $fuelTank = null !== $fuelTankRaw ? $this->rawValue->integer($fuelTankRaw) : null;

        $gearboxLabel = $rows[self::GEARBOX_LABEL] ?? null;
        $gearbox = null !== $gearboxLabel ? $this->gearboxResolver->resolve($gearboxLabel) : null;

        $driveLabel = $rows[self::DRIVE_LABEL] ?? null;
        $drive = null !== $driveLabel ? $this->driveResolver->resolve($driveLabel) : null;

        return new SourceVariant(
            ref: $ref,
            acceleration0To100S: $acceleration,
            fuelConsumptionL100Km: $fuelConsumption,
            fuelTankL: $fuelTank,
            gearbox: $gearbox,
            gearboxUnmappedLabel: null === $gearbox ? $gearboxLabel : null,
            drive: $drive,
            driveUnmappedLabel: null === $drive ? $driveLabel : null,
        );
    }
}
