<?php

namespace Tests;

use App\DeviceInbounds;
use App\Models\Client;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

class DeviceInboundsTest extends TestCase
{
    private const ALL_ON = [
        'vless' => ['enabled' => true, 'port' => 443],
        'shadowsocks' => ['enabled' => true, 'port' => 9443],
        'trojan' => ['enabled' => true, 'port' => 2053],
        'hysteria2' => ['enabled' => true, 'port' => 8443],
        'wireguard' => ['enabled' => true, 'port' => 38020],
        'amneziawg' => ['enabled' => true, 'port' => 38021],
    ];

    protected function setUp(): void
    {
        Setting::set('reality_listen_ip', '203.0.113.10');
        Setting::set('reality_public_host', '203.0.113.10');
        Setting::set('reality_listen_port', '443');
        Setting::set('reality_server_name', 'www.example.com');
        Setting::set('reality_private_key', 'testkey');
        Setting::set('reality_public_key', 'testpub');
        Setting::set('reality_short_id', 'abcd1234');
    }

    protected function tearDown(): void
    {
        // Остальные тесты рассчитывают на поведение по умолчанию (только VLESS).
        foreach (array_keys(DeviceInbounds::PROTOCOLS) as $p) {
            Setting::set("inbound_{$p}_enabled", $p === 'vless' ? '1' : '0');
        }
    }

    public function testOnlyVlessEnabledByDefault(): void
    {
        $this->assertSame(['vless'], DeviceInbounds::enabled());
    }

    public function testAllProtocolsProduceClientConfigsAndSingboxInbounds(): void
    {
        DeviceInbounds::saveSettings(self::ALL_ON);
        $client = Client::create('Phone-' . uniqid());
        $client = Client::find($client['id']);

        $configs = DeviceInbounds::clientConfigs($client);
        $this->assertSame(array_keys(DeviceInbounds::PROTOCOLS), array_keys($configs));
        $this->assertStringStartsWith('vless://', $configs['vless']['uri']);
        $this->assertStringStartsWith('ss://2022-blake3-aes-128-gcm:', $configs['shadowsocks']['uri']);
        $this->assertStringStartsWith('trojan://', $configs['trojan']['uri']);
        $this->assertStringStartsWith('hysteria2://', $configs['hysteria2']['uri']);
        $this->assertStringContainsString('obfs=salamander', $configs['hysteria2']['uri']);
        $this->assertStringContainsString('Endpoint = 203.0.113.10:38020', $configs['wireguard']['file']);
        $this->assertStringContainsString('Jc = ', $configs['amneziawg']['file']);
        $this->assertStringNotContainsString('Jc = ', $configs['wireguard']['file']);

        $built = (new SingboxConfigBuilder())->build()['config'];
        $types = array_column($built['inbounds'], 'type');
        foreach (['vless', 'shadowsocks', 'trojan', 'hysteria2', 'redirect'] as $t) {
            $this->assertContains($t, $types);
        }
        $this->assertSame('wg-in', $built['endpoints'][0]['tag']);
        $this->assertSame(['action' => 'sniff'], $built['route']['rules'][0]);

        $wg = $built['endpoints'][0];
        $peerKeys = array_column($wg['peers'], 'public_key');
        $this->assertContains($client['wg_public_key'], $peerKeys);

        $awg = DeviceInbounds::amneziaServerConfig(Client::active());
        $this->assertStringContainsString($client['wg_public_key'], $awg);
        $this->assertStringContainsString('ListenPort = 38021', $awg);
        $this->assertContains('8443/udp', DeviceInbounds::openPorts());
        $this->assertContains('9443/tcp', DeviceInbounds::openPorts());
    }

    public function testVlessCanBeDisabledWithoutRealityBlockingBuild(): void
    {
        $values = self::ALL_ON;
        $values['vless']['enabled'] = false;
        DeviceInbounds::saveSettings($values);
        Setting::set('reality_private_key', '');
        Client::create('Laptop-' . uniqid());

        $built = (new SingboxConfigBuilder())->build()['config'];
        $this->assertNotContains('vless', array_column($built['inbounds'], 'type'));
    }

    public function testPortConflictIsRejected(): void
    {
        $values = self::ALL_ON;
        $values['trojan']['port'] = 443; // занят VLESS (tcp)
        $this->expectException(\InvalidArgumentException::class);
        DeviceInbounds::saveSettings($values);
    }

    public function testSamePortDifferentTransportIsAllowed(): void
    {
        $values = self::ALL_ON;
        $values['hysteria2']['port'] = 443; // VLESS — tcp, Hysteria2 — udp
        DeviceInbounds::saveSettings($values);
        $this->assertSame(443, DeviceInbounds::port('hysteria2'));
    }

    public function testBackfillGivesOldClientsCredentials(): void
    {
        $pdo = \App\Database::get();
        $pdo->prepare('INSERT INTO clients (name, uuid) VALUES (?, ?)')->execute(['Old-' . uniqid(), 'uuid-' . uniqid()]);
        $id = (int) $pdo->lastInsertId();

        Client::backfillCredentials();
        $row = Client::find($id);
        $this->assertNotEmpty($row['password']);
        $this->assertSame(16, strlen(base64_decode($row['ss_psk'])));
        $this->assertSame(32, strlen(base64_decode($row['wg_public_key'])));
    }

    public function testTunnelAddressesAreDistinctPerClient(): void
    {
        $this->assertSame('10.66.0.2', DeviceInbounds::tunnelAddress('wireguard', 1));
        $this->assertSame('10.67.1.1', DeviceInbounds::tunnelAddress('amneziawg', 256));
    }
}
