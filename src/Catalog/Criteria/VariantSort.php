<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

enum VariantSort: string
{
    case AccelerationAscending = 'acceleration';
    case AccelerationDescending = '-acceleration';
}
