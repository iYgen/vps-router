<?php

namespace Tests;

use App\CamouflageFront;
use PHPUnit\Framework\TestCase;

class CamouflageFrontTest extends TestCase
{
    public function testPresetsIncludeCustom(): void
    {
        $p = CamouflageFront::presets();
        $this->assertArrayHasKey('updates', $p);
        $this->assertArrayHasKey('custom', $p);
    }

    public function testUpdatesIsEnglish(): void
    {
        $files = CamouflageFront::files('updates', 'SB update services');
        $this->assertArrayHasKey('index.html', $files);
        $this->assertArrayHasKey('status.html', $files);

        $index = $files['index.html'];
        $this->assertStringContainsString('Update Server', $index);
        $this->assertStringContainsString('lang="en"', $index);
        $this->assertStringContainsString('SB update services', $index);
        // Никакой кириллицы на заграничном ресурсе.
        $this->assertSame(0, preg_match('/[\x{0400}-\x{04FF}]/u', $index), 'updates index не должен содержать кириллицу');
        $this->assertSame(0, preg_match('/[\x{0400}-\x{04FF}]/u', $files['releases.html']));
        $this->assertSame(0, preg_match('/[\x{0400}-\x{04FF}]/u', $files['status.html']));
    }

    public function testStatusHasDynamicMetricsScript(): void
    {
        $status = CamouflageFront::files('updates', 'SB')['status.html'];
        // id-метки, которые заполняет inline-скрипт.
        foreach (['id="u1"', 'id="ms"', 'id="reqs"'] as $needle) {
            $this->assertStringContainsString($needle, $status);
        }
        $this->assertStringContainsString('<script>', $status, 'есть скрипт живых метрик');
        $this->assertStringContainsString('getUTCHours', $status, 'метрики считаются от времени');
    }

    public function testCustomFullDocumentVerbatim(): void
    {
        $html = '<!doctype html><html lang="en"><head><title>Hi</title></head><body><h1>Custom</h1></body></html>';
        $out = CamouflageFront::files('custom', 'X', $html)['index.html'];
        $this->assertSame($html, $out, 'полный документ отдаётся как есть');
    }

    public function testCustomFragmentWrapped(): void
    {
        $out = CamouflageFront::files('custom', 'Brand', '<h1>Hello</h1>')['index.html'];
        $this->assertStringStartsWith('<!doctype html>', $out);
        $this->assertStringContainsString('<h1>Hello</h1>', $out);
        $this->assertStringContainsString('<title>Brand</title>', $out);
    }

    public function testCustomEmptyFallsBackToMaintenance(): void
    {
        $out = CamouflageFront::files('custom', 'Brand', '   ')['index.html'];
        $this->assertStringContainsStringIgnoringCase('техническ', $out, 'пустой custom → заглушка техработ');
    }
}
