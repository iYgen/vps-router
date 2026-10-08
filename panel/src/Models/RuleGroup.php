<?php

namespace App\Models;

use App\Database;

class RuleGroup
{
    /**
     * @param int|null $routerServerId scope to one router; null = every router
     * @param bool $includeNull also include groups with no router set (legacy
     *        rows belong to the self-router) — used when scoping to self
     */
    public static function all(?int $routerServerId = null, bool $includeNull = false): array
    {
        $sql = 'SELECT rg.*, es.name AS exit_name, es.status AS exit_status, ss.name AS server_set_name
                FROM rule_groups rg
                LEFT JOIN exit_servers es ON es.id = rg.exit_server_id
                LEFT JOIN server_sets ss ON ss.id = rg.server_set_id';
        if ($routerServerId !== null) {
            $sql .= $includeNull
                ? ' WHERE (rg.router_server_id = ? OR rg.router_server_id IS NULL)'
                : ' WHERE rg.router_server_id = ?';
        }
        $sql .= ' ORDER BY rg.sort_order, rg.name';
        if ($routerServerId !== null) {
            $stmt = Database::get()->prepare($sql);
            $stmt->execute([$routerServerId]);
            return $stmt->fetchAll();
        }
        return Database::get()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM rule_groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(
        string $name,
        ?int $exitServerId,
        ?int $serverSetId = null,
        ?string $comment = null,
        ?int $parentId = null,
        string $origin = 'manual',
        ?string $importSourcePath = null,
        ?int $routerServerId = null
    ): int {
        $stmt = Database::get()->prepare(
            'INSERT INTO rule_groups (name, exit_server_id, server_set_id, comment, parent_id, origin, import_source_path, router_server_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $exitServerId, $serverSetId, $comment, $parentId, $origin, $importSourcePath, $routerServerId]);
        return (int) Database::get()->lastInsertId();
    }

    /** Для идемпотентного re-sync импортированных списков (см. App\IpListImporter). */
    public static function findByImportSource(string $path): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM rule_groups WHERE import_source_path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** name UNIQUE — используется импортёром, чтобы не столкнуться с уже занятым именем. */
    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        $stmt = Database::get()->prepare('SELECT id FROM rule_groups WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row && (int) $row['id'] !== $excludeId;
    }

    public static function setParent(int $id, ?int $parentId): void
    {
        $stmt = Database::get()->prepare('UPDATE rule_groups SET parent_id = ? WHERE id = ?');
        $stmt->execute([$parentId, $id]);
    }

    /** Дерево: плоский список all() с добавленным полем children_ids для рендера на клиенте. */
    public static function tree(?int $routerServerId = null): array
    {
        $groups = self::all($routerServerId);
        $byId = [];
        foreach ($groups as $g) {
            $g['children_ids'] = [];
            $byId[$g['id']] = $g;
        }
        foreach ($byId as $id => $g) {
            if ($g['parent_id'] && isset($byId[$g['parent_id']])) {
                $byId[$g['parent_id']]['children_ids'][] = $id;
            }
        }
        return array_values($byId);
    }

    public static function rename(int $id, string $name): void
    {
        $stmt = Database::get()->prepare('UPDATE rule_groups SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public static function setExitServer(int $id, ?int $exitServerId): void
    {
        // Ручной выбор одного exit-сервера отменяет привязку к server set
        // (это два взаимоисключающих режима выбора выходного узла).
        $stmt = Database::get()->prepare('UPDATE rule_groups SET exit_server_id = ?, server_set_id = NULL WHERE id = ?');
        $stmt->execute([$exitServerId, $id]);
    }

    public static function setServerSet(int $id, ?int $serverSetId): void
    {
        $stmt = Database::get()->prepare('UPDATE rule_groups SET server_set_id = ?, exit_server_id = NULL WHERE id = ?');
        $stmt->execute([$serverSetId, $id]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        $stmt = Database::get()->prepare('UPDATE rule_groups SET enabled = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    public static function setComment(int $id, ?string $comment): void
    {
        $stmt = Database::get()->prepare('UPDATE rule_groups SET comment = ? WHERE id = ?');
        $stmt->execute([$comment, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM rule_groups WHERE id = ?');
        $stmt->execute([$id]);
    }
}
