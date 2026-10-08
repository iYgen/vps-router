<?php

namespace Tests;

use App\LocalSystem;
use PHPUnit\Framework\TestCase;

class Ipv6LeakTest extends TestCase
{
    public function testDetectsGlobalUnicast(): void
    {
        $out = "2: eth0    inet6 2a01:4f8:1c1c:abcd::1/64 scope global \\       valid_lft forever";
        $this->assertSame('2a01:4f8:1c1c:abcd::1', LocalSystem::parseGlobalIpv6($out));
    }

    public function testIgnoresLinkLocalUlaLoopback(): void
    {
        $out = implode("\n", [
            '1: lo    inet6 ::1/128 scope host',
            '2: eth0  inet6 fe80::1/64 scope link',
            '3: eth0  inet6 fd00:dead:beef::1/64 scope global',
        ]);
        $this->assertNull(LocalSystem::parseGlobalIpv6($out));
    }

    public function testEmptyWhenNoIpv6(): void
    {
        $this->assertNull(LocalSystem::parseGlobalIpv6(''));
        $this->assertNull(LocalSystem::parseGlobalIpv6('2: eth0 inet 203.0.113.5/24 scope global'));
    }

    public function testPicksFirstGlobalAmongMany(): void
    {
        $out = implode("\n", [
            '2: eth0 inet6 fe80::5/64 scope link',
            '2: eth0 inet6 2606:4700::1111/64 scope global',
            '2: eth0 inet6 3fff::2/64 scope global',
        ]);
        $this->assertSame('2606:4700::1111', LocalSystem::parseGlobalIpv6($out));
    }
}
