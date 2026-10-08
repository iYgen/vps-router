<?php

namespace Tests;

use App\Models\Server;
use App\ScheduledReboots;
use PHPUnit\Framework\TestCase;

class ScheduledRebootsTest extends TestCase
{
    public function testSaveAndLoad(): void
    {
        $sid = Server::create(['name' => 'RB-' . uniqid(), 'role' => 'exit']);
        ScheduledReboots::save($sid, true, 'daily', '03:30', 0);
        $s = ScheduledReboots::forServer($sid);
        $this->assertSame(1, $s['enabled']);
        $this->assertSame('daily', $s['freq']);
        $this->assertSame('03:30', $s['time_utc']);
    }

    public function testSaveValidatesTimeAndFreq(): void
    {
        $sid = Server::create(['name' => 'RB-' . uniqid(), 'role' => 'exit']);
        $this->expectException(\InvalidArgumentException::class);
        ScheduledReboots::save($sid, true, 'daily', '25:99', 0);
    }

    public function testDueDailyInWindow(): void
    {
        // 2026-06-15 04:05 UTC, расписание daily 04:00 → в окне 10 мин.
        $now = gmmktime(4, 5, 0, 6, 15, 2026);
        $sched = ['enabled' => 1, 'freq' => 'daily', 'time_utc' => '04:00', 'dow' => 0, 'last_run_at' => null];
        $this->assertTrue(ScheduledReboots::isDue($sched, $now, 10));
    }

    public function testNotDueOutsideWindow(): void
    {
        $now = gmmktime(5, 0, 0, 6, 15, 2026); // 05:00, расписание 04:00 → мимо
        $sched = ['enabled' => 1, 'freq' => 'daily', 'time_utc' => '04:00', 'dow' => 0, 'last_run_at' => null];
        $this->assertFalse(ScheduledReboots::isDue($sched, $now, 10));
    }

    public function testNotDueIfAlreadyRanThisWindow(): void
    {
        $now = gmmktime(4, 5, 0, 6, 15, 2026);
        $sched = ['enabled' => 1, 'freq' => 'daily', 'time_utc' => '04:00', 'dow' => 0,
            'last_run_at' => gmdate('Y-m-d H:i:s', gmmktime(4, 1, 0, 6, 15, 2026))];
        $this->assertFalse(ScheduledReboots::isDue($sched, $now, 10), 'уже запускались в этом окне');
    }

    public function testWeeklyMatchesDayOfWeek(): void
    {
        // 2026-06-15 — это понедельник (dow=1).
        $now = gmmktime(4, 2, 0, 6, 15, 2026);
        $this->assertSame(1, (int) gmdate('w', $now));
        $mon = ['enabled' => 1, 'freq' => 'weekly', 'time_utc' => '04:00', 'dow' => 1, 'last_run_at' => null];
        $sun = ['enabled' => 1, 'freq' => 'weekly', 'time_utc' => '04:00', 'dow' => 0, 'last_run_at' => null];
        $this->assertTrue(ScheduledReboots::isDue($mon, $now, 10));
        $this->assertFalse(ScheduledReboots::isDue($sun, $now, 10), 'не тот день недели');
    }

    public function testDisabledNeverDue(): void
    {
        $now = gmmktime(4, 5, 0, 6, 15, 2026);
        $sched = ['enabled' => 0, 'freq' => 'daily', 'time_utc' => '04:00', 'dow' => 0, 'last_run_at' => null];
        $this->assertFalse(ScheduledReboots::isDue($sched, $now, 10));
    }
}
