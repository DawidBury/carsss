<?php

declare(strict_types=1);

namespace App\Source\Autocentrum;

final class RawValue
{
    public function normalizeText(string $raw): ?string
    {
        $normalized = trim(str_replace("\u{00A0}", ' ', $raw));

        return $normalized === '' ? null : $normalized;
    }

    public function decimal(string $normalized): ?float
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $normalized, $matches) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $matches[0]);

        return $value > 0 ? $value : null;
    }

    public function integer(string $normalized): ?int
    {
        $decimal = $this->decimal($normalized);

        return $decimal === null ? null : (int) round($decimal);
    }

    public function fuelConsumptionLPer100Km(string $normalized): ?float
    {
        if (!str_contains(strtolower($normalized), 'l/100km')) {
            return null;
        }

        return $this->decimal($normalized);
    }
}
