<?php

namespace Tests;

use App\Database;
use App\Models\Client;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use App\TrafficCollector;
use PHPUnit\Framework\TestCase;

class TrafficCollectorTest extends TestCase
{
    public function testParsesSingboxLogIntoAddressToUserMap(): void
    {
        // Реальный формат журнала sing-box 1.14 (проверено вживую), с ANSI-цветами и без.
        $lines = [
            "INFO[0002] [\e[38;5;153m410873225\e[0m 0ms] inbound/vless[reality-in]: inbound connection from 203.0.113.5:60229",
            'INFO[0002] [410873225 0ms] inbound/vless[reality-in]: [c7] inbound connection to api.ipify.org:443',
            '+0300 2026-09-25 01:00:00 INFO [55 0ms] inbound/trojan[trojan-in]: inbound connection from [2001:db8::1]:4444',
            '+0300 2026-09-25 01:00:00 INFO [55 1ms] inbound/trojan[trojan-in]: [c12] inbound connection to youtube.com:443',
            'INFO [99 0ms] inbound/vless[reality-in]: inbound connection from 198.51.100.9:1000', // без строки с пользователем
            'INFO [1 0ms] outbound/direct[exit-6]: outbound connection to api.ipify.org:443',
        ];

        $map = TrafficCollector::parseLogUsers($lines);

        $this->assertSame(['203.0.113.5:60229' => 'c7', '2001:db8::1:4444' => 'c12'], $map);
    }

    public function testClientKeyResolution(): void
    {
        $addr = ['203.0.113.5:60229' => 'c7'];
        $ips = ['198.51.100.9' => 'c3'];

        $this->assertSame('c7', TrafficCollector::clientKey(['sourceIP' => '203.0.113.5', 'sourcePort' => '60229'], $addr, $ips));
        $this->assertSame('c3', TrafficCollector::clientKey(['sourceIP' => '198.51.100.9', 'sourcePort' => '1'], $addr, $ips));
        // WireGuard/AmneziaWG — по адресу в туннеле, без журнала.
        $this->assertSame('c5', TrafficCollector::clientKey(['sourceIP' => '10.66.0.6', 'sourcePort' => '1'], [], []));
        $this->assertSame('c256', TrafficCollector::clientKey(['sourceIP' => '10.67.1.1', 'sourcePort' => '1'], [], []));
        $this->assertSame('ip:192.0.2.1', TrafficCollector::clientKey(['sourceIP' => '192.0.2.1', 'sourcePort' => '1'], [], []));

        // metadata.user из Clash API — авторитетнее всего: устройство узнаётся по
        // аутентифицированному пользователю инбаунда, даже с незнакомого IP.
        $this->assertSame('c9', TrafficCollector::clientKey(['user' => 'c9', 'sourceIP' => '192.0.2.1', 'sourcePort' => '1'], [], []));
        // user имеет приоритет над журналом/IP-эвристикой.
        $this->assertSame('c9', TrafficCollector::clientKey(['user' => 'c9', 'sourceIP' => '203.0.113.5', 'sourcePort' => '60229'], $addr, $ips));
        // Пустой/чужой формат user — игнорируем, работает прежняя логика.
        $this->assertSame('ip:192.0.2.1', TrafficCollector::clientKey(['user' => '', 'sourceIP' => '192.0.2.1', 'sourcePort' => '1'], [], []));
    }

    public function testSummaryAggregatesPerDeviceAndOutbound(): void
    {
        $client = Client::create('Phone-' . uniqid());
        $key = 'c' . $client['id'];
        $minute = gmdate('Y-m-d H:i');
        $pdo = Database::get();
        $ins = $pdo->prepare('INSERT INTO traffic_minute (minute, client_key, outbound, host, inbound, up, down) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$minute, $key, 'exit-6', 'youtube.com', 'vless/reality-in', 1000, 50000]);
        $ins->execute([$minute, $key, 'direct-rf', 'yandex.ru', 'vless/reality-in', 200, 3000]);
        $pdo->prepare('INSERT OR REPLACE INTO device_presence (client_key, source_ip, inbound, last_seen) VALUES (?, ?, ?, ?)')
            ->execute([$key, '203.0.113.5', 'vless/reality-in', time()]);

        $summary = TrafficCollector::summary(15);
        $device = null;
        foreach ($summary['devices'] as $d) {
            if ($d['key'] === $key) {
                $device = $d;
            }
        }

        $this->assertNotNull($device);
        $this->assertSame($client['name'], $device['name']);
        $this->assertTrue($device['online']);
        $this->assertSame(1200, $device['up']);
        $this->assertSame(53000, $device['down']);
        $this->assertSame('youtube.com', $device['hosts'][0]['host']);
        $this->assertGreaterThanOrEqual(50000, $summary['by_outbound']['exit-6']['down']);
    }

    public function testConfigHasLocalClashApiAndOptionalAdblock(): void
    {
        foreach (['reality_listen_ip' => '203.0.113.10', 'reality_server_name' => 'www.example.com', 'reality_private_key' => 'k', 'reality_short_id' => 'ab'] as $k => $v) {
            if (!Setting::get($k)) {
                Setting::set($k, $v);
            }
        }

        Setting::set('block_quic', '0'); // чтобы не смещал индексы правил в проверке ниже
        Setting::set('smart_dns', '0');  // hijack-dns тоже смещает индексы — отключаем для детерминизма
        Setting::set('adblock_enabled', '0');
        $config = (new SingboxConfigBuilder())->build()['config'];
        $this->assertSame(TrafficCollector::CLASH_LISTEN, $config['experimental']['clash_api']['external_controller']);
        $this->assertStringStartsWith('127.0.0.1:', $config['experimental']['clash_api']['external_controller']);
        $this->assertNotContains(['rule_set' => ['geosite-category-ads-all'], 'action' => 'reject'], $config['route']['rules']);

        Setting::set('adblock_enabled', '1');
        $config = (new SingboxConfigBuilder())->build()['config'];
        Setting::set('adblock_enabled', '0');
        $this->assertSame(['action' => 'sniff'], $config['route']['rules'][0]);
        $this->assertSame(['rule_set' => ['geosite-category-ads-all'], 'action' => 'reject'], $config['route']['rules'][1]);
        $this->assertContains('geosite-category-ads-all', array_column($config['route']['rule_set'], 'tag'));
    }
}
