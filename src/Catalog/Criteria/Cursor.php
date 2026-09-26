<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class Cursor
{
    public function __construct(
        public string $afterValue,
        public string $afterPublicId,
    ) {
    }
}
