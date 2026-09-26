<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Contract\SourceVariant;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

final readonly class VariantsParser
{
    private const string CONFIG_BOX_SELECTOR = 'div.config-box[data-context="config"]';
    private const string OPTION_SELECTOR = 'select#config-select option';
    private const string RANKING_LINK_SELECTOR = 'a[href*="cid="]';

    public function __construct(private ConfigBoxParser $configBoxParser)
    {
    }

    /**
     * @return list<SourceVariant>|null null means the page could not be parsed reliably
     */
    public function parse(Crawler $document): ?array
    {
        $boxes = $document->filter(self::CONFIG_BOX_SELECTOR);
        if (0 === $boxes->count()) {
            return [];
        }

        $optionDataIds = $this->optionDataIds($document);
        if (null !== $optionDataIds && count($optionDataIds) !== $boxes->count()) {
            return null;
        }

        $variants = $boxes->each(
            fn (Crawler $box, int $position): ?SourceVariant => $this->configBoxParser->parse(
                $box,
                $this->resolveRef($box, $position, $optionDataIds),
            ),
        );

        return array_values(array_filter($variants));
    }

    /**
     * @return list<string|null>|null
     */
    private function optionDataIds(Crawler $document): ?array
    {
        $options = $document->filter(self::OPTION_SELECTOR);
        if (0 === $options->count()) {
            return null;
        }

        return $options->each(static fn (Crawler $option): ?string => $option->attr('data-id'));
    }

    /**
     * @param list<string|null>|null $optionDataIds
     */
    private function resolveRef(Crawler $box, int $position, ?array $optionDataIds): string
    {
        $cid = $this->extractCid($box);
        if (null !== $cid) {
            return $cid;
        }

        $optionDataId = $optionDataIds[$position] ?? null;

        return $optionDataId ?? (string) $position;
    }

    private function extractCid(Crawler $box): ?string
    {
        $links = $box->filter(self::RANKING_LINK_SELECTOR);

        foreach ($links as $node) {
            $href = $node instanceof DOMElement ? $node->getAttribute('href') : '';
            if (1 === preg_match('/[?&]cid=(\d+)/', $href, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
