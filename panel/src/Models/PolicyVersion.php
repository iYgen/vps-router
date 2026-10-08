<?php

namespace App\Models;

use App\Database;

/** Immutable snapshot-версии canonical policy профиля — см. App\ClientPolicyBuilder. */
class PolicyVersion
{
    public static function forProfile(int $profileId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM policy_versions WHERE profile_id = ? ORDER BY version DESC');
        $stmt->execute([$profileId]);
        return $stmt->fetchAll();
    }

    public static function latest(int $profileId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM policy_versions WHERE profile_id = ? ORDER BY version DESC LIMIT 1');
        $stmt->execute([$profileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM policy_versions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function nextVersionNumber(int $profileId): int
    {
        $latest = self::latest($profileId);
        return $latest ? ((int) $latest['version'] + 1) : 1;
    }

    public static function create(int $profileId, int $version, array $policy, ?string $createdBy, bool $published = true): int
    {
        $json = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = Database::get()->prepare(
            "INSERT INTO policy_versions (profile_id, version, policy_json, policy_hash, created_by, published_at)
             VALUES (?, ?, ?, ?, ?, " . ($published ? "datetime('now')" : 'NULL') . ')'
        );
        $stmt->execute([$profileId, $version, $json, hash('sha256', $json), $createdBy]);
        return (int) Database::get()->lastInsertId();
    }
}
