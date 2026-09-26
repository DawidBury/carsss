<?php

declare(strict_types=1);

namespace App\Source\Contract;

enum Gearbox: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
