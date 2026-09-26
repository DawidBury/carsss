<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Autocentrum\RawValue;
use Symfony\Component\DomCrawler\Crawler;

final readonly class DtRowReader
{
    public function __construct(private RawValue $rawValue)
    {
    }

    /**
     * @return array<string, string>
     */
    public function outsideConfigBoxes(Crawler $document): array
    {
        $rows = $document->filter('div.dt-row')->reduce(
            static fn (Crawler $row): bool => null === $row->closest('div.config-box[data-context="config"]'),
        );

        return $this->map($rows);
    }

    /**
     * @return array<string, string>
     */
    public function withinConfigBox(Crawler $configBox): array
    {
        return $this->map($configBox->filter('div.dt-row'));
    }

    /**
     * @return array<string, string>
     */
    private function map(Crawler $rows): array
    {
        $map = [];

        $rows->each(function (Crawler $row) use (&$map): void {
            $labelNodes = $row->filter('div.dt-row__text');
            if (0 === $labelNodes->count()) {
                return;
            }

            $label = $labelNodes->attr('data-label');
            if (null === $label) {
                return;
            }

            $valueNodes = $row->filter('span.dt-param-value');
            $raw = $valueNodes->count() > 0 ? $valueNodes->text() : '';
            $normalized = $this->rawValue->normalizeText($raw);

            if (null !== $normalized) {
                $map[$label] = $normalized;
            }
        });

        return $map;
    }
}
