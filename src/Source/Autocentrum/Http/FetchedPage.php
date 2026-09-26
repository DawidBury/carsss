<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Http;

final readonly class FetchedPage
{
    private function __construct(
        public bool $unchanged,
        public ?string $html,
        public ?string $etag,
        public ?string $canonicalRef,
    ) {
    }

    public static function fetched(string $html, ?string $etag): self
    {
        return new self(false, $html, $etag, null);
    }

    public static function unchanged(string $canonicalRef): self
    {
        return new self(true, null, null, $canonicalRef);
    }
}
