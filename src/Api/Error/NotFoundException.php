<?php

declare(strict_types=1);

namespace App\Api\Error;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    public function __construct(public readonly string $detail)
    {
        parent::__construct($detail);
    }
}
