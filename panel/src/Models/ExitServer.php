<?php

namespace App\Models;

use App\Database;
use App\Secrets;

/**
 * wg_peer_psk/wg_local_privkey/protocol_params хранят секреты (WG-ключи,
 * VLESS UUID/Reality-ключи, Shadowsocks-пароль и т.п.) — зашифрованы в БД
 * через App\Secrets, но, в отличие от Server::sshPrivateKey(), здесь
 * расшифровка происходит прозрачно внутри all()/find(): у ExitServer, в
 * отличие от ssh-ключа сервера, десятки легитимных внутренних потребителей
 * расшифрованного значения (SingboxConfigBuilder, AmneziaConfigBuilder,
 * Provisioner, генератор wg-конфигов для устройств) — заводить для каждого
 * узкий метод нецелесообразно. Наружу (api/exit-servers.php) секреты всё
 * равно не уходят — та ручка явно выбирает белый список колонок без них.
 * decryptOrPlain() терпим к ещё не мигрированным (plaintext) старым строкам —
 * см. bin/encrypt-exit-secrets.php.
 */
class ExitServer
{
    private const SECRET_FIELDS = ['wg_peer_psk', 'wg_local_privkey', 'protocol_params'];

    public static function all(): array
    {
        $rows = Database::get()->query('SELECT * FROM exit_servers ORDER BY name')->fetchAll();
        return array_map([self::class, 'decryptRow'], $rows);
    }

    /** Взаимозаменяемые для балансировки нагрузки exit-серверы одного пула. */
    public static function activeInPool(string $poolLabel, string $protocol): array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM exit_servers WHERE pool_label = ? AND protocol = ? AND status = 'active' ORDER BY name"
        );
        $stmt->execute([$poolLabel, $protocol]);
        return array_map([self::class, 'decryptRow'], $stmt->fetchAll());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM exit_servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decryptRow($row) : null;
    }

    private static function decryptRow(array $row): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = Secrets::decryptOrPlain($row[$field]);
            }
        }
        return $row;
    }

    public const PROTOCOLS = ['amneziawg', 'wireguard', 'vless', 'shadowsocks'];

    public static function create(array $data): int
    {
        $data += self::defaults();
        $stmt = Database::get()->prepare(
            'INSERT INTO exit_servers
                (name, endpoint_host, endpoint_port, wg_peer_pubkey, wg_peer_psk,
                 wg_local_privkey, wg_local_address, interface_name, amnezia_params, status,
                 protocol, protocol_params, pool_label, source, country)
             VALUES (:name, :endpoint_host, :endpoint_port, :wg_peer_pubkey, :wg_peer_psk,
                     :wg_local_privkey, :wg_local_address, :interface_name, :amnezia_params, :status,
                     :protocol, :protocol_params, :pool_label, :source, :country)'
        );
        $stmt->execute(self::bindParams($data));
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $data += self::defaults();
        $data['id'] = $id;
        $stmt = Database::get()->prepare(
            'UPDATE exit_servers SET
                name = :name,
                endpoint_host = :endpoint_host,
                endpoint_port = :endpoint_port,
                wg_peer_pubkey = :wg_peer_pubkey,
                wg_peer_psk = :wg_peer_psk,
                wg_local_privkey = :wg_local_privkey,
                wg_local_address = :wg_local_address,
                interface_name = :interface_name,
                amnezia_params = :amnezia_params,
                status = :status,
                protocol = :protocol,
                protocol_params = :protocol_params,
                pool_label = :pool_label,
                source = :source,
                country = :country
             WHERE id = :id'
        );
        $stmt->execute(self::bindParams($data) + ['id' => $id]);
    }

    /**
     * protocol_params всегда приходит как array — здесь же сериализуем в JSON
     * для колонки, затем шифруем секретные поля (см. SECRET_FIELDS) перед
     * записью. wg_local_privkey='' (плейсхолдер для протоколов без WG-полей,
     * см. defaults()) намеренно не шифруется — это не секрет, а заглушка.
     */
    private static function bindParams(array $data): array
    {
        $data['protocol_params'] = isset($data['protocol_params']) && $data['protocol_params'] !== null
            ? (is_string($data['protocol_params']) ? $data['protocol_params'] : json_encode($data['protocol_params'], JSON_UNESCAPED_SLASHES))
            : null;
        unset($data['id']);

        foreach (self::SECRET_FIELDS as $field) {
            if (!empty($data[$field])) {
                $data[$field] = Secrets::encrypt($data[$field]);
            }
        }

        return $data;
    }

    /**
     * wg_peer_pubkey/wg_local_privkey/wg_local_address/interface_name — NOT NULL
     * (interface_name ещё и UNIQUE) в исходной схеме, рассчитанной только на
     * amneziawg-строки. Пересобирать таблицу под NULL-able колонки на боевой
     * СУБД (SQLite 3.7.17 на проде) рискованно: DROP TABLE с включённым
     * foreign_keys выполняет неявный каскад ON DELETE SET NULL по
     * rule_groups/connections ДО того, как строки будут вставлены обратно —
     * так можно потерять существующие связи. Вместо миграции схемы — просто
     * подставляем безопасные placeholder-значения для протоколов, которым
     * эти WG-специфичные колонки не нужны.
     */
    private static function defaults(): array
    {
        return [
            'wg_peer_pubkey' => '',
            'wg_peer_psk' => null,
            'wg_local_privkey' => '',
            'wg_local_address' => '',
            'interface_name' => 'n-a-' . bin2hex(random_bytes(4)),
            'amnezia_params' => null,
            'protocol' => 'amneziawg',
            'protocol_params' => null,
            'pool_label' => null,
            'source' => 'own',
            'country' => null,
        ];
    }

    public static function setProvisionStatus(int $id, string $status, ?string $log = null): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE exit_servers SET
                provision_status = ?,
                last_provision_at = datetime('now'),
                last_provision_log = COALESCE(?, last_provision_log)
             WHERE id = ?"
        );
        $stmt->execute([$status, $log, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM exit_servers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function setHealth(int $id, bool $ok, ?int $latencyMs = null): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE exit_servers SET
                last_health_check_at = datetime('now'),
                last_health_ok_at = CASE WHEN ? THEN datetime('now') ELSE last_health_ok_at END,
                latency_ms = CASE WHEN ? THEN ? ELSE latency_ms END
             WHERE id = ?"
        );
        // latency пишем только при успешной проверке (иначе оставляем прежнее значение).
        $stmt->execute([$ok ? 1 : 0, ($ok && $latencyMs !== null) ? 1 : 0, $latencyMs, $id]);
    }
}
