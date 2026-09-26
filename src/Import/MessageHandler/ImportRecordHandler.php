<?php

declare(strict_types=1);

namespace App\Import\MessageHandler;

use App\Import\Message\ImportRecord;
use App\Import\Writer\RecordWriter;
use App\Source\Contract\FetchMode;
use App\Source\Contract\SourceFetchResult;
use App\Source\Contract\SourceRecordState;
use App\Source\SourceCatalog;
use LogicException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
#[WithMonologChannel('import')]
final readonly class ImportRecordHandler
{
    public function __construct(
        private SourceCatalog $source,
        private RecordWriter $writer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportRecord $message): void
    {
        try {
            $result = $this->source->fetchRecord($message->ref, FetchMode::Incremental);
        } catch (Throwable $exception) {
            $this->logFailure($message->runId, $message->ref, $exception);

            throw $exception;
        }

        try {
            match ($result->state) {
                SourceRecordState::Record => $this->write($message->runId, $result),
                SourceRecordState::Unchanged => $this->handleUnchanged($message, $result),
                SourceRecordState::Absent => $this->logAbsent($message->runId, $message->ref),
            };
        } catch (Throwable $exception) {
            $this->logFailure($message->runId, $result->ref ?? $message->ref, $exception);

            throw $exception;
        }
    }

    private function logFailure(int $runId, string $ref, Throwable $exception): void
    {
        $this->logger->error('import.record_failed', [
            'runId' => $runId,
            'ref' => $ref,
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ]);
    }

    private function handleUnchanged(ImportRecord $message, SourceFetchResult $unchangedResult): void
    {
        $ref = $unchangedResult->ref;
        if (null === $ref) {
            throw new LogicException('An Unchanged-state fetch result must carry a reference.');
        }

        $affected = $this->writer->markSeen($message->runId, $ref);
        if ($affected > 0) {
            $this->logger->info('import.version_unchanged', [
                'runId' => $message->runId,
                'ref' => $ref,
            ]);

            return;
        }

        $this->logger->info('import.refetch_forced', [
            'runId' => $message->runId,
            'ref' => $ref,
        ]);

        $result = $this->source->fetchRecord($message->ref, FetchMode::Full);

        match ($result->state) {
            SourceRecordState::Record => $this->write($message->runId, $result),
            SourceRecordState::Absent => $this->logAbsent($message->runId, $message->ref),
            SourceRecordState::Unchanged => throw new LogicException(
                sprintf('Source catalog returned Unchanged for "%s" while fetching in Full mode.', $message->ref),
            ),
        };
    }

    private function write(int $runId, SourceFetchResult $result): void
    {
        $record = $result->record;
        if (null === $record) {
            throw new LogicException('A Record-state fetch result must carry a record.');
        }

        $this->writer->write($runId, $record);
    }

    private function logAbsent(int $runId, string $ref): void
    {
        $this->logger->info('import.record_skipped', [
            'runId' => $runId,
            'ref' => $ref,
            'reason' => 'absent',
        ]);
    }
}
