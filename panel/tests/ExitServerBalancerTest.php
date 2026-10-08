<?php

namespace Tests;

use App\ExitServerBalancer;
use App\Models\ExitServer;
use App\Models\ExitServerLoad;
use App\Models\ExitServerPeer;
use PHPUnit\Framework\TestCase;

class ExitServerBalancerTest extends TestCase
{
    private function makeExitServer(string $poolLabel, string $status = 'active', string $protocol = 'wireguard'): int
    {
        return ExitServer::create([
            'name' => 'balancer-target-' . uniqid(),
            'endpoint_host' => 'wg.example.com',
            'endpoint_port' => 51820,
            'status' => $status,
            'protocol' => $protocol,
            'pool_label' => $poolLabel,
        ]);
    }

    public function testEmptyPoolReturnsNull(): void
    {
        $this->assertNull(ExitServerBalancer::pickLeastLoaded('no-such-pool-' . uniqid()));
    }

    public function testSingleCandidateReturnedWithoutNeedingMetrics(): void
    {
        $pool = 'pool-' . uniqid();
        $id = $this->makeExitServer($pool);

        $picked = ExitServerBalancer::pickLeastLoaded($pool);
        $this->assertNotNull($picked);
        $this->assertSame($id, (int) $picked['id']);
    }

    public function testIgnoresDisabledAndOtherProtocolServers(): void
    {
        $pool = 'pool-' . uniqid();
        $active = $this->makeExitServer($pool, 'active', 'wireguard');
        $this->makeExitServer($pool, 'disabled', 'wireguard');
        $this->makeExitServer($pool, 'active', 'amneziawg');

        $picked = ExitServerBalancer::pickLeastLoaded($pool, 'wireguard');
        $this->assertSame($active, (int) $picked['id']);
    }

    public function testPicksLowestCpuLoadAmongFreshSamples(): void
    {
        $pool = 'pool-' . uniqid();
        $busy = $this->makeExitServer($pool);
        $idle = $this->makeExitServer($pool);

        ExitServerLoad::upsert($busy, ['cpu_load_1min' => 2.5, 'mem_used_percent' => 80, 'net_bytes_total' => 1000, 'net_bytes_per_sec' => null]);
        ExitServerLoad::upsert($idle, ['cpu_load_1min' => 0.1, 'mem_used_percent' => 10, 'net_bytes_total' => 1000, 'net_bytes_per_sec' => null]);

        $picked = ExitServerBalancer::pickLeastLoaded($pool);
        $this->assertSame($idle, (int) $picked['id']);
    }

    public function testMemUsedPercentBreaksTieOnEqualCpuLoad(): void
    {
        $pool = 'pool-' . uniqid();
        $highMem = $this->makeExitServer($pool);
        $lowMem = $this->makeExitServer($pool);

        ExitServerLoad::upsert($highMem, ['cpu_load_1min' => 1.0, 'mem_used_percent' => 90, 'net_bytes_total' => 1000, 'net_bytes_per_sec' => null]);
        ExitServerLoad::upsert($lowMem, ['cpu_load_1min' => 1.0, 'mem_used_percent' => 20, 'net_bytes_total' => 1000, 'net_bytes_per_sec' => null]);

        $picked = ExitServerBalancer::pickLeastLoaded($pool);
        $this->assertSame($lowMem, (int) $picked['id']);
    }

    public function testStaleSampleIsTreatedAsUnknownAndFallsBackToPeerCount(): void
    {
        $pool = 'pool-' . uniqid();
        $staleButBusyPeerCount = $this->makeExitServer($pool);
        $freshNoSample = $this->makeExitServer($pool);

        // Старый замер (за пределами окна свежести) — не должен участвовать
        // в сравнении по метрикам, даже если он выглядит "хорошо".
        $db = \App\Database::get();
        $db->prepare(
            "INSERT INTO exit_server_load (exit_server_id, cpu_load_1min, mem_used_percent, net_bytes_total, net_bytes_per_sec, sample_at)
             VALUES (?, 0.01, 1, 1000, NULL, datetime('now', '-2 hours'))"
        )->execute([$staleButBusyPeerCount]);

        ExitServerPeer::create($staleButBusyPeerCount, 'existing-1', 'pub1', 'priv1', '10.90.1.3/32');
        ExitServerPeer::create($staleButBusyPeerCount, 'existing-2', 'pub2', 'priv2', '10.90.1.4/32');

        $picked = ExitServerBalancer::pickLeastLoaded($pool);
        // freshNoSample has 0 existing peers vs 2 on the stale one — bootstrap fallback picks it.
        $this->assertSame($freshNoSample, (int) $picked['id']);
    }
}
