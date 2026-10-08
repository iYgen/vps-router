<?php

namespace Tests;

use App\HydraRouteExport;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class HydraRouteExportTest extends TestCase
{
    public function testExportGroupsByPolicy(): void
    {
        $routerId = Server::create(['name' => 'hr-r-' . uniqid(), 'role' => 'router']);
        $exit = ExitServer::create([
            'name' => 'FL server', 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        $g = RuleGroup::create('hr-g-' . uniqid(), $exit, null, null, null, 'manual', null, $routerId);
        Rule::create($g, 'domain_suffix', 'youtube.com');
        Rule::create($g, 'geosite', 'google');
        Rule::create($g, 'ip_cidr', '203.0.113.0/24');
        Rule::create($g, 'domain_keyword', 'ignored'); // HydraRoute не знает keyword

        $policy = HydraRouteExport::policyName('FL server', $exit);
        $this->assertSame('FL_server', $policy); // пробел -> _

        $dc = HydraRouteExport::domainConf($routerId, false);
        $this->assertStringContainsString("youtube.com/$policy", $dc);
        $this->assertStringContainsString("geosite:google/$policy", $dc);
        $this->assertStringNotContainsString('ignored', $dc); // keyword пропущен

        $il = HydraRouteExport::ipList($routerId, false);
        $this->assertStringContainsString("/$policy", $il);
        $this->assertStringContainsString('203.0.113.0/24', $il);
    }

    public function testPolicyNameFallback(): void
    {
        $this->assertSame('exit42', HydraRouteExport::policyName('···', 42));
        $this->assertSame('My-Exit_1', HydraRouteExport::policyName('My-Exit 1', 7));
    }
}
