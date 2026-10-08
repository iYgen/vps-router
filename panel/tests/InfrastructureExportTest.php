<?php

namespace Tests;

use App\InfrastructureExport;
use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Server;
use App\Models\ServerSet;
use PHPUnit\Framework\TestCase;

class InfrastructureExportTest extends TestCase
{
    public function testExportContainsNoSecrets(): void
    {
        $router = Server::create(['name' => 'ExpRouter-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'ExpExit-' . uniqid(), 'role' => 'exit']);
        $exitServerId = ExitServer::create([
            'name' => 'exp-wg-' . uniqid(),
            'endpoint_host' => 'exp.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'amneziawg',
            'wg_peer_pubkey' => 'pub-should-stay-out',
            'wg_peer_psk' => 'PSK-SECRET-VALUE',
            'wg_local_privkey' => 'PRIVKEY-SECRET-VALUE',
            'wg_local_address' => '10.99.0.2/32',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 'exp link');

        $dump = InfrastructureExport::export();
        $json = json_encode($dump);

        $this->assertStringNotContainsString('PSK-SECRET-VALUE', $json);
        $this->assertStringNotContainsString('PRIVKEY-SECRET-VALUE', $json);
        $this->assertStringNotContainsString('pub-should-stay-out', $json);
        $this->assertStringContainsString('exp-wg-', $json);

        $exitRow = current(array_filter($dump['exit_servers'], fn ($r) => str_starts_with($r['name'], 'exp-wg-')));
        $this->assertArrayNotHasKey('wg_peer_psk', $exitRow);
        $this->assertArrayNotHasKey('wg_local_privkey', $exitRow);
        $this->assertArrayNotHasKey('protocol_params', $exitRow);
    }

    public function testReimportingOwnExportCreatesNothingNew(): void
    {
        // Гарантируем хотя бы одну строку в каждой категории для этого прогона.
        $router = Server::create(['name' => 'ReRouter-' . uniqid(), 'role' => 'router']);
        $exitNode = Server::create(['name' => 'ReExit-' . uniqid(), 'role' => 'exit']);
        $exitServerId = ExitServer::create([
            'name' => 're-wg-' . uniqid(),
            'endpoint_host' => 're.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'wg_peer_pubkey' => 'pub',
            'wg_peer_psk' => null,
            'wg_local_privkey' => 'priv',
            'wg_local_address' => '10.99.0.3/32',
        ]);
        Connection::create($router, $exitNode, 'amneziawg', $exitServerId, null, 're link');
        $setId = ServerSet::create(['name' => 'ReSet-' . uniqid()]);
        ServerSet::addMember($setId, $exitNode, 0);

        $dump = InfrastructureExport::export();
        $result = InfrastructureExport::import($dump);

        $this->assertSame(0, $result['created']['servers']);
        $this->assertSame(0, $result['created']['connections']);
        $this->assertSame(0, $result['created']['exit_servers']);
        $this->assertSame(0, $result['created']['server_sets']);
    }

    public function testImportCreatesNewNamedEntitiesAndSkipsSelfNode(): void
    {
        $payload = [
            'format_version' => 1,
            'servers' => [
                ['name' => 'ImportedRouterSelf', 'role' => 'router', 'is_self' => true],
                ['name' => 'ImportedExit-' . uniqid(), 'role' => 'exit', 'is_self' => false],
            ],
            'connections' => [],
            'exit_servers' => [],
            'server_sets' => [],
        ];

        $result = InfrastructureExport::import($payload);

        $this->assertSame(1, $result['created']['servers']);
        $selfNode = Server::self();
        $this->assertNotSame('ImportedRouterSelf', $selfNode['name'] ?? null);
    }

    public function testImportRejectsPayloadMissingExpectedFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InfrastructureExport::import(['servers' => []]);
    }
}
