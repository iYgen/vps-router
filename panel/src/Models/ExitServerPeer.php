<?php

namespace App\Models;

use App\Database;

/**
 * Дополнительный WireGuard-пир на exit-сервере (protocol='wireguard') —
 * для подключения домашних устройств (Keenetic и т.п.) напрямую к
 * exit-серверу, в обход входной VPS. См. App\Provisioner::addWireguardPeer().
 */
class ExitServerPeer
{
    public static function forExitServer(int $exitServerId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM exit_server_peers WHERE exit_server_id = ? ORDER BY id'
        );
        $stmt->execute([$exitServerId]);
        return $stmt->fetchAll();
    }

    /** Все WireGuard-пиры со всех exit-серверов сразу, с именем/статусом сервера — для общего списка "Устройства". */
    public static function allWithExitServer(): array
    {
        return Database::get()->query(
            'SELECT p.*, es.name AS exit_server_name, es.endpoint_host, es.endpoint_port, es.provision_status AS exit_provision_status
             FROM exit_server_peers p
             JOIN exit_servers es ON es.id = p.exit_server_id
             ORDER BY p.created_at DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM exit_server_peers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(int $exitServerId, string $name, string $publicKey, string $privateKey, string $tunnelAddress): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO exit_server_peers (exit_server_id, name, public_key, private_key, tunnel_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$exitServerId, $name, $publicKey, $privateKey, $tunnelAddress]);
        return (int) Database::get()->lastInsertId();
    }

    public static function setApplied(int $id, ?string $log): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE exit_server_peers SET applied_at = datetime('now'), apply_log = ? WHERE id = ?"
        );
        $stmt->execute([$log, $id]);
    }

    public static function setRevoked(int $id, bool $revoked): void
    {
        $stmt = Database::get()->prepare('UPDATE exit_server_peers SET revoked = ? WHERE id = ?');
        $stmt->execute([$revoked ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM exit_server_peers WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Следующий свободный последний октет в /24 (.1 — сам сервер, .2 — входной VPS, дальше — устройства). */
    public static function nextTunnelOctet(int $exitServerId): int
    {
        $used = [1, 2];
        foreach (self::forExitServer($exitServerId) as $p) {
            if (preg_match('/\.(\d+)\/32$/', $p['tunnel_address'], $m)) {
                $used[] = (int) $m[1];
            }
        }
        return max($used) + 1;
    }
}
