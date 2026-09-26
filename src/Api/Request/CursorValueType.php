<?php

declare(strict_types=1);

namespace App\Api\Request;

enum CursorValueType
{
    case Text;
    case PositiveDecimal;
}
