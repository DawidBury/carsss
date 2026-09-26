<?php

declare(strict_types=1);

namespace App\Import\Run;

use DateTimeImmutable;

final readonly class ImportProgress
{
    private function __construct(
        public ?int $queueDepth,
        public ?int $failedCount,
        public ?int $estimatedTotal,
        public ?float $percentComplete,
        public ?int $estimatedSecondsRemaining,
    ) {
    }

    public static function calculate(
        ImportRunSnapshot $run,
        ?int $queueDepth,
        ?int $failedCount,
        DateTimeImmutable $now,
    ): self {
        $estimatedTotal = null;
        $percentComplete = null;

        if (null !== $queueDepth) {
            $estimatedTotal = $run->versionTouchedThisRun + $queueDepth;
            if ($estimatedTotal > 0) {
                $percentComplete = $run->versionTouchedThisRun / $estimatedTotal * 100;
            }
        }

        return new self(
            queueDepth: $queueDepth,
            failedCount: $failedCount,
            estimatedTotal: $estimatedTotal,
            percentComplete: $percentComplete,
            estimatedSecondsRemaining: self::estimateSecondsRemaining($run, $queueDepth, $now),
        );
    }

    private static function estimateSecondsRemaining(
        ImportRunSnapshot $run,
        ?int $queueDepth,
        DateTimeImmutable $now,
    ): ?int {
        if (!$run->isRunning() || null === $queueDepth || $queueDepth <= 0) {
            return null;
        }

        $elapsedSeconds = $now->getTimestamp() - $run->startedAt->getTimestamp();
        if ($elapsedSeconds <= 0 || $run->versionTouchedThisRun <= 0) {
            return null;
        }

        $rate = $run->versionTouchedThisRun / $elapsedSeconds;
        if ($rate <= 0.0) {
            return null;
        }

        return (int) round($queueDepth / $rate);
    }
}
