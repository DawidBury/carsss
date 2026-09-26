<?php

declare(strict_types=1);

namespace App\Source;

use App\Source\Contract\FetchMode;
use App\Source\Contract\SourceFetchResult;

interface SourceCatalog
{
    public function name(): string;

    /**
     * @return iterable<string>
     */
    public function listRecords(): iterable;

    public function fetchRecord(string $ref, FetchMode $mode): SourceFetchResult;
}
