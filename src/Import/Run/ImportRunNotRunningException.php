<?php

declare(strict_types=1);

namespace App\Import\Run;

use RuntimeException;

final class ImportRunNotRunningException extends RuntimeException
{
    public static function forRun(int $runId): self
    {
        return new self(sprintf('Import run %d is not currently running.', $runId));
    }
}
