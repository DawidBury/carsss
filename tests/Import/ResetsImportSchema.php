<?php

declare(strict_types=1);

namespace App\Tests\Import;

use Doctrine\DBAL\Connection;
use UnexpectedValueException;

trait ResetsImportSchema
{
    private function resetImportSchema(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM version');
        $connection->executeStatement('DELETE FROM car_model');
        $connection->executeStatement('DELETE FROM brand');
        $connection->executeStatement('DELETE FROM import_run');
    }

    private function createRunningImportRun(Connection $connection, string $source = 'fake'): int
    {
        return self::databaseInt($connection->fetchOne(
            "INSERT INTO import_run (source, status) VALUES (:source, 'running') RETURNING id",
            ['source' => $source],
        ));
    }

    private static function databaseInt(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new UnexpectedValueException('Expected the database to return a numeric identifier.');
        }

        return (int) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function databaseRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException('Expected the database to return a row.');
        }

        return $row;
    }
}
