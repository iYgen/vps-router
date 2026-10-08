<?php

namespace Tests;

use App\SecurityAudit;
use PHPUnit\Framework\TestCase;

class SecurityAuditTest extends TestCase
{
    public function testReportHasAllSections(): void
    {
        $report = SecurityAudit::report();
        foreach (['os', 'php', 'updates', 'findings'] as $key) {
            $this->assertArrayHasKey($key, $report);
        }
        $this->assertIsArray($report['findings']);
    }

    public function testPhpStatusReportsCurrentVersion(): void
    {
        $php = SecurityAudit::phpStatus();
        $this->assertSame(PHP_VERSION, $php['version']);
        $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $php['branch']);
        $this->assertArrayHasKey('eol', $php);
        $this->assertIsBool($php['eol']);
    }

    public function testPendingUpdatesDefaultsWhenNoCache(): void
    {
        $upd = SecurityAudit::pendingUpdates();
        $this->assertArrayHasKey('total', $upd);
        $this->assertArrayHasKey('security', $upd);
        $this->assertIsInt($upd['total']);
    }

    public function testFindingsUseTranslatableKeys(): void
    {
        // Every finding must reference an sec.* i18n key that resolves (not blank).
        foreach (SecurityAudit::findings() as $f) {
            $this->assertArrayHasKey('level', $f);
            $this->assertContains($f['level'], ['warning', 'error']);
            $this->assertStringStartsWith('sec.', $f['key']);
            $this->assertNotSame('', t($f['key'], ...$f['args']));
        }
        $this->assertTrue(true);
    }
}
