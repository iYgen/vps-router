<?php

namespace Tests;

use App\NodeServices;
use PHPUnit\Framework\TestCase;

class NodeServicesTest extends TestCase
{
    public function testParseAmneziaContainersPeersAndUnits(): void
    {
        $now = 1762800000;
        $out = implode("\n", [
            '---NOW---', (string) $now,
            '---DOCKER---',
            'amnezia-awg2|amnezia-awg2|running|Up 2 days|0.0.0.0:33676->33676/udp, :::33676->33676/udp',
            'amnezia-xray|amnezia-xray|running|Up 2 days|0.0.0.0:2222->2222/tcp, 0.0.0.0:2222->2222/udp, :::2222->2222/tcp',
            'amnezia-wireguard|amnezia-wireguard|exited|Exited (0) 3 hours ago|',
            'portainer|portainer/portainer-ce|running|Up 5 days|0.0.0.0:9443->9443/tcp',
            '---WG---',
            '## amnezia-awg2',
            // remoteCommand отдаёт только latest-handshake каждого пира
            (string) ($now - 30),
            '0',
            (string) ($now - 4000),
            '---UNITS---',
            'sing-box-exit active',
            'docker active',
            'fail2ban failed',
        ]);
        $r = NodeServices::parse($out);

        $this->assertCount(3, $r['amnezia']);
        $awg = $r['amnezia'][0];
        $this->assertSame('AmneziaWG 2.0', $awg['protocol']);
        $this->assertSame(['33676/udp'], $awg['ports']);
        $this->assertSame(['total' => 3, 'fresh' => 1], $awg['peers']);
        $this->assertSame('XRay (VLESS Reality)', $r['amnezia'][1]['protocol']);
        $this->assertSame(['2222/tcp', '2222/udp'], $r['amnezia'][1]['ports']);
        $this->assertSame('exited', $r['amnezia'][2]['state']);

        $this->assertCount(1, $r['containers']);
        $this->assertSame('portainer', $r['containers'][0]['name']);
        $this->assertSame([['name' => 'sing-box-exit', 'state' => 'active'], ['name' => 'docker', 'state' => 'active'], ['name' => 'fail2ban', 'state' => 'failed']], $r['units']);
        $this->assertTrue($r['docker']);
    }

    public function testParseNoDocker(): void
    {
        $r = NodeServices::parse("---NOW---\n1\n---DOCKER---\n---WG---\n---UNITS---\nsing-box active\n");
        $this->assertSame([], $r['amnezia']);
        $this->assertFalse($r['docker']);
        $this->assertSame('sing-box', $r['units'][0]['name']);
    }

    public function testUnknownAmneziaContainerGetsReadableName(): void
    {
        $r = NodeServices::parse("---DOCKER---\namnezia-newproto|img|running|Up|\n");
        $this->assertSame('Newproto', $r['amnezia'][0]['protocol']);
    }
}
