<?php

namespace Tests;

use App\Models\ExitServer;
use App\Models\ExitServerLoad;
use PHPUnit\Framework\TestCase;

class ExitServerLoadTest extends TestCase
{
    private function makeExitServer(): int
    {
        return ExitServer::create([
            'name' => 'load-target-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'protocol' => 'wireguard',
        ]);
    }

    public function testLatestForReturnsNullWhenNoSampleYet(): void
    {
        $id = $this->makeExitServer();
        $this->assertNull(ExitServerLoad::latestFor($id));
    }

    public function testUpsertInsertsThenUpdatesSameRow(): void
    {
        $id = $this->makeExitServer();

        ExitServerLoad::upsert($id, ['cpu_load_1min' => 0.5, 'mem_used_percent' => 30, 'net_bytes_total' => 1000, 'net_bytes_per_sec' => null]);
        $first = ExitServerLoad::latestFor($id);
        $this->assertSame(0.5, (float) $first['cpu_load_1min']);

        ExitServerLoad::upsert($id, ['cpu_load_1min' => 1.5, 'mem_used_percent' => 60, 'net_bytes_total' => 2000, 'net_bytes_per_sec' => 12.3]);
        $second = ExitServerLoad::latestFor($id);
        $this->assertSame(1.5, (float) $second['cpu_load_1min']);
        $this->assertSame(60, (int) $second['mem_used_percent']);
        $this->assertSame(12.3, (float) $second['net_bytes_per_sec']);

        $stmt = \App\Database::get()->prepare('SELECT COUNT(*) FROM exit_server_load WHERE exit_server_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'upsert должен обновлять существующую строку, а не плодить новые');
    }

    public function testRawErrorIsStoredAndMetricsCanBeNull(): void
    {
        $id = $this->makeExitServer();
        ExitServerLoad::upsert($id, ['cpu_load_1min' => null, 'mem_used_percent' => null, 'net_bytes_total' => null, 'net_bytes_per_sec' => null, 'raw_error' => 'SSH недоступен']);

        $row = ExitServerLoad::latestFor($id);
        $this->assertNull($row['cpu_load_1min']);
        $this->assertSame('SSH недоступен', $row['raw_error']);
    }
}
