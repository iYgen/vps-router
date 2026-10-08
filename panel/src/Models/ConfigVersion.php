<?php

namespace App\Models;

use App\Database;

/** Снапшоты применённых sing-box конфигов — используются для preview-diff и rollback. */
class ConfigVersion
{
    public static function record(string $username, string $description, array $config, array $applyResult): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO config_versions (username, description, singbox_config, status, apply_stdout, apply_stderr, apply_exit_code)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            $description,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $applyResult['exit_code'] === 0 ? 'applied' : 'failed',
            $applyResult['stdout'] ?? null,
            $applyResult['stderr'] ?? null,
            $applyResult['exit_code'] ?? null,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function latest(): ?array
    {
        $row = Database::get()->query(
            "SELECT * FROM config_versions WHERE status = 'applied' ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM config_versions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function recent(int $limit = 50): array
    {
        $stmt = Database::get()->prepare('SELECT id, created_at, username, description, status, apply_exit_code FROM config_versions ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markRolledBack(int $id): void
    {
        Database::get()->prepare("UPDATE config_versions SET status = 'rolled_back' WHERE id = ?")->execute([$id]);
    }
}
