<?php

namespace App\Models;

use App\Database;

class Rule
{
    public const TYPES = ['domain_suffix', 'domain_full', 'domain_keyword', 'ip_cidr', 'geosite'];

    public static function forGroup(int $groupId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM rules WHERE group_id = ? ORDER BY id');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public static function create(int $groupId, string $type, string $value): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown rule type: ' . $type);
        }
        $stmt = Database::get()->prepare(
            'INSERT INTO rules (group_id, type, value) VALUES (?, ?, ?)'
        );
        $stmt->execute([$groupId, $type, trim($value)]);
        return (int) Database::get()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM rules WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function deleteAllForGroup(int $groupId): void
    {
        $stmt = Database::get()->prepare('DELETE FROM rules WHERE group_id = ?');
        $stmt->execute([$groupId]);
    }

    /** Быстрая вставка много правил одного типа в одной транзакции (импорт списков). */
    public static function createMany(int $groupId, string $type, array $values): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown rule type: ' . $type);
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO rules (group_id, type, value) VALUES (?, ?, ?)');
        $pdo->beginTransaction();
        try {
            foreach ($values as $value) {
                $stmt->execute([$groupId, $type, trim($value)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
