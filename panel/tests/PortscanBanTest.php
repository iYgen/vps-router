<?php

namespace Tests;

use App\Models\Server;
use App\Provisioner;
use PHPUnit\Framework\TestCase;

class PortscanBanTest extends TestCase
{
    public function testRejectsEmptyDecoyPorts(): void
    {
        $id = Server::create(['name' => 'ScanBan-' . uniqid(), 'role' => 'exit']);
        $this->expectException(\InvalidArgumentException::class);
        Provisioner::hardenPortscanBan($id, []);
    }

    public function testRejectsUnknownServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Provisioner::hardenPortscanBan(999999, [21, 23]);
    }

    public function testRejectsServerWithoutSshKey(): void
    {
        $id = Server::create(['name' => 'NoKey-' . uniqid(), 'role' => 'exit']);
        $this->expectException(\InvalidArgumentException::class);
        Provisioner::hardenPortscanBan($id, [21, 23]);
    }

    public function testSetPortscanBanConfigPersistsAndIsReturnedByFind(): void
    {
        $id = Server::create(['name' => 'ScanBanCfg-' . uniqid(), 'role' => 'exit']);
        Server::setPortscanBanConfig($id, true, [21, 23, 3389], 3600);

        $server = Server::find($id);
        $this->assertSame(1, (int) $server['portscan_ban_enabled']);
        $this->assertSame([21, 23, 3389], json_decode($server['portscan_decoy_ports'], true));
        $this->assertSame(3600, (int) $server['portscan_ban_seconds']);
    }

    public function testDefaultConfigIsDisabled(): void
    {
        $id = Server::create(['name' => 'ScanBanDefault-' . uniqid(), 'role' => 'exit']);
        $server = Server::find($id);
        $this->assertSame(0, (int) $server['portscan_ban_enabled']);
    }
}
