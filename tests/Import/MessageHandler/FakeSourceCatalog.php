<?php

declare(strict_types=1);

namespace App\Tests\Import\MessageHandler;

use App\Source\Contract\FetchMode;
use App\Source\Contract\SourceFetchResult;
use App\Source\SourceCatalog;
use LogicException;

final class FakeSourceCatalog implements SourceCatalog
{
    /**
     * @var list<array{ref: string, mode: FetchMode}>
     */
    public array $calls = [];

    /**
     * @param list<SourceFetchResult> $results consumed in order, one per call to fetchRecord()
     * @param list<string>            $refs    returned by listRecords()
     */
    public function __construct(
        private array $results = [],
        private array $refs = [],
        private string $catalogName = 'fake',
    ) {
    }

    public function name(): string
    {
        return $this->catalogName;
    }

    public function listRecords(): iterable
    {
        return $this->refs;
    }

    public function fetchRecord(string $ref, FetchMode $mode): SourceFetchResult
    {
        $this->calls[] = ['ref' => $ref, 'mode' => $mode];

        $result = array_shift($this->results);
        if (null === $result) {
            throw new LogicException('FakeSourceCatalog has no more queued results.');
        }

        return $result;
    }
}
