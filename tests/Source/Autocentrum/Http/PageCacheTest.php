<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Http;

use App\Source\Autocentrum\Http\PageCache;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageCacheTest extends KernelTestCase
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

    public function testFindReturnsNullForUnknownUrl(): void
    {
        self::assertNull($this->pageCache->find('https://www.autocentrum.pl/unknown/'));
    }

    public function testStoreThenFindReturnsTheStoredEtagAndCanonicalRef(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';

        $this->pageCache->store($url, 'W/"abc123"', $url);

        $entry = $this->pageCache->find($url);
        self::assertNotNull($entry);
        self::assertSame('W/"abc123"', $entry->etag);
        self::assertSame($url, $entry->canonicalRef);
    }

    public function testStoreTwiceOverwritesTheEtagAndCanonicalRef(): void
    {
        $url = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1/';
        $firstCanonical = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limuzyna/';
        $secondCanonical = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limousine/';

        $this->pageCache->store($url, 'first', $firstCanonical);
        $this->pageCache->store($url, 'second', $secondCanonical);

        $entry = $this->pageCache->find($url);
        self::assertNotNull($entry);
        self::assertSame('second', $entry->etag);
        self::assertSame($secondCanonical, $entry->canonicalRef);
    }

    public function testCanonicalRefIsNotUniqueAcrossConvergingAddresses(): void
    {
        $firstUrl = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limuzyna/';
        $secondUrl = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limousine/';
        $canonicalRef = 'https://www.autocentrum.pl/dane-techniczne/audi/a4/silnik-benzynowy-1-limousine/';

        $this->pageCache->store($firstUrl, 'first', $canonicalRef);
        $this->pageCache->store($secondUrl, 'second', $canonicalRef);

        self::assertSame($canonicalRef, $this->pageCache->find($firstUrl)?->canonicalRef);
        self::assertSame($canonicalRef, $this->pageCache->find($secondUrl)?->canonicalRef);
    }
}
