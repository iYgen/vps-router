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
        $this->assertSame(array_keys(self::ALL_ON), array_keys($configs)); // xhttp без домена не готов
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

    public function testAppOutboundsForSingboxProtocols(): void
    {
        DeviceInbounds::saveSettings(self::ALL_ON);
        $client = Client::find(Client::create('App-' . uniqid())['id']);
        $configs = DeviceInbounds::clientConfigs($client);

        foreach (['vless', 'shadowsocks', 'trojan', 'hysteria2'] as $p) {
            $o = $configs[$p]['outbound'] ?? null;
            $this->assertIsArray($o, $p);
            $this->assertSame($p, $o['type']);
            $this->assertSame('203.0.113.10', $o['server']);
            $this->assertSame(self::ALL_ON[$p]['port'], $o['server_port']);
        }
        $this->assertSame($client['uuid'], $configs['vless']['outbound']['uuid']);
        $this->assertSame('testpub', $configs['vless']['outbound']['tls']['reality']['public_key']);
        $this->assertSame('salamander', $configs['hysteria2']['outbound']['obfs']['type']);
        // Самоподписанный сертификат закреплён по ключу, а не «insecure».
        foreach (['trojan', 'hysteria2'] as $p) {
            $tls = $configs[$p]['outbound']['tls'];
            $this->assertArrayNotHasKey('insecure', $tls, $p);
            $this->assertSame([DeviceInbounds::spkiPin()], $tls['certificate_public_key_sha256'], $p);
            $this->assertSame(32, strlen(base64_decode($tls['certificate_public_key_sha256'][0])));
        }
        $this->assertArrayNotHasKey('outbound', $configs['wireguard']);
        $this->assertArrayNotHasKey('outbound', $configs['amneziawg']);

        // DUMP_OUTBOUNDS=<каталог> — выгрузить для проверки ядром: sing-box check -c <файл>
        if ($dir = getenv('DUMP_OUTBOUNDS')) {
            foreach (['vless', 'shadowsocks', 'trojan', 'hysteria2'] as $p) {
                $cfg = ['outbounds' => [$configs[$p]['outbound'] + ['tag' => 'proxy']]];
                file_put_contents("$dir/$p.json", json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            file_put_contents("$dir/tls.crt", Setting::get('inbound_tls_cert'));
            file_put_contents("$dir/tls.key", Setting::get('inbound_tls_key'));
            file_put_contents("$dir/trojan.pass", $client['password']);
        }
    }

    public function testXhttpOutboundForXrayCore(): void
    {
        DeviceInbounds::saveSettings(self::ALL_ON + ['xhttp' => ['enabled' => true, 'port' => 443]]);
        Setting::set('inbound_xhttp_host', 'site.example.com');
        Setting::set('inbound_xhttp_path', '/abcdef12');
        try {
            $client = Client::find(Client::create('X-' . uniqid())['id']);
            $o = DeviceInbounds::clientConfigs($client)['xhttp']['outbound'] ?? null;
            $this->assertIsArray($o);
            $this->assertSame('vless', $o['protocol']);
            $this->assertArrayNotHasKey('type', $o); // признак Xray-формата для приложения
            $this->assertSame('site.example.com', $o['settings']['vnext'][0]['address']);
            $this->assertSame($client['uuid'], $o['settings']['vnext'][0]['users'][0]['id']);
            $this->assertSame('xhttp', $o['streamSettings']['network']);
            $this->assertSame('/abcdef12', $o['streamSettings']['xhttpSettings']['path']);
            $this->assertSame('site.example.com', $o['streamSettings']['tlsSettings']['serverName']);
            if ($dir = getenv('DUMP_OUTBOUNDS')) {
                file_put_contents("$dir/xhttp.json", json_encode($o, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        } finally {
            Setting::set('inbound_xhttp_host', '');
            Setting::set('inbound_xhttp_path', '');
        }
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
