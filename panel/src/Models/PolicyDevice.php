<?php

namespace App\Models;

use App\Database;
use App\Secrets;

/**
 * Policy Consumer — устройство, которому панель синхронизирует Client
 * Routing Policy (сейчас — только Keenetic-роутеры, см.
 * docs/infrastructure-ui.md раздел 15). rci_password_enc шифруется через
 * App\Secrets тем же способом, что ExitServer (прозрачно в find()/all()) —
 * расшифрованный пароль легитимно нужен App\KeeneticSyncService при каждом
 * sync, узкий accessor не даёт выигрыша. Наружу (api/policy-devices.php)
 * пароль не отдаётся — там свой белый список полей.
 */
class PolicyDevice
{
    public const ADAPTER_TYPES = ['keenetic', 'android', 'windows'];

    public static function all(): array
    {
        $rows = Database::get()->query(
            'SELECT pd.*, pp.name AS profile_name
             FROM policy_devices pd
             LEFT JOIN policy_profiles pp ON pp.id = pd.profile_id
             ORDER BY pd.name'
        )->fetchAll();
        return array_map([self::class, 'decryptRow'], $rows);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT pd.*, pp.name AS profile_name
             FROM policy_devices pd
             LEFT JOIN policy_profiles pp ON pp.id = pd.profile_id
             WHERE pd.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decryptRow($row) : null;
    }

    private static function decryptRow(array $row): array
    {
        if (array_key_exists('rci_password_enc', $row)) {
            $row['rci_password_enc'] = Secrets::decryptOrPlain($row['rci_password_enc']);
        }
        return $row;
    }

    public static function create(array $data): int
    {
        self::validate($data);
        $stmt = Database::get()->prepare(
            'INSERT INTO policy_devices
                (name, adapter_type, profile_id, exit_server_peer_id, rci_host, rci_port, rci_scheme, rci_username, rci_password_enc, enabled)
             VALUES (:name, :adapter_type, :profile_id, :exit_server_peer_id, :rci_host, :rci_port, :rci_scheme, :rci_username, :rci_password_enc, :enabled)'
        );
        $stmt->execute(self::bindParams($data));
        return (int) Database::get()->lastInsertId();
    }

    /** Если data['rci_password'] пуст/не передан — существующий пароль не трогаем. */
    public static function update(int $id, array $data): void
    {
        self::validate($data);
        $sql = 'UPDATE policy_devices SET
                    name = :name, adapter_type = :adapter_type, profile_id = :profile_id,
                    exit_server_peer_id = :exit_server_peer_id, rci_host = :rci_host,
                    rci_port = :rci_port, rci_scheme = :rci_scheme, rci_username = :rci_username,
                    enabled = :enabled';
        $params = self::bindParams($data);
        unset($params[':rci_password_enc']);

        if (!empty($data['rci_password'])) {
            $sql .= ', rci_password_enc = :rci_password_enc';
            $params[':rci_password_enc'] = Secrets::encrypt($data['rci_password']);
        }

        $sql .= ' WHERE id = :id';
        $params[':id'] = $id;

        Database::get()->prepare($sql)->execute($params);
    }

    public static function setSyncResult(int $id, string $status, ?int $appliedVersion, ?string $log): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE policy_devices SET
                sync_status = ?,
                applied_policy_version = COALESCE(?, applied_policy_version),
                last_sync_at = datetime('now'),
                last_sync_log = ?
             WHERE id = ?"
        );
        $stmt->execute([$status, $appliedVersion, $log, $id]);
    }

    public static function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM policy_devices WHERE id = ?')->execute([$id]);
    }

    private static function bindParams(array $data): array
    {
        return [
            ':name' => $data['name'],
            ':adapter_type' => in_array($data['adapter_type'] ?? '', self::ADAPTER_TYPES, true) ? $data['adapter_type'] : 'keenetic',
            ':profile_id' => !empty($data['profile_id']) ? (int) $data['profile_id'] : null,
            ':exit_server_peer_id' => !empty($data['exit_server_peer_id']) ? (int) $data['exit_server_peer_id'] : null,
            ':rci_host' => $data['rci_host'] ?? null,
            ':rci_port' => (int) ($data['rci_port'] ?? 443),
            ':rci_scheme' => $data['rci_scheme'] ?? 'https',
            ':rci_username' => $data['rci_username'] ?? null,
            ':rci_password_enc' => !empty($data['rci_password']) ? Secrets::encrypt($data['rci_password']) : null,
            ':enabled' => array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
        ];
    }

    private static function validate(array $data): void
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Название устройства обязательно');
        }
    }
}
