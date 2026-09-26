<?php

declare(strict_types=1);

namespace App\Tests\Import;

use Psr\Log\AbstractLogger;
use Stringable;

final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public function recordsWithReason(string $reason): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => ($record['context']['reason'] ?? null) === $reason,
        ));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public function recordsWithMessage(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['message'] === $message,
        ));
    }
}
