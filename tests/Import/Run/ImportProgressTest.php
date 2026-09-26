<?php

declare(strict_types=1);

namespace App\Tests\Import\Run;

use App\Import\Run\ImportProgress;
use App\Import\Run\ImportRunSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ImportProgressTest extends TestCase
{
    public function testUnknownQueueDepthLeavesTotalPercentAndEtaUnavailable(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 100, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, null, null, new DateTimeImmutable());

        self::assertNull($progress->queueDepth);
        self::assertNull($progress->estimatedTotal);
        self::assertNull($progress->percentComplete);
        self::assertNull($progress->estimatedSecondsRemaining);
    }

    public function testComputesEstimatedTotalAndPercentFromTouchedAndQueueDepth(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 25, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, 75, 0, new DateTimeImmutable());

        self::assertSame(100, $progress->estimatedTotal);
        self::assertEqualsWithDelta(25.0, $progress->percentComplete, 0.001);
    }

    public function testEstimatesSecondsRemainingFromObservedThroughput(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 100, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, 200, 0, new DateTimeImmutable());

        self::assertSame(200, $progress->estimatedSecondsRemaining);
    }

    public function testNoEtaWhenTheRunIsNotCurrentlyRunning(): void
    {
        $run = $this->snapshot(status: 'completed', versionTouchedThisRun: 100, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, 200, 0, new DateTimeImmutable());

        self::assertNull($progress->estimatedSecondsRemaining);
    }

    public function testNoEtaWhenNothingHasBeenTouchedYet(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 0, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, 200, 0, new DateTimeImmutable());

        self::assertNull($progress->estimatedSecondsRemaining);
        self::assertSame(0.0, $progress->percentComplete);
    }

    public function testNoEtaWhenTheQueueIsAlreadyEmpty(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 100, startedSecondsAgo: 100);

        $progress = ImportProgress::calculate($run, 0, 0, new DateTimeImmutable());

        self::assertNull($progress->estimatedSecondsRemaining);
        self::assertEqualsWithDelta(100.0, $progress->percentComplete, 0.001);
    }

    public function testUnknownFailedCountIsPreservedAsNull(): void
    {
        $run = $this->snapshot(status: 'running', versionTouchedThisRun: 10, startedSecondsAgo: 10);

        $progress = ImportProgress::calculate($run, 10, null, new DateTimeImmutable());

        self::assertNull($progress->failedCount);
    }

    private function snapshot(string $status, int $versionTouchedThisRun, int $startedSecondsAgo): ImportRunSnapshot
    {
        $startedAt = new DateTimeImmutable(sprintf('-%d seconds', $startedSecondsAgo));

        return new ImportRunSnapshot(
            id: 1,
            source: 'autocentrum',
            status: $status,
            startedAt: $startedAt,
            finishedAt: 'running' === $status ? null : new DateTimeImmutable(),
            brandTotal: 0,
            brandNewThisRun: 0,
            modelTotal: 0,
            modelNewThisRun: 0,
            versionTotal: 0,
            versionNewThisRun: 0,
            versionTouchedThisRun: $versionTouchedThisRun,
            variantTotal: 0,
            variantNewThisRun: 0,
        );
    }
}
