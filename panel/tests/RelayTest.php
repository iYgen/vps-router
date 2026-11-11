<?php

namespace Tests;

use App\DeviceInbounds;
use App\Models\Client;
use App\Models\Connection;
use App\Models\Server;
use App\Models\Setting;
use App\Relay;
use PHPUnit\Framework\TestCase;

class RelayTest extends TestCase
{
    private array $connIds = [];
    private array $serverIds = [];

    protected function setUp(): void
    {
        Setting::set('reality_listen_ip', '203.0.113.10');
        Setting::set('reality_public_host', '203.0.113.10');
        Setting::set('reality_listen_port', '2222');
        Setting::set('reality_server_name', 'www.example.com');
        Setting::set('reality_private_key', 'testkey');
        Setting::set('reality_public_key', 'testpub');
        Setting::set('reality_short_id', 'abcd1234');
    }

    protected function tearDown(): void
    {
        foreach ($this->connIds as $id) {
            Connection::delete($id);
        }
        foreach ($this->serverIds as $id) {
            Server::delete($id);
        }
        Setting::set('inbound_xhttp_enabled', '0');
        Setting::set('inbound_xhttp_host', '');
        Setting::set('inbound_wireguard_enabled', '0');
    }

    private function selfRouter(): array
    {
        $self = Server::self();
        $this->assertNotNull($self, 'test DB must have the self node');
        return $self;
    }

    private function relay(string $host, bool $provisioned = true): int
    {
        $sid = Server::create([
            'name' => 'relay-' . uniqid(), 'role' => 'relay', 'host' => $host,
            'ssh_port' => 22, 'ssh_user' => 'root', 'enabled' => 1,
        ]);
        $this->serverIds[] = $sid;
        $cid = Connection::create($sid, (int) $this->selfRouter()['id'], 'relay', null, null, null);
        $this->connIds[] = $cid;
        if ($provisioned) {
            Connection::setConfig($cid, ['provision_status' => 'provisioned', 'ports' => [], 'target_ip' => '203.0.113.10']);
        }
        return $cid;
    }

    public function testParseConfigKey(): void
    {
        $this->assertSame(['vless', null], DeviceInbounds::parseConfigKey('vless'));
        $this->assertSame(['xhttp', 12], DeviceInbounds::parseConfigKey('xhttp@12'));
        $this->assertNull(DeviceInbounds::parseConfigKey('nope'));
        $this->assertNull(DeviceInbounds::parseConfigKey('vless@x'));
        $this->assertNull(DeviceInbounds::parseConfigKey('../vless'));
    }

    public function testLinksViaRelayKeepKeysAndSwapOnlyAddress(): void
    {
        DeviceInbounds::saveXhttpSettings(['host' => 'site.example.com', 'xmux' => '1', 'xmux_concurrency' => '16-32']);
        DeviceInbounds::enableProtocol('xhttp');
        DeviceInbounds::enableProtocol('wireguard');
        $cid = $this->relay('198.51.100.7');
        $this->relay('198.51.100.8', false); // не настроен — ссылок через него нет
        $c = Client::create('Ph-' . uniqid());
        $client = Client::find($c['id']);

        $all = DeviceInbounds::allConfigs($client);
        $this->assertArrayHasKey('vless', $all);
        $this->assertArrayHasKey("vless@$cid", $all);
        $this->assertArrayHasKey("xhttp@$cid", $all);
        $this->assertArrayHasKey("wireguard@$cid", $all);
        $this->assertSame(1, count(array_filter(array_keys($all), fn($k) => str_starts_with($k, 'vless@'))));

        $direct = $all['vless']['uri'];
        $via = $all["vless@$cid"]['uri'];
        $this->assertStringContainsString('@203.0.113.10:2222?', $direct);
        $this->assertStringContainsString('@198.51.100.7:2222?', $via);
        // всё, кроме адреса и имени, совпадает
        $strip = fn($u) => preg_replace(['/@[^:]+:/', '/#.*$/'], ['@H:', ''], $u);
        $this->assertSame($strip($direct), $strip($via));

        $xh = $all["xhttp@$cid"]['uri'];
        $this->assertStringStartsWith('vless://' . $client['uuid'] . '@198.51.100.7:443?', $xh);
        $this->assertStringContainsString('sni=site.example.com', $xh);
        $this->assertStringContainsString('Endpoint = 198.51.100.7:', $all["wireguard@$cid"]['file']);
        $this->assertLessThanOrEqual(20, strlen($all["wireguard@$cid"]['filename']));
    }

    public function testPortsAndTarget(): void
    {
        DeviceInbounds::saveXhttpSettings(['host' => 'site.example.com']);
        DeviceInbounds::enableProtocol('xhttp');
        $self = $this->selfRouter();
        $ports = Relay::ports($self);
        $this->assertContains('2222/tcp', $ports);
        $this->assertContains('443/tcp', $ports);
        $this->assertSame('203.0.113.10', Relay::targetIp($self));
    }

    public function testSubTokenIsStableAndPerDevice(): void
    {
        $a = Client::find(Client::create('A-' . uniqid())['id']);
        $b = Client::find(Client::create('B-' . uniqid())['id']);
        $this->assertSame(DeviceInbounds::subToken($a), DeviceInbounds::subToken($a));
        $this->assertNotSame(DeviceInbounds::subToken($a), DeviceInbounds::subToken($b));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', DeviceInbounds::subToken($a));
    }

    public function testRelayScriptShipped(): void
    {
        $this->assertFileExists(\App\Provisioner::scriptsDir() . '/' . Relay::SCRIPT);
    }
}
