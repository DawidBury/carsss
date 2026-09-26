<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use App\Source\Autocentrum\AutocentrumCatalog;
use App\Source\Autocentrum\DriveResolver;
use App\Source\Autocentrum\FuelTokenResolver;
use App\Source\Autocentrum\GearboxResolver;
use App\Source\Autocentrum\Http\AutocentrumHttpClient;
use App\Source\Autocentrum\Http\PageCache;
use App\Source\Autocentrum\Parser\ConfigBoxParser;
use App\Source\Autocentrum\Parser\DtRowReader;
use App\Source\Autocentrum\Parser\PageParser;
use App\Source\Autocentrum\Parser\TaxonomyReader;
use App\Source\Autocentrum\Parser\VariantsParser;
use App\Source\Autocentrum\RawValue;
use App\Source\Autocentrum\Sitemap\SitemapReader;
use App\Source\Contract\FetchMode;
use App\Source\Contract\SourceRecordState;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AutocentrumCatalogTest extends KernelTestCase
{
    private const string FIXTURES_DIR = __DIR__ . '/../../Fixtures/Source/Autocentrum';

    private Connection $connection;
    private PageCache $pageCache;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->pageCache = new PageCache($this->connection);
        $this->connection->executeStatement('TRUNCATE autocentrum_page_cache');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('TRUNCATE autocentrum_page_cache');
        parent::tearDown();
    }

    public function testSuccessfulParseStoresTheEtagWithTheCanonicalReferenceInOneRow(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/abarth/punto-evo/silnik-benzynowy-1-4-multiair/';
        $html = $this->fixture('flat-path-depth-3.html');

        $mockClient = new MockHttpClient(new MockResponse($html, ['response_headers' => ['etag' => '"v1"']]));

        $result = $this->catalog($mockClient)->fetchRecord($url, FetchMode::Incremental);

        self::assertSame(SourceRecordState::Record, $result->state);
        self::assertNotNull($result->record);
        self::assertSame($result->record->ref, $result->ref);

        $entry = $this->pageCache->find($url);
        self::assertNotNull($entry);
        self::assertSame('"v1"', $entry->etag);
        self::assertSame($result->record->ref, $entry->canonicalRef);
    }

    public function testUnparsablePageDoesNotStoreAnEtagItHasNotEarned(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/audi/';
        $html = '<html><head><link rel="canonical" href="https://www.autocentrum.pl/dane-techniczne/audi/"></head><body></body></html>';

        $mockClient = new MockHttpClient(new MockResponse($html, ['response_headers' => ['etag' => '"unearned"']]));

        $result = $this->catalog($mockClient)->fetchRecord($url, FetchMode::Incremental);

        self::assertSame(SourceRecordState::Absent, $result->state);
        self::assertNull($this->pageCache->find($url));
    }

    public function testUnchangedResponseReturnsTheCachedCanonicalReferenceWithoutFetchingHtml(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limuzyna/';
        $canonicalRef = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limousine/';
        $this->pageCache->store($url, '"cached"', $canonicalRef);

        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 304]));

        $result = $this->catalog($mockClient)->fetchRecord($url, FetchMode::Incremental);

        self::assertSame(SourceRecordState::Unchanged, $result->state);
        self::assertSame($canonicalRef, $result->ref);
        self::assertNull($result->record);
    }

    public function testAbsentPageIsReportedWithoutTouchingTheCache(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/gone/';
        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $result = $this->catalog($mockClient)->fetchRecord($url, FetchMode::Incremental);

        self::assertSame(SourceRecordState::Absent, $result->state);
        self::assertNull($result->ref);
        self::assertNull($this->pageCache->find($url));
    }

    private function catalog(MockHttpClient $mockClient): AutocentrumCatalog
    {
        $rawValue = new RawValue();
        $dtRowReader = new DtRowReader($rawValue);
        $configBoxParser = new ConfigBoxParser($dtRowReader, $rawValue, new GearboxResolver(), new DriveResolver());
        $variantsParser = new VariantsParser($configBoxParser);
        $logger = new NullLogger();

        $pageParser = new PageParser(
            new FuelTokenResolver(),
            new TaxonomyReader($logger),
            $dtRowReader,
            $variantsParser,
            $rawValue,
            $logger,
        );

        $rateLimiter = new RateLimiterFactory(
            [
                'id' => 'test-crawl',
                'policy' => 'token_bucket',
                'limit' => 1000,
                'rate' => ['interval' => '1 second', 'amount' => 1000],
            ],
            new InMemoryStorage(),
            new LockFactory(new InMemoryStore()),
        );

        $httpClient = new AutocentrumHttpClient($mockClient, $this->pageCache, $rateLimiter, 'cars-api-import-test/1.0');

        return new AutocentrumCatalog(new SitemapReader(''), $httpClient, $pageParser, $this->pageCache);
    }

    private function fixture(string $filename): string
    {
        $html = file_get_contents(self::FIXTURES_DIR . '/' . $filename);
        if (false === $html) {
            self::fail(sprintf('Could not read fixture "%s".', $filename));
        }

        return $html;
    }
}
