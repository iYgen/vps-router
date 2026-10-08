<?php

namespace Tests;

use App\Database;
use App\Models\PolicyDevice;
use PHPUnit\Framework\TestCase;

class PolicyDeviceTest extends TestCase
{
    public function testCreateEncryptsPasswordAndFindDecrypts(): void
    {
        $id = PolicyDevice::create([
            'name' => 'Keenetic-' . uniqid(),
            'rci_host' => 'myrouter.keenetic.link',
            'rci_username' => 'admin',
            'rci_password' => 'SuperSecret123',
        ]);

        $found = PolicyDevice::find($id);
        $this->assertSame('SuperSecret123', $found['rci_password_enc']);

        $stmt = Database::get()->prepare('SELECT rci_password_enc FROM policy_devices WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        $this->assertStringNotContainsString('SuperSecret123', $raw);
    }

    public function testUpdateWithoutPasswordKeepsExisting(): void
    {
        $id = PolicyDevice::create([
            'name' => 'KeepPass-' . uniqid(),
            'rci_username' => 'admin',
            'rci_password' => 'original-pass',
        ]);

        PolicyDevice::update($id, ['name' => 'KeepPass-renamed']);

        $this->assertSame('original-pass', PolicyDevice::find($id)['rci_password_enc']);
    }

    public function testDefaultAdapterTypeIsKeenetic(): void
    {
        $id = PolicyDevice::create(['name' => 'Default-' . uniqid()]);
        $this->assertSame('keenetic', PolicyDevice::find($id)['adapter_type']);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PolicyDevice::create(['name' => '']);
    }
}
