<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use App\Source\Autocentrum\GearboxResolver;
use App\Source\Contract\Gearbox;
use PHPUnit\Framework\TestCase;

final class GearboxResolverTest extends TestCase
{
    private GearboxResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GearboxResolver();
    }

    public function testResolvesManual(): void
    {
        self::assertSame(Gearbox::Manual, $this->resolver->resolve('manualna'));
    }

    public function testResolvesAutomatic(): void
    {
        self::assertSame(Gearbox::Automatic, $this->resolver->resolve('automatyczna'));
    }

    public function testUnrecognizedLabelDoesNotMapAndIsNotThrown(): void
    {
        self::assertNull($this->resolver->resolve('półautomatyczna sekwencyjna'));
    }
}
