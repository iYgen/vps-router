<?php

namespace App\Models;

use App\Database;

/** Шаблон оформления портала (палитра + свой CSS + хедер/футер). */
class PortalTemplate
{
    public const FIELDS = ['name', 'dark', 'accent', 'bg', 'surface', 'surface2', 'text', 'muted', 'border', 'radius', 'layout_width', 'plan_style', 'intro_html', 'custom_css', 'header_html', 'footer_html'];
    public const PLAN_STYLES = ['cards', 'table', 'rows'];
    public const WIDTHS = ['contained', 'full'];

    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM portal_templates ORDER BY is_builtin DESC, id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM portal_templates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Активный шаблон (по настройке) или дефолтный «Лайт»/первый. */
    public static function active(): ?array
    {
        $id = (int) Setting::get('portal_active_template_id', 0);
        if ($id && ($t = self::find($id))) {
            return $t;
        }
        $light = Database::get()->query('SELECT * FROM portal_templates WHERE dark = 0 ORDER BY is_builtin DESC, id LIMIT 1')->fetch();
        return $light ?: (Database::get()->query('SELECT * FROM portal_templates ORDER BY id LIMIT 1')->fetch() ?: null);
    }

    public static function create(array $d): int
    {
        $cols = self::FIELDS;
        $place = implode(',', array_map(fn($c) => ":$c", $cols));
        $stmt = Database::get()->prepare('INSERT INTO portal_templates (' . implode(',', $cols) . ') VALUES (' . $place . ')');
        $stmt->execute(self::bind($d));
        return (int) Database::get()->lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        $set = implode(', ', array_map(fn($c) => "$c = :$c", self::FIELDS));
        $params = self::bind($d);
        $params[':id'] = $id;
        $stmt = Database::get()->prepare("UPDATE portal_templates SET $set WHERE id = :id");
        $stmt->execute($params);
    }

    public static function delete(int $id): void
    {
        // Встроенные не удаляем.
        $stmt = Database::get()->prepare('DELETE FROM portal_templates WHERE id = ? AND is_builtin = 0');
        $stmt->execute([$id]);
    }

    private static function bind(array $d): array
    {
        return [
            ':name'        => trim((string) ($d['name'] ?? 'Template')) ?: 'Template',
            ':dark'        => !empty($d['dark']) ? 1 : 0,
            ':accent'      => self::color($d['accent'] ?? '', '#2563eb'),
            ':bg'          => self::color($d['bg'] ?? '', '#17171a'),
            ':surface'     => self::color($d['surface'] ?? '', '#1d1d22'),
            ':surface2'    => self::color($d['surface2'] ?? '', '#222228'),
            ':text'        => self::color($d['text'] ?? '', '#f5f5f7'),
            ':muted'       => self::color($d['muted'] ?? '', '#a1a1aa'),
            ':border'      => trim((string) ($d['border'] ?? '')) ?: 'rgba(255,255,255,.08)',
            ':radius'      => preg_match('/^\d{1,3}px$/', (string) ($d['radius'] ?? '')) ? $d['radius'] : '14px',
            ':layout_width' => in_array($d['layout_width'] ?? '', self::WIDTHS, true) ? $d['layout_width'] : 'contained',
            ':plan_style'  => in_array($d['plan_style'] ?? '', self::PLAN_STYLES, true) ? $d['plan_style'] : 'cards',
            ':intro_html'  => trim((string) ($d['intro_html'] ?? '')) ?: null,
            ':custom_css'  => trim((string) ($d['custom_css'] ?? '')) ?: null,
            ':header_html' => trim((string) ($d['header_html'] ?? '')) ?: null,
            ':footer_html' => trim((string) ($d['footer_html'] ?? '')) ?: null,
        ];
    }

    /** Разрешаем только безопасные CSS-цвета (#hex, rgb/rgba, имя) — не даём впихнуть что-то лишнее. */
    private static function color(string $v, string $fallback): string
    {
        $v = trim($v);
        return preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,%\s]+\)|[a-zA-Z]{3,20})$/', $v) ? $v : $fallback;
    }
}
