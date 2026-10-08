<?php

namespace App\Models;

use App\Database;

class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::get()->prepare('SELECT value FROM server_settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    /** SQLite < 3.24 не понимает ON CONFLICT...DO UPDATE — portable-вариант. */
    public static function set(string $key, string $value): void
    {
        $pdo = Database::get();
        $exists = $pdo->prepare('SELECT 1 FROM server_settings WHERE key = ?');
        $exists->execute([$key]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('UPDATE server_settings SET value = ? WHERE key = ?')->execute([$value, $key]);
        } else {
            $pdo->prepare('INSERT INTO server_settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
        }
    }

    public static function all(): array
    {
        $stmt = Database::get()->query('SELECT key, value FROM server_settings');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }
}
