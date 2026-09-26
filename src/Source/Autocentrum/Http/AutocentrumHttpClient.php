<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Http;

use App\Source\Contract\FetchMode;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AutocentrumHttpClient
{
    private const string RATE_LIMITER_KEY = 'autocentrum';
    private const array ABSENT_STATUS_CODES = [404, 410];

    public function __construct(
        private HttpClientInterface $httpClient,
        private PageCache $pageCache,
        private RateLimiterFactory $crawlLimiter,
        #[Autowire('%source.user_agent%')]
        private string $userAgent,
    ) {
    }

    public function get(string $url, FetchMode $mode): ?FetchedPage
    {
        $headers = ['User-Agent' => $this->userAgent];
        $cached = null;

        if (FetchMode::Incremental === $mode) {
            $cached = $this->pageCache->find($url);
            if (null !== $cached) {
                $headers['If-None-Match'] = $cached->etag;
            }
        }

        $this->crawlLimiter->create(self::RATE_LIMITER_KEY)->reserve(1)->wait();

        $response = $this->httpClient->request('GET', $url, ['headers' => $headers]);
        $statusCode = $response->getStatusCode();

        if (304 === $statusCode) {
            if (null === $cached) {
                throw new LogicException('Received a 304 response without a cached page for the requested URL.');
            }

            return FetchedPage::unchanged($cached->canonicalRef);
        }

        if (in_array($statusCode, self::ABSENT_STATUS_CODES, true)) {
            return null;
        }

        $html = $response->getContent();
        $etag = $response->getHeaders()['etag'][0] ?? null;

        return FetchedPage::fetched($html, $etag);
    }
}
