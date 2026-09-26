<?php

declare(strict_types=1);

namespace App\Catalog\Result;

final readonly class SearchHit
{
    public function __construct(
        public string $id,
        public string $brandName,
        public string $modelName,
        public string $versionName,
        public string $fuelType,
        public ?string $generation,
        public ?string $bodyStyle,
        public ?string $unresolvedSegment,
        public ?string $gearbox,
        public ?string $drive,
    ) {
    }
}
