<?php

declare(strict_types=1);

namespace App\Source\Contract;

enum FetchMode
{
    case Incremental;
    case Full;
}
