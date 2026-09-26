<?php

declare(strict_types=1);

namespace App\Tests\Api;

use UnexpectedValueException;

trait DatabaseValues
{
    private static function asInt(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a numeric value.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a string value.');
        }

        return $value;
    }
}
