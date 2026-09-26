<?php

declare(strict_types=1);

namespace App\Import\Run;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use UnexpectedValueException;

final readonly class ImportRunStatusReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function latest(): ?ImportRunSnapshot
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, source, status, started_at, finished_at
                FROM import_run
                ORDER BY (status = 'running') DESC, started_at DESC
                LIMIT 1
                SQL,
        );

        if (false === $row) {
            return null;
        }

        $runId = self::requireInt($row['id']);

        return new ImportRunSnapshot(
            id: $runId,
            source: self::requireString($row['source']),
            status: self::requireString($row['status']),
            startedAt: new DateTimeImmutable(self::requireString($row['started_at'])),
            finishedAt: null !== $row['finished_at'] ? new DateTimeImmutable(self::requireString($row['finished_at'])) : null,
            brandTotal: $this->total('brand'),
            brandNewThisRun: $this->newThisRun('brand', $runId),
            modelTotal: $this->total('car_model'),
            modelNewThisRun: $this->newThisRun('car_model', $runId),
            versionTotal: $this->total('version'),
            versionNewThisRun: $this->newThisRun('version', $runId),
            versionTouchedThisRun: $this->versionTouchedThisRun($runId),
            variantTotal: $this->total('variant'),
            variantNewThisRun: $this->newThisRun('variant', $runId),
        );
    }

    private function total(string $table): int
    {
        return self::requireInt($this->connection->fetchOne('SELECT count(*) FROM ' . $table));
    }

    private function newThisRun(string $table, int $runId): int
    {
        return self::requireInt($this->connection->fetchOne(
            'SELECT count(*) FROM ' . $table . ' WHERE first_seen_run_id = :runId',
            ['runId' => $runId],
        ));
    }

    private function versionTouchedThisRun(int $runId): int
    {
        return self::requireInt($this->connection->fetchOne(
            'SELECT count(*) FROM version WHERE last_seen_run_id = :runId',
            ['runId' => $runId],
        ));
    }

    private static function requireInt(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a numeric value.');
        }

        return (int) $value;
    }

    private static function requireString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a string value.');
        }

        return $value;
    }
}
