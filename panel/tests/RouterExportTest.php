<?php

namespace Tests;

use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\Connection;
use App\RouterExport;
use PHPUnit\Framework\TestCase;

class RouterExportTest extends TestCase
{
    private int $groupId;

    protected function setUp(): void
    {
        // Группа маршрутов, ведущая на exit, с одним ip_cidr-правилом.
        $router = Server::create(['name' => 'R-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'E-' . uniqid(), 'role' => 'exit']);
        $exitId = ExitServer::create([
            'name' => 'ex-' . uniqid(), 'endpoint_host' => '198.51.100.7', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless',
        ]);
        Connection::create($router, $exitNode, 'vless', $exitId, null, 'l');
        $this->groupId = RuleGroup::create('G-' . uniqid(), $exitId); // enabled=1 по умолчанию
        Rule::create($this->groupId, 'ip_cidr', '203.0.113.0/24');
        Rule::create($this->groupId, 'ip_cidr', '198.51.100.9');       // одиночный IP -> /32
        Rule::create($this->groupId, 'ip_cidr', '2001:db8::/32');      // IPv6 -> отбрасывается
    }

    public function testTargets(): void
    {
        $t = RouterExport::targets();
        $this->assertArrayHasKey('mikrotik', $t);
        $this->assertArrayHasKey('openwrt', $t);
    }

    public function testMikrotikScript(): void
    {
        $s = RouterExport::mikrotik(null, true, 'wg0');
        $this->assertStringContainsString('/ip route', $s);
        $this->assertStringContainsString('add dst-address=203.0.113.0/24 gateway=wg0', $s);
        $this->assertStringContainsString('add dst-address=198.51.100.9/32 gateway=wg0', $s);
        $this->assertStringNotContainsString('2001:db8', $s, 'IPv6 не экспортируется');
        $this->assertStringContainsString('comment="vps_router"', $s);
    }

    public function testOpenwrtScript(): void
    {
        $s = RouterExport::openwrt(null, true, 'wgvpn');
        $this->assertStringContainsString('#!/bin/sh', $s);
        $this->assertStringContainsString('IFACE="wgvpn"', $s);
        $this->assertStringContainsString('ip route add 203.0.113.0/24 dev "$IFACE"', $s);
        $this->assertStringContainsString('ip route add 198.51.100.9/32 dev "$IFACE"', $s);
    }

    public function testIfaceSanitisedAgainstInjection(): void
    {
        // Небезопасное имя интерфейса откатывается к дефолту (нет инъекции в скрипт).
        $s = RouterExport::openwrt(null, true, 'wg0; rm -rf /');
        $this->assertStringContainsString('IFACE="wg-vpn"', $s);
        $this->assertStringNotContainsString('rm -rf', $s);
    }
}
