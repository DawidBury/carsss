<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Api\Error\InvalidParameterException;
use App\Catalog\Criteria\Cursor;
use Symfony\Component\Uid\Uuid;

final readonly class CursorParameter
{
    public function __construct(private CursorCodec $codec)
    {
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $expectedContext
     */
    public function parse(array $query, array $expectedContext, CursorValueType $valueType): ?Cursor
    {
        $raw = QueryValidation::string($query, 'cursor');

        if ($raw === null) {
            return null;
        }

        try {
            $decoded = $this->codec->decode($raw);
        } catch (InvalidCursorException) {
            throw self::invalidCursor();
        }

        if ($valueType === CursorValueType::PositiveDecimal && !QueryValidation::isPositiveDecimal($decoded->afterValue)) {
            throw self::invalidCursor();
        }

        if (!Uuid::isValid($decoded->afterPublicId)) {
            throw self::invalidCursor();
        }

        if ($decoded->context !== $expectedContext) {
            throw new InvalidParameterException(
                "Parameter 'cursor' does not match the filters and sort of this request.",
                [['parameter' => 'cursor', 'reason' => 'cursor_mismatch']],
            );
        }

        return new Cursor($decoded->afterValue, $decoded->afterPublicId);
    }

    private static function invalidCursor(): InvalidParameterException
    {
        return new InvalidParameterException(
            "Parameter 'cursor' is not a valid cursor.",
            [['parameter' => 'cursor', 'reason' => 'invalid_cursor']],
        );
    }
}
