<?php

namespace Tests;

use App\Database;
use App\Models\Server;
use App\TrafficSeries;
use PHPUnit\Framework\TestCase;

class TrafficSeriesTest extends TestCase
{
    private int $sid;

    protected function setUp(): void
    {
        $this->sid = Server::create(['name' => 'TS-' . uniqid(), 'role' => 'exit']);
        $pdo = Database::get();
        $ins = $pdo->prepare('INSERT INTO server_traffic_daily (server_id, day, rx, tx) VALUES (?, ?, ?, ?)');
        // Две даты в одном месяце + одна в следующем.
        $ins->execute([$this->sid, '2026-01-05', 100, 10]);
        $ins->execute([$this->sid, '2026-01-20', 200, 20]);
        $ins->execute([$this->sid, '2026-02-03', 50, 5]);
    }

    /** Находит ряд ИМЕННО нашего сервера (в общей выборке могут быть и чужие). */
    private function mine(array $s): array
    {
        foreach ($s['servers'] as $srv) {
            if ($srv['id'] === $this->sid) {
                return $srv;
            }
        }
        $this->fail('наш сервер не найден в рядах');
    }

    public function testDailyGranularityKeepsEachDay(): void
    {
        $s = TrafficSeries::serverSeries('2026-01-05', '2026-01-20', 'day');
        $this->assertSame('day', $s['granularity']);
        $this->assertSame('2026-01-05', $s['labels'][0]);
        $this->assertSame('2026-01-20', end($s['labels']));
        $this->assertSame(300, array_sum($this->mine($s)['rx']));
    }

    public function testMonthGranularityBuckets(): void
    {
        $s = TrafficSeries::serverSeries('2026-01-01', '2026-02-28', 'month');
        $this->assertSame(['2026-01', '2026-02'], $s['labels']);
        $srv = $this->mine($s);
        $this->assertSame(300, $srv['rx'][0], 'январь = 100+200');
        $this->assertSame(50, $srv['rx'][1], 'февраль = 50');
        $this->assertSame(30, $srv['tx'][0]);
    }

    public function testYearGranularity(): void
    {
        $s = TrafficSeries::serverSeries('2026-01-01', '2026-12-31', 'year');
        $this->assertSame(['2026'], $s['labels']);
        $this->assertSame(350, $this->mine($s)['rx'][0]);
    }

    public function testGranularityForAutoPicks(): void
    {
        $this->assertSame('day', TrafficSeries::granularityFor(30));
        $this->assertSame('week', TrafficSeries::granularityFor(365));
        $this->assertSame('month', TrafficSeries::granularityFor(900));
        $this->assertSame('year', TrafficSeries::granularityFor(2000));
    }
}
