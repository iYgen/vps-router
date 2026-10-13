<?php

namespace Tests;

use App\ExitTunnelHealth;
use App\Models\ExitServer;
use App\Models\Setting;
use App\Singbox\ExitOutboundBuilder;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Резервные порты exit'а + urltest-failover (DPI на пути может начать
 * резать Reality на одном порту, остальные при этом проходят).
 */
class ExitAltPortsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Setting::get('reality_listen_ip')) {
            Setting::set('reality_listen_ip', '203.0.113.10');
            Setting::set('reality_server_name', 'www.example.com');
            Setting::set('reality_private_key', 'testkey');
            Setting::set('reality_short_id', 'abcd1234');
        }
    }

    private function vlessExit(array $extra = []): array
    {
        return [
            'id' => 6,
            'name' => 'exit-a',
            'endpoint_host' => '198.51.100.7',
            'endpoint_port' => 443,
            'protocol' => 'vless',
            'protocol_params' => json_encode($extra + [
                'uuid' => 'u-1',
                'reality' => ['public_key' => 'pub', 'short_id' => 'ab12', 'server_name' => 'cover.example'],
            ]),
        ];
    }

    public function testNoAltPortsKeepsSingleOutbound(): void
    {
        $built = ExitOutboundBuilder::buildAll($this->vlessExit());
        $this->assertCount(1, $built['outbounds']);
        $this->assertSame('exit-6', $built['outbounds'][0]['tag']);
        $this->assertSame('vless', $built['outbounds'][0]['type']);
    }

    public function testAltPortsBuildUrltestGroupUnderExitTag(): void
    {
        $built = ExitOutboundBuilder::buildAll($this->vlessExit(['alt_ports' => [47443, '8444', 443, 0, 47443]]));
        $byTag = array_column($built['outbounds'], null, 'tag');

        // Группа носит прежний тег exit-N — маршруты/DNS менять не нужно, и идёт первой.
        $this->assertSame('exit-6', $built['outbounds'][0]['tag']);
        $this->assertSame('urltest', $byTag['exit-6']['type']);
        // Основной порт первым, дубли/основной/мусор из alt_ports отброшены.
        $this->assertSame(['exit-6~p443', 'exit-6~p47443', 'exit-6~p8444'], $byTag['exit-6']['outbounds']);
        $this->assertSame(47443, $byTag['exit-6~p47443']['server_port']);
        $this->assertSame('vless', $byTag['exit-6~p47443']['type']);
        $this->assertSame('cover.example', $byTag['exit-6~p47443']['tls']['server_name']);
    }

    public function testAltPortsIgnoredForNonVless(): void
    {
        $es = ['protocol' => 'shadowsocks', 'endpoint_port' => 8388, 'protocol_params' => json_encode(['alt_ports' => [9000]])];
        $this->assertSame([], ExitOutboundBuilder::altPorts($es));
    }

    public function testMultiplexOnlyWithoutVisionFlow(): void
    {
        [$plain] = ExitOutboundBuilder::build($this->vlessExit());
        $this->assertSame('h2mux', $plain['multiplex']['protocol'] ?? null);

        [$vision] = ExitOutboundBuilder::build($this->vlessExit(['flow' => 'xtls-rprx-vision']));
        $this->assertArrayNotHasKey('multiplex', $vision);

        Setting::set('exit_multiplex', '0');
        try {
            [$off] = ExitOutboundBuilder::build($this->vlessExit());
            $this->assertArrayNotHasKey('multiplex', $off);
        } finally {
            Setting::set('exit_multiplex', '1');
        }
    }

    public function testPortDeadOnlyAfterConsecutiveFailures(): void
    {
        $dead = [];
        $streaks = [];
        // Две неудачи, удача, ещё две — порт ещё не мёртв (моргание пробы).
        foreach ([null, null, 20, null, null] as $d) {
            [$dead, $streaks] = ExitTunnelHealth::deadPorts([443 => $d, 2053 => 30], $dead, $streaks);
        }
        $this->assertSame([], $dead);

        [$dead, $streaks] = ExitTunnelHealth::deadPorts([443 => null, 2053 => 30], $dead, $streaks);
        $this->assertSame([443], $dead);

        // Одна удача не воскрешает, три подряд — да.
        foreach ([20, 20] as $d) {
            [$dead, $streaks] = ExitTunnelHealth::deadPorts([443 => $d, 2053 => 30], $dead, $streaks);
            $this->assertSame([443], $dead);
        }
        [$dead] = ExitTunnelHealth::deadPorts([443 => 20, 2053 => 30], $dead, $streaks);
        $this->assertSame([], $dead);
    }

    public function testCanonicalTagStripsPortSuffix(): void
    {
        $this->assertSame('exit-6', ExitOutboundBuilder::canonicalTag('exit-6~p47443'));
        $this->assertSame('exit-6', ExitOutboundBuilder::canonicalTag('exit-6'));
        $this->assertSame('direct-rf', ExitOutboundBuilder::canonicalTag('direct-rf'));
    }

    public function testFullConfigContainsGroupAndMembers(): void
    {
        $id = ExitServer::create([
            'name' => 'alt-' . uniqid(),
            'endpoint_host' => '198.51.100.8',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'vless',
            'protocol_params' => [
                'uuid' => 'u-2',
                'alt_ports' => [47443],
                'reality' => ['public_key' => 'pub', 'short_id' => 'ab12', 'server_name' => 'cover.example'],
            ],
        ]);
        $config = (new SingboxConfigBuilder())->build()['config'];
        $tags = array_column($config['outbounds'], 'type', 'tag');
        $this->assertSame('urltest', $tags["exit-$id"] ?? null);
        $this->assertSame('vless', $tags["exit-$id~p443"] ?? null);
        $this->assertSame('vless', $tags["exit-$id~p47443"] ?? null);
    }

    public function testAlertOnDownAfterThresholdAndOnRecovery(): void
    {
        $es = $this->vlessExit();
        $dead = ['ok' => false, 'latency' => null, 'ports' => [443 => null]];
        $up = ['status' => 'up', 'fails' => 0, 'dead' => []];

        $mail = ExitTunnelHealth::transitionMail($es, $up, 'down', [443], $dead);
        $this->assertStringContainsString('НЕ РАБОТАЕТ', $mail['subject']);

        // Пока не набрали DOWN_AFTER неудач — статус прежний, письма нет.
        $this->assertNull(ExitTunnelHealth::transitionMail($es, $up, 'up', [443], ['ok' => true, 'latency' => 20, 'ports' => [443 => 20]]));

        $ok = ['ok' => true, 'latency' => 15, 'ports' => [443 => 15]];
        $mail = ExitTunnelHealth::transitionMail($es, ['status' => 'down', 'fails' => 5, 'dead' => [443]], 'up', [], $ok);
        $this->assertStringContainsString('снова работает', $mail['subject']);
    }

    public function testWarnsWhenSomePortsDieButGroupStillUp(): void
    {
        $es = $this->vlessExit(['alt_ports' => [47443]]);
        $partial = ['ok' => true, 'latency' => 30, 'ports' => [443 => null, 47443 => 30]];
        $mail = ExitTunnelHealth::transitionMail($es, ['status' => 'up', 'fails' => 0, 'dead' => []], 'up', [443], $partial);
        $this->assertStringContainsString('443', $mail['subject']);

        // Тот же набор мёртвых портов — повторно не шлём.
        $this->assertNull(ExitTunnelHealth::transitionMail($es, ['status' => 'up', 'fails' => 0, 'dead' => [443]], 'up', [443], $partial));
    }
}
