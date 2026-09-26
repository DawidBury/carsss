<?php

declare(strict_types=1);

namespace App\Source\Contract;

final readonly class SourceVariant
{
    public function __construct(
        public string $ref,
        public float $acceleration0To100S,
        public ?float $fuelConsumptionL100Km,
        public ?int $fuelTankL,
        public ?Gearbox $gearbox,
        public ?string $gearboxUnmappedLabel,
        public ?Drive $drive,
        public ?string $driveUnmappedLabel,
    ) {
    }
}
