<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Autocentrum\FuelTokenResolver;
use App\Source\Autocentrum\RawValue;
use App\Source\Autocentrum\Url\EnginePath;
use App\Source\Contract\SourceRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

final readonly class PageParser
{
    private const string CANONICAL_LINK_SELECTOR = 'link[rel="canonical"]';
    private const string POWER_LABEL = 'Moc silnika';
    private const string ENGINE_DISPLACEMENT_LABEL = 'Pojemność skokowa';
    private const string BOOT_CAPACITY_LABEL = 'Minimalna pojemność bagażnika (siedzenia rozłożone)';
    private const int MINIMUM_TAXONOMY_DEPTH = 3;

    public function __construct(
        private FuelTokenResolver $fuelTokenResolver,
        private TaxonomyReader $taxonomyReader,
        private DtRowReader $dtRowReader,
        private VariantsParser $variantsParser,
        private RawValue $rawValue,
        private LoggerInterface $logger,
    ) {
    }

    public function parse(string $html): ?SourceRecord
    {
        $crawler = new Crawler($html);

        $canonicalUrl = $this->canonicalUrl($crawler);
        if (null === $canonicalUrl) {
            $this->logger->warning('autocentrum: page has no canonical link', []);

            return null;
        }

        $enginePath = EnginePath::fromUrl($canonicalUrl);
        if (!$enginePath->isWithinTaxonomyRoot() || $enginePath->depth() < self::MINIMUM_TAXONOMY_DEPTH) {
            $this->logger->warning('autocentrum: canonical link falls outside the taxonomy root', [
                'ref' => $canonicalUrl,
            ]);

            return null;
        }

        $fuelType = $this->fuelTokenResolver->fromEngineSlug($enginePath->engineSlug());
        if (null === $fuelType) {
            $this->logger->warning('autocentrum: skipping record with unknown fuel token', [
                'reason' => 'unknown_fuel_token',
                'ref' => $canonicalUrl,
            ]);

            return null;
        }

        $variants = $this->variantsParser->parse($crawler);
        if (null === $variants) {
            $this->logger->warning('autocentrum: config-box and select option counts differ', [
                'ref' => $canonicalUrl,
            ]);

            return null;
        }

        $taxonomy = $this->taxonomyReader->read($enginePath, BreadcrumbNames::fromCrawler($crawler), $canonicalUrl);
        $versionRows = $this->dtRowReader->outsideConfigBoxes($crawler);

        return new SourceRecord(
            ref: $canonicalUrl,
            taxonomy: $taxonomy,
            fuelType: $fuelType,
            powerHp: $this->decimalRow($versionRows, self::POWER_LABEL),
            engineDisplacementCcm: $this->integerRow($versionRows, self::ENGINE_DISPLACEMENT_LABEL),
            bootCapacityL: $this->integerRow($versionRows, self::BOOT_CAPACITY_LABEL),
            variants: $variants,
        );
    }

    private function canonicalUrl(Crawler $crawler): ?string
    {
        $link = $crawler->filter(self::CANONICAL_LINK_SELECTOR);

        return $link->count() > 0 ? $link->attr('href') : null;
    }

    /**
     * @param array<string, string> $rows
     */
    private function decimalRow(array $rows, string $label): ?float
    {
        $raw = $rows[$label] ?? null;

        return null !== $raw ? $this->rawValue->decimal($raw) : null;
    }

    /**
     * @param array<string, string> $rows
     */
    private function integerRow(array $rows, string $label): ?int
    {
        $raw = $rows[$label] ?? null;

        return null !== $raw ? $this->rawValue->integer($raw) : null;
    }
}
