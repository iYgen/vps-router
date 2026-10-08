<?php

namespace Tests;

use App\Models\ExitServer;
use App\Models\ExitServerPeer;
use App\Provisioner;
use PHPUnit\Framework\TestCase;

class ExitServerPeerTest extends TestCase
{
    public function testNextTunnelOctetStartsAtThreeAndSkipsUsed(): void
    {
        $esId = ExitServer::create([
            'name' => 'wg-peer-target-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
        ]);

        $this->assertSame(3, ExitServerPeer::nextTunnelOctet($esId));

        ExitServerPeer::create($esId, 'Device A', 'pub-a', 'priv-a', '10.90.1.3/32');
        $this->assertSame(4, ExitServerPeer::nextTunnelOctet($esId));

        ExitServerPeer::create($esId, 'Device B', 'pub-b', 'priv-b', '10.90.1.4/32');
        $this->assertSame(5, ExitServerPeer::nextTunnelOctet($esId));
    }

    public function testCreateFindDelete(): void
    {
        $esId = ExitServer::create([
            'name' => 'wg-peer-target2-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
        ]);

        $peerId = ExitServerPeer::create($esId, 'Keenetic', 'pub-x', 'priv-x', '10.90.1.3/32');
        $peer = ExitServerPeer::find($peerId);
        $this->assertNotNull($peer);
        $this->assertSame('Keenetic', $peer['name']);
        $this->assertSame('10.90.1.3/32', $peer['tunnel_address']);
        $this->assertSame(0, (int) $peer['revoked']);

        $this->assertCount(1, ExitServerPeer::forExitServer($esId));

        ExitServerPeer::delete($peerId);
        $this->assertNull(ExitServerPeer::find($peerId));
    }

    public function testAddWireguardPeerRejectsNonWireguardProtocol(): void
    {
        $esId = ExitServer::create([
            'name' => 'amnezia-target-' . uniqid(),
            'endpoint_host' => 'awg.example.com',
            'endpoint_port' => 51900,
            'status' => 'active',
            'protocol' => 'amneziawg',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/только для protocol=wireguard/');
        Provisioner::addWireguardPeer($esId, 'Keenetic');
    }

    public function testAddWireguardPeerRejectsUnprovisionedServer(): void
    {
        $esId = ExitServer::create([
            'name' => 'wg-not-ready-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Сначала установите/');
        Provisioner::addWireguardPeer($esId, 'Keenetic');
    }
}
