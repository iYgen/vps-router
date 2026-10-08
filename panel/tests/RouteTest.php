<?php

namespace Tests;

use App\Models\Rule;
use App\Models\RuleGroup;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    public function testCreateRouteWithRule(): void
    {
        $groupId = RuleGroup::create('Route-' . uniqid(), null, null);
        Rule::create($groupId, 'domain_suffix', 'example.com');

        $rules = Rule::forGroup($groupId);
        $this->assertCount(1, $rules);
        $this->assertSame('domain_suffix', $rules[0]['type']);
    }

    public function testBulkStyleCreateOneGroupPerDestination(): void
    {
        $destinations = ['a' . uniqid() . '.com', 'b' . uniqid() . '.com', 'c' . uniqid() . '.com'];
        $ids = [];
        foreach ($destinations as $dest) {
            $gid = RuleGroup::create($dest, null, null);
            Rule::create($gid, 'domain_suffix', $dest);
            $ids[] = $gid;
        }

        $this->assertCount(3, array_unique($ids));
        foreach ($ids as $gid) {
            $this->assertCount(1, Rule::forGroup($gid));
        }
    }

    public function testRejectsUnknownRuleType(): void
    {
        $groupId = RuleGroup::create('Route-' . uniqid(), null, null);
        $this->expectException(\InvalidArgumentException::class);
        Rule::create($groupId, 'not-a-real-type', 'value');
    }

    public function testSetServerSetClearsExitServer(): void
    {
        $groupId = RuleGroup::create('Route-' . uniqid(), null, null);
        RuleGroup::setExitServer($groupId, null);
        $setId = \App\Models\ServerSet::create(['name' => 'SetFor-' . uniqid()]);
        RuleGroup::setServerSet($groupId, $setId);

        $group = RuleGroup::find($groupId);
        $this->assertSame($setId, (int) $group['server_set_id']);
        $this->assertNull($group['exit_server_id']);
    }

    public function testDeleteRoute(): void
    {
        $groupId = RuleGroup::create('ToDelete-' . uniqid(), null, null);
        RuleGroup::delete($groupId);
        $this->assertNull(RuleGroup::find($groupId));
    }
}
