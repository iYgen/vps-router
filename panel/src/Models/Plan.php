<?php

namespace App\Models;

use App\Database;

/** Тариф биллинга: цена за период, лимит устройств/трафика. */
class Plan
{
    public static function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM billing_plans' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, id';
        return Database::get()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM billing_plans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO billing_plans (name, price, currency, period_days, device_limit, traffic_gb, enabled, sort_order, description, features, featured)
             VALUES (:name, :price, :currency, :period_days, :device_limit, :traffic_gb, :enabled, :sort_order, :description, :features, :featured)'
        );
        $stmt->execute(self::bind($d));
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        $params = self::bind($d);
        $params[':id'] = $id;
        $stmt = Database::get()->prepare(
            'UPDATE billing_plans SET name=:name, price=:price, currency=:currency, period_days=:period_days,
             device_limit=:device_limit, traffic_gb=:traffic_gb, enabled=:enabled, sort_order=:sort_order,
             description=:description, features=:features, featured=:featured WHERE id=:id'
        );
        $stmt->execute($params);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM billing_plans WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Переупорядочить тарифы: массив id в нужном порядке → sort_order 0,1,2… */
    public static function reorder(array $ids): void
    {
        $stmt = Database::get()->prepare('UPDATE billing_plans SET sort_order = ? WHERE id = ?');
        $i = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $stmt->execute([$i++, $id]);
            }
        }
    }

    private static function bind(array $d): array
    {
        $traffic = ($d['traffic_gb'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $d['traffic_gb']);
        return [
            ':name'         => trim((string) ($d['name'] ?? '')),
            ':price'        => (float) str_replace(',', '.', (string) ($d['price'] ?? 0)),
            ':currency'     => trim((string) ($d['currency'] ?? 'RUB')) ?: 'RUB',
            ':period_days'  => max(1, (int) ($d['period_days'] ?? 30)),
            ':device_limit' => max(1, (int) ($d['device_limit'] ?? 1)),
            ':traffic_gb'   => $traffic,
            ':enabled'      => !empty($d['enabled']) ? 1 : 0,
            ':sort_order'   => (int) ($d['sort_order'] ?? 0),
            ':description'  => trim((string) ($d['description'] ?? '')) ?: null,
            ':features'     => trim((string) ($d['features'] ?? '')) ?: null,
            ':featured'     => !empty($d['featured']) ? 1 : 0,
        ];
    }
}
