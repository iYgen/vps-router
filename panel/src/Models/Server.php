<?php

namespace App\Models;

use App\Database;
use App\Secrets;

/**
 * Узел графа инфраструктуры. ssh_private_key никогда не возвращается
 * методами all()/find() — только узким sshPrivateKey(), который вызывает
 * исключительно App\Ssh при тестовом подключении.
 */
class Server
{
    public const ROLES = ['router', 'exit', 'proxy', 'vpn', 'gateway', 'storage', 'generic'];
    public const STATUSES = ['online', 'warning', 'offline', 'unknown', 'maintenance'];

    private const PUBLIC_COLUMNS =
        'id, name, role, host, ssh_port, ssh_user, ssh_public_key, country, region,
         description, tags, enabled, is_self, position_x, position_y, status,
         last_checked_at, last_check_result, created_at,
         knock_enabled, knock_protected_port, knock_ports,
         portscan_ban_enabled, portscan_decoy_ports, portscan_ban_seconds,
         traffic_limit_gb, traffic_reset_day, traffic_count_mode, traffic_sync_gb, traffic_sync_measured_gb, traffic_sync_at,
         CASE WHEN ssh_private_key_enc IS NOT NULL THEN 1 ELSE 0 END AS has_ssh_key';

    public static function all(): array
    {
        return Database::get()->query('SELECT ' . self::PUBLIC_COLUMNS . ' FROM servers ORDER BY is_self DESC, name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function self(): ?array
    {
        $row = Database::get()->query('SELECT ' . self::PUBLIC_COLUMNS . ' FROM servers WHERE is_self = 1 LIMIT 1')->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        self::validate($data);
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO servers
                (name, role, host, ssh_port, ssh_user, ssh_private_key_enc, ssh_public_key,
                 country, region, description, tags, enabled, position_x, position_y)
             VALUES
                (:name, :role, :host, :ssh_port, :ssh_user, :ssh_private_key_enc, :ssh_public_key,
                 :country, :region, :description, :tags, :enabled, :position_x, :position_y)'
        );
        $stmt->execute(self::bindParams($data));
        return (int) $pdo->lastInsertId();
    }

    /** Если data['ssh_private_key'] пуст/не передан — существующий ключ не трогаем. */
    public static function update(int $id, array $data): void
    {
        self::validate($data, $id);
        $pdo = Database::get();

        $sql = 'UPDATE servers SET
                    name = :name, role = :role, host = :host, ssh_port = :ssh_port,
                    ssh_user = :ssh_user,
                    country = :country, region = :region, description = :description,
                    tags = :tags, enabled = :enabled';
        // bindParams() отдаёт полный набор для create() (включая position_x/y,
        // ssh_private_key_enc) — тут нужны только плейсхолдеры, реально
        // присутствующие в SQL выше, иначе SQLite ругается на лишние параметры.
        $params = array_intersect_key(self::bindParams($data), array_flip([
            ':name', ':role', ':host', ':ssh_port', ':ssh_user',
            ':country', ':region', ':description', ':tags', ':enabled',
        ]));

        // Лимит трафика тарифа — необязательные поля, меняем только если пришли в запросе.
        if (array_key_exists('traffic_limit_gb', $data)) {
            $limit = trim((string) $data['traffic_limit_gb']);
            $sql .= ', traffic_limit_gb = :traffic_limit_gb';
            $params[':traffic_limit_gb'] = $limit === '' ? null : max(0, (float) str_replace(',', '.', $limit));
        }
        if (array_key_exists('traffic_reset_day', $data)) {
            $sql .= ', traffic_reset_day = :traffic_reset_day';
            $params[':traffic_reset_day'] = max(1, min(28, (int) $data['traffic_reset_day'] ?: 1));
        }
        if (array_key_exists('traffic_count_mode', $data)) {
            $sql .= ', traffic_count_mode = :traffic_count_mode';
            $params[':traffic_count_mode'] = isset(\App\ServerTraffic::MODES[$data['traffic_count_mode']]) ? $data['traffic_count_mode'] : 'sum';
        }

        // Форма редактирования не присылает публичный ключ — не затираем тот,
        // что панель сама сохранила при создании ключа по паролю.
        if (array_key_exists('ssh_public_key', $data)) {
            $sql .= ', ssh_public_key = :ssh_public_key';
            $params[':ssh_public_key'] = $data['ssh_public_key'];
        }

        if (!empty($data['ssh_private_key'])) {
            $sql .= ', ssh_private_key_enc = :ssh_private_key_enc';
            $params[':ssh_private_key_enc'] = Secrets::encrypt($data['ssh_private_key']);
        }

        $sql .= ' WHERE id = :id';
        $params[':id'] = $id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(int $id): void
    {
        $row = self::find($id);
        if ($row && (int) $row['is_self'] === 1) {
            throw new \InvalidArgumentException('Нельзя удалить сервер, на котором работает панель');
        }
        // Одной транзакцией (атомарно + устойчиво к "database is locked"):
        // сначала убираем exit_servers, привязанные к соединениям этого узла.
        // Без этого FK ON DELETE CASCADE снесёт строки connections, но сам
        // exit_server (у connections.exit_server_id — ON DELETE SET NULL) уцелеет
        // и станет «осиротевшим»: Provisioner::syncOrphanExitServers() при
        // следующей загрузке графа заново создаст узел и связь — узел «не
        // удаляется» и возвращается после обновления страницы. Зеркалит логику
        // Connection::delete(), которая так же чистит exit_server у одной связи.
        Database::transaction(function (\PDO $pdo) use ($id) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT exit_server_id FROM connections
                 WHERE (source_server_id = ? OR target_server_id = ?) AND exit_server_id IS NOT NULL'
            );
            $stmt->execute([$id, $id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $exitId) {
                ExitServer::delete((int) $exitId);
            }
            $pdo->prepare('DELETE FROM servers WHERE id = ?')->execute([$id]);
        });
    }

    public static function setPosition(int $id, float $x, float $y): void
    {
        $stmt = Database::get()->prepare('UPDATE servers SET position_x = ?, position_y = ? WHERE id = ?');
        $stmt->execute([$x, $y, $id]);
    }

    public static function setCheckResult(int $id, string $status, array $result): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE servers SET status = ?, last_checked_at = datetime('now'), last_check_result = ? WHERE id = ?"
        );
        $stmt->execute([$status, json_encode($result, JSON_UNESCAPED_SLASHES), $id]);
    }

    /** Кладёт свежие метрики в last_check_result, не трогая статус — для нагрузки на графе. */
    public static function storeMetrics(int $id, array $metrics): void
    {
        $stmt = Database::get()->prepare('SELECT last_check_result FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $current = json_decode((string) $stmt->fetchColumn(), true);
        $current = is_array($current) ? $current : [];
        $current['metrics'] = $metrics;
        $upd = Database::get()->prepare('UPDATE servers SET last_check_result = ? WHERE id = ?');
        $upd->execute([json_encode($current, JSON_UNESCAPED_SLASHES), $id]);
    }

    /**
     * Сохраняет конфигурацию "стука" — вызывается после того, как
     * App\Provisioner::hardenPortKnock() успешно применил её на самом
     * сервере, чтобы панель знала, как достучаться перед следующими SSH-
     * подключениями (см. App\Provisioner::knockIfConfigured()).
     */
    public static function setKnockConfig(int $id, bool $enabled, int $protectedPort, array $ports): void
    {
        $stmt = Database::get()->prepare(
            'UPDATE servers SET knock_enabled = ?, knock_protected_port = ?, knock_ports = ? WHERE id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $protectedPort, json_encode(array_values($ports)), $id]);
    }

    /**
     * Сохраняет конфигурацию port-scan ban — вызывается после того, как
     * App\Provisioner::hardenPortscanBan() успешно применил её на самом
     * сервере (см. deploy/provision/harden-portscan-ban.sh).
     */
    public static function setPortscanBanConfig(int $id, bool $enabled, array $decoyPorts, int $banSeconds): void
    {
        $stmt = Database::get()->prepare(
            'UPDATE servers SET portscan_ban_enabled = ?, portscan_decoy_ports = ?, portscan_ban_seconds = ? WHERE id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, json_encode(array_values($decoyPorts)), $banSeconds, $id]);
    }

    /**
     * Сохраняет SSH-пару, созданную панелью при входе по паролю
     * (App\Ssh::bootstrapKeyWithPassword) — приватный шифруется, как и
     * вручную введённый ключ.
     */
    public static function setSshKeyPair(int $id, string $privateKey, string $publicKey): void
    {
        $stmt = Database::get()->prepare('UPDATE servers SET ssh_private_key_enc = ?, ssh_public_key = ? WHERE id = ?');
        $stmt->execute([Secrets::encrypt($privateKey), $publicKey, $id]);
    }

    /** Только для App\Ssh — расшифрованный приватный ключ сервера. */
    public static function sshPrivateKey(int $id): ?string
    {
        $stmt = Database::get()->prepare('SELECT ssh_private_key_enc FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $row['ssh_private_key_enc'] === null) {
            return null;
        }
        return Secrets::decrypt($row['ssh_private_key_enc']);
    }

    private static function bindParams(array $data): array
    {
        return [
            ':name' => $data['name'],
            ':role' => $data['role'],
            ':host' => $data['host'] ?? '',
            ':ssh_port' => (int) ($data['ssh_port'] ?? 22),
            ':ssh_user' => $data['ssh_user'] ?? 'root',
            ':ssh_private_key_enc' => !empty($data['ssh_private_key']) ? Secrets::encrypt($data['ssh_private_key']) : null,
            ':ssh_public_key' => $data['ssh_public_key'] ?? null,
            ':country' => $data['country'] ?? null,
            ':region' => $data['region'] ?? null,
            ':description' => $data['description'] ?? null,
            ':tags' => isset($data['tags']) ? json_encode(array_values((array) $data['tags'])) : '[]',
            ':enabled' => array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
            ':position_x' => (float) ($data['position_x'] ?? 0),
            ':position_y' => (float) ($data['position_y'] ?? 0),
        ];
    }

    private static function validate(array $data, ?int $ignoreId = null): void
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Название сервера обязательно');
        }
        if (!in_array($data['role'] ?? '', self::ROLES, true)) {
            throw new \InvalidArgumentException('Неизвестная роль сервера');
        }
        $port = (int) ($data['ssh_port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SSH-порт должен быть в диапазоне 1..65535');
        }
    }
}
