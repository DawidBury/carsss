<?php

declare(strict_types=1);

namespace App\Catalog\Result;

final readonly class Model
{
    public function __construct(
        public string $id,
        public string $name,
        public Brand $brand,
    ) {
    }
}
