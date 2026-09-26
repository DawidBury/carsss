<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Parser;

use App\Source\Autocentrum\Parser\BreadcrumbNames;
use App\Source\Autocentrum\Parser\TaxonomyReader;
use App\Source\Autocentrum\Url\EnginePath;
use App\Tests\Source\Autocentrum\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

final class TaxonomyReaderTest extends TestCase
{
    public function testWellFormedBreadcrumbNeedsNoFallbackAndLogsNothing(): void
    {
        $logger = new RecordingLogger();
        $reader = new TaxonomyReader($logger);

        $html = $this->breadcrumbHtml([
            ['position' => 1, 'name' => 'AutoCentrum', 'item' => 'https://www.autocentrum.pl/'],
            ['position' => 2, 'name' => 'Dane techniczne ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/'],
            ['position' => 3, 'name' => 'Dane techniczne Foo ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/foo/'],
            ['position' => 4, 'name' => 'Dane techniczne Foo Bar ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/foo/bar/'],
            ['position' => 5, 'name' => 'Dane techniczne Foo Bar 1.0 100KM ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/foo/bar/silnik-benzynowy-1.0-100km/'],
        ]);

        $path = EnginePath::fromUrl('https://www.autocentrum.pl/dane-techniczne/foo/bar/silnik-benzynowy-1.0-100km/');
        $breadcrumb = BreadcrumbNames::fromCrawler(new Crawler($html));

        $taxonomy = $reader->read($path, $breadcrumb, 'https://www.autocentrum.pl/dane-techniczne/foo/bar/silnik-benzynowy-1.0-100km/');

        self::assertSame('Foo', $taxonomy->brandName);
        self::assertSame('Bar', $taxonomy->modelName);
        self::assertSame('1.0 100KM', $taxonomy->versionName);
        self::assertSame([], $logger->records);
    }

    public function testBreadcrumbNameNotExtendingTheShallowerPrefixFallsBackToTheFullNameAndLogsAWarning(): void
    {
        $logger = new RecordingLogger();
        $reader = new TaxonomyReader($logger);

        $html = $this->breadcrumbHtml([
            ['position' => 1, 'name' => 'AutoCentrum', 'item' => 'https://www.autocentrum.pl/'],
            ['position' => 2, 'name' => 'Dane techniczne ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/'],
            ['position' => 3, 'name' => 'Dane techniczne Foo ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/foo/'],
            ['position' => 4, 'name' => 'Totally unrelated text ', 'item' => 'https://www.autocentrum.pl/dane-techniczne/foo/bar/'],
        ]);

        $path = EnginePath::fromUrl('https://www.autocentrum.pl/dane-techniczne/foo/bar/silnik-benzynowy-1.0-100km/');
        $breadcrumb = BreadcrumbNames::fromCrawler(new Crawler($html));

        $ref = 'https://www.autocentrum.pl/dane-techniczne/foo/bar/silnik-benzynowy-1.0-100km/';
        $taxonomy = $reader->read($path, $breadcrumb, $ref);

        self::assertSame('Totally unrelated text', $taxonomy->modelName);

        $warnings = $logger->recordsContaining('does not extend the shallower prefix');
        self::assertNotEmpty($warnings);

        $warning = $warnings[0];
        self::assertSame($ref, $warning['context']['ref']);
        self::assertSame(1, $warning['context']['segmentIndex']);
        self::assertSame('Totally unrelated text ', $warning['context']['name']);
        self::assertSame('Dane techniczne Foo ', $warning['context']['prefix']);
    }

    /**
     * @param list<array{position: int, name: string, item: string}> $items
     */
    private function breadcrumbHtml(array $items): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (array $item): array => ['@type' => 'ListItem', ...$item],
                $items,
            ),
        ]);

        return '<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>';
    }
}
