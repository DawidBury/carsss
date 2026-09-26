<?php

declare(strict_types=1);

namespace App\Api\Request;

final readonly class DecodedCursor
{
    /**
     * @param array<string, string> $context
     */
    public function __construct(
        public string $afterValue,
        public string $afterPublicId,
        public array $context,
    ) {
    }
}
