<?php

namespace App\Models;

use App\Database;

class ServerSet
{
    public const STRATEGIES = ['manual', 'priority', 'failover'];

    /** Секунд, после которых last_health_ok_at считается "протухшим" — та же граница, что использует resolveExitServerId() для failover. */
    private const HEALTH_TIMEOUT = 180;

    /** Состояния из внешней архитектурной критики (см. docs/infrastructure-ui.md #76.3),
     *  без RECOVERING — для него нет отслеживаемого сигнала (истории проверок),
     *  добавлять его означало бы придумывать поведение, которого нет в backend. */
    public const HEALTH_STATUSES = ['healthy', 'degraded', 'offline', 'maintenance'];

    public static function all(): array
    {
        $sets = Database::get()->query('SELECT * FROM server_sets ORDER BY name')->fetchAll();
        foreach ($sets as &$set) {
            $set['members'] = self::members((int) $set['id']);
            $set['resolved_exit_server_id'] = self::resolveExitServerId((int) $set['id']);
            $set['health_status'] = self::healthStatus($set);
        }
        return $sets;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM server_sets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['members'] = self::members($id);
        $row['resolved_exit_server_id'] = self::resolveExitServerId($id);
        $row['health_status'] = self::healthStatus($row);
        return $row;
    }

    /**
     * Агрегированный статус набора — строго по тем же правилам, что реально
     * применяет resolveExitServerId(), чтобы UI не показывал ничего, чего
     * рантайм не решал бы так же:
     *  - maintenance — набор выключен целиком (enabled=0).
     *  - offline — нет ни одного enabled-участника, либо resolveExitServerId()
     *    не смог ничего выбрать, либо выбранный участник не здоров (это
     *    происходит, когда стратегия manual/priority всегда берёт первого по
     *    приоритету независимо от здоровья, либо failover не нашёл ни одного
     *    здорового и взял первого "за неимением лучшего").
     *  - degraded — реально используется участник НЕ с наивысшим приоритетом
     *    (failover переключился на резерв), но он здоров.
     *  - healthy — реально используется участник с наивысшим приоритетом, и он здоров.
     */
    public static function healthStatus(array $set): string
    {
        if (!(int) ($set['enabled'] ?? 0)) {
            return 'maintenance';
        }

        $candidates = array_values(array_filter($set['members'] ?? [], fn($m) => (int) $m['enabled'] === 1));
        if (empty($candidates)) {
            return 'offline';
        }

        $resolvedId = $set['resolved_exit_server_id'] ?? null;
        if ($resolvedId === null) {
            return 'offline';
        }

        $resolvedMember = null;
        foreach ($candidates as $m) {
            if ((int) ($m['exit_server_id'] ?? 0) === (int) $resolvedId) {
                $resolvedMember = $m;
                break;
            }
        }
        if (!$resolvedMember || !$resolvedMember['is_healthy']) {
            return 'offline';
        }

        $isPrimary = (int) ($candidates[0]['exit_server_id'] ?? -1) === (int) $resolvedId;
        return $isPrimary ? 'healthy' : 'degraded';
    }

