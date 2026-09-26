<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Parser;

use App\Source\Autocentrum\DriveResolver;
use App\Source\Autocentrum\FuelTokenResolver;
use App\Source\Autocentrum\GearboxResolver;
use App\Source\Autocentrum\Parser\ConfigBoxParser;
use App\Source\Autocentrum\Parser\DtRowReader;
use App\Source\Autocentrum\Parser\PageParser;
use App\Source\Autocentrum\Parser\TaxonomyReader;
use App\Source\Autocentrum\Parser\VariantsParser;
use App\Source\Autocentrum\RawValue;
use App\Source\Contract\Drive;
use App\Source\Contract\FuelType;
use App\Source\Contract\Gearbox;
use App\Source\Contract\SourceRecord;
use App\Source\Contract\SourceVariant;
use App\Tests\Source\Autocentrum\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PageParserTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__ . '/../../../Fixtures/Source/Autocentrum';

    private PageParser $parser;

    protected function setUp(): void
    {
        $this->parser = $this->buildParser(new NullLogger());
    }

    private function buildParser(LoggerInterface $logger): PageParser
    {
        $rawValue = new RawValue();
        $dtRowReader = new DtRowReader($rawValue);
        $configBoxParser = new ConfigBoxParser($dtRowReader, $rawValue, new GearboxResolver(), new DriveResolver());
        $variantsParser = new VariantsParser($configBoxParser);

        return new PageParser(
            new FuelTokenResolver(),
            new TaxonomyReader($logger),
            $dtRowReader,
            $variantsParser,
            $rawValue,
            $logger,
        );
    }

    public function testElectricDepthThreePageWithSingleVariant(): void
    {
        $record = $this->parseFixture('electric-no-combustion.html');

        self::assertSame(
            'https://www.autocentrum.pl/dane-techniczne/citroen/c-zero/silnik-elektryczny-elektryczny-67km-2010-2012/',
            $record->ref,
        );
        self::assertSame(FuelType::Electric, $record->fuelType);
        self::assertSame(67.0, $record->powerHp);
        self::assertNull($record->engineDisplacementCcm);
        self::assertNull($record->bootCapacityL);

        self::assertSame('citroen', $record->taxonomy->brandRef);
        self::assertSame('Citroen', $record->taxonomy->brandName);
        self::assertSame('citroen/c-zero', $record->taxonomy->modelRef);
        self::assertSame('c zero', $record->taxonomy->modelName);
        self::assertSame('C-Zero Elektryczny 67KM 49kW 2010-2012', $record->taxonomy->versionName);
        self::assertNull($record->taxonomy->generation);
        self::assertNull($record->taxonomy->bodyStyle);
        self::assertNull($record->taxonomy->unresolvedSegment);

        self::assertCount(1, $record->variants);
        $variant = $record->variants[0];
        self::assertSame('20085', $variant->ref);
        self::assertSame(15.9, $variant->acceleration0To100S);
        self::assertNull($variant->fuelConsumptionL100Km);
        self::assertNull($variant->fuelTankL);
        self::assertSame(Gearbox::Automatic, $variant->gearbox);
        self::assertNull($variant->gearboxUnmappedLabel);
        self::assertSame(Drive::Rear, $variant->drive);
        self::assertNull($variant->driveUnmappedLabel);
    }

    public function testFlatDepthThreePageWithSingleVariant(): void
    {
        $record = $this->parseFixture('flat-path-depth-3.html');

        self::assertSame(FuelType::Petrol, $record->fuelType);
        self::assertSame(165.0, $record->powerHp);
        self::assertSame(1368, $record->engineDisplacementCcm);
        self::assertNull($record->bootCapacityL);

        self::assertSame('abarth', $record->taxonomy->brandRef);
        self::assertSame('Abarth', $record->taxonomy->brandName);
        self::assertSame('abarth/punto-evo', $record->taxonomy->modelRef);
        self::assertSame('punto evo', $record->taxonomy->modelName);
        self::assertSame('Punto Evo 1.4 MultiAir 165KM 121kW 2010-2012', $record->taxonomy->versionName);
        self::assertNull($record->taxonomy->generation);
        self::assertNull($record->taxonomy->bodyStyle);
        self::assertNull($record->taxonomy->unresolvedSegment);

        self::assertCount(1, $record->variants);
        $variant = $record->variants[0];
        self::assertSame('10328', $variant->ref);
        self::assertSame(7.9, $variant->acceleration0To100S);
        self::assertSame(6.1, $variant->fuelConsumptionL100Km);
        self::assertNull($variant->fuelTankL);
        self::assertSame(Gearbox::Manual, $variant->gearbox);
        self::assertSame(Drive::Front, $variant->drive);
    }

    public function testUnrecognizedGearboxLabelIsStoredAsUnmappedLabelWithoutFailingTheImport(): void
    {
        $record = $this->parseFixture('synthetic-unmapped-gearbox-label.html');

        self::assertCount(1, $record->variants);
        $variant = $record->variants[0];
        self::assertSame('10328', $variant->ref);
        self::assertSame(7.9, $variant->acceleration0To100S);
        self::assertNull($variant->gearbox);
        self::assertSame('półautomatyczna', $variant->gearboxUnmappedLabel);
        self::assertSame(Drive::Front, $variant->drive);
        self::assertNull($variant->driveUnmappedLabel);
    }

    public function testDepthFivePageWithHybridFuelAndSingleVariant(): void
    {
        $record = $this->parseFixture('hybrid.html');

        self::assertSame(FuelType::Hybrid, $record->fuelType);
        self::assertSame(465.0, $record->powerHp);
        self::assertSame(4395, $record->engineDisplacementCcm);
        self::assertSame(500, $record->bootCapacityL);

        self::assertSame('bmw', $record->taxonomy->brandRef);
        self::assertSame('BMW', $record->taxonomy->brandName);
        self::assertSame('bmw/seria-7', $record->taxonomy->modelRef);
        self::assertSame('Seria 7', $record->taxonomy->modelName);
        self::assertSame('F01', $record->taxonomy->generation);
        self::assertSame('Sedan', $record->taxonomy->bodyStyle);
        self::assertNull($record->taxonomy->unresolvedSegment);
        self::assertSame('ActiveHybrid V8 465KM 342kW 2010-2012', $record->taxonomy->versionName);

        self::assertCount(1, $record->variants);
        $variant = $record->variants[0];
        self::assertSame('17316', $variant->ref);
        self::assertSame(4.9, $variant->acceleration0To100S);
        self::assertSame(9.4, $variant->fuelConsumptionL100Km);
        self::assertSame(80, $variant->fuelTankL);
        self::assertSame(Gearbox::Automatic, $variant->gearbox);
        self::assertSame(Drive::Rear, $variant->drive);
    }

    public function testMultiVariantPageSkipsStubFirstOptionWithoutAcceleration(): void
    {
        $record = $this->parseFixture('multi-variant-with-empty-first-option.html');

        self::assertSame(FuelType::Petrol, $record->fuelType);
        self::assertSame(211.0, $record->powerHp);
        self::assertSame(1984, $record->engineDisplacementCcm);
        self::assertSame(490, $record->bootCapacityL);

        self::assertSame('audi', $record->taxonomy->brandRef);
        self::assertSame('Audi', $record->taxonomy->brandName);
        self::assertSame('audi/a4', $record->taxonomy->modelRef);
        self::assertSame('A4', $record->taxonomy->modelName);
        self::assertSame('B8', $record->taxonomy->generation);
        self::assertSame('Avant Facelifting', $record->taxonomy->bodyStyle);
        self::assertSame('2.0 TFSI 211KM 155kW 2012-2013', $record->taxonomy->versionName);

        self::assertCount(3, $record->variants, 'the stub first <option> without cid and without acceleration must be skipped');
        self::assertNotContains('0', array_map(static fn (SourceVariant $variant): string => $variant->ref, $record->variants));

        $variant15751 = $this->variantByRef($record->variants, '15751');
        self::assertSame(6.7, $variant15751->acceleration0To100S);
        self::assertSame(7.5, $variant15751->fuelConsumptionL100Km);
        self::assertSame(64, $variant15751->fuelTankL);
        self::assertSame(Gearbox::Manual, $variant15751->gearbox);
        self::assertSame(Drive::All, $variant15751->drive);

        $variant20312 = $this->variantByRef($record->variants, '20312');
        self::assertSame(7.4, $variant20312->acceleration0To100S);
        self::assertSame(Gearbox::Automatic, $variant20312->gearbox);
        self::assertSame(Drive::Front, $variant20312->drive);

        $variant23416 = $this->variantByRef($record->variants, '23416');
        self::assertSame(6.6, $variant23416->acceleration0To100S);
        self::assertSame(Gearbox::Automatic, $variant23416->gearbox);
        self::assertSame(Drive::All, $variant23416->drive);
    }

    public function testPageWithoutAnyConfigBoxKeepsVersionButYieldsNoVariants(): void
    {
        $record = $this->parseFixture('no-config-box-at-all.html');

        self::assertSame(FuelType::Petrol, $record->fuelType);
        self::assertSame(310.0, $record->powerHp);
        self::assertNull($record->engineDisplacementCcm);
        self::assertNull($record->bootCapacityL);

        self::assertSame('cadillac', $record->taxonomy->brandRef);
        self::assertSame('Cadillac', $record->taxonomy->brandName);
        self::assertSame('Eldorado', $record->taxonomy->modelName);
        self::assertSame('II', $record->taxonomy->generation);
        self::assertSame('Cabrio', $record->taxonomy->bodyStyle);

        self::assertSame([], $record->variants);
    }

    public function testSingleVariantPageWithoutSelectReadsCidFromRankingLink(): void
    {
        $record = $this->parseFixture('single-variant-no-select.html');

        self::assertSame(FuelType::Petrol, $record->fuelType);
        self::assertSame(335.0, $record->powerHp);
        self::assertSame(4163, $record->engineDisplacementCcm);
        self::assertSame(546, $record->bootCapacityL);

        self::assertSame('audi', $record->taxonomy->brandRef);
        self::assertSame('A6', $record->taxonomy->modelName);
        self::assertSame('C6', $record->taxonomy->generation);
        self::assertSame('Limousine', $record->taxonomy->bodyStyle);

        self::assertCount(1, $record->variants);
        $variant = $record->variants[0];
        self::assertSame('22567', $variant->ref);
        self::assertSame(6.0, $variant->acceleration0To100S);
        self::assertSame(11.6, $variant->fuelConsumptionL100Km);
        self::assertSame(80, $variant->fuelTankL);
        self::assertSame(Gearbox::Automatic, $variant->gearbox);
        self::assertSame(Drive::All, $variant->drive);
    }

    public function testCanonicalLinkOutsideTheTaxonomyRootIsSkippedInsteadOfCrashing(): void
    {
        $logger = new RecordingLogger();
        $parser = $this->buildParser($logger);

        $html = '<html><head><link rel="canonical" href="https://www.autocentrum.pl/"></head><body></body></html>';

        self::assertNull($parser->parse($html));
        self::assertNotEmpty($logger->recordsContaining('outside the taxonomy root'));
    }

    public function testCanonicalLinkShallowerThanTheMinimumTaxonomyDepthIsSkipped(): void
    {
        $logger = new RecordingLogger();
        $parser = $this->buildParser($logger);

        $html = '<html><head><link rel="canonical" href="https://www.autocentrum.pl/dane-techniczne/audi/"></head><body></body></html>';

        self::assertNull($parser->parse($html));
        self::assertNotEmpty($logger->recordsContaining('outside the taxonomy root'));
    }

    private function parseFixture(string $filename): SourceRecord
    {
        $html = file_get_contents(self::FIXTURES_DIR . '/' . $filename);
        if (false === $html) {
            self::fail(sprintf('Could not read fixture "%s".', $filename));
        }

        $record = $this->parser->parse($html);
        if (null === $record) {
            self::fail(sprintf('Expected "%s" to parse into a SourceRecord.', $filename));
        }

        return $record;
    }

    /**
     * @param list<SourceVariant> $variants
     */
    private function variantByRef(array $variants, string $ref): SourceVariant
    {
        foreach ($variants as $variant) {
            if ($variant->ref === $ref) {
                return $variant;
            }
        }

        self::fail(sprintf('No variant with ref "%s" found.', $ref));
    }
}
