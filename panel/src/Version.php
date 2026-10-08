<?php

namespace App;

/**
 * Версия панели и сравнение версий (semver-подобное).
 *
 * Локальная версия — из файла panel/VERSION (одна строка, напр. «1.2.3»).
 * Его бампят при релизе; после выкладки нового кода миграции накатываются сами
 * (App\Database::migrate при первом подключении), а UpdateChecker сверяет
 * локальную версию с версией в репозитории оператора.
 */
class Version
{
    /** Текущая версия панели из файла VERSION (или 0.0.0, если файла нет). */
    public static function current(): string
    {
        $f = __DIR__ . '/../VERSION';
        if (is_file($f)) {
            $v = trim((string) @file_get_contents($f));
            if ($v !== '') {
                return self::normalize($v);
            }
        }
        return '0.0.0';
    }

    /** Убирает ведущий «v» и мусор по краям: «v1.2.3» → «1.2.3». */
    public static function normalize(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && ($v[0] === 'v' || $v[0] === 'V')) {
            $v = substr($v, 1);
        }
        return trim($v);
    }

    /**
     * Сравнение версий по числовым компонентам (1.10 > 1.9). Нечисловые хвосты
     * (напр. «-rc1») игнорируются для устойчивости. Возвращает -1/0/1.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        $n = max(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x !== $y) {
                return $x < $y ? -1 : 1;
            }
        }
        return 0;
    }

    /** @return int[] числовые компоненты версии */
    private static function parts(string $v): array
    {
        $v = self::normalize($v);
        // Берём только числовые группы: «1.2.3-rc4» → [1,2,3,4]; но хвост после
        // первого нечислового разделителя достаточно предсказуем для сравнения.
        preg_match_all('/\d+/', $v, $m);
        return array_map('intval', $m[0] ?: ['0']);
    }

    /** Есть ли смысл обновляться: remote строго новее local. */
    public static function isUpdateAvailable(string $local, string $remote): bool
    {
        $remote = self::normalize($remote);
        if ($remote === '' || !preg_match('/\d/', $remote)) {
            return false; // мусор вместо версии — не поднимаем ложную тревогу
        }
        return self::compare($remote, $local) > 0;
    }

    /**
     * Файлы миграций, которых ещё нет в schema_migrations. В норме пусто —
     * миграции накатываются автоматически при первом подключении к БД. Непустой
     * список = код обновлён, но БД ещё не мигрировала (диагностика для UI).
     *
     * @return string[] версии (имена без .sql)
     */
    public static function pendingMigrations(): array
    {
        $dir = __DIR__ . '/../migrations';
        $files = glob($dir . '/*.sql') ?: [];
        $all = array_map(fn($f) => basename($f, '.sql'), $files);
        sort($all, SORT_NATURAL);

        try {
            $applied = [];
            foreach (Database::get()->query('SELECT version FROM schema_migrations') as $row) {
                $applied[$row['version']] = true;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter($all, fn($v) => !isset($applied[$v])));
    }
}
