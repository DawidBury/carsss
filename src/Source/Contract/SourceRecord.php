<?php

declare(strict_types=1);

namespace App\Source\Contract;

final readonly class SourceRecord
{
    /**
     * @param list<SourceVariant> $variants
     */
    public function __construct(
        public string $ref,
        public SourceTaxonomy $taxonomy,
        public FuelType $fuelType,
        public ?float $powerHp,
        public ?int $engineDisplacementCcm,
        public ?int $bootCapacityL,
        public array $variants,
    ) {
    }
}
