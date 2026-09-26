<?php

declare(strict_types=1);

namespace App\Source\Contract;

enum SourceRecordState
{
    case Record;
    case Unchanged;
    case Absent;
}
