<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use App\Source\Autocentrum\FuelTokenResolver;
use App\Source\Contract\FuelType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuelTokenResolverTest extends TestCase
{
    private FuelTokenResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FuelTokenResolver();
    }

    /**
     * @return iterable<string, array{string, ?FuelType}>
     */
    public static function slugs(): iterable
    {
        yield 'petrol' => ['silnik-benzynowy-2.0-tfsi-211km-2012-2013', FuelType::Petrol];
        yield 'diesel' => ['silnik-diesla-2.0-tdi-150km-2015-2018', FuelType::Diesel];
        yield 'hybrid' => ['silnik-hybrydowy-activehybrid-v8-465km-2010-2012', FuelType::Hybrid];
        yield 'electric' => ['silnik-elektryczny-elektryczny-67km-2010-2012', FuelType::Electric];
        yield 'unknown token' => ['silnik-wodorowy-1.0-100km-2020-2022', null];
        yield 'not an engine slug' => ['sedan', null];
        yield 'first segment wins over a later occurrence of another token' => [
            'silnik-hybrydowy-2.0-b5-benzynowy-mild-hybrid-264km-od-2020',
            FuelType::Hybrid,
        ];
        yield 'first segment wins the other way round too' => [
            'silnik-benzynowy-2.0-b5-hybrydowy-mild-hybrid-264km-od-2020',
            FuelType::Petrol,
        ];
    }

    #[DataProvider('slugs')]
    public function testResolvesFuelTypeFromFirstSegmentAfterEnginePrefix(string $slug, ?FuelType $expected): void
    {
        self::assertSame($expected, $this->resolver->fromEngineSlug($slug));
    }
}