    /**
     * Члены набора, отсортированные по priority (для priority/failover), с
     * привязанным exit-сервером (через connections, как exitServerForServer())
     * и вычисленным is_healthy — тем же порогом, что реально использует
     * resolveExitServerId() для failover, чтобы UI не врал про то, что решает
     * рантайм.
     *
     * @return array<int,array>
     */
    public static function members(int $setId): array
    {
        $placeholders = implode(',', array_fill(0, count(Connection::EXECUTABLE_TYPES), '?'));
        $stmt = Database::get()->prepare(
            "SELECT m.server_id, m.priority, s.name, s.enabled, s.status, s.role,
                    (SELECT es.id FROM connections c JOIN exit_servers es ON es.id = c.exit_server_id
                     WHERE c.target_server_id = m.server_id AND c.type IN ($placeholders) LIMIT 1) AS exit_server_id,
                    (SELECT es.name FROM connections c JOIN exit_servers es ON es.id = c.exit_server_id
                     WHERE c.target_server_id = m.server_id AND c.type IN ($placeholders) LIMIT 1) AS exit_server_name,
                    (SELECT es.last_health_ok_at FROM connections c JOIN exit_servers es ON es.id = c.exit_server_id
                     WHERE c.target_server_id = m.server_id AND c.type IN ($placeholders) LIMIT 1) AS last_health_ok_at,
                    (SELECT es.last_health_check_at FROM connections c JOIN exit_servers es ON es.id = c.exit_server_id
                     WHERE c.target_server_id = m.server_id AND c.type IN ($placeholders) LIMIT 1) AS last_health_check_at
             FROM server_set_members m JOIN servers s ON s.id = m.server_id
             WHERE m.server_set_id = ? ORDER BY m.priority ASC, s.name"
        );
        $stmt->execute([
            ...Connection::EXECUTABLE_TYPES, ...Connection::EXECUTABLE_TYPES,
            ...Connection::EXECUTABLE_TYPES, ...Connection::EXECUTABLE_TYPES,
            $setId,
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['is_healthy'] = self::isRecentUtc($row['last_health_ok_at'] ?? null, self::HEALTH_TIMEOUT);
            // enabled=0 (узел графа выключен) — именно это реально исключает
            // участника из candidates в resolveExitServerId(), поэтому только
            // это и считается "maintenance" здесь (не exit_servers.status —
            // тот сейчас на резолюцию не влияет, помечать по нему значило бы врать про рантайм).
            $row['member_status'] = (int) $row['enabled'] === 0
                ? 'maintenance'
                : ($row['is_healthy'] ? 'healthy' : 'offline');
        }
        return $rows;
    }

    public static function create(array $data): int
    {
        self::validate($data);
        $stmt = Database::get()->prepare(
            'INSERT INTO server_sets (name, description, strategy, enabled, tags) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['strategy'] ?? 'manual',
            array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
            isset($data['tags']) ? json_encode(array_values((array) $data['tags'])) : '[]',
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        self::validate($data);
        $stmt = Database::get()->prepare(
            'UPDATE server_sets SET name = ?, description = ?, strategy = ?, enabled = ?, tags = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['strategy'] ?? 'manual',
            array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
            isset($data['tags']) ? json_encode(array_values((array) $data['tags'])) : '[]',
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM server_sets WHERE id = ?')->execute([$id]);
    }

    /**
     * Удалить набор ВМЕСТЕ с серверами-участниками (и их exit-туннелями через
     * Server::delete). Узел-панель (is_self) никогда не удаляется. Возвращает
     * число удалённых серверов. Всё одной транзакцией (Server::delete внутри
     * работает вложенно — Database::transaction это учитывает).
     */
    public static function deleteCascade(int $id): int
    {
        return Database::transaction(function () use ($id): int {
            $deleted = 0;
            foreach (self::members($id) as $m) {
                $serverId = (int) $m['server_id'];
                $srv = Server::find($serverId);
                if ($srv && (int) ($srv['is_self'] ?? 0) === 1) {
                    continue; // сервер с панелью не трогаем
                }
                Server::delete($serverId); // каскадно удалит и exit_servers
                $deleted++;
            }
            self::delete($id);
            return $deleted;
        });
    }

    /** SQLite < 3.24 (нередко на старых системных сборках PHP) не понимает
     *  ON CONFLICT...DO UPDATE — делаем portable-вариант через SELECT+INSERT/UPDATE. */
    public static function addMember(int $setId, int $serverId, int $priority = 0): void
    {
        $pdo = Database::get();
        $exists = $pdo->prepare('SELECT 1 FROM server_set_members WHERE server_set_id = ? AND server_id = ?');
        $exists->execute([$setId, $serverId]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('UPDATE server_set_members SET priority = ? WHERE server_set_id = ? AND server_id = ?')
                ->execute([$priority, $setId, $serverId]);
        } else {
            $pdo->prepare('INSERT INTO server_set_members (server_set_id, server_id, priority) VALUES (?, ?, ?)')
                ->execute([$setId, $serverId, $priority]);
        }
    }

    public static function removeMember(int $setId, int $serverId): void
    {
        $stmt = Database::get()->prepare('DELETE FROM server_set_members WHERE server_set_id = ? AND server_id = ?');
        $stmt->execute([$setId, $serverId]);
    }

    /**
     * Разрешает набор в конкретный exit_server_id для генерации конфига,
     * согласно стратегии. Используется SingboxConfigBuilder.
     *
     * - manual/priority: первый enabled-член по priority ASC.
     * - failover: то же, но пропускает члены с "протухшим" health-check
     *   (last_health_ok_at exit_servers старше 3 минут) в пользу следующего
     *   по приоритету, если такой есть.
     *
     * @return int|null exit_server_id выбранного члена, либо null если
     *                   в наборе нет пригодного члена (роут уходит direct).
     */
    public static function resolveExitServerId(int $setId): ?int
    {
        // Намеренно НЕ через self::find() — find()/all() сами зовут этот метод,
        // чтобы отдать resolved_exit_server_id наружу; поход через find() здесь
        // означал бы взаимную рекурсию (find -> resolveExitServerId -> find -> ...).
        $stmt = Database::get()->prepare('SELECT * FROM server_sets WHERE id = ?');
        $stmt->execute([$setId]);
        $set = $stmt->fetch();
        if (!$set || !$set['enabled']) {
            return null;
        }
        $members = self::members($setId);

        $candidates = array_values(array_filter($members, fn($m) => (int) $m['enabled'] === 1));
        if (empty($candidates)) {
            return null;
        }

        if ($set['strategy'] === 'failover') {
            foreach ($candidates as $m) {
                $es = self::exitServerForServer((int) $m['server_id']);
                if ($es && self::isHealthy($es)) {
                    return (int) $es['id'];
                }
            }
            // Ничего свежего — берём первого по приоритету как последний шанс.
        }

        $first = $candidates[0];
        $es = self::exitServerForServer((int) $first['server_id']);
        return $es ? (int) $es['id'] : null;
    }

    private static function exitServerForServer(int $serverId): ?array
    {
        $placeholders = implode(',', array_fill(0, count(Connection::EXECUTABLE_TYPES), '?'));
        $stmt = Database::get()->prepare(
            "SELECT es.* FROM connections c
             JOIN exit_servers es ON es.id = c.exit_server_id
             WHERE c.target_server_id = ? AND c.type IN ($placeholders)
             LIMIT 1"
        );
        $stmt->execute([$serverId, ...Connection::EXECUTABLE_TYPES]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function isHealthy(array $exitServer): bool
    {
        return self::isRecentUtc($exitServer['last_health_ok_at'] ?? null, self::HEALTH_TIMEOUT);
    }

    /**
     * SQLite datetime('now') всегда пишет UTC БЕЗ таймзоны в строке
     * ("2026-09-23 12:37:08"). strtotime() без явной зоны трактует такую
     * строку в ТЕКУЩЕЙ таймзоне PHP (date_default_timezone_get()) — на
     * сервере это обычно не UTC (например Europe/Moscow, UTC+3), из-за чего
     * "только что" помеченное здоровым всегда выглядело "протухшим на 3
     * часа" и failover ни разу реально не отличал здоровые члены от
     * нездоровых. Явно указываем UTC при парсинге.
     */
    private static function isRecentUtc(?string $ts, int $seconds): bool
    {
        if (empty($ts)) {
            return false;
        }
        $parsed = strtotime($ts . ' UTC');
        return $parsed !== false && $parsed > time() - $seconds;
    }

    private static function validate(array $data): void
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Название набора обязательно');
        }
        if (!in_array($data['strategy'] ?? 'manual', self::STRATEGIES, true)) {
            throw new \InvalidArgumentException('Неизвестная стратегия набора');
        }
    }
}
