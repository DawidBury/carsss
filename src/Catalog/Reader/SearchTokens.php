<?php

declare(strict_types=1);

namespace App\Catalog\Reader;

final class SearchTokens
{
    private const int MAX_TOKENS = 10;
    private const int MIN_CORRECTABLE_LENGTH = 3;

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function fromPhrase(string $phrase): array
    {
        $pieces = preg_split('/[^\p{L}\p{N}.]+/u', mb_strtolower($phrase), -1, \PREG_SPLIT_NO_EMPTY);

        if ($pieces === false) {
            return [];
        }

        $tokens = [];

        foreach ($pieces as $piece) {
            $token = trim($piece, '.');

            if ($token === '') {
                continue;
            }

            $tokens[] = $token;

            if (\count($tokens) === self::MAX_TOKENS) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     */
    public static function toTsQuery(array $tokens): string
    {
        $lastIndex = \count($tokens) - 1;
        $terms = [];

        foreach ($tokens as $index => $token) {
            $terms[] = $index === $lastIndex ? $token . ':*' : $token;
        }

        return implode(' & ', $terms);
    }

    public static function isCorrectable(string $token): bool
    {
        return mb_strlen($token) >= self::MIN_CORRECTABLE_LENGTH;
    }
}
