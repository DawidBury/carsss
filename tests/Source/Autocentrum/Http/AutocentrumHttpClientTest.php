<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Http;

use App\Source\Autocentrum\Http\AutocentrumHttpClient;
use App\Source\Autocentrum\Http\PageCache;
use App\Source\Contract\FetchMode;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AutocentrumHttpClientTest extends KernelTestCase
{
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

    public function testIncrementalFetchSendsNoConditionalHeaderWithoutACachedEtag(): void
    {
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertArrayNotHasKey('if-none-match', $this->lowercasedHeaders($options));

            return new MockResponse('<html></html>', ['response_headers' => ['etag' => '"v1"']]);
        });

        $client = $this->client($mockClient);
        $page = $client->get('https://www.autocentrum.pl/x/', FetchMode::Incremental);

        self::assertNotNull($page);
        self::assertFalse($page->unchanged);
        self::assertSame('<html></html>', $page->html);
        self::assertSame('"v1"', $page->etag);
        self::assertNull($this->pageCache->find('https://www.autocentrum.pl/x/'));
    }

    public function testIncrementalFetchSendsIfNoneMatchWhenEtagIsCached(): void
    {
        $this->pageCache->store('https://www.autocentrum.pl/x/', '"cached-etag"', 'https://www.autocentrum.pl/x/');

        $mockClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('"cached-etag"', $this->lowercasedHeaders($options)['if-none-match'] ?? null);

            return new MockResponse('', ['http_code' => 304]);
        });

        $page = $this->client($mockClient)->get('https://www.autocentrum.pl/x/', FetchMode::Incremental);

        self::assertNotNull($page);
        self::assertTrue($page->unchanged);
        self::assertNull($page->html);
        self::assertSame('https://www.autocentrum.pl/x/', $page->canonicalRef);
    }

    public function testFullModeNeverSendsIfNoneMatchEvenWhenEtagIsCached(): void
    {
        $this->pageCache->store('https://www.autocentrum.pl/x/', '"cached-etag"', 'https://www.autocentrum.pl/x/');

        $mockClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertArrayNotHasKey('if-none-match', $this->lowercasedHeaders($options));

            return new MockResponse('<html>full</html>', ['response_headers' => ['etag' => '"v2"']]);
        });

        $page = $this->client($mockClient)->get('https://www.autocentrum.pl/x/', FetchMode::Full);

        self::assertNotNull($page);
        self::assertFalse($page->unchanged);
        self::assertSame('<html>full</html>', $page->html);
        self::assertSame('"v2"', $page->etag);
    }

    public function testAbsentPageReturnsNull(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        self::assertNull($this->client($mockClient)->get('https://www.autocentrum.pl/gone/', FetchMode::Incremental));
    }

    public function testUserAgentIsSentOnEveryRequest(): void
    {
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('cars-api-import-test/1.0', $this->lowercasedHeaders($options)['user-agent'] ?? null);

            return new MockResponse('<html></html>');
        });

        $this->client($mockClient)->get('https://www.autocentrum.pl/x/', FetchMode::Incremental);
    }

    public function testFetchedPageWithoutAnEtagCarriesNoEtag(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('<html></html>'));

        $page = $this->client($mockClient)->get('https://www.autocentrum.pl/x/', FetchMode::Incremental);

        self::assertNotNull($page);
        self::assertNull($page->etag);
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<string, string>
     */
    private function lowercasedHeaders(array $options): array
    {
        $rawHeaders = $options['headers'] ?? [];
        if (!is_array($rawHeaders)) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $header) {
            if (!is_string($header)) {
                continue;
            }

            $separator = strpos($header, ': ');
            if (false === $separator) {
                continue;
            }

            $headers[strtolower(substr($header, 0, $separator))] = substr($header, $separator + 2);
        }

        return $headers;
    }

    private function client(MockHttpClient $mockClient): AutocentrumHttpClient
    {
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

        return new AutocentrumHttpClient($mockClient, $this->pageCache, $rateLimiter, 'cars-api-import-test/1.0');
    }
}
