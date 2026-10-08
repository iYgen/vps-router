<?php

namespace Tests;

use App\Applier;
use App\Models\Client;
use App\Models\NodeSetting;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Multi-router (variant 3), Phase 1: devices and route groups can be scoped to a
 * specific entry-router, and each router has its own settings with a fallback to
 * the global store. These lock the data foundation before the config builder is
 * parameterised by node.
 */
class MultiRouterTest extends TestCase
{
    private function makeRouter(): int
    {
        return Server::create(['name' => 'router-' . uniqid(), 'role' => 'router', 'host' => 'r.example.com']);
    }

    public function testClientsScopedByRouter(): void
    {
        $a = $this->makeRouter();
        $b = $this->makeRouter();
        Client::create('dev-a-' . uniqid(), $a);
        Client::create('dev-a2-' . uniqid(), $a);
        Client::create('dev-b-' . uniqid(), $b);

        $this->assertCount(2, Client::all($a));
        $this->assertCount(1, Client::all($b));
        $this->assertCount(2, Client::active($a));
        $this->assertCount(1, Client::active($b));

        // Unscoped queries still see every device (backward compatible).
        $this->assertGreaterThanOrEqual(3, count(Client::all()));
    }

    public function testRuleGroupsScopedByRouter(): void
    {
        $a = $this->makeRouter();
        $b = $this->makeRouter();
        RuleGroup::create('grp-a-' . uniqid(), null, null, null, null, 'manual', null, $a);
        RuleGroup::create('grp-b-' . uniqid(), null, null, null, null, 'manual', null, $b);

        $this->assertCount(1, RuleGroup::all($a));
        $this->assertCount(1, RuleGroup::all($b));
        $this->assertGreaterThanOrEqual(2, count(RuleGroup::all()));
    }

    public function testNodeSettingOverrideAndFallback(): void
    {
        $server = $this->makeRouter();
        $other = $this->makeRouter();
        Setting::set('reality_server_name', 'global.example.com');

        // No per-node override → falls back to the global value.
        $this->assertSame('global.example.com', NodeSetting::get($server, 'reality_server_name'));

        // Per-node override wins for that server only.
        NodeSetting::set($server, 'reality_server_name', 'node.example.com');
        $this->assertSame('node.example.com', NodeSetting::get($server, 'reality_server_name'));
        $this->assertSame('global.example.com', NodeSetting::get($other, 'reality_server_name'));

        // Missing key → provided default.
        $this->assertSame('fallback', NodeSetting::get($server, 'no_such_key', 'fallback'));

        $this->assertSame('node.example.com', NodeSetting::allForServer($server)['reality_server_name'] ?? null);
    }

    public function testBuildIsScopedToOneRouter(): void
    {
        // Two independent entry-routers, each with its own device + Reality config.
        $r1 = $this->makeRouter();
        $r2 = $this->makeRouter();

        foreach ([$r1 => 'r1.example.com', $r2 => 'r2.example.com'] as $rid => $sni) {
            NodeSetting::set($rid, 'reality_listen_ip', '203.0.113.1');
            NodeSetting::set($rid, 'reality_server_name', $sni);
            NodeSetting::set($rid, 'reality_private_key', 'pk-' . $rid);
            NodeSetting::set($rid, 'reality_short_id', 'sid' . $rid);
        }

        $dev1 = Client::create('phone-r1', $r1);
        $dev2 = Client::create('phone-r2', $r2);

        $g2 = RuleGroup::create('r2-yt-' . uniqid(), null, null, null, null, 'manual', null, $r2);
        Rule::create($g2, 'domain_suffix', 'youtube.com');

        $built1 = (new SingboxConfigBuilder())->build($r1);
        $built2 = (new SingboxConfigBuilder())->build($r2);

        $uuids1 = $this->realityUuids($built1);
        $uuids2 = $this->realityUuids($built2);

        // Each router sees only its own device.
        $this->assertContains($dev1['uuid'], $uuids1);
        $this->assertNotContains($dev2['uuid'], $uuids1);
        $this->assertContains($dev2['uuid'], $uuids2);
        $this->assertNotContains($dev1['uuid'], $uuids2);

        // Each router uses its own Reality SNI from node_settings.
        $this->assertSame('r1.example.com', $this->realitySni($built1));
        $this->assertSame('r2.example.com', $this->realitySni($built2));

        // The route group created on r2 is present only in r2's config.
        $this->assertContains('group-' . $g2, $this->ruleSetTags($built2));
        $this->assertNotContains('group-' . $g2, $this->ruleSetTags($built1));
    }

