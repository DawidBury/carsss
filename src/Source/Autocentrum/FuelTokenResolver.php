<?php

declare(strict_types=1);

namespace App\Source\Autocentrum;

use App\Source\Contract\FuelType;

final class FuelTokenResolver
{
    private const string ENGINE_PREFIX = 'silnik-';

    public function fromEngineSlug(string $engineSlug): ?FuelType
    {
        if (!str_starts_with($engineSlug, self::ENGINE_PREFIX)) {
            return null;
        }

        $withoutPrefix = substr($engineSlug, strlen(self::ENGINE_PREFIX));
        $token = explode('-', $withoutPrefix, 2)[0];

        return match ($token) {
            'benzynowy' => FuelType::Petrol,
            'diesla' => FuelType::Diesel,
            'hybrydowy' => FuelType::Hybrid,
            'elektryczny' => FuelType::Electric,
            default => null,
        };
    }
}
