<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Url;

use App\Source\Autocentrum\Url\EnginePath;
use PHPUnit\Framework\TestCase;

final class EnginePathTest extends TestCase
{
    public function testParsesDepthFiveUrl(): void
    {
        $path = EnginePath::fromUrl(
            'https://www.autocentrum.pl/dane-techniczne/audi/a4/b8/avant-facelifting/silnik-benzynowy-2.0-tfsi-211km-2012-2013/',
        );

        self::assertSame(5, $path->depth());
        self::assertSame('audi', $path->brandSlug());
        self::assertSame('audi/a4', $path->modelSlug());
        self::assertSame('silnik-benzynowy-2.0-tfsi-211km-2012-2013', $path->engineSlug());
        self::assertSame(['audi', 'a4', 'b8', 'avant-facelifting', 'silnik-benzynowy-2.0-tfsi-211km-2012-2013'], $path->segments);
    }

    public function testParsesDepthThreeUrl(): void
    {
        $path = EnginePath::fromUrl(
            'https://www.autocentrum.pl/dane-techniczne/abarth/punto-evo/silnik-benzynowy-1.4-multiair-165km-2010-2012/',
        );

        self::assertSame(3, $path->depth());
        self::assertSame('abarth', $path->brandSlug());
        self::assertSame('abarth/punto-evo', $path->modelSlug());
    }
}
