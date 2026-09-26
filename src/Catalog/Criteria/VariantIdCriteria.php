<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

final readonly class VariantIdCriteria
{
    public function __construct(public string $publicId)
    {
    }
}
