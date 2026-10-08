<?php

namespace Tests;

use App\Models\Connection;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testCreateGenericConnection(): void
    {
        $a = Server::create(['name' => 'A-' . uniqid(), 'role' => 'generic']);
        $b = Server::create(['name' => 'B-' . uniqid(), 'role' => 'generic']);

        $id = Connection::create($a, $b, 'ssh', null, ['note' => 'test'], 'A to B');
        $conn = Connection::find($id);

        $this->assertSame('ssh', $conn['type']);
        $this->assertSame($a, (int) $conn['source_server_id']);
        $this->assertSame($b, (int) $conn['target_server_id']);
    }

    public function testDuplicateConnectionRejected(): void
    {
        $a = Server::create(['name' => 'A-' . uniqid(), 'role' => 'generic']);
        $b = Server::create(['name' => 'B-' . uniqid(), 'role' => 'generic']);
        Connection::create($a, $b, 'tcp', null, null, null);

        $this->expectException(\InvalidArgumentException::class);
        Connection::create($a, $b, 'tcp', null, null, null);
    }

    public function testSelfConnectionRejected(): void
    {
        $a = Server::create(['name' => 'SelfLoop-' . uniqid(), 'role' => 'generic']);
        $this->expectException(\InvalidArgumentException::class);
        Connection::create($a, $a, 'tcp', null, null, null);
    }

    public function testUnknownTypeRejected(): void
    {
        $a = Server::create(['name' => 'A-' . uniqid(), 'role' => 'generic']);
        $b = Server::create(['name' => 'B-' . uniqid(), 'role' => 'generic']);
        $this->expectException(\InvalidArgumentException::class);
        Connection::create($a, $b, 'not-a-real-type', null, null, null);
    }

    public function testDelete(): void
    {
        $a = Server::create(['name' => 'A-' . uniqid(), 'role' => 'generic']);
        $b = Server::create(['name' => 'B-' . uniqid(), 'role' => 'generic']);
        $id = Connection::create($a, $b, 'generic', null, null, null);

        Connection::delete($id);
        $this->assertNull(Connection::find($id));
    }
}
