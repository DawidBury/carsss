<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class VariantListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $brandId = null,
        public ?string $modelId = null,
        public ?string $versionId = null,
        public ?FuelType $fuelType = null,
        public ?Gearbox $gearbox = null,
        public ?Drive $drive = null,
        public ?string $accelerationMin = null,
        public ?string $accelerationMax = null,
        public VariantSort $sort = VariantSort::AccelerationAscending,
        public ?Cursor $cursor = null,
    ) {
    }
}
