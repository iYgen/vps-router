<?php

namespace Tests;

use App\Database;
use App\Models\Server;
use App\ServerTraffic;
use App\TrafficCollector;
use PHPUnit\Framework\TestCase;

class ServerTrafficTest extends TestCase
{
    private function newServer(array $extra = []): array
    {
        $id = Server::create(['name' => 'Q-' . uniqid(), 'role' => 'exit']);
        if ($extra) {
            Server::update($id, ['name' => 'Q-' . uniqid(), 'role' => 'exit'] + $extra);
        }
        return Server::find($id);
    }

    public function testParsesRemoteCounterOutput(): void
    {
        $this->assertSame(['iface' => 'ens3', 'rx' => 123, 'tx' => 456], ServerTraffic::parseCounters("ens3 123 456\n"));
        $this->assertNull(ServerTraffic::parseCounters(''));
    }

    public function testDeltasSurviveRebootAndIgnoreFirstSample(): void
    {
        $s = $this->newServer();
        $id = (int) $s['id'];
        $t = time();
        ServerTraffic::record($id, ['iface' => 'eth0', 'rx' => 1000, 'tx' => 5000], $t);       // точка отсчёта
        ServerTraffic::record($id, ['iface' => 'eth0', 'rx' => 3000, 'tx' => 9000], $t + 60);  // +2000 / +4000
        ServerTraffic::record($id, ['iface' => 'eth0', 'rx' => 500, 'tx' => 700], $t + 120);   // перезагрузка: +500 / +700

        $row = Database::get()->query("SELECT SUM(rx) r, SUM(tx) t FROM server_traffic_daily WHERE server_id = $id")->fetch();
        $this->assertSame(2500, (int) $row['r']);
        $this->assertSame(4700, (int) $row['t']);
    }

    public function testPeriodStartUsesResetDay(): void
    {
        $this->assertSame('2026-09-01', ServerTraffic::periodStart(1, strtotime('2026-09-25 12:00 UTC')));
        $this->assertSame('2026-08-28', ServerTraffic::periodStart(28, strtotime('2026-09-25 12:00 UTC')));
        $this->assertSame('2025-12-15', ServerTraffic::periodStart(15, strtotime('2026-01-03 12:00 UTC')));
    }

    public function testUsageModesLimitLevelsAndHosterSync(): void
    {
        $s = $this->newServer(['traffic_limit_gb' => '3000', 'traffic_reset_day' => 1, 'traffic_count_mode' => 'sum']);
        $id = (int) $s['id'];
        $day = gmdate('Y-m-d');
        Database::get()->prepare('INSERT INTO server_traffic_daily (server_id, day, rx, tx) VALUES (?, ?, ?, ?)')
            ->execute([$id, $day, 1000 * 1e9, 1600 * 1e9]); // 1000 ГБ вход, 1600 ГБ выход

        $u = ServerTraffic::usage(Server::find($id));
        $this->assertSame(2600e9, (float) $u['used_bytes']);
        $this->assertSame(86.7, $u['percent']);
        $this->assertSame('warn', $u['level']);

        Server::update($id, ['name' => $s['name'], 'role' => 'exit', 'traffic_count_mode' => 'out']);
        $u = ServerTraffic::usage(Server::find($id));
        $this->assertSame(1600e9, (float) $u['used_bytes']);
        $this->assertSame('ok', $u['level']);

        // Хостер показывает 2900 ГБ, панель насчитала 1600 — дальше считаем с поправкой.
        ServerTraffic::sync($id, 2900);
        $u = ServerTraffic::usage(Server::find($id));
        $this->assertTrue($u['synced']);
        $this->assertEqualsWithDelta(2900e9, $u['used_bytes'], 1e6);
        $this->assertSame('danger', $u['level']);
    }

    public function testNoLimitMeansNoWarning(): void
    {
        $u = ServerTraffic::usage($this->newServer());
        $this->assertNull($u['limit_bytes']);
        $this->assertSame('none', $u['level']);
    }

    public function testDeviceTotalsSplitTodayMonthAndAllTime(): void
    {
        $key = 'c' . random_int(100000, 999999);
        $ins = Database::get()->prepare('INSERT INTO traffic_daily (day, client_key, up, down) VALUES (?, ?, ?, ?)');
        $ins->execute([gmdate('Y-m-d'), $key, 10, 100]);
        $ins->execute([gmdate('Y-m-d', time() - 10 * 86400), $key, 20, 200]);
        $ins->execute([gmdate('Y-m-d', time() - 90 * 86400), $key, 30, 300]);

        $t = TrafficCollector::deviceTotals()[$key];
        $this->assertSame([10, 100], [$t['today_up'], $t['today_down']]);
        $this->assertSame([30, 300], [$t['d30_up'], $t['d30_down']]);
        $this->assertSame([60, 600], [$t['total_up'], $t['total_down']]);
    }
}
