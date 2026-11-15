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

    public function testRandom31IsValidAndV3(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $p = \App\Amnezia\AwgProfile::random31();
            $this->assertSame([], AwgParams::validate($p), json_encode($p));
            $this->assertTrue(\App\Amnezia\AwgProfile::isV3($p));
            foreach (['S1', 'S2', 'S3', 'S4'] as $k) {
                $this->assertGreaterThanOrEqual(12, $p[$k]);
            }
            $this->assertCount(3, array_unique([148 + $p['S1'], 92 + $p['S2'], 64 + $p['S3']]));
            $this->assertSame(32, strlen(base64_decode($p['HeaderProtectionKey'], true)));
        }
        $this->assertSame('22-30', \App\Amnezia\AwgProfile::keepalive(\App\Amnezia\AwgProfile::random31()));
        $this->assertSame('25', \App\Amnezia\AwgProfile::keepalive(['Jc' => 4]));
    }

    public function testDnsLikeI1IsWellFormedDnsResponse(): void
    {
        $i1 = \App\Amnezia\AwgProfile::dnsLikeI1();
        $this->assertMatchesRegularExpression('/^(<(b 0x[0-9a-f]+|r \d+)>)+$/', $i1);
        // Собираем пакет, подставив нули вместо <r N>, и проверяем, что это разбираемый DNS-ответ.
        $hex = preg_replace_callback('/<r (\d+)>/', fn($m) => str_repeat('00', (int) $m[1]), $i1);
        $bin = hex2bin(str_replace(['<b 0x', '>'], '', $hex));
        $this->assertSame(0x8180, unpack('n', $bin, 2)[1]);
        $this->assertSame([1, 2, 0, 1], array_values(unpack('n4', $bin, 4)));
        $this->assertGreaterThanOrEqual(60, strlen($bin));
    }

    public function testV3RequiresPaddingAtLeast12AndValidKey(): void
    {
        $p = \App\Amnezia\AwgProfile::random31();
        $p['S4'] = 8;
        $this->assertStringContainsString('S4 меньше 12', implode("\n", AwgParams::validate($p)));
        $p = \App\Amnezia\AwgProfile::random31();
        $p['HeaderProtectionKey'] = 'пароль';
        $this->assertStringContainsString('32 байтами', implode("\n", AwgParams::validate($p)));
        $p = \App\Amnezia\AwgProfile::random31();
        $p['I1'] = '<x 1>';
        $this->assertStringContainsString('I1', implode("\n", AwgParams::validate($p)));
    }

    public function testHeaderRangesMustNotOverlap(): void
    {
        $ok = ['H1' => '100-200', 'H2' => '300-400', 'H3' => '500', 'H4' => '600-700'];
        $this->assertSame([], AwgParams::validate($ok));
        $bad = ['H1' => '100-200', 'H2' => '150-400', 'H3' => '500', 'H4' => '600'];
        $this->assertStringContainsString('пересекаться', implode("\n", AwgParams::validate($bad)));
    }

    public function testDuplicateHeadersRejected(): void
    {
        $errs = AwgParams::validate(['H1' => 5, 'H2' => 5, 'H3' => 7, 'H4' => 8]);
        $this->assertStringContainsString('H1..H4', implode("\n", $errs));
    }
}
