<?php

namespace Tests;

use App\DeviceInbounds;
use App\Models\Setting;
use PHPUnit\Framework\TestCase;

/**
 * Мультиплекс на 443: опциональные VLESS-WS / VLESS-gRPC inbound'ы на loopback
 * без TLS (TLS терминирует фронт). Проверяем генерацию конфига sing-box.
 */
class ConfigMuxTest extends TestCase
{
    private array $clients = [
        ['id' => 1, 'uuid' => '11111111-1111-1111-1111-111111111111'],
        ['id' => 2, 'uuid' => '22222222-2222-2222-2222-222222222222'],
    ];

    protected function tearDown(): void
    {
        foreach (['inbound_mux_ws_enabled', 'inbound_mux_grpc_enabled',
                  'inbound_mux_ws_path', 'inbound_mux_grpc_service',
                  'inbound_mux_ws_port', 'inbound_mux_grpc_port'] as $k) {
            Setting::set($k, '');
        }
    }

    private function tagOf(array $inbounds, string $tag): ?array
    {
        foreach ($inbounds as $i) {
            if (($i['tag'] ?? '') === $tag) {
                return $i;
            }
        }
        return null;
    }

    public function testDisabledByDefault(): void
    {
        $out = DeviceInbounds::singboxInbounds($this->clients);
        $this->assertNull($this->tagOf($out['inbounds'], 'vless-ws-in'));
        $this->assertNull($this->tagOf($out['inbounds'], 'vless-grpc-in'));
    }

    public function testWsInboundLoopbackNoTls(): void
    {
        Setting::set('inbound_mux_ws_enabled', '1');
        Setting::set('inbound_mux_ws_path', '/myws');
        $ws = $this->tagOf(DeviceInbounds::singboxInbounds($this->clients)['inbounds'], 'vless-ws-in');

        $this->assertNotNull($ws);
        $this->assertSame('vless', $ws['type']);
        $this->assertSame('127.0.0.1', $ws['listen'], 'слушает только loopback');
        $this->assertArrayNotHasKey('tls', $ws, 'TLS терминирует фронт, не sing-box');
        $this->assertSame('ws', $ws['transport']['type']);
        $this->assertSame('/myws', $ws['transport']['path']);
        $this->assertSame(DeviceInbounds::MUX_WS_PORT, $ws['listen_port']);
        $this->assertSame(['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'],
            array_column($ws['users'], 'uuid'));
        $this->assertSame(['c1', 'c2'], array_column($ws['users'], 'name'));
    }

    public function testGrpcInbound(): void
    {
        Setting::set('inbound_mux_grpc_enabled', '1');
        Setting::set('inbound_mux_grpc_service', 'mysvc');
        $grpc = $this->tagOf(DeviceInbounds::singboxInbounds($this->clients)['inbounds'], 'vless-grpc-in');

        $this->assertNotNull($grpc);
        $this->assertSame('127.0.0.1', $grpc['listen']);
        $this->assertSame('grpc', $grpc['transport']['type']);
        $this->assertSame('mysvc', $grpc['transport']['service_name']);
    }

    public function testRejectsUnsafePathAndServiceFallback(): void
    {
        Setting::set('inbound_mux_ws_path', 'no-leading-slash; rm -rf');
        Setting::set('inbound_mux_grpc_service', 'bad service!');
        $this->assertSame('/vpnws', DeviceInbounds::muxWsPath());
        $this->assertSame('vpngrpc', DeviceInbounds::muxGrpcService());
    }

    public function testNoInboundsWithoutClients(): void
    {
        Setting::set('inbound_mux_ws_enabled', '1');
        $out = DeviceInbounds::singboxInbounds([]);
        $this->assertSame([], $out['inbounds'], 'без устройств inbound не поднимаем');
    }
}
