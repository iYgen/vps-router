<?php

namespace Tests;

use App\Database;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\Setting;
use App\PanelBackup;
use PHPUnit\Framework\TestCase;

class PanelBackupTest extends TestCase
{
    public function testExportStructure(): void
    {
        Setting::set('backup_probe', 'hello');
        $data = PanelBackup::export();
        $this->assertSame('vps_router.settings', $data['format']);
        $this->assertArrayHasKey('app_secret', $data);
        $this->assertArrayHasKey('server_settings', $data['tables']);
        $this->assertArrayHasKey('servers', $data['tables']);
    }

    public function testRoundTripRestoresData(): void
    {
        // Seed distinctive data.
        $sid = Server::create(['name' => 'BackupSrv-' . uniqid(), 'role' => 'exit', 'host' => 'b.example.com']);
        $gid = RuleGroup::create('bkp-route-' . uniqid(), null);
        Setting::set('backup_marker', 'v-' . $sid);

        $dump = PanelBackup::export();
        $this->assertNotEmpty($dump['tables']['servers']);

        // Wipe those tables, then restore.
        Database::get()->exec('PRAGMA foreign_keys = OFF');
        Database::get()->exec('DELETE FROM servers');
        Database::get()->exec('DELETE FROM rule_groups');
        Setting::set('backup_marker', 'WIPED');
        Database::get()->exec('PRAGMA foreign_keys = ON');
        $this->assertNull(Server::find($sid));

        $res = PanelBackup::import($dump);
        $this->assertArrayHasKey('servers', $res['restored']);

        // Data is back.
        $this->assertNotNull(Server::find($sid));
        $this->assertSame('v-' . $sid, Setting::get('backup_marker'));
        $this->assertNotNull(RuleGroup::find($gid));
    }

    public function testImportRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PanelBackup::import(['not' => 'a backup']);
    }
}
