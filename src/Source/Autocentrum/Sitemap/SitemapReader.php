<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Sitemap;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use XMLReader;

final class SitemapReader
{
    private const string ENGINE_LEAF_PREFIX = 'silnik-';

    public function __construct(
        #[Autowire('%source.sitemap_path%')]
        private readonly string $sitemapPath,
    ) {
    }

    /**
     * @return iterable<string>
     */
    public function leafUrls(): iterable
    {
        $reader = new XMLReader();
        if (!$reader->open($this->sitemapPath)) {
            throw new RuntimeException(sprintf('Cannot open sitemap at "%s".', $this->sitemapPath));
        }

        try {
            while ($reader->read()) {
                if (XMLReader::ELEMENT !== $reader->nodeType || 'loc' !== $reader->localName) {
                    continue;
                }

                $url = trim($this->readElementText($reader));
                if ('' !== $url && $this->isEngineLeaf($url)) {
                    yield $url;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function readElementText(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        if (!$reader->read() || XMLReader::TEXT !== $reader->nodeType) {
            return '';
        }

        return $reader->value;
    }

    private function isEngineLeaf(string $url): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ('' === $path) {
            return false;
        }

        $segments = explode('/', $path);

        return str_starts_with(end($segments), self::ENGINE_LEAF_PREFIX);
    }
}
