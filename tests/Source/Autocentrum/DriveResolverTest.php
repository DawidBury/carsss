<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use App\Source\Autocentrum\DriveResolver;
use App\Source\Contract\Drive;
use PHPUnit\Framework\TestCase;

final class DriveResolverTest extends TestCase
{
    private DriveResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DriveResolver();
    }

    public function testResolvesFront(): void
    {
        self::assertSame(Drive::Front, $this->resolver->resolve('na przednią oś'));
    }

    public function testResolvesRear(): void
    {
        self::assertSame(Drive::Rear, $this->resolver->resolve('na tylną oś'));
    }

    public function testResolvesAll(): void
    {
        self::assertSame(Drive::All, $this->resolver->resolve('4x4'));
    }

    public function testUnrecognizedLabelDoesNotMapAndIsNotThrown(): void
    {
        self::assertNull($this->resolver->resolve('na cztery koła i pół'));
    }
}
