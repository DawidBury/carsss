<?php

declare(strict_types=1);

namespace App\Source\Autocentrum;

use App\Source\Autocentrum\Http\AutocentrumHttpClient;
use App\Source\Autocentrum\Http\PageCache;
use App\Source\Autocentrum\Parser\PageParser;
use App\Source\Autocentrum\Sitemap\SitemapReader;
use App\Source\Contract\FetchMode;
use App\Source\Contract\SourceFetchResult;
use App\Source\SourceCatalog;
use LogicException;

final readonly class AutocentrumCatalog implements SourceCatalog
{
    public function __construct(
        private SitemapReader $sitemapReader,
        private AutocentrumHttpClient $httpClient,
        private PageParser $pageParser,
        private PageCache $pageCache,
    ) {
    }

    public function name(): string
    {
        return 'autocentrum';
    }

    public function listRecords(): iterable
    {
        return $this->sitemapReader->leafUrls();
    }

    public function fetchRecord(string $ref, FetchMode $mode): SourceFetchResult
    {
        $page = $this->httpClient->get($ref, $mode);
        if (null === $page) {
            return SourceFetchResult::absent();
        }

        if ($page->unchanged) {
            $canonicalRef = $page->canonicalRef;
            if (null === $canonicalRef) {
                throw new LogicException('An unchanged fetch result must carry the canonical reference.');
            }

            return SourceFetchResult::unchanged($canonicalRef);
        }

        $html = $page->html;
        if (null === $html) {
            throw new LogicException('A fetched page must carry HTML content when it is not unchanged.');
        }

        $record = $this->pageParser->parse($html);
        if (null === $record) {
            return SourceFetchResult::absent();
        }

        if (null !== $page->etag) {
            $this->pageCache->store($ref, $page->etag, $record->ref);
        }

        return SourceFetchResult::record($record);
    }
}
