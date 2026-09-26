<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Sitemap;

use App\Source\Autocentrum\FuelTokenResolver;
use App\Source\Autocentrum\Sitemap\SitemapReader;
use App\Source\Autocentrum\Url\EnginePath;
use App\Source\Contract\FuelType;
use PHPUnit\Framework\TestCase;

final class SitemapReaderTest extends TestCase
{
    private const string REAL_SITEMAP_PATH = __DIR__ . '/../../../../cars_details.xml';

    public function testLeafUrlsMatchTheMeasuredCountFromTheSourceContract(): void
    {
        $reader = new SitemapReader(self::REAL_SITEMAP_PATH);

        $leaves = iterator_to_array($reader->leafUrls(), false);

        self::assertCount(28892, $leaves);
        self::assertCount(28892, array_unique($leaves), 'sitemap leaves must be distinct URLs');

        foreach ($leaves as $leaf) {
            self::assertStringStartsWith('https://www.autocentrum.pl/dane-techniczne/', $leaf);
        }
    }

    public function testFuelTokenDistributionMatchesTheMeasuredCountFromTheSourceContract(): void
    {
        $reader = new SitemapReader(self::REAL_SITEMAP_PATH);
        $resolver = new FuelTokenResolver();

        $counts = [
            FuelType::Petrol->value => 0,
            FuelType::Diesel->value => 0,
            FuelType::Hybrid->value => 0,
            FuelType::Electric->value => 0,
        ];
        $unknown = 0;

        foreach ($reader->leafUrls() as $leaf) {
            $engineSlug = EnginePath::fromUrl($leaf)->engineSlug();
            $fuelType = $resolver->fromEngineSlug($engineSlug);

            if (null === $fuelType) {
                ++$unknown;
                continue;
            }

            ++$counts[$fuelType->value];
        }

        self::assertSame(18482, $counts[FuelType::Petrol->value]);
        self::assertSame(8837, $counts[FuelType::Diesel->value]);
        self::assertSame(915, $counts[FuelType::Hybrid->value]);
        self::assertSame(658, $counts[FuelType::Electric->value]);
        self::assertSame(0, $unknown);
    }
}
