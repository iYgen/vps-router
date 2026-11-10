<?php

namespace Tests;

use App\DeviceInbounds;
use App\Models\Client;
use App\Models\Setting;
use App\Singbox\XrayXhttpBuilder;
use PHPUnit\Framework\TestCase;

class XhttpInboundTest extends TestCase
{
    protected function setUp(): void
    {
        Setting::set('reality_listen_ip', '203.0.113.10');
        Setting::set('reality_public_host', '203.0.113.10');
        Setting::set('reality_server_name', 'www.example.com');
        Setting::set('reality_private_key', 'testkey');
        Setting::set('reality_public_key', 'testpub');
        Setting::set('reality_short_id', 'abcd1234');
    }

    protected function tearDown(): void
    {
        Setting::set('inbound_xhttp_enabled', '0');
        Setting::set('inbound_xhttp_host', '');
    }

    private function enable(bool $xmux = true): array
    {
        DeviceInbounds::saveXhttpSettings(['host' => 'Site.Example.com', 'path' => '', 'xmux' => $xmux ? '1' : '', 'xmux_concurrency' => '16-32']);
        DeviceInbounds::enableProtocol('xhttp');
        $c = Client::create('Xh-' . uniqid());
        return Client::find($c['id']);
    }

    public function testNotReadyWithoutHost(): void
    {
        Setting::set('inbound_xhttp_enabled', '1');
        Setting::set('inbound_xhttp_host', '');
        $this->assertFalse(DeviceInbounds::xhttpReady());
        $this->assertSame('', DeviceInbounds::xhttpEnv());
        $this->assertNull(XrayXhttpBuilder::build([['id' => 1, 'uuid' => 'u']]));
    }

    public function testRejectsBadInput(): void
    {
        foreach ([['host' => 'bad host;'], ['path' => '/a b'], ['path' => 'noslash123'], ['xmux_concurrency' => '1-2-3']] as $bad) {
            try {
                DeviceInbounds::saveXhttpSettings($bad + ['host' => 'site.example.com']);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLinkXrayConfigAndBridgeAgree(): void
    {
        $client = $this->enable();
        $this->assertTrue(DeviceInbounds::xhttpReady());
        $path = DeviceInbounds::xhttpPath();
        $this->assertMatchesRegularExpression('#^/[0-9a-f]{16}$#', $path);
        $this->assertSame("PATH_PREFIX=$path\nLOCAL_PORT=" . DeviceInbounds::XHTTP_LOCAL_PORT . "\n", DeviceInbounds::xhttpEnv());

        $uri = DeviceInbounds::clientConfigs($client)['xhttp']['uri'];
        $this->assertStringStartsWith('vless://' . $client['uuid'] . '@site.example.com:443?', $uri);
        parse_str(parse_url($uri, PHP_URL_QUERY), $q);
        $this->assertSame('xhttp', $q['type']);
        $this->assertSame('tls', $q['security']);
        $this->assertSame('site.example.com', $q['sni']);
        $this->assertSame($path, $q['path']);
        $extra = json_decode($q['extra'], true);
        $this->assertSame('16-32', $extra['xmux']['maxConcurrency']);

        $name = 'c' . $client['id'];
        $xray = XrayXhttpBuilder::build([$client]);
        $in = $xray['inbounds'][0];
        $this->assertSame('127.0.0.1', $in['listen']);
        $this->assertSame('xhttp', $in['streamSettings']['network']);
        $this->assertSame($path, $in['streamSettings']['xhttpSettings']['path']);
        $this->assertSame(['id' => $client['uuid'], 'email' => $name], $in['settings']['clients'][0]);
        $this->assertSame('blackhole', $xray['outbounds'][0]['protocol'], 'unknown user must not leak to a real outbound');
        $sock = $xray['outbounds'][1]['settings']['servers'][0];
        $pass = $sock['users'][0]['pass'];

        $bridge = null;
        foreach (DeviceInbounds::singboxInbounds([$client])['inbounds'] as $ib) {
            if (($ib['tag'] ?? '') === DeviceInbounds::XHTTP_BRIDGE_TAG) {
                $bridge = $ib;
            }
        }
        $this->assertNotNull($bridge);
        $this->assertSame('127.0.0.1', $bridge['listen']);
        $this->assertSame($sock['port'], $bridge['listen_port']);
        $this->assertSame(['username' => $name, 'password' => $pass], $bridge['users'][0]);
    }

    public function testXmuxOffDropsExtra(): void
    {
        $client = $this->enable(false);
        $uri = DeviceInbounds::clientConfigs($client)['xhttp']['uri'];
        $this->assertStringNotContainsString('extra=', $uri);
    }
}
