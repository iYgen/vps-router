<?php

namespace App\Models;

use App\Database;

/**
 * Ребро графа между двумя servers. Для type=amneziawg/wireguard реальные
 * параметры туннеля живут в exit_servers (см. ExitServer) — connections
 * лишь ссылается на её id, чтобы не дублировать источник правды, который
 * уже читает AmneziaConfigBuilder/SingboxConfigBuilder без изменений.
 */
class Connection
{
    public const TYPES = [
        'ssh', 'wireguard', 'amneziawg', 'tcp', 'http', 'socks', 'vless', 'shadowsocks',
        'hysteria2', 'tuic', 'trojan', 'generic',
    ];

    /** Типы, которые реально компилируются в рабочий рантайм сегодня. */
    public const EXECUTABLE_TYPES = ['amneziawg', 'wireguard', 'vless', 'shadowsocks', 'hysteria2', 'tuic', 'trojan'];

    public static function all(): array
    {
        return Database::get()->query(
            'SELECT c.*, ss.name AS source_name, ts.name AS target_name
             FROM connections c
             JOIN servers ss ON ss.id = c.source_server_id
             JOIN servers ts ON ts.id = c.target_server_id
             ORDER BY c.id'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM connections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function forServer(int $serverId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT c.*, ss.name AS source_name, ts.name AS target_name
             FROM connections c
             JOIN servers ss ON ss.id = c.source_server_id
             JOIN servers ts ON ts.id = c.target_server_id
             WHERE c.source_server_id = ? OR c.target_server_id = ?
             ORDER BY c.id'
        );
        $stmt->execute([$serverId, $serverId]);
        return $stmt->fetchAll();
    }

    /** Обратный поиск к forServer(): по exit_server_id найти связь (и через неё — target-сервер с SSH-доступом). */
    public static function forExitServer(int $exitServerId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT c.*, ss.name AS source_name, ts.name AS target_name
             FROM connections c
             JOIN servers ss ON ss.id = c.source_server_id
             JOIN servers ts ON ts.id = c.target_server_id
             WHERE c.exit_server_id = ?
             LIMIT 1'
        );
        $stmt->execute([$exitServerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(int $sourceId, int $targetId, string $type, ?int $exitServerId, ?array $config, ?string $label): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Неизвестный тип соединения: ' . $type);
        }
        if ($sourceId === $targetId) {
            throw new \InvalidArgumentException('Сервер нельзя соединить сам с собой');
        }
        if (self::exists($sourceId, $targetId, $type)) {
            throw new \InvalidArgumentException('Такое соединение уже существует');
        }

        $stmt = Database::get()->prepare(
            'INSERT INTO connections (source_server_id, target_server_id, type, exit_server_id, config, label)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sourceId, $targetId, $type, $exitServerId,
            $config !== null ? json_encode($config, JSON_UNESCAPED_SLASHES) : null,
            $label,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function exists(int $sourceId, int $targetId, string $type): bool
    {
        $stmt = Database::get()->prepare(
            'SELECT 1 FROM connections
             WHERE type = ? AND ((source_server_id = ? AND target_server_id = ?) OR (source_server_id = ? AND target_server_id = ?))'
        );
        $stmt->execute([$type, $sourceId, $targetId, $targetId, $sourceId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function delete(int $id): void
    {
        $conn = self::find($id);
        // Атомарно и устойчиво к "database is locked" (см. Database::transaction).
        Database::transaction(function (\PDO $pdo) use ($id, $conn) {
            $pdo->prepare('DELETE FROM connections WHERE id = ?')->execute([$id]);
            // Связанный exit_servers-туннель отдельно не живёт без connection —
            // убираем вместе, иначе останется "осиротевший" WG-конфиг.
            if ($conn && $conn['exit_server_id']) {
                ExitServer::delete((int) $conn['exit_server_id']);
            }
        });
    }
}
