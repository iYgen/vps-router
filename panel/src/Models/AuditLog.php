<?php

namespace App\Models;

use App\Auth;
use App\Database;

class AuditLog
{
    public const CATEGORIES = ['action', 'auth', 'block'];

    public static function record(string $action, string $details = '', string $category = 'action'): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO audit_log (username, action, details, category, ip) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([Auth::username() ?? 'system', $action, $details, $category, Auth::clientIp()]);
    }

    /**
     * Как record(), но НЕ обращается к Auth::username() — нужно для
     * событий входа, которые по определению происходят ДО того, как
     * сессия аутентифицирована (неудачная/удачная попытка логина).
     */
    public static function recordAuth(string $username, string $action, string $ip, string $details = ''): void
    {
        $stmt = Database::get()->prepare(
            "INSERT INTO audit_log (username, action, details, category, ip) VALUES (?, ?, ?, 'auth', ?)"
        );
        $stmt->execute([$username, $action, $details, $ip]);
    }

    public static function recent(int $limit = 100, ?string $category = null): array
    {
        if ($category !== null) {
            $stmt = Database::get()->prepare('SELECT * FROM audit_log WHERE category = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $category, \PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        } else {
            $stmt = Database::get()->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
