<?php

namespace App\Models;

use App\Database;

/** Подписка: привязка подписчика к тарифу со сроком действия. */
class Subscription
{
    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Текущая (последняя) подписка подписчика. */
    public static function forSubscriber(int $subscriberId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_subscriptions WHERE subscriber_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$subscriberId]);
        return $stmt->fetch() ?: null;
    }

    /** Все подписки с именами подписчика/тарифа — для списка в UI. */
    public static function allDetailed(): array
    {
        return Database::get()->query(
            'SELECT s.*, sub.name AS subscriber_name, sub.email AS subscriber_email, p.name AS plan_name, p.price, p.currency
             FROM billing_subscriptions s
             JOIN billing_subscribers sub ON sub.id = s.subscriber_id
             LEFT JOIN billing_plans p ON p.id = s.plan_id
             ORDER BY s.expires_at'
        )->fetchAll();
    }

    public static function create(int $subscriberId, ?int $planId, string $expiresAt, bool $autoRenew = false): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO billing_subscriptions (subscriber_id, plan_id, status, expires_at, auto_renew)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$subscriberId, $planId, 'active', $expiresAt, $autoRenew ? 1 : 0]);
        return (int) Database::get()->lastInsertId();
    }

    public static function setExpiry(int $id, string $expiresAt, string $status = 'active'): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscriptions SET expires_at = ?, status = ? WHERE id = ?');
        $stmt->execute([$expiresAt, $status, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_subscriptions SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function markReminded(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE billing_subscriptions SET reminded_at = datetime('now') WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function markEnforced(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE billing_subscriptions SET enforced_at = datetime('now') WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM billing_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
    }
}
