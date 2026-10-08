<?php

namespace Tests;

use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\RouteInspector;
use PHPUnit\Framework\TestCase;

class RouteInspectorTest extends TestCase
{
    private int $routerId;
    private string $exitName;

    protected function setUp(): void
    {
        $this->routerId = Server::create(['name' => 'ri-r-' . uniqid(), 'role' => 'router']);
        $this->exitName = 'ri-exit-' . uniqid();
        $exit = ExitServer::create([
            'name' => $this->exitName, 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        $u = uniqid();
        $g1 = RuleGroup::create('ri-yt-' . $u, $exit, null, null, null, 'manual', null, $this->routerId);
        Rule::create($g1, 'domain_suffix', 'youtube.com');
        Rule::create($g1, 'ip_cidr', '10.0.0.0/8');
        $g2 = RuleGroup::create('ri-geo-' . $u, $exit, null, null, null, 'manual', null, $this->routerId);
        Rule::create($g2, 'geosite', 'google');
    }

    private function inspect(string $q): array
    {
        return RouteInspector::inspect($q, $this->routerId, false);
    }

    public function testDomainSuffixMatch(): void
    {
        $r = $this->inspect('www.youtube.com');
        $this->assertSame('rule', $r['match']);
        $this->assertSame($this->exitName, $r['exit']);
        $this->assertStringContainsString('youtube.com', $r['via']);
    }

    public function testIpCidrMatch(): void
    {
        $r = $this->inspect('10.11.12.13');
        $this->assertSame('rule', $r['match']);
        $this->assertTrue($r['is_ip']);
        $this->assertStringContainsString('10.0.0.0/8', $r['via']);
    }

    public function testNoMatchIsDirect(): void
    {
        $r = $this->inspect('example.org');
        $this->assertSame('direct', $r['match']);
    }

    public function testGeositeReportedAsCandidate(): void
    {
        $r = $this->inspect('anything.test');
        $found = false;
        foreach ($r['geosite_candidates'] as $c) {
            if ($c['geosite'] === 'google') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'geosite-правило должно попасть в возможные');
    }
}
