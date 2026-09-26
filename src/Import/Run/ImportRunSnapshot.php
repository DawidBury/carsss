<?php

declare(strict_types=1);

namespace App\Import\Run;

use DateTimeImmutable;

final readonly class ImportRunSnapshot
{
    public function __construct(
        public int $id,
        public string $source,
        public string $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public int $brandTotal,
        public int $brandNewThisRun,
        public int $modelTotal,
        public int $modelNewThisRun,
        public int $versionTotal,
        public int $versionNewThisRun,
        public int $versionTouchedThisRun,
        public int $variantTotal,
        public int $variantNewThisRun,
    ) {
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }
}
