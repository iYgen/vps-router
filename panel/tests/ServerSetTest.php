<?php

namespace Tests;

use App\Models\Connection;
use App\Models\Server;
use App\Models\ServerSet;
use PHPUnit\Framework\TestCase;

class ServerSetTest extends TestCase
{
    public function testCreateAddRemoveMember(): void
    {
        $setId = ServerSet::create(['name' => 'Set-' . uniqid(), 'strategy' => 'manual']);
        $serverId = Server::create(['name' => 'Member-' . uniqid(), 'role' => 'exit']);

        ServerSet::addMember($setId, $serverId, 5);
        $set = ServerSet::find($setId);
        $this->assertCount(1, $set['members']);
        $this->assertSame(5, (int) $set['members'][0]['priority']);

        ServerSet::removeMember($setId, $serverId);
        $set = ServerSet::find($setId);
        $this->assertCount(0, $set['members']);
    }

    public function testDeleteCascadeRemovesMemberServersAndExits(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'ExitDel-' . uniqid(), 'role' => 'exit']);
        $exitServerId = \App\Models\ExitServer::create([
            'name' => 'wgdel-' . uniqid(),
            'endpoint_host' => 'de.example.com', 'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub', 'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.9/32',
            'interface_name' => 'awg-del-' . random_int(100, 999), 'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'link');

        $setId = ServerSet::create(['name' => 'PoolDel-' . uniqid(), 'strategy' => 'failover']);
        ServerSet::addMember($setId, $exitNode, 0);

        $deleted = ServerSet::deleteCascade($setId);

        $this->assertSame(1, $deleted);
        $this->assertNull(ServerSet::find($setId), 'набор удалён');
        $this->assertNull(Server::find($exitNode), 'сервер-участник удалён');
        $this->assertNull(\App\Models\ExitServer::find($exitServerId), 'exit-туннель удалён каскадом');
        $this->assertNotNull(Server::find($router), 'роутер (не участник) не тронут');
    }

    public function testDeleteCascadeSkipsSelfNode(): void
    {
        // Узел с панелью (is_self) не должен удаляться даже если попал в набор.
        $selfId = Server::create(['name' => 'Self-' . uniqid(), 'role' => 'router']);
        \App\Database::get()->prepare('UPDATE servers SET is_self = 1 WHERE id = ?')->execute([$selfId]);
        $setId = ServerSet::create(['name' => 'WithSelf-' . uniqid()]);
        ServerSet::addMember($setId, $selfId, 0);

        $deleted = ServerSet::deleteCascade($setId);

        $this->assertSame(0, $deleted, 'is_self пропущен');
        $this->assertNotNull(Server::find($selfId), 'узел с панелью цел');
        $this->assertNull(ServerSet::find($setId), 'набор всё равно удалён');
    }

    public function testRejectsUnknownStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerSet::create(['name' => 'Bad-' . uniqid(), 'strategy' => 'not-real']);
    }

    public function testResolveExitServerIdEmptySetReturnsNull(): void
    {
        $setId = ServerSet::create(['name' => 'Empty-' . uniqid()]);
        $this->assertNull(ServerSet::resolveExitServerId($setId));
    }

    public function testResolveExitServerIdViaAmneziawgConnection(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'Exit-' . uniqid(), 'role' => 'exit']);

        $exitServerId = \App\Models\ExitServer::create([
            'name' => 'wg-' . uniqid(),
            'endpoint_host' => 'de.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.9/32',
            'interface_name' => 'awg-test-' . random_int(100, 999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'test link');

        $setId = ServerSet::create(['name' => 'ResolvedSet-' . uniqid(), 'strategy' => 'priority']);
        ServerSet::addMember($setId, $exitNode, 0);

        $this->assertSame($exitServerId, ServerSet::resolveExitServerId($setId));
    }

    public function testFindExposesResolvedExitServerId(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'Exit-' . uniqid(), 'role' => 'exit']);
        $exitServerId = \App\Models\ExitServer::create([
            'name' => 'wg-' . uniqid(),
            'endpoint_host' => 'de.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.9/32',
            'interface_name' => 'awg-test-' . random_int(100, 999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'test link');

        $setId = ServerSet::create(['name' => 'FindResolved-' . uniqid(), 'strategy' => 'priority']);
        ServerSet::addMember($setId, $exitNode, 0);

        $found = ServerSet::find($setId);
        $this->assertSame($exitServerId, $found['resolved_exit_server_id']);

        $all = ServerSet::all();
        $match = current(array_filter($all, fn($s) => (int) $s['id'] === $setId));
        $this->assertSame($exitServerId, $match['resolved_exit_server_id']);
    }

    public function testMembersReportHealthAndExitServer(): void
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'Exit-' . uniqid(), 'role' => 'exit']);
        $exitServerId = \App\Models\ExitServer::create([
            'name' => 'wg-' . uniqid(),
            'endpoint_host' => 'de.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.9/32',
            'interface_name' => 'awg-test-' . random_int(100, 999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'test link');

        $setId = ServerSet::create(['name' => 'HealthSet-' . uniqid()]);
        ServerSet::addMember($setId, $exitNode, 0);

        $members = ServerSet::members($setId);
        $this->assertCount(1, $members);
        $this->assertSame($exitServerId, (int) $members[0]['exit_server_id']);
        // Свежесозданный exit_server ещё ни разу не проверялся health_check.php.
        $this->assertFalse($members[0]['is_healthy']);

        \App\Models\ExitServer::setHealth($exitServerId, true);
        $members = ServerSet::members($setId);
        $this->assertTrue($members[0]['is_healthy']);
    }

    private function makeExitMember(string $priorityTag): array
    {
        $router = Server::create(['name' => 'Router-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => "Exit-$priorityTag-" . uniqid(), 'role' => 'exit']);
        $exitServerId = \App\Models\ExitServer::create([
            'name' => "wg-$priorityTag-" . uniqid(),
            'endpoint_host' => 'de.example.com',
            'endpoint_port' => 51820,
            'wg_peer_pubkey' => 'peerpub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'localpriv',
            'wg_local_address' => '10.90.0.9/32',
            'interface_name' => 'awg-test-' . random_int(1000, 999999),
            'amnezia_params' => null,
            'status' => 'active',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'test link');
        return ['server_id' => $exitNode, 'exit_server_id' => $exitServerId];
    }

    public function testHealthStatusMaintenanceWhenSetDisabled(): void
    {
        $setId = ServerSet::create(['name' => 'Disabled-' . uniqid(), 'enabled' => false]);
        $this->assertSame('maintenance', ServerSet::find($setId)['health_status']);
    }

    public function testHealthStatusOfflineWhenNoMembers(): void
    {
        $setId = ServerSet::create(['name' => 'NoMembers-' . uniqid()]);
        $this->assertSame('offline', ServerSet::find($setId)['health_status']);
    }

    public function testHealthStatusOfflineWhenPrimaryUnhealthy(): void
    {
        $primary = $this->makeExitMember('primary');
        $setId = ServerSet::create(['name' => 'Unhealthy-' . uniqid(), 'strategy' => 'priority']);
        ServerSet::addMember($setId, $primary['server_id'], 0);

        // Свежесозданный exit_server ещё не проходил health-check.
        $this->assertSame('offline', ServerSet::find($setId)['health_status']);
    }

    public function testHealthStatusHealthyWhenPrimaryIsHealthy(): void
    {
        $primary = $this->makeExitMember('primary');
        \App\Models\ExitServer::setHealth($primary['exit_server_id'], true);

        $setId = ServerSet::create(['name' => 'Healthy-' . uniqid(), 'strategy' => 'priority']);
        ServerSet::addMember($setId, $primary['server_id'], 0);

        $this->assertSame('healthy', ServerSet::find($setId)['health_status']);
    }

    public function testHealthStatusDegradedWhenFailoverUsesSecondary(): void
    {
        $primary = $this->makeExitMember('primary');
        $secondary = $this->makeExitMember('secondary');
        \App\Models\ExitServer::setHealth($secondary['exit_server_id'], true);
        // primary остаётся без health-check (не здоров) — failover должен уйти на secondary.

        $setId = ServerSet::create(['name' => 'Failover-' . uniqid(), 'strategy' => 'failover']);
        ServerSet::addMember($setId, $primary['server_id'], 0);
        ServerSet::addMember($setId, $secondary['server_id'], 1);

        $found = ServerSet::find($setId);
        $this->assertSame($secondary['exit_server_id'], $found['resolved_exit_server_id']);
        $this->assertSame('degraded', $found['health_status']);
    }

    public function testMemberStatusIsMaintenanceWhenServerDisabled(): void
    {
        $primary = $this->makeExitMember('disabled');
        Server::update($primary['server_id'], ['name' => 'Disabled-node', 'role' => 'exit', 'enabled' => false]);

        $setId = ServerSet::create(['name' => 'MaintMember-' . uniqid()]);
        ServerSet::addMember($setId, $primary['server_id'], 0);

        $members = ServerSet::members($setId);
        $this->assertSame('maintenance', $members[0]['member_status']);
        $this->assertSame('offline', ServerSet::find($setId)['health_status']);
    }
}
