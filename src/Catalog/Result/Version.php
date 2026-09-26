<?php

declare(strict_types=1);

namespace App\Catalog\Result;

final readonly class Version
{
    public function __construct(
        public string $id,
        public string $name,
        public string $fuelType,
        public ?string $generation,
        public ?string $bodyStyle,
        public ?string $unresolvedSegment,
        public ?string $powerHp,
        public ?int $engineDisplacementCcm,
        public ?int $bootCapacityL,
        public Model $model,
    ) {
    }
}
