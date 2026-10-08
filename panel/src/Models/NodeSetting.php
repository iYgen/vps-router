<?php

namespace App\Models;

use App\Database;

/**
 * Per-router (per-node) settings — same key/value shape as the global
 * {@see Setting} store, but scoped to one router node (servers.id).
 *
 * Multi-router (variant 3): every entry-router has its own inbound/Reality
 * configuration. Reads fall back to the global {@see Setting} store when a node
 * has no override, so settings that are still global keep working and the
 * self-router behaves exactly as before its values were seeded (migration 015).
 */
class NodeSetting
{
    public static function get(int $serverId, string $key, ?string $default = null): ?string
    {
        $stmt = Database::get()->prepare('SELECT value FROM node_settings WHERE server_id = ? AND key = ?');
        $stmt->execute([$serverId, $key]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['value'];
        }
        // Fall back to the global setting (and its default) — covers keys that
        // were never scoped per node and any node created before seeding.
        return Setting::get($key, $default);
    }

    /** SQLite < 3.24 has no ON CONFLICT DO UPDATE — portable upsert. */
    public static function set(int $serverId, string $key, string $value): void
    {
        $pdo = Database::get();
        $exists = $pdo->prepare('SELECT 1 FROM node_settings WHERE server_id = ? AND key = ?');
        $exists->execute([$serverId, $key]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('UPDATE node_settings SET value = ? WHERE server_id = ? AND key = ?')
                ->execute([$value, $serverId, $key]);
        } else {
            $pdo->prepare('INSERT INTO node_settings (server_id, key, value) VALUES (?, ?, ?)')
                ->execute([$serverId, $key, $value]);
        }
    }

    /** All node-scoped overrides for one router (does not merge in globals). */
    public static function allForServer(int $serverId): array
    {
        $stmt = Database::get()->prepare('SELECT key, value FROM node_settings WHERE server_id = ?');
        $stmt->execute([$serverId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    /** Copy the self-router's seeded settings onto a newly-registered router. */
    public static function copyDefaultsTo(int $serverId): void
    {
        $self = Server::self();
        if (!$self || (int) $self['id'] === $serverId) {
            return;
        }
        foreach (self::allForServer((int) $self['id']) as $key => $value) {
            if ($value !== null) {
                self::set($serverId, $key, $value);
            }
        }
    }
}
