<?php

declare(strict_types=1);

namespace App\Entity;

enum ImportRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
