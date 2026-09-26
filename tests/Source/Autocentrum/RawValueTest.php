<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum;

use App\Source\Autocentrum\RawValue;
use PHPUnit\Framework\TestCase;

final class RawValueTest extends TestCase
{
    private RawValue $rawValue;

    protected function setUp(): void
    {
        $this->rawValue = new RawValue();
    }

    public function testNormalizeTextTrimsAndConvertsNonBreakingSpace(): void
    {
        self::assertSame('9.3 s', $this->rawValue->normalizeText("  9.3\u{00A0}s  "));
    }

    public function testNormalizeTextTreatsEmptyValueAsAbsentNotZero(): void
    {
        self::assertNull($this->rawValue->normalizeText(''));
        self::assertNull($this->rawValue->normalizeText('   '));
        self::assertNull($this->rawValue->normalizeText("\u{00A0}"));
    }

    public function testDecimalAcceptsDotSeparator(): void
    {
        self::assertSame(9.3, $this->rawValue->decimal('9.3 s'));
    }

    public function testDecimalAcceptsCommaSeparator(): void
    {
        self::assertSame(6.3, $this->rawValue->decimal('6,3 l/100km'));
    }

    public function testDecimalReadsLeadingNumberOnlyFromCompoundValue(): void
    {
        self::assertSame(465.0, $this->rawValue->decimal('465 KM (342 kW) przy 5500 obr/min'));
    }

    public function testDecimalReturnsNullForNonNumericText(): void
    {
        self::assertNull($this->rawValue->decimal('z przodu, podłużnie'));
    }

    public function testDecimalTreatsZeroAsImplausibleAndMapsToNull(): void
    {
        self::assertNull($this->rawValue->decimal('0 s'));
    }

    public function testDecimalTreatsNegativeValueAsImplausibleAndMapsToNull(): void
    {
        self::assertNull($this->rawValue->decimal('-5 s'));
    }

    public function testIntegerRoundsDecimalValue(): void
    {
        self::assertSame(1984, $this->rawValue->integer('1984 cm³'));
    }

    public function testFuelConsumptionAcceptsLitersPer100Km(): void
    {
        self::assertSame(6.3, $this->rawValue->fuelConsumptionLPer100Km('6.3 l/100km'));
    }

    public function testFuelConsumptionRejectsKilowattHoursUnit(): void
    {
        self::assertNull($this->rawValue->fuelConsumptionLPer100Km('15.2 kWh/100km'));
    }
}
