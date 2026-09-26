<?php

declare(strict_types=1);

namespace App\Import\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'import')]
final readonly class ImportRecord
{
    public function __construct(
        public int $runId,
        public string $ref,
    ) {
    }
}
