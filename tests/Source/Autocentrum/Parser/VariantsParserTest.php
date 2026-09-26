<?php

declare(strict_types=1);

namespace App\Tests\Source\Autocentrum\Parser;

use App\Source\Autocentrum\DriveResolver;
use App\Source\Autocentrum\GearboxResolver;
use App\Source\Autocentrum\Parser\ConfigBoxParser;
use App\Source\Autocentrum\Parser\DtRowReader;
use App\Source\Autocentrum\Parser\VariantsParser;
use App\Source\Autocentrum\RawValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

final class VariantsParserTest extends TestCase
{
    private VariantsParser $parser;

    protected function setUp(): void
    {
        $rawValue = new RawValue();
        $dtRowReader = new DtRowReader($rawValue);
        $configBoxParser = new ConfigBoxParser($dtRowReader, $rawValue, new GearboxResolver(), new DriveResolver());
        $this->parser = new VariantsParser($configBoxParser);
    }

    public function testAbortsWhenOptionCountDiffersFromConfigBoxCount(): void
    {
        $html = <<<'HTML'
            <html><body>
                <select id="config-select">
                    <option data-id="1">A</option>
                    <option data-id="2">B</option>
                </select>
                <div class="config-box" data-context="config">
                    <div class="dt-row">
                        <div class="dt-row__text" data-label="Przyspieszenie (od 0 do 100km/h)"></div>
                        <span class="dt-param-value">9.0 s</span>
                    </div>
                </div>
            </body></html>
            HTML;

        self::assertNull($this->parser->parse(new Crawler($html)));
    }

    public function testReturnsEmptyListWhenNoConfigBoxExists(): void
    {
        $html = '<html><body></body></html>';

        self::assertSame([], $this->parser->parse(new Crawler($html)));
    }
}
