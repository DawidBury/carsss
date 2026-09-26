<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Autocentrum\Url\EnginePath;
use Symfony\Component\DomCrawler\Crawler;

final readonly class BreadcrumbNames
{
    /**
     * @param array<int, string> $namesByDepth keyed by the number of path segments
     *                                         after "dane-techniczne/" that the crumb's
     *                                         own link points at
     */
    private function __construct(private array $namesByDepth)
    {
    }

    public static function fromCrawler(Crawler $crawler): self
    {
        $namesByDepth = [];

        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode((string) $node->textContent, true);
            if (!is_array($decoded) || ($decoded['@type'] ?? null) !== 'BreadcrumbList') {
                continue;
            }

            $items = $decoded['itemListElement'] ?? null;
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['name'], $item['item'])) {
                    continue;
                }

                $name = $item['name'];
                $link = $item['item'];
                if (!is_string($name) || !is_string($link)) {
                    continue;
                }

                $itemPath = EnginePath::fromUrl($link);
                if (!$itemPath->isWithinTaxonomyRoot()) {
                    continue;
                }

                $namesByDepth[$itemPath->depth()] = $name;
            }

            break;
        }

        return new self($namesByDepth);
    }

    public function at(int $depth): ?string
    {
        return $this->namesByDepth[$depth] ?? null;
    }
}
