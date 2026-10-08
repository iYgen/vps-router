<?php

namespace App\Models;

use App\Database;

/** Дизайн сайта/кабинета (design data). Бизнес-данных не хранит. */
class SiteDesign
{
    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM site_designs ORDER BY id DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM site_designs WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Активный (опубликованный) дизайн. */
    public static function published(): ?array
    {
        $row = Database::get()->query("SELECT * FROM site_designs WHERE status = 'published' ORDER BY version DESC, id DESC LIMIT 1")->fetch();
        return $row ?: null;
    }

    /** Черновик для редактирования (последний) или, если нет, активный. */
    public static function draft(): ?array
    {
        $row = Database::get()->query("SELECT * FROM site_designs WHERE status = 'draft' ORDER BY id DESC LIMIT 1")->fetch();
        return $row ?: self::published();
    }

    public static function decode(?array $row): array
    {
        if (!$row) {
            return [];
        }
        $cfg = json_decode((string) ($row['config_json'] ?? '{}'), true);
        return is_array($cfg) ? $cfg : [];
    }

    public static function create(string $name, string $template, array $config, string $status = 'draft'): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO site_designs (name, template, config_json, status, author) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $template, json_encode($config, JSON_UNESCAPED_UNICODE), $status, 'admin']);
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, string $template, array $config): void
    {
        $stmt = Database::get()->prepare('UPDATE site_designs SET template = ?, config_json = ? WHERE id = ?');
        $stmt->execute([$template, json_encode($config, JSON_UNESCAPED_UNICODE), $id]);
    }

    /** Гарантировать редактируемый черновик: если нет — создать из опубликованного. */
    public static function ensureDraft(): array
    {
        $row = Database::get()->query("SELECT * FROM site_designs WHERE status = 'draft' ORDER BY id DESC LIMIT 1")->fetch();
        if ($row) {
            return $row;
        }
        $pub = self::published();
        $id = self::create(
            'Черновик',
            (string) ($pub['template'] ?? 'modern-saas'),
            self::decode($pub),
            'draft'
        );
        if ($pub) {
            $stmt = Database::get()->prepare('UPDATE site_designs SET based_on_id = ? WHERE id = ?');
            $stmt->execute([(int) $pub['id'], $id]);
        }
        return self::find($id);
    }

    /** Опубликовать черновик: прежний published → archived, этот → published с новой версией. */
    public static function publish(int $draftId): void
    {
        $pdo = Database::get();
        $maxV = (int) $pdo->query('SELECT COALESCE(MAX(version),0) FROM site_designs')->fetchColumn();
        $pdo->exec("UPDATE site_designs SET status = 'archived' WHERE status = 'published'");
        $stmt = $pdo->prepare("UPDATE site_designs SET status = 'published', version = ?, published_at = datetime('now') WHERE id = ?");
        $stmt->execute([$maxV + 1, $draftId]);
    }
}
