<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Url;

final readonly class EnginePath
{
    private const string TAXONOMY_ROOT_SEGMENT = 'dane-techniczne';

    /**
     * @param list<string> $segments
     */
    private function __construct(
        public array $segments,
        private bool $withinTaxonomyRoot,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $allSegments = '' === $path ? [] : explode('/', $path);

        $rootIndex = array_search(self::TAXONOMY_ROOT_SEGMENT, $allSegments, true);
        if (false === $rootIndex) {
            return new self([], false);
        }

        return new self(array_slice($allSegments, $rootIndex + 1), true);
    }

    public function isWithinTaxonomyRoot(): bool
    {
        return $this->withinTaxonomyRoot;
    }

    public function depth(): int
    {
        return count($this->segments);
    }

    public function engineSlug(): string
    {
        return $this->segments[$this->depth() - 1];
    }

    public function brandSlug(): string
    {
        return $this->segments[0];
    }

    public function modelSlug(): string
    {
        return $this->segments[0] . '/' . $this->segments[1];
    }
}
