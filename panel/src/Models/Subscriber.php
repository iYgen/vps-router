<?php

namespace App\Models;

use App\Database;

/** Подписчик — конечный пользователь, которому принадлежат устройства (clients). */
class Subscriber
{
    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM billing_subscribers ORDER BY name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_subscribers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $stmt = Database::get()->prepare('INSERT INTO billing_subscribers (name, email, note) VALUES (?, ?, ?)');
        $stmt->execute([
            trim((string) ($d['name'] ?? '')),
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['note'] ?? '')) ?: null,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET name=?, email=?, note=? WHERE id=?');
        $stmt->execute([
            trim((string) ($d['name'] ?? '')),
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['note'] ?? '')) ?: null,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // Отвязываем устройства (clients.subscriber_id → NULL через FK ON DELETE SET NULL),
        // подписки/платежи удаляются каскадом.
        $stmt = Database::get()->prepare('DELETE FROM billing_subscribers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $stmt = Database::get()->prepare('SELECT * FROM billing_subscribers WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function setPassword(int $id, string $password): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function setVerifyToken(int $id, string $token): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET verify_token = ?, email_verified = 0 WHERE id = ?');
        $stmt->execute([$token, $id]);
    }

    public static function findByVerifyToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = Database::get()->prepare('SELECT * FROM billing_subscribers WHERE verify_token = ? LIMIT 1');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public static function markVerified(int $id): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET email_verified = 1, verify_token = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function setResetToken(int $id, string $token, string $expires): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET reset_token = ?, reset_expires = ? WHERE id = ?');
        $stmt->execute([$token, $expires, $id]);
    }

    /** Подписчик по действующему токену сброса (не истёк). */
    public static function findByResetToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = Database::get()->prepare("SELECT * FROM billing_subscribers WHERE reset_token = ? AND reset_expires > datetime('now') LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public static function clearReset(int $id): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscribers SET reset_token = NULL, reset_expires = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Устройства подписчика. */
    public static function devices(int $id): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM clients WHERE subscriber_id = ? ORDER BY id');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public static function deviceCount(int $id): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM clients WHERE subscriber_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }
}
