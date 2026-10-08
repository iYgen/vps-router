<?php

namespace Tests;

use App\RiskScanner;
use PHPUnit\Framework\TestCase;

class RiskScannerBrandTest extends TestCase
{
    private const MS = ['www.microsoft.com', 'wwwqa.microsoft.com', 'www.microsoft.com', 'staticview.microsoft.com', 'i.s-microsoft.com', 'microsoft.com', 'c.s-microsoft.com', 'privacy.microsoft.com'];

    public function testManyNamesGiveSingleFinding(): void
    {
        $f = RiskScanner::brandImpersonation(443, self::MS, 'FL-server');
        $this->assertSame('error', $f['level']);
        $this->assertStringContainsString('www.microsoft.com', $f['message']);
        $this->assertStringContainsString('и др.', $f['message']);
    }

    public function testOwnRealityCamouflageIsWarningWithAdvice(): void
    {
        $f = RiskScanner::brandImpersonation(443, self::MS, 'FL-server', ['www.microsoft.com']);
        $this->assertSame('warning', $f['level']);
        $this->assertStringContainsString('маскировка Reality', $f['message']);
    }

    public function testOwnSiteCertIsNotFlagged(): void
    {
        $this->assertNull(RiskScanner::brandImpersonation(2222, ['example.com', 'www.example.com'], 'Entry Router', ['example.com']));
    }
}
