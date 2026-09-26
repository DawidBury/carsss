<?php

declare(strict_types=1);

namespace App\Source\Contract;

final readonly class SourceTaxonomy
{
    public function __construct(
        public string $brandRef,
        public string $brandName,
        public string $modelRef,
        public string $modelName,
        public string $versionName,
        public ?string $generation,
        public ?string $bodyStyle,
        public ?string $unresolvedSegment,
    ) {
    }
}
