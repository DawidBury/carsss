<?php

declare(strict_types=1);

namespace App\Catalog\Result;

/**
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {
    }
}
