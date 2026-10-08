<?php

namespace Tests;

use App\Models\RiskScanResult;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class RiskScanResultTest extends TestCase
{
    public function testRecordAndHistory(): void
    {
        $id = Server::create(['name' => 'RiskHist-' . uniqid(), 'role' => 'exit', 'host' => '203.0.113.5']);

        RiskScanResult::record($id, '203.0.113.5', 15, [['level' => 'info', 'message' => 'ok']], 'cron');
        RiskScanResult::record($id, '203.0.113.5', 55, [['level' => 'warning', 'message' => 'port open']], 'manual');

        $history = RiskScanResult::historyFor($id);
        $this->assertCount(2, $history);
        $this->assertSame(55, (int) $history[0]['score']); // самый свежий первым
        $this->assertSame('manual', $history[0]['triggered_by']);

        $latest = RiskScanResult::latestFor($id);
        $this->assertSame(55, (int) $latest['score']);
    }

    public function testLatestPerServerReturnsOnlyMostRecentEach(): void
    {
        $id1 = Server::create(['name' => 'RiskA-' . uniqid(), 'role' => 'exit', 'host' => '203.0.113.6']);
        $id2 = Server::create(['name' => 'RiskB-' . uniqid(), 'role' => 'exit', 'host' => '203.0.113.7']);

        RiskScanResult::record($id1, '203.0.113.6', 10, [], 'cron');
        RiskScanResult::record($id1, '203.0.113.6', 70, [], 'cron');
        RiskScanResult::record($id2, '203.0.113.7', 5, [], 'cron');

        $latest = RiskScanResult::latestPerServer();
        $byServer = [];
        foreach ($latest as $row) {
            $byServer[(int) $row['server_id']] = (int) $row['score'];
        }
        $this->assertSame(70, $byServer[$id1]);
        $this->assertSame(5, $byServer[$id2]);
    }

    public function testHistoryEmptyForServerNeverScanned(): void
    {
        $id = Server::create(['name' => 'NeverScanned-' . uniqid(), 'role' => 'exit']);
        $this->assertSame([], RiskScanResult::historyFor($id));
        $this->assertNull(RiskScanResult::latestFor($id));
    }
}
