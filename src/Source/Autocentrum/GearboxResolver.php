<?php

declare(strict_types=1);

namespace App\Source\Autocentrum;

use App\Source\Contract\Gearbox;

final class GearboxResolver
{
    public function resolve(string $label): ?Gearbox
    {
        return match ($label) {
            'manualna' => Gearbox::Manual,
            'automatyczna' => Gearbox::Automatic,
            default => null,
        };
    }
}
