<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
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
    public function recordsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => str_contains($record['message'], $needle),
        ));
    }
}
