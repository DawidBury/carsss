<?php

declare(strict_types=1);

namespace App\Catalog\Criteria;

enum Gearbox: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
