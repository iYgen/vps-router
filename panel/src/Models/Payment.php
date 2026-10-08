<?php

namespace App\Models;

use App\Database;

/** Платёж (ручной или через шлюз). При status=paid продлевает подписку на period_days. */
class Payment
{
    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_payments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByExternal(string $method, string $externalId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_payments WHERE method = ? AND external_id = ? LIMIT 1');
        $stmt->execute([$method, $externalId]);
        return $stmt->fetch() ?: null;
    }

    /** Последние платежи с именем подписчика — для истории в UI. */
    public static function recent(int $limit = 50, int $offset = 0): array
    {
        $stmt = Database::get()->prepare(
            'SELECT pay.*, sub.name AS subscriber_name
             FROM billing_payments pay
             JOIN billing_subscribers sub ON sub.id = pay.subscriber_id
             ORDER BY pay.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Платежи одного подписчика (для кабинета). */
    public static function forSubscriber(int $subscriberId, int $limit = 10): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_payments WHERE subscriber_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $subscriberId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn();
    }

    public static function create(array $d): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO billing_payments (subscriber_id, subscription_id, plan_id, amount, currency, method, status, period_days, external_id, comment, purpose, paid_at)
             VALUES (:subscriber_id, :subscription_id, :plan_id, :amount, :currency, :method, :status, :period_days, :external_id, :comment, :purpose, :paid_at)'
        );
        $stmt->execute([
            ':subscriber_id'   => (int) $d['subscriber_id'],
            ':subscription_id' => isset($d['subscription_id']) ? (int) $d['subscription_id'] : null,
            ':plan_id'         => isset($d['plan_id']) ? (int) $d['plan_id'] : null,
            ':amount'          => (float) ($d['amount'] ?? 0),
            ':currency'        => (string) ($d['currency'] ?? 'RUB'),
            ':method'          => (string) ($d['method'] ?? 'manual'),
            ':status'          => (string) ($d['status'] ?? 'paid'),
            ':period_days'     => (int) ($d['period_days'] ?? 0),
            ':external_id'     => $d['external_id'] ?? null,
            ':comment'         => $d['comment'] ?? null,
            ':purpose'         => (string) ($d['purpose'] ?? 'subscription'),
            ':paid_at'         => $d['paid_at'] ?? null,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function markPaid(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE billing_payments SET status = 'paid', paid_at = datetime('now') WHERE id = ?");
        $stmt->execute([$id]);
    }
}
