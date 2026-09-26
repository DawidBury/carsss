<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class ModelListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $brandId = null,
        public ?Cursor $cursor = null,
    ) {
    }
}
