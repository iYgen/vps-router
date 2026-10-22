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

    private static function advisories(): array
    {
        return [
            [
                'ghsa_id' => 'GHSA-crit', 'cve_id' => 'CVE-2026-109618', 'severity' => 'critical',
                'summary' => 'RCE via OOM corruption', 'withdrawn_at' => null,
                'vulnerabilities' => [
                    ['patched_versions' => '8.2.35', 'vulnerable_version_range' => '< 8.2.35'],
                    ['patched_versions' => '8.3.36', 'vulnerable_version_range' => '< 8.3.36'],
                ],
            ],
            [
                'ghsa_id' => 'GHSA-old', 'cve_id' => 'CVE-2025-1', 'severity' => 'medium',
                'summary' => 'Fixed long ago', 'withdrawn_at' => null,
                'vulnerabilities' => [['patched_versions' => '8.2.10', 'vulnerable_version_range' => '< 8.2.10']],
            ],
            [
                'ghsa_id' => 'GHSA-gone', 'cve_id' => 'CVE-2026-2', 'severity' => 'high',
                'summary' => 'Withdrawn', 'withdrawn_at' => '2026-10-01T00:00:00Z',
                'vulnerabilities' => [['patched_versions' => '8.2.99']],
            ],
        ];
    }

    public function testAffectingAdvisoriesMatchesBranchFix(): void
    {
        $v = SecurityAudit::affectingAdvisories(self::advisories(), '8.2.20');
        $this->assertCount(1, $v);
        $this->assertSame('CVE-2026-109618', $v[0]['cve']);
        $this->assertSame('8.2.35', $v[0]['fixed']);

        $this->assertSame([], SecurityAudit::affectingAdvisories(self::advisories(), '8.2.35'));
    }

    public function testAffectingAdvisoriesFlagsEolBranchWithoutFix(): void
    {
        $v = SecurityAudit::affectingAdvisories(self::advisories(), '7.1.33');
        $ids = array_column($v, 'id');
        $this->assertSame(['GHSA-crit', 'GHSA-old'], $ids);
        $this->assertNull($v[0]['fixed']);
    }

    public function testScanPhpUpstreamStoresLatestAndVulns(): void
    {
        $adv = json_encode(self::advisories());
        $fetch = fn(string $url) => str_contains($url, 'php.net') ? '{"version":"8.2.35"}' : $adv;
        $r = SecurityAudit::scanPhpUpstream($fetch, '8.2.20');
        $this->assertSame('8.2.35', $r['latest']);
        $this->assertCount(1, $r['vulns']);
        $this->assertNull($r['error']);

        $r = SecurityAudit::scanPhpUpstream(fn() => null, '8.2.20');
        $this->assertNull($r['latest']);
        $this->assertNotNull($r['error']);
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
