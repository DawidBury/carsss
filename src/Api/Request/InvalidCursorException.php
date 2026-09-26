<?php

declare(strict_types=1);

namespace App\Api\Request;

use RuntimeException;

final class InvalidCursorException extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('malformed');
    }

    public static function mismatched(): self
    {
        return new self('mismatched');
    }
}
