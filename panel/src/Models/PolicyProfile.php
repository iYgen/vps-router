<?php

namespace App\Models;

use App\Database;

/**
 * Именованный набор маршрутов для Client Routing Policy (Keenetic и,
 * в будущем, Android/Windows) — см. docs/infrastructure-ui.md раздел 4.
 * Ссылается на уже существующие rule_groups через policy_profile_routes,
 * не дублирует маршруты (раздел 6 дока).
 */
class PolicyProfile
{
    public const ACTIONS = ['direct_local', 'proxy', 'block'];

    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM policy_profiles ORDER BY name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM policy_profiles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        self::validate($data);
        $stmt = Database::get()->prepare(
            'INSERT INTO policy_profiles (name, description, default_action, enabled) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['default_action'] ?? 'direct_local',
            array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        self::validate($data);
        $stmt = Database::get()->prepare(
            "UPDATE policy_profiles SET name = ?, description = ?, default_action = ?, enabled = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['default_action'] ?? 'direct_local',
            array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM policy_profiles WHERE id = ?')->execute([$id]);
    }

    /** Rule groups, назначенные профилю — с тем же обогащением, что RuleGroup::all() (exit_name/server_set_name). */
    public static function routes(int $profileId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT rg.*, es.name AS exit_name, ss.name AS server_set_name
             FROM policy_profile_routes ppr
             JOIN rule_groups rg ON rg.id = ppr.rule_group_id
             LEFT JOIN exit_servers es ON es.id = rg.exit_server_id
             LEFT JOIN server_sets ss ON ss.id = rg.server_set_id
             WHERE ppr.profile_id = ?
             ORDER BY rg.sort_order, rg.name'
        );
        $stmt->execute([$profileId]);
        return $stmt->fetchAll();
    }

    public static function addRoute(int $profileId, int $ruleGroupId): void
    {
        $exists = Database::get()->prepare('SELECT 1 FROM policy_profile_routes WHERE profile_id = ? AND rule_group_id = ?');
        $exists->execute([$profileId, $ruleGroupId]);
        if ($exists->fetchColumn()) {
            return;
        }
        Database::get()->prepare('INSERT INTO policy_profile_routes (profile_id, rule_group_id) VALUES (?, ?)')
            ->execute([$profileId, $ruleGroupId]);
    }

    public static function removeRoute(int $profileId, int $ruleGroupId): void
    {
        Database::get()->prepare('DELETE FROM policy_profile_routes WHERE profile_id = ? AND rule_group_id = ?')
            ->execute([$profileId, $ruleGroupId]);
    }

    private static function validate(array $data): void
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Название профиля обязательно');
        }
        if (!in_array($data['default_action'] ?? 'direct_local', self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Неизвестное действие по умолчанию');
        }
    }
}