    public function testBuildRemoteParamsMatchesRouterConfig(): void
    {
        $r = $this->makeRouter();
        NodeSetting::set($r, 'reality_listen_ip', '203.0.113.5');
        NodeSetting::set($r, 'reality_server_name', 'remote.example.com');
        NodeSetting::set($r, 'reality_private_key', 'pk-remote');
        NodeSetting::set($r, 'reality_short_id', 'sidremote');
        Client::create('remote-phone', $r);

        ['params' => $params, 'built' => $built] = (new Applier())->buildRemoteParams($r);

        foreach (['singbox_config', 'rule_sets', 'amnezia_confs', 'interfaces_list', 'awg_inbound_env', 'open_ports'] as $key) {
            $this->assertArrayHasKey($key, $params);
        }
        // The pushed config is exactly what the builder produces for this router.
        $this->assertSame($built['config'], $params['singbox_config']);
        $this->assertSame('remote.example.com', $params['singbox_config']['inbounds'][0]['tls']['server_name']);
    }

    public function testRouterContextListsRoutersAndScoping(): void
    {
        $r = $this->makeRouter();
        $ids = array_map(fn($x) => (int) $x['id'], \App\RouterContext::routers());
        $this->assertContains($r, $ids);
        $this->assertGreaterThanOrEqual(1, \App\RouterContext::count());
        // A freshly-made non-self router is not self and excludes NULL-legacy rows.
        $this->assertFalse(\App\RouterContext::isSelf($r));
        $this->assertFalse(\App\RouterContext::includeNull($r));
    }

    public function testPendingStatePerRouter(): void
    {
        $r = $this->makeRouter();
        NodeSetting::set($r, 'reality_listen_ip', '203.0.113.9');
        NodeSetting::set($r, 'reality_server_name', 'pend.example.com');
        NodeSetting::set($r, 'reality_private_key', 'pk');
        NodeSetting::set($r, 'reality_short_id', 'sid');
        Client::create('pend-dev', $r);

        // Not applied yet → pending.
        $this->assertTrue(\App\Applier::pendingState($r)['pending']);

        // Store the current fingerprint as this router's applied state → not pending.
        $built = (new SingboxConfigBuilder())->build($r);
        NodeSetting::set($r, 'applied_fingerprint', \App\Applier::fingerprint($built));
        $this->assertFalse(\App\Applier::pendingState($r)['pending']);

        // A change (new device) → pending again.
        Client::create('pend-dev2', $r);
        $this->assertTrue(\App\Applier::pendingState($r)['pending']);
    }

    public function testApplyRemoteRequiresSshKey(): void
    {
        $r = $this->makeRouter(); // no SSH key stored
        NodeSetting::set($r, 'reality_listen_ip', '203.0.113.6');
        NodeSetting::set($r, 'reality_server_name', 'nokey.example.com');
        NodeSetting::set($r, 'reality_private_key', 'pk');
        NodeSetting::set($r, 'reality_short_id', 'sid');

        $this->expectException(\InvalidArgumentException::class);
        (new Applier())->apply('tester', 'remote apply', $r);
    }

    private function realityInbound(array $built): array
    {
        foreach ($built['config']['inbounds'] as $in) {
            if (($in['tag'] ?? '') === 'reality-in') {
                return $in;
            }
        }
        return [];
    }

    private function realityUuids(array $built): array
    {
        return array_column($this->realityInbound($built)['users'] ?? [], 'uuid');
    }

    private function realitySni(array $built): ?string
    {
        return $this->realityInbound($built)['tls']['server_name'] ?? null;
    }

    private function ruleSetTags(array $built): array
    {
        $tags = [];
        foreach ($built['config']['route']['rules'] as $rule) {
            foreach ($rule['rule_set'] ?? [] as $t) {
                $tags[] = $t;
            }
        }
        return $tags;
    }
}
