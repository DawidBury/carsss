<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class SearchCriteria
{
    public function __construct(
        public string $phrase,
        public int $limit,
    ) {
    }
}
