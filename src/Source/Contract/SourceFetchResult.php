<?php

declare(strict_types=1);

namespace App\Source\Contract;

final readonly class SourceFetchResult
{
    private function __construct(
        public SourceRecordState $state,
        public ?SourceRecord $record,
        public ?string $ref,
    ) {
    }

    public static function record(SourceRecord $record): self
    {
        return new self(SourceRecordState::Record, $record, $record->ref);
    }

    public static function unchanged(string $ref): self
    {
        return new self(SourceRecordState::Unchanged, null, $ref);
    }

    public static function absent(): self
    {
        return new self(SourceRecordState::Absent, null, null);
    }
}
