<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Http;

final readonly class PageCacheEntry
{
    public function __construct(
        public string $etag,
        public string $canonicalRef,
    ) {
    }
}
