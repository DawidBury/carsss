<?php

declare(strict_types=1);

namespace App\Source\Autocentrum;

use App\Source\Contract\Drive;

final class DriveResolver
{
    public function resolve(string $label): ?Drive
    {
        return match ($label) {
            'na przednią oś' => Drive::Front,
            'na tylną oś' => Drive::Rear,
            '4x4' => Drive::All,
            default => null,
        };
    }
}
