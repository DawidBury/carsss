<?php

declare(strict_types=1);

namespace App\Import\Console;

use App\Import\Run\ImportProgress;
use App\Import\Run\ImportRunSnapshot;
use App\Import\Run\ImportRunStatusReader;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[AsCommand(name: 'app:import:status', description: 'Shows the progress of the current or most recent import run (read-only)')]
final class ImportStatusCommand extends Command
{
    public function __construct(
        private readonly ImportRunStatusReader $statusReader,
        #[Autowire(service: 'messenger.transport.import')]
        private readonly TransportInterface $importTransport,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly TransportInterface $failedTransport,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $run = $this->statusReader->latest();
        if (null === $run) {
            $io->note('No import run has ever started.');

            return Command::SUCCESS;
        }

        $queueDepth = $this->messageCount($this->importTransport);
        $failedCount = $this->messageCount($this->failedTransport);
        $progress = ImportProgress::calculate($run, $queueDepth, $failedCount, new DateTimeImmutable());

        $this->renderRun($io, $run);
        $this->renderProgress($io, $run, $progress);
        $this->renderFailed($io, $progress);
        $this->renderTotals($io, $run);

        return Command::SUCCESS;
    }

    private function renderRun(SymfonyStyle $io, ImportRunSnapshot $run): void
    {
        $io->section(sprintf('Import run #%d (%s) — %s', $run->id, $run->source, $run->status));

        $duration = $this->formatDuration($run->startedAt, $run->finishedAt ?? new DateTimeImmutable());
        $io->text(sprintf('Started:  %s', $run->startedAt->format('Y-m-d H:i:s T')));

        if (null !== $run->finishedAt) {
            $io->text(sprintf('Finished: %s (ran for %s)', $run->finishedAt->format('Y-m-d H:i:s T'), $duration));
        } else {
            $io->text(sprintf('Finished: — (running for %s)', $duration));
        }
    }

    private function renderProgress(SymfonyStyle $io, ImportRunSnapshot $run, ImportProgress $progress): void
    {
        $io->section('Progress');

        $io->text(sprintf('Versions touched this run (written or marked seen): %d', $run->versionTouchedThisRun));

        if (null === $progress->queueDepth) {
            $io->text('Queue depth: unavailable — the "import" transport does not report a message count.');
        } else {
            $io->text(sprintf('Queue depth ("import" transport): %d message(s) remaining', $progress->queueDepth));
        }

        if (null !== $progress->estimatedTotal && null !== $progress->percentComplete) {
            $io->text(sprintf(
                'Estimated total (touched + queued): %d — estimated progress: %.1f%%',
                $progress->estimatedTotal,
                $progress->percentComplete,
            ));
        } else {
            $io->text('Estimated total and progress percentage: unavailable without a queue depth.');
        }

        if (null !== $progress->estimatedSecondsRemaining) {
            $io->text(sprintf(
                'Estimated time remaining: ~%s',
                $this->formatSeconds($progress->estimatedSecondsRemaining),
            ));
        } elseif ($run->isRunning()) {
            $io->text('Estimated time remaining: not enough data yet.');
        }

        $io->text([
            '',
            'Note: "touched" counts pages that resulted in a database write or a markSeen() update.',
            'Pages skipped as absent / no_usable_variants / unknown_fuel_token leave the queue without',
            'touching a version row, so the estimated total and progress run slightly behind reality',
            'until the queue is empty.',
        ]);
    }

    private function renderFailed(SymfonyStyle $io, ImportProgress $progress): void
    {
        $io->section('Failed transport');

        if (null === $progress->failedCount) {
            $io->text('Failed message count: unavailable — the "failed" transport does not report a message count.');
        } else {
            $io->text(sprintf('%d message(s) in the failed transport.', $progress->failedCount));
        }
    }

    private function renderTotals(SymfonyStyle $io, ImportRunSnapshot $run): void
    {
        $io->section('Database totals');

        $io->table(
            ['Table', 'Total', 'New this run'],
            [
                ['brand', (string) $run->brandTotal, (string) $run->brandNewThisRun],
                ['car_model', (string) $run->modelTotal, (string) $run->modelNewThisRun],
                ['version', (string) $run->versionTotal, (string) $run->versionNewThisRun],
                ['variant', (string) $run->variantTotal, (string) $run->variantNewThisRun],
            ],
        );
    }

    private function messageCount(TransportInterface $transport): ?int
    {
        if (!$transport instanceof MessageCountAwareInterface) {
            return null;
        }

        return $transport->getMessageCount();
    }

    private function formatDuration(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return $this->formatSeconds(max(0, $end->getTimestamp() - $start->getTimestamp()));
    }

    private function formatSeconds(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $remainingSeconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }
}
