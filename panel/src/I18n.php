<?php

namespace App;

/**
 * Локализация интерфейса. Словари — panel/lang/<code>.php (ассоциативный
 * массив ключ→строка). Добавить язык = положить ещё один файл в lang/.
 *
 * Активный язык определяется один раз (bootstrap): выбор пользователя
 * (users.lang) > cookie panel_lang > дефолт. Ключи одинаковы для всех
 * языков; при отсутствии перевода — fallback на дефолтный язык, затем сам ключ.
 */
class I18n
{
    public const DEFAULT_LANG = 'ru';

    private static string $lang = self::DEFAULT_LANG;
    /** @var array<string,array<string,string>> */
    private static array $dicts = [];

    /** Языки, для которых есть словарь: код => родное название. */
    public static function available(): array
    {
        $names = ['ru' => 'Русский', 'en' => 'English'];
        $out = [];
        foreach (glob(self::langDir() . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $out[$code] = $names[$code] ?? strtoupper($code);
        }
        return $out ?: ['ru' => 'Русский'];
    }

    public static function setLang(string $lang): void
    {
        $lang = preg_replace('/[^a-z]/', '', strtolower($lang));
        if ($lang !== '' && is_file(self::langDir() . '/' . $lang . '.php')) {
            self::$lang = $lang;
        }
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    /**
     * Перевод по ключу. Доп. аргументы подставляются через sprintf
     * (в строках можно использовать %s, %d и т.п.).
     */
    public static function t(string $key, ...$args): string
    {
        $s = self::dict(self::$lang)[$key]
            ?? self::dict(self::DEFAULT_LANG)[$key]
            ?? $key;
        return $args ? vsprintf($s, $args) : $s;
    }

    /** Для отдачи строк в JS: весь словарь активного языка (с fallback-слиянием). */
    public static function jsDict(): array
    {
        return array_merge(self::dict(self::DEFAULT_LANG), self::dict(self::$lang));
    }

    private static function dict(string $lang): array
    {
        if (!isset(self::$dicts[$lang])) {
            $file = self::langDir() . '/' . $lang . '.php';
            self::$dicts[$lang] = is_file($file) ? (require $file) : [];
        }
        return self::$dicts[$lang];
    }

    private static function langDir(): string
    {
        return __DIR__ . '/../lang';
    }
}
