<?php

namespace App\Models;

use App\Database;

/** История прогонов App\RiskScanner — раньше результат виден был только "по кнопке" и нигде не сохранялся. */
class RiskScanResult
{
    public static function record(int $serverId, string $host, int $score, array $findings, string $triggeredBy = 'cron'): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO risk_scan_results (server_id, host, score, findings_json, triggered_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$serverId, $host, $score, json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $triggeredBy]);
        return (int) Database::get()->lastInsertId();
    }

    public static function historyFor(int $serverId, int $limit = 20): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM risk_scan_results WHERE server_id = ? ORDER BY scanned_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $serverId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function latestFor(int $serverId): ?array
    {
        $rows = self::historyFor($serverId, 1);
        return $rows[0] ?? null;
    }

    /** Последний результат по КАЖДОМУ серверу, у которого он вообще есть — для сводки по всей инфраструктуре. */
    public static function latestPerServer(): array
    {
        return Database::get()->query(
            'SELECT r.*, s.name AS server_name
             FROM risk_scan_results r
             JOIN servers s ON s.id = r.server_id
             WHERE r.id IN (SELECT MAX(id) FROM risk_scan_results GROUP BY server_id)
             ORDER BY r.score DESC'
        )->fetchAll();
    }
}
