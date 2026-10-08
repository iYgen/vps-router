<?php

namespace App;

/**
 * Плановая перезагрузка серверов по расписанию (ежедневно / еженедельно в
 * заданное время UTC). Хранит одно расписание на сервер. Исполняется кроном
 * bin/scheduled_reboots.php, который раз в несколько минут перезагружает
 * «подошедшие» узлы по SSH и помечает last_run_at (чтобы не перезапускать
 * повторно в том же окне).
 */
class ScheduledReboots
{
    public const FREQS = ['daily', 'weekly'];

    /** Расписание сервера (или дефолт, если не задано). */
    public static function forServer(int $serverId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM scheduled_reboots WHERE server_id = ?');
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['server_id' => $serverId, 'enabled' => 0, 'freq' => 'weekly', 'time_utc' => '04:00', 'dow' => 0, 'last_run_at' => null];
        }
        $row['enabled'] = (int) $row['enabled'];
        $row['dow'] = (int) $row['dow'];
        return $row;
    }

    public static function save(int $serverId, bool $enabled, string $freq, string $timeUtc, int $dow): void
    {
        if (!in_array($freq, self::FREQS, true)) {
            throw new \InvalidArgumentException('Неизвестная частота');
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $timeUtc)) {
            throw new \InvalidArgumentException('Время в формате HH:MM (UTC)');
        }
        $dow = max(0, min(6, $dow));

        $pdo = Database::get();
        $ex = $pdo->prepare('SELECT 1 FROM scheduled_reboots WHERE server_id = ?');
        $ex->execute([$serverId]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE scheduled_reboots SET enabled=?, freq=?, time_utc=?, dow=?, updated_at=datetime('now') WHERE server_id=?")
                ->execute([$enabled ? 1 : 0, $freq, $timeUtc, $dow, $serverId]);
        } else {
            $pdo->prepare('INSERT INTO scheduled_reboots (server_id, enabled, freq, time_utc, dow) VALUES (?, ?, ?, ?, ?)')
                ->execute([$serverId, $enabled ? 1 : 0, $freq, $timeUtc, $dow]);
        }
    }

    /** @return array<int,array> включённые расписания */
    public static function allEnabled(): array
    {
        $rows = Database::get()->query('SELECT * FROM scheduled_reboots WHERE enabled = 1')->fetchAll();
        foreach ($rows as &$r) {
            $r['enabled'] = (int) $r['enabled'];
            $r['dow'] = (int) $r['dow'];
        }
        return $rows;
    }

    /**
     * Пора ли перезагружать: текущее время (UTC) попало в минуту расписания
     * (с окном $windowMin, равным частоте запуска крона), и в этом окне ещё не
     * запускались (last_run_at). Для weekly — ещё и совпадение дня недели.
     */
    public static function isDue(array $sched, int $now, int $windowMin = 10): bool
    {
        if ((int) ($sched['enabled'] ?? 0) !== 1) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', (string) $sched['time_utc']));
        $schedToday = gmmktime($h, $m, 0, (int) gmdate('n', $now), (int) gmdate('j', $now), (int) gmdate('Y', $now));
        // Окно: [schedToday, schedToday + window). Срабатываем, если now в окне.
        $inWindow = $now >= $schedToday && $now < $schedToday + $windowMin * 60;
        if (!$inWindow) {
            return false;
        }
        if (($sched['freq'] ?? 'weekly') === 'weekly' && (int) gmdate('w', $now) !== (int) $sched['dow']) {
            return false;
        }
        // Уже запускали в этом окне?
        if (!empty($sched['last_run_at'])) {
            $last = strtotime($sched['last_run_at'] . ' UTC');
            if ($last !== false && $last >= $schedToday) {
                return false;
            }
        }
        return true;
    }

    public static function markRun(int $serverId, int $now): void
    {
        Database::get()->prepare('UPDATE scheduled_reboots SET last_run_at = ? WHERE server_id = ?')
            ->execute([gmdate('Y-m-d H:i:s', $now), $serverId]);
    }
}
