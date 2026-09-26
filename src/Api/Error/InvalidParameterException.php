<?php

declare(strict_types=1);

namespace App\Api\Error;

use RuntimeException;

final class InvalidParameterException extends RuntimeException
{
    /**
     * @param list<array{parameter: string, reason: string}> $violations
     */
    public function __construct(
        public readonly string $detail,
        public readonly array $violations = [],
    ) {
        parent::__construct($detail);
    }
}
