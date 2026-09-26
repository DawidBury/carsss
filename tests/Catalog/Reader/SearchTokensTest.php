<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Reader;

use App\Catalog\Reader\SearchTokens;
use PHPUnit\Framework\TestCase;

final class SearchTokensTest extends TestCase
{
    public function testSplitsAPhraseIntoLowercaseTokens(): void
    {
        self::assertSame(['audi', 'a4', '2.0', 'tdi'], SearchTokens::fromPhrase('Audi A4 2.0 TDI'));
    }

    public function testCollapsesRepeatedSeparators(): void
    {
        self::assertSame(['audi', 'a4'], SearchTokens::fromPhrase("  Audi \t\n  A4  "));
    }

    public function testStripsCharactersThatCarryMeaningInATsQuery(): void
    {
        self::assertSame(['audi', 'a4'], SearchTokens::fromPhrase('audi & a4:*'));
        self::assertSame(['audi', 'a4'], SearchTokens::fromPhrase('audi | !a4'));
        self::assertSame(['audi', 'a4'], SearchTokens::fromPhrase("audi' <-> (a4)"));
    }

    public function testKeepsLettersOutsideAscii(): void
    {
        self::assertSame(['škoda', 'octavia'], SearchTokens::fromPhrase('Škoda Octavia'));
    }

    public function testDropsTokensMadeOnlyOfDots(): void
    {
        self::assertSame([], SearchTokens::fromPhrase('... ..'));
        self::assertSame(['2.0'], SearchTokens::fromPhrase('.2.0.'));
    }

    public function testBlankPhraseYieldsNoTokens(): void
    {
        self::assertSame([], SearchTokens::fromPhrase('   '));
        self::assertSame([], SearchTokens::fromPhrase('!@#$%^&*()'));
    }

    public function testCapsTheNumberOfTokens(): void
    {
        $tokens = SearchTokens::fromPhrase(implode(' ', array_map(static fn (int $i): string => 'token' . $i, range(1, 25))));

        self::assertCount(10, $tokens);
        self::assertSame('token1', $tokens[0]);
        self::assertSame('token10', $tokens[9]);
    }

    public function testOnlyTheLastTokenIsPrefixMatched(): void
    {
        self::assertSame('audi & a4 & td:*', SearchTokens::toTsQuery(['audi', 'a4', 'td']));
        self::assertSame('audi:*', SearchTokens::toTsQuery(['audi']));
    }

    public function testShortTokensAreNotCorrectable(): void
    {
        self::assertFalse(SearchTokens::isCorrectable('a4'));
        self::assertFalse(SearchTokens::isCorrectable('x'));
        self::assertTrue(SearchTokens::isCorrectable('tdi'));
        self::assertTrue(SearchTokens::isCorrectable('mercedez'));
    }
}
