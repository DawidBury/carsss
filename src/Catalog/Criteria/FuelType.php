<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
}
