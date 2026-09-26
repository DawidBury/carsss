<?php

declare(strict_types=1);

namespace App\Import\Run;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use UnexpectedValueException;

final readonly class ImportRunLifecycle
{
    public function __construct(private Connection $connection)
    {
    }

    public function start(string $source): int
    {
        try {
            $id = $this->connection->fetchOne(
                'INSERT INTO import_run (source, status) VALUES (:source, :status) RETURNING id',
                ['source' => $source, 'status' => 'running'],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw ImportAlreadyRunningException::forSource($source, $exception);
        }

        if (!is_int($id) && !is_string($id)) {
            throw new UnexpectedValueException('Expected the database to return a numeric identifier.');
        }

        return (int) $id;
    }

    public function complete(int $runId): void
    {
        $this->finish($runId, 'completed');
    }

    public function fail(int $runId): void
    {
        $this->finish($runId, 'failed');
    }

    private function finish(int $runId, string $status): void
    {
        $affected = $this->connection->executeStatement(
            "UPDATE import_run SET status = :status, finished_at = now() WHERE id = :id AND status = 'running'",
            ['status' => $status, 'id' => $runId],
        );

        if (0 === $affected) {
            throw ImportRunNotRunningException::forRun($runId);
        }
    }
}
