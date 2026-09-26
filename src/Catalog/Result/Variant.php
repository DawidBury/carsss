<?php

declare(strict_types=1);

namespace App\Catalog\Result;

final readonly class Variant
{
    public function __construct(
        public string $id,
        public string $acceleration0To100S,
        public ?string $fuelConsumptionL100Km,
        public ?int $fuelTankL,
        public ?string $gearbox,
        public ?string $drive,
        public Version $version,
    ) {
    }
}
