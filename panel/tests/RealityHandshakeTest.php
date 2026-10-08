<?php

namespace Tests;

use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

class RealityHandshakeTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::set('reality_handshake', '');
    }

    public function testDefaultsToSniDomainOn443(): void
    {
        Setting::set('reality_handshake', '');
        $this->assertSame(['server' => 'example.com', 'server_port' => 443], SingboxConfigBuilder::realityHandshake('example.com'));
    }

    public function testLocalFrontOverride(): void
    {
        Setting::set('reality_handshake', '127.0.0.1:8443');
        $this->assertSame(['server' => '127.0.0.1', 'server_port' => 8443], SingboxConfigBuilder::realityHandshake('example.com'));
    }

    public function testGarbageFallsBackToDefault(): void
    {
        Setting::set('reality_handshake', 'rm -rf /; :x');
        $this->assertSame(['server' => 'example.com', 'server_port' => 443], SingboxConfigBuilder::realityHandshake('example.com'));
    }
}
