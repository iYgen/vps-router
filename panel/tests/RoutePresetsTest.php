<?php

namespace Tests;

use App\Models\Rule;
use App\Models\RuleGroup;
use App\RoutePresets;
use PHPUnit\Framework\TestCase;

class RoutePresetsTest extends TestCase
{
    public function testSyncRefreshesPresetGroupGeosite(): void
    {
        // Группа-пресет с УСТАРЕВШИМ geosite + ручное не-geosite правило.
        $gid = RuleGroup::create('YT-' . uniqid(), null, null, 'preset:youtube', null, 'preset', RoutePresets::SOURCE_PREFIX . 'youtube', null);
        Rule::create($gid, 'geosite', 'stale-category');
        Rule::create($gid, 'ip_cidr', '203.0.113.0/24'); // ручное — должно сохраниться

        $res = RoutePresets::sync();
        $this->assertGreaterThanOrEqual(1, $res['presets_updated']);

        $rules = Rule::forGroup($gid);
        $geo = array_values(array_filter(array_map(fn($r) => $r['type'] === 'geosite' ? $r['value'] : null, $rules)));
        $this->assertSame(['youtube'], $geo, 'geosite приведён к каталогу');
        $cidr = array_values(array_filter(array_map(fn($r) => $r['type'] === 'ip_cidr' ? $r['value'] : null, $rules)));
        $this->assertSame(['203.0.113.0/24'], $cidr, 'ручное правило сохранено');

        // Повторная синхронизация — уже без изменений.
        $res2 = RoutePresets::sync();
        $this->assertSame(0, $res2['presets_updated']);
    }

    public function testSyncReportsMissingPreset(): void
    {
        $gid = RuleGroup::create('Gone-' . uniqid(), null, null, null, null, 'preset', RoutePresets::SOURCE_PREFIX . 'nonexistent-xyz', null);
        Rule::create($gid, 'geosite', 'whatever');
        $res = RoutePresets::sync();
        $this->assertContains('nonexistent-xyz', $res['presets_missing']);
        // Правило не тронуто (пресета нет в каталоге).
        $this->assertCount(1, Rule::forGroup($gid));
    }
    public function testAllReturnsPresetsWithGeosite(): void
    {
        $all = RoutePresets::all();
        $this->assertNotEmpty($all);
        foreach ($all as $p) {
            $this->assertArrayHasKey('id', $p);
            $this->assertArrayHasKey('name', $p);
            $this->assertNotEmpty($p['geosite']);
        }
        $ids = array_column($all, 'id');
        $this->assertContains('youtube', $ids);
    }

    public function testGetKnownAndUnknown(): void
    {
        $yt = RoutePresets::get('youtube');
        $this->assertNotNull($yt);
        $this->assertContains('youtube', $yt['geosite']);
        $this->assertSame('YouTube', $yt['name']);

        $this->assertNull(RoutePresets::get('does-not-exist'));
        $this->assertNull(RoutePresets::get(''));
    }
}
