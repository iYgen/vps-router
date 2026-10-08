<?php

namespace App\Models;

use App\Database;

/** Кэш последнего замера CPU/RAM/трафика exit-сервера — источник данных для App\ExitServerBalancer. */
class ExitServerLoad
{
    public static function latestFor(int $exitServerId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM exit_server_load WHERE exit_server_id = ?');
        $stmt->execute([$exitServerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{cpu_load_1min:?float,mem_used_percent:?int,net_bytes_total:?int,net_bytes_per_sec:?float,raw_error:?string} $data
     */
    public static function upsert(int $exitServerId, array $data): void
    {
        $stmt = Database::get()->prepare(
            "INSERT INTO exit_server_load
                (exit_server_id, cpu_load_1min, mem_used_percent, net_bytes_total, net_bytes_per_sec, sample_at, raw_error)
             VALUES (?, ?, ?, ?, ?, datetime('now'), ?)
             ON CONFLICT(exit_server_id) DO UPDATE SET
                cpu_load_1min = excluded.cpu_load_1min,
                mem_used_percent = excluded.mem_used_percent,
                net_bytes_total = excluded.net_bytes_total,
                net_bytes_per_sec = excluded.net_bytes_per_sec,
                sample_at = excluded.sample_at,
                raw_error = excluded.raw_error"
        );
        $stmt->execute([
            $exitServerId,
            $data['cpu_load_1min'] ?? null,
            $data['mem_used_percent'] ?? null,
            $data['net_bytes_total'] ?? null,
            $data['net_bytes_per_sec'] ?? null,
            $data['raw_error'] ?? null,
        ]);
    }
}
