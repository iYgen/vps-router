<?php

namespace Tests;

use App\Models\Server;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    public function testCreateAndFind(): void
    {
        $id = Server::create(['name' => 'Test-' . uniqid(), 'role' => 'exit', 'host' => 'example.com']);
        $server = Server::find($id);

        $this->assertNotNull($server);
        $this->assertSame('exit', $server['role']);
        $this->assertArrayNotHasKey('ssh_private_key_enc', $server, 'find() не должен отдавать сырой зашифрованный ключ');
    }

    public function testRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Server::create(['name' => 'Bad', 'role' => 'not-a-real-role']);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Server::create(['name' => '', 'role' => 'generic']);
    }

    public function testSshPrivateKeyRoundTrip(): void
    {
        $id = Server::create([
            'name' => 'WithKey-' . uniqid(),
            'role' => 'generic',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfaketestkeycontent\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        $list = Server::find($id);
        $this->assertSame(1, $list['has_ssh_key']);

        $decrypted = Server::sshPrivateKey($id);
        $this->assertStringContainsString('faketestkeycontent', $decrypted);
    }

    public function testUpdateWithoutKeyKeepsExistingKey(): void
    {
        $id = Server::create([
            'name' => 'KeepKey-' . uniqid(),
            'role' => 'generic',
            'ssh_private_key' => 'original-secret-key',
        ]);

        Server::update($id, ['name' => 'KeepKey-renamed', 'role' => 'generic']);

        $this->assertSame('original-secret-key', Server::sshPrivateKey($id));
    }

    public function testCannotDeleteSelfNode(): void
    {
        $self = Server::self();
        $this->assertNotNull($self, 'миграция должна была создать self-узел (входной VPS)');

        $this->expectException(\InvalidArgumentException::class);
        Server::delete((int) $self['id']);
    }

    public function testSetPosition(): void
    {
        $id = Server::create(['name' => 'Pos-' . uniqid(), 'role' => 'generic']);
        Server::setPosition($id, 123.5, 456.5);
        $server = Server::find($id);
        $this->assertEquals(123.5, $server['position_x']);
        $this->assertEquals(456.5, $server['position_y']);
    }
}
