<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class BrandListCriteria
{
    public function __construct(
        public int $limit,
        public ?Cursor $cursor = null,
    ) {
    }
}
