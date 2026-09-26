<?php

declare(strict_types=1);

namespace App\Entity;

enum Drive: string
{
    case Front = 'front';
    case Rear = 'rear';
    case All = 'all';
}
