<?php

namespace Tests;

use App\Models\ExitServer;
use App\Models\PolicyProfile;
use App\Models\RuleGroup;
use PHPUnit\Framework\TestCase;

class PolicyProfileTest extends TestCase
{
    public function testCreateFindUpdateDelete(): void
    {
        $id = PolicyProfile::create(['name' => 'Personal-' . uniqid(), 'default_action' => 'direct_local']);
        $profile = PolicyProfile::find($id);
        $this->assertSame('direct_local', $profile['default_action']);

        PolicyProfile::update($id, ['name' => $profile['name'], 'default_action' => 'proxy']);
        $this->assertSame('proxy', PolicyProfile::find($id)['default_action']);

        PolicyProfile::delete($id);
        $this->assertNull(PolicyProfile::find($id));
    }

    public function testRejectsUnknownDefaultAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PolicyProfile::create(['name' => 'Bad-' . uniqid(), 'default_action' => 'teleport']);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PolicyProfile::create(['name' => '']);
    }

    public function testAddRemoveRoute(): void
    {
        $profileId = PolicyProfile::create(['name' => 'RouteTest-' . uniqid()]);
        $exitId = ExitServer::create([
            'name' => 'pp-exit-' . uniqid(), 'endpoint_host' => 'x.example.com', 'endpoint_port' => 51820,
            'status' => 'active', 'wg_peer_pubkey' => 'a', 'wg_local_privkey' => 'b', 'wg_local_address' => '10.1.1.1/32',
        ]);
        $groupId = RuleGroup::create('Group-' . uniqid(), $exitId);

        PolicyProfile::addRoute($profileId, $groupId);
        $routes = PolicyProfile::routes($profileId);
        $this->assertCount(1, $routes);
        $this->assertSame($groupId, (int) $routes[0]['id']);

        // Повторное добавление — не дублирует (PK на profile_id+rule_group_id).
        PolicyProfile::addRoute($profileId, $groupId);
        $this->assertCount(1, PolicyProfile::routes($profileId));

        PolicyProfile::removeRoute($profileId, $groupId);
        $this->assertCount(0, PolicyProfile::routes($profileId));
    }
}
