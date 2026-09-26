<?php

declare(strict_types=1);

namespace App\Tests\Api;

trait DecodesJsonResponses
{
    /**
     * @return array<array-key, mixed>
     */
    private static function jsonArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeJson(string|false $content): array
    {
        if ($content === false) {
            self::fail('Response has no content.');
        }

        return self::jsonArray(json_decode($content, true, flags: \JSON_THROW_ON_ERROR));
    }

    private static function jsonString(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
