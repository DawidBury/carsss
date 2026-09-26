<?php

declare(strict_types=1);

namespace App\Import\Run;

use RuntimeException;
use Throwable;

final class ImportAlreadyRunningException extends RuntimeException
{
    public static function forSource(string $source, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('An import run for source "%s" is already running.', $source),
            0,
            $previous,
        );
    }
}
