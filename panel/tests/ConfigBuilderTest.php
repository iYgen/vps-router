<?php

namespace Tests;

use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\ServerSet;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

class ConfigBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        // Reality обязателен для build(), иначе он выбрасывает исключение.
        if (!Setting::get('reality_listen_ip')) {
            Setting::set('reality_listen_ip', '203.0.113.10');
            Setting::set('reality_server_name', 'www.example.com');
            Setting::set('reality_private_key', 'testkey');
            Setting::set('reality_short_id', 'abcd1234');
        }
    }

    public function testServerSetFailoverResolvesToExitOutbound(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'Exit-' . uniqid(), 'role' => 'exit']);

        $exitServerId = ExitServer::create([
            'name' => 'wg-' . uniqid(),
            'endpoint_host' => 'de.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.10/32',
            'interface_name' => 'awg-cbt-' . random_int(1000, 9999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, null);

        $setId = ServerSet::create(['name' => 'CBSet-' . uniqid(), 'strategy' => 'failover']);
        ServerSet::addMember($setId, $exitNode, 0);

        $domain = 'cbtest' . uniqid() . '.example';
        $groupId = RuleGroup::create($domain, null, $setId);
        Rule::create($groupId, 'domain_suffix', $domain);

        $built = (new SingboxConfigBuilder())->build();

        $tags = array_column($built['config']['outbounds'], 'tag');
        $this->assertContains(SingboxConfigBuilder::exitOutboundTag($exitServerId), $tags);

        $matchingRule = null;
        foreach ($built['config']['route']['rules'] as $rule) {
            if (in_array('group-' . $groupId, $rule['rule_set'] ?? [], true)) {
                $matchingRule = $rule;
            }
        }
        $this->assertNotNull($matchingRule, 'правило для нового route должно попасть в конфиг');
        $this->assertSame(SingboxConfigBuilder::exitOutboundTag($exitServerId), $matchingRule['outbound']);
    }

    public function testEmptyServerSetFallsBackToDirect(): void
    {
        $setId = ServerSet::create(['name' => 'EmptySet-' . uniqid()]);
        $domain = 'emptyset' . uniqid() . '.example';
        $groupId = RuleGroup::create($domain, null, $setId);
        Rule::create($groupId, 'domain_suffix', $domain);

        $built = (new SingboxConfigBuilder())->build();

        $matchingRule = null;
        foreach ($built['config']['route']['rules'] as $rule) {
            if (in_array('group-' . $groupId, $rule['rule_set'] ?? [], true)) {
                $matchingRule = $rule;
            }
        }
        $this->assertNotNull($matchingRule);
        $this->assertSame('direct-rf', $matchingRule['outbound']);
    }

    public function testSmartDnsResolvesExitDomainsViaExit(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'Exit-' . uniqid(), 'role' => 'exit']);
        $exitServerId = ExitServer::create([
            'name' => 'wg-dns-' . uniqid(),
            'endpoint_host' => 'de.example.com', 'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub', 'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv', 'wg_local_address' => '10.90.0.11/32',
            'interface_name' => 'awg-dns-' . random_int(1000, 9999),
            'amnezia_params' => null, 'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, null);
        $setId = ServerSet::create(['name' => 'DnsSet-' . uniqid(), 'strategy' => 'failover']);
        ServerSet::addMember($setId, $exitNode, 0);
        $domain = 'dnsroute' . uniqid() . '.example';
        $groupId = RuleGroup::create($domain, null, $setId);
        Rule::create($groupId, 'domain_suffix', $domain);

        Setting::set('smart_dns', '1');
        $built = (new SingboxConfigBuilder())->build();
        $exitTag = SingboxConfigBuilder::exitOutboundTag($exitServerId);

        // Есть DNS-секция и hijack-dns правило.
        $this->assertArrayHasKey('dns', $built['config']);
        $hijack = false;
        foreach ($built['config']['route']['rules'] as $r) {
            if (($r['action'] ?? null) === 'hijack-dns') {
                $hijack = true;
            }
        }
        $this->assertTrue($hijack, 'должно быть правило hijack-dns');

        // Есть DoH-сервер через exit и DNS-правило, ссылающееся на него.
        $serverTags = array_column($built['config']['dns']['servers'], 'tag');
        $exitDnsTag = null;
        foreach ($built['config']['dns']['servers'] as $s) {
            if (($s['detour'] ?? null) === $exitTag && ($s['type'] ?? null) === 'https') {
                $exitDnsTag = $s['tag'];
            }
        }
        $this->assertNotNull($exitDnsTag, 'нужен DoH-сервер с detour через exit');
        $this->assertContains('dns-local', $serverTags);

        $found = false;
        foreach ($built['config']['dns']['rules'] as $r) {
            if (($r['server'] ?? null) === $exitDnsTag) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'DNS-правило должно резолвить домен через exit');
    }

    public function testSmartDnsDisabledOmitsDnsSection(): void
    {
        Setting::set('smart_dns', '0');
        $built = (new SingboxConfigBuilder())->build();
        $this->assertArrayNotHasKey('dns', $built['config']);
        foreach ($built['config']['route']['rules'] as $r) {
            $this->assertNotSame('hijack-dns', $r['action'] ?? null);
        }
        Setting::set('smart_dns', '1');
    }

    public function testListFetchInboundAddedWhenEnabledAndExitExists(): void
    {
        $router = Server::create(['name' => 'lf-r-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'lf-e-' . uniqid(), 'role' => 'exit']);
        $exitId = ExitServer::create([
            'name' => 'lf-' . uniqid(), 'endpoint_host' => 'de.example.com', 'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'p', 'wg_local_privkey' => 'l', 'wg_local_address' => '10.90.0.20/32',
            'interface_name' => 'awg-lf-' . random_int(1000, 9999), 'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitId, null, null);

        Setting::set('list_fetch_via_exit', '0');
        $off = (new SingboxConfigBuilder())->build()['config'];
        $this->assertNotContains('list-fetch-in', array_column($off['inbounds'], 'tag'));

        Setting::set('list_fetch_via_exit', '1');
        $on = (new SingboxConfigBuilder())->build()['config'];
        Setting::set('list_fetch_via_exit', '0');
        $this->assertContains('list-fetch-in', array_column($on['inbounds'], 'tag'));
        $ruleHit = false;
        foreach ($on['route']['rules'] as $r) {
            if (in_array('list-fetch-in', $r['inbound'] ?? [], true)) {
                $ruleHit = true;
                // exitTags[0] — первый exit глобально; проверяем лишь, что это exit-*.
                $this->assertStringStartsWith('exit-', (string) $r['outbound']);
            }
        }
        $this->assertTrue($ruleHit, 'должно быть правило list-fetch-in -> exit');
    }

    public function testValidateFlagsEmptyEnabledServerSet(): void
    {
        $setId = ServerSet::create(['name' => 'ValidateEmpty-' . uniqid(), 'enabled' => 1]);
        $set = ServerSet::find($setId);
        $issues = (new SingboxConfigBuilder())->validate();

        $found = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], $set['name'])) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'validate() должен предупредить о пустом включённом server set');
    }
}
