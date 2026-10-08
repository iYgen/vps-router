<?php

namespace Tests;

use App\Amnezia\AwgParams;
use PHPUnit\Framework\TestCase;

class AwgParamsTest extends TestCase
{
    public function testValidParamsPass(): void
    {
        $ok = ['Jc' => 4, 'Jmin' => 40, 'Jmax' => 70, 'S1' => 100, 'S2' => 200, 'H1' => 10, 'H2' => 20, 'H3' => 30, 'H4' => 40];
        $this->assertSame([], AwgParams::validate($ok));
    }

    public function testDefaultHeadersDistinctPass(): void
    {
        // H1..H4 = 1..4 (заголовки не обфусцируются) — валидно, они различны.
        $p = ['Jc' => 4, 'Jmin' => 40, 'Jmax' => 70, 'S1' => 0, 'S2' => 0, 'H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4];
        $this->assertSame([], AwgParams::validate($p));
    }

    public function testJmaxLessThanJmin(): void
    {
        $errs = AwgParams::validate(['Jmin' => 70, 'Jmax' => 40, 'H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4]);
        $this->assertNotEmpty($errs);
        $this->assertStringContainsString('Jmax', implode("\n", $errs));
    }

    public function testS2EqualsS1Plus56Rejected(): void
    {
        $errs = AwgParams::validate(['S1' => 100, 'S2' => 156, 'H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4]);
        $this->assertStringContainsString('S1 + 56', implode("\n", $errs));
    }

    public function testDuplicateHeadersRejected(): void
    {
        $errs = AwgParams::validate(['H1' => 5, 'H2' => 5, 'H3' => 7, 'H4' => 8]);
        $this->assertStringContainsString('H1..H4', implode("\n", $errs));
    }
}
