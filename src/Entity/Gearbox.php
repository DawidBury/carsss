<?php

declare(strict_types=1);

namespace App\Entity;

enum Gearbox: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
