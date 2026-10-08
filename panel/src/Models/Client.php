<?php

namespace App\Models;

use App\Database;

class Client
{
    /**
     * @param int|null $routerServerId scope to one router; null = every router
     * @param bool $includeNull also include rows with no router set (legacy rows
     *        belong to the self-router) — used when scoping to the self-router
     */
    public static function all(?int $routerServerId = null, bool $includeNull = false): array
    {
        if ($routerServerId !== null) {
            $where = $includeNull ? 'router_server_id = ? OR router_server_id IS NULL' : 'router_server_id = ?';
            $stmt = Database::get()->prepare("SELECT * FROM clients WHERE $where ORDER BY created_at DESC");
            $stmt->execute([$routerServerId]);
            return $stmt->fetchAll();
        }
        return Database::get()->query('SELECT * FROM clients ORDER BY created_at DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Допустимые типы устройства (для иконки); прочее сохраняем как NULL. */
    public const DEVICE_TYPES = ['router', 'phone', 'tablet', 'computer'];

    public static function create(string $name, ?int $routerServerId = null, ?string $deviceType = null): array
    {
        $uuid = self::uuidv4();
        $creds = self::newCredentials();
        $deviceType = in_array($deviceType, self::DEVICE_TYPES, true) ? $deviceType : null;
        $stmt = Database::get()->prepare(
            'INSERT INTO clients (name, uuid, password, ss_psk, wg_private_key, wg_public_key, router_server_id, device_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $uuid, $creds['password'], $creds['ss_psk'], $creds['wg_private_key'], $creds['wg_public_key'], $routerServerId, $deviceType]);
        return ['id' => (int) Database::get()->lastInsertId(), 'name' => $name, 'uuid' => $uuid, 'router_server_id' => $routerServerId, 'device_type' => $deviceType] + $creds;
    }

    /**
     * Устройствам, созданным до появления нескольких протоколов, дозаполняет
     * недостающие учётные данные — чтобы у старых устройств тоже появились
     * ссылки Shadowsocks/Trojan/Hysteria2/WireGuard. Идемпотентно.
     */
    public static function backfillCredentials(): void
    {
        $pdo = Database::get();
        $rows = $pdo->query(
            'SELECT id, password, ss_psk, wg_private_key, wg_public_key FROM clients
             WHERE password IS NULL OR ss_psk IS NULL OR wg_private_key IS NULL OR wg_public_key IS NULL'
        )->fetchAll();
        if (!$rows) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE clients SET password = ?, ss_psk = ?, wg_private_key = ?, wg_public_key = ? WHERE id = ?');
        foreach ($rows as $row) {
            $fresh = self::newCredentials();
            $hasWg = !empty($row['wg_private_key']) && !empty($row['wg_public_key']);
            $stmt->execute([
                $row['password'] ?: $fresh['password'],
                $row['ss_psk'] ?: $fresh['ss_psk'],
                $hasWg ? $row['wg_private_key'] : $fresh['wg_private_key'],
                $hasWg ? $row['wg_public_key'] : $fresh['wg_public_key'],
                $row['id'],
            ]);
        }
    }

    public static function setRevoked(int $id, bool $revoked): void
    {
        $stmt = Database::get()->prepare('UPDATE clients SET revoked = ? WHERE id = ?');
        $stmt->execute([$revoked ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM clients WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function active(?int $routerServerId = null, bool $includeNull = false): array
    {
        if ($routerServerId !== null) {
            $where = $includeNull
                ? '(router_server_id = ? OR router_server_id IS NULL)'
                : 'router_server_id = ?';
            $stmt = Database::get()->prepare("SELECT * FROM clients WHERE revoked = 0 AND $where ORDER BY id");
            $stmt->execute([$routerServerId]);
            return $stmt->fetchAll();
        }
        return Database::get()->query('SELECT * FROM clients WHERE revoked = 0 ORDER BY id')->fetchAll();
    }

    private static function newCredentials(): array
    {
        $kp = sodium_crypto_box_keypair();
        return [
            'password' => bin2hex(random_bytes(16)),
            'ss_psk' => base64_encode(random_bytes(16)),
            'wg_private_key' => base64_encode(sodium_crypto_box_secretkey($kp)),
            'wg_public_key' => base64_encode(sodium_crypto_box_publickey($kp)),
        ];
    }

    private static function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
