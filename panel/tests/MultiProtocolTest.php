<?php

namespace Tests;

use App\ExitServerFactory;
use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

class MultiProtocolTest extends TestCase
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

    public function testWireguardOutboundIsNativeNotBindInterface(): void
    {
        $id = ExitServer::create([
            'name' => 'wg-native-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
            'protocol_params' => [
                'peer_pubkey' => 'serverpub',
                'local_privkey' => 'clientpriv',
                'local_address' => '10.90.5.2/32',
                'mtu' => 1420,
            ],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $tag = SingboxConfigBuilder::exitOutboundTag($id);

        // С sing-box 1.11+ WireGuard — отдельная сущность "endpoint", а не
        // outbound; endpoint и outbound делят пространство тегов в route,
        // поэтому отдельного outbound-обёртки для wireguard нет вообще
        // (проверено вживую: "direct" outbound с полем endpoint сам
        // sing-box 1.14 отклоняет как неизвестное поле).
        foreach ($built['config']['outbounds'] as $ob) {
            $this->assertNotSame($tag, $ob['tag'] ?? null, 'для wireguard не должно быть outbound с тегом exit-N');
        }

        $endpoint = null;
        foreach ($built['config']['endpoints'] as $e) {
            if ($e['tag'] === $tag) {
                $endpoint = $e;
            }
        }
        $this->assertNotNull($endpoint, 'wireguard endpoint должен быть в config.endpoints с тегом exit-N');
        $this->assertSame('wireguard', $endpoint['type']);
        $this->assertArrayNotHasKey('bind_interface', $endpoint);
        $this->assertSame('clientpriv', $endpoint['private_key']);
        $this->assertSame(['10.90.5.2/32'], $endpoint['address']);
        $this->assertSame('serverpub', $endpoint['peers'][0]['public_key']);
        $this->assertSame('wg.example.com', $endpoint['peers'][0]['address']);
        $this->assertSame(51820, $endpoint['peers'][0]['port']);
    }

    public function testVlessRealityOutbound(): void
    {
        $id = ExitServer::create([
            'name' => 'vless-' . uniqid(),
            'endpoint_host' => 'vless.example.com',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'vless',
            'protocol_params' => [
                'uuid' => 'uuid-1234',
                'flow' => 'xtls-rprx-vision',
                'transport' => 'reality',
                'reality' => ['public_key' => 'pub123', 'short_id' => 'ab', 'server_name' => 'www.microsoft.com'],
            ],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('vless', $outbound['type']);
        $this->assertSame('uuid-1234', $outbound['uuid']);
        $this->assertTrue($outbound['tls']['reality']['enabled']);
        $this->assertSame('pub123', $outbound['tls']['reality']['public_key']);
        $this->assertTrue($outbound['tls']['utls']['enabled'], 'sing-box 1.14 требует uTLS для Reality-клиента');
    }

    public function testShadowsocksOutbound(): void
    {
        $id = ExitServer::create([
            'name' => 'ss-' . uniqid(),
            'endpoint_host' => 'ss.example.com',
            'endpoint_port' => 8388,
            'status' => 'active',
            'protocol' => 'shadowsocks',
            'protocol_params' => ['method' => 'chacha20-ietf-poly1305', 'password' => 'secretpw'],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('shadowsocks', $outbound['type']);
        $this->assertSame('secretpw', $outbound['password']);
    }

    public function testHysteria2OutboundWithObfs(): void
    {
        $id = ExitServer::create([
            'name' => 'hy2-' . uniqid(),
            'endpoint_host' => 'hy2.example.com',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'hysteria2',
            'protocol_params' => ['domain' => 'hy2.example.com', 'password' => 'pw1', 'obfs_password' => 'salamander-pw'],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('hysteria2', $outbound['type']);
        $this->assertSame('pw1', $outbound['password']);
        $this->assertSame('salamander', $outbound['obfs']['type']);
        $this->assertSame('salamander-pw', $outbound['obfs']['password']);
        $this->assertSame('hy2.example.com', $outbound['tls']['server_name']);
    }

    public function testTuicOutbound(): void
    {
        $id = ExitServer::create([
            'name' => 'tuic-' . uniqid(),
            'endpoint_host' => 'tuic.example.com',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'tuic',
            'protocol_params' => ['domain' => 'tuic.example.com', 'uuid' => 'u-1', 'password' => 'pw2', 'congestion_control' => 'bbr'],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('tuic', $outbound['type']);
        $this->assertSame('u-1', $outbound['uuid']);
        $this->assertSame('bbr', $outbound['congestion_control']);
    }

    public function testTrojanOutbound(): void
    {
        $id = ExitServer::create([
            'name' => 'trojan-' . uniqid(),
            'endpoint_host' => 'trojan.example.com',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'trojan',
            'protocol_params' => ['domain' => 'trojan.example.com', 'password' => 'pw3'],
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('trojan', $outbound['type']);
        $this->assertSame('pw3', $outbound['password']);
    }

    public function testExitServerFactoryRejectsHysteria2WithoutDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $target = ['name' => 'X-' . uniqid(), 'host' => 'x.example.com'];
        ExitServerFactory::fromRequest('hysteria2', ['hysteria2' => []], $target);
    }

    public function testAmneziawgStillUsesBindInterface(): void
    {
        $id = ExitServer::create([
            'name' => 'awg-' . uniqid(),
            'endpoint_host' => 'awg.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.11/32',
            'interface_name' => 'awg-mpt-' . random_int(1000, 9999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = $this->findOutbound($built, SingboxConfigBuilder::exitOutboundTag($id));

        $this->assertSame('direct', $outbound['type']);
        $this->assertArrayHasKey('bind_interface', $outbound);
    }

    /** Ключевой материал не обязателен при создании — соединение создаётся
     *  "черновиком", реальные ключи появляются после провижининга. */
    public function testExitServerFactoryAllowsDraftWireguardWithoutKeys(): void
    {
        $target = ['name' => 'X-' . uniqid(), 'host' => 'x.example.com'];
        $id = ExitServerFactory::fromRequest('wireguard', ['wireguard' => []], $target);

        $es = ExitServer::find($id);
        $this->assertSame('wireguard', $es['protocol']);
        $params = json_decode($es['protocol_params'], true);
        $this->assertSame('', $params['peer_pubkey']);
    }

    public function testExitServerFactoryRejectsUnknownVlessTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $target = ['name' => 'X-' . uniqid(), 'host' => 'x.example.com'];
        ExitServerFactory::fromRequest('vless', [
            'vless' => ['uuid' => 'u', 'transport' => 'quic-not-supported'],
        ], $target);
    }

    public function testExitServerFactoryCreatesShadowsocks(): void
    {
        $target = ['name' => 'SS target-' . uniqid(), 'host' => 'ss.example.com'];
        $id = ExitServerFactory::fromRequest('shadowsocks', [
            'shadowsocks' => ['method' => 'aes-256-gcm', 'password' => 'pw123'],
        ], $target);

        $es = ExitServer::find($id);
        $this->assertSame('shadowsocks', $es['protocol']);
        $params = json_decode($es['protocol_params'], true);
        $this->assertSame('pw123', $params['password']);
    }

    /** connections.php не должен генерировать awg-quick файл для не-amneziawg протоколов. */
    public function testAmneziaBuilderSkipsNonAmneziawgProtocols(): void
    {
        ExitServer::create([
            'name' => 'skip-me-' . uniqid(),
            'endpoint_host' => 'x.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
            'protocol_params' => ['peer_pubkey' => 'a', 'local_privkey' => 'b', 'local_address' => '10.1.1.1/32'],
        ]);

        $confs = (new \App\AmneziaConfigBuilder())->buildAll();
        foreach ($confs as $content) {
            $this->assertStringNotContainsString('skip-me', $content);
        }
    }

    /** Черновик (без ключей) на включённом route должен ловиться validate() ДО Apply, а не падать криптическим decode-error в sing-box. */
    public function testValidateFlagsDraftExitServerOnEnabledRoute(): void
    {
        $target = ['name' => 'Draft target-' . uniqid(), 'host' => 'draft.example.com'];
        $exitId = ExitServerFactory::fromRequest('wireguard', ['wireguard' => []], $target);

        $domain = 'draftroute' . uniqid() . '.example';
        $groupId = RuleGroup::create($domain, $exitId);
        Rule::create($groupId, 'domain_suffix', $domain);

        $issues = (new SingboxConfigBuilder())->validate();

        $found = false;
        foreach ($issues as $issue) {
            if ($issue['level'] === 'error' && str_contains($issue['message'], 'WireGuard-ключи ещё не сгенерированы')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'validate() должен предупредить о непровизионированном exit-сервере на включённом route');
    }

    private function findOutbound(array $built, string $tag): array
    {
        foreach ($built['config']['outbounds'] as $ob) {
            if ($ob['tag'] === $tag) {
                return $ob;
            }
        }
        $this->fail("outbound с тегом $tag не найден");
    }
}
