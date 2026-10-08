<?php

namespace Tests;

use App\ClientPolicyBuilder;
use App\Models\ExitServer;
use App\Models\PolicyProfile;
use App\Models\Rule;
use App\Models\RuleGroup;
use PHPUnit\Framework\TestCase;

class ClientPolicyBuilderTest extends TestCase
{
    private function makeExit(): int
    {
        return ExitServer::create([
            'name' => 'cpb-exit-' . uniqid(),
            'endpoint_host' => 'x.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'wg_peer_pubkey' => 'a',
            'wg_local_privkey' => 'b',
            'wg_local_address' => '10.1.1.1/32',
        ]);
    }

    public function testBuildProducesRuleWithTargetAndVersion(): void
    {
        $exitId = $this->makeExit();
        $groupId = RuleGroup::create('YouTube-' . uniqid(), $exitId);
        Rule::create($groupId, 'domain_suffix', 'youtube.com');

        $profileId = PolicyProfile::create(['name' => 'Build-' . uniqid()]);
        PolicyProfile::addRoute($profileId, $groupId);

        $result = (new ClientPolicyBuilder())->build($profileId);

        $this->assertSame(1, $result['policy']['version']);
        $this->assertCount(1, $result['policy']['rules']);
        $this->assertSame('youtube.com', $result['policy']['rules'][0]['matcher']['value']);
        $this->assertSame('exit_server:' . $exitId, $result['policy']['rules'][0]['action']['target']);
        $this->assertSame([], $result['warnings']);
        $this->assertNotEmpty($result['policy']['hash']);
    }

    public function testGeositeRuleIsSkippedWithWarning(): void
    {
        $exitId = $this->makeExit();
        $groupId = RuleGroup::create('Geo-' . uniqid(), $exitId);
        Rule::create($groupId, 'geosite', 'youtube');

        $profileId = PolicyProfile::create(['name' => 'Geo-' . uniqid()]);
        PolicyProfile::addRoute($profileId, $groupId);

        $result = (new ClientPolicyBuilder())->build($profileId);

        $this->assertCount(0, $result['policy']['rules']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('geosite', $result['warnings'][0]);
    }

    public function testEmptyGroupIsSkippedWithWarning(): void
    {
        $exitId = $this->makeExit();
        $groupId = RuleGroup::create('Empty-' . uniqid(), $exitId);

        $profileId = PolicyProfile::create(['name' => 'Empty-' . uniqid()]);
        PolicyProfile::addRoute($profileId, $groupId);

        $result = (new ClientPolicyBuilder())->build($profileId);

        $this->assertCount(0, $result['policy']['rules']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testGroupWithoutTargetIsSkippedWithWarning(): void
    {
        $groupId = RuleGroup::create('NoTarget-' . uniqid(), null);
        Rule::create($groupId, 'domain_suffix', 'example.com');

        $profileId = PolicyProfile::create(['name' => 'NoTarget-' . uniqid()]);
        PolicyProfile::addRoute($profileId, $groupId);

        $result = (new ClientPolicyBuilder())->build($profileId);

        $this->assertCount(0, $result['policy']['rules']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testMissingProfileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ClientPolicyBuilder())->build(999999);
    }

    public function testPublishIncrementsVersionAcrossCalls(): void
    {
        $exitId = $this->makeExit();
        $groupId = RuleGroup::create('Ver-' . uniqid(), $exitId);
        Rule::create($groupId, 'domain_suffix', 'example.com');

        $profileId = PolicyProfile::create(['name' => 'Ver-' . uniqid()]);
        PolicyProfile::addRoute($profileId, $groupId);

        $builder = new ClientPolicyBuilder();
        $first = $builder->publish($profileId, 'tester');
        $second = $builder->publish($profileId, 'tester');

        $this->assertSame(1, $first['policy']['version']);
        $this->assertSame(2, $second['policy']['version']);
    }
}
