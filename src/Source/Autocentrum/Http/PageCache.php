<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Http;

use Doctrine\DBAL\Connection;
use UnexpectedValueException;

final readonly class PageCache
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $url): ?PageCacheEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT etag, canonical_ref FROM autocentrum_page_cache WHERE url = :url',
            ['url' => $url],
        );

        if (false === $row) {
            return null;
        }

        $etag = $row['etag'];
        $canonicalRef = $row['canonical_ref'];

        if (!is_string($etag) || !is_string($canonicalRef)) {
            throw new UnexpectedValueException('Expected the database to return string columns for the page cache row.');
        }

        return new PageCacheEntry($etag, $canonicalRef);
    }

    public function store(string $url, string $etag, string $canonicalRef): void
    {
        $this->connection->executeStatement(
            'INSERT INTO autocentrum_page_cache (url, etag, canonical_ref) VALUES (:url, :etag, :canonicalRef) '
            . 'ON CONFLICT (url) DO UPDATE SET etag = EXCLUDED.etag, canonical_ref = EXCLUDED.canonical_ref',
            ['url' => $url, 'etag' => $etag, 'canonicalRef' => $canonicalRef],
        );
    }
}
