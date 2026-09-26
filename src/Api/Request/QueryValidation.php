<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Api\Error\InvalidParameterException;
use Symfony\Component\Uid\Uuid;

final class QueryValidation
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $allowed
     */
    public static function rejectUnknownParameters(array $query, array $allowed): void
    {
        $unknown = array_diff(array_keys($query), $allowed);

        if ($unknown !== []) {
            $parameter = array_values($unknown)[0];

            throw new InvalidParameterException(
                sprintf("Unknown query parameter '%s'.", $parameter),
                [['parameter' => $parameter, 'reason' => 'unknown_parameter']],
            );
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function limit(array $query): int
    {
        return self::limitBetween($query, 50, 200);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function limitBetween(array $query, int $default, int $max): int
    {
        if (!isset($query['limit'])) {
            return $default;
        }

        $raw = $query['limit'];
        $message = sprintf("Parameter 'limit' must be an integer between 1 and %d.", $max);

        if (!\is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
            throw new InvalidParameterException(
                $message,
                [['parameter' => 'limit', 'reason' => 'invalid_number']],
            );
        }

        $value = (int) $raw;

        if ($value < 1 || $value > $max) {
            throw new InvalidParameterException(
                $message,
                [['parameter' => 'limit', 'reason' => 'out_of_range']],
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function uuid(array $query, string $name): ?string
    {
        if (!isset($query[$name])) {
            return null;
        }

        $raw = $query[$name];

        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' must be a valid UUID.", $name),
                [['parameter' => $name, 'reason' => 'invalid_uuid']],
            );
        }

        return strtolower($raw);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function positiveDecimal(array $query, string $name): ?string
    {
        if (!isset($query[$name])) {
            return null;
        }

        $raw = $query[$name];

        if (!\is_string($raw) || !self::isPositiveDecimal($raw)) {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' must be a positive number.", $name),
                [['parameter' => $name, 'reason' => 'invalid_number']],
            );
        }

        return $raw;
    }

    public static function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && (float) $value > 0.0;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function requiredPhrase(array $query, string $name, int $maxLength): string
    {
        if (!isset($query[$name])) {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' is required.", $name),
                [['parameter' => $name, 'reason' => 'missing_parameter']],
            );
        }

        $phrase = trim(self::string($query, $name) ?? '');

        if ($phrase === '') {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' must not be blank.", $name),
                [['parameter' => $name, 'reason' => 'invalid_value']],
            );
        }

        if (mb_strlen($phrase) > $maxLength) {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' must be at most %d characters long.", $name, $maxLength),
                [['parameter' => $name, 'reason' => 'out_of_range']],
            );
        }

        return $phrase;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function string(array $query, string $name): ?string
    {
        if (!isset($query[$name])) {
            return null;
        }

        $raw = $query[$name];

        if (!\is_string($raw)) {
            throw new InvalidParameterException(
                sprintf("Parameter '%s' must be a string.", $name),
                [['parameter' => $name, 'reason' => 'invalid_value']],
            );
        }

        return $raw;
    }
}
