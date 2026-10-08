<?php

namespace App;

use App\Models\Setting;

/**
 * Самообновление кода панели из репозитория (tar.gz ветки/релиза).
 *
 * Работает от пользователя панели (php-fpm владеет /var/www/panel), без sudo:
 * скачивает tar-архив репозитория, распаковывает во временную папку, делает
 * бэкап текущего кода, накладывает новый код поверх (НЕ трогая vendor/, config.php
 * вне webroot и БД в /var/lib/panel), накатывает миграции и сбрасывает OPcache.
 * При любой ошибке на этапах backup→apply→migrate — откат из бэкапа.
 *
 * Адрес архива: `update_tarball_url` из настроек, либо выводится из
 * `update_repo_url` (GitHub) + `update_branch` (по умолчанию main):
 *   https://codeload.github.com/<owner>/<repo>/tar.gz/refs/heads/<branch>
 *
 * Обновляется только КОД: config.php (в /etc/panel) и БД (в /var/lib/panel) лежат
 * вне дерева кода и не затрагиваются; vendor/ не входит в архив и сохраняется.
 */
class SelfUpdater
{
    private const DL_TIMEOUT = 60;
    private const MAX_BYTES = 64 * 1024 * 1024; // 64 МБ — архив кода заведомо меньше

    /** Каталоги/файлы кода, которые бэкапим и накладываем (относительно корня). */
    private const CODE_PATHS = ['src', 'public', 'lang', 'migrations', 'bin', 'templates',
        'composer.json', 'composer.lock', 'phpunit.xml', 'VERSION'];

    /** Корень кода панели (/var/www/panel). */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function workDir(): string
    {
        return self::root() . '/.update';
    }

    /** URL tar.gz: явный из настроек или выведенный из repo_url + branch. */
    public static function tarballUrl(): string
    {
        $explicit = trim((string) Setting::get('update_tarball_url', ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $repo = rtrim(UpdateChecker::repoUrl(), '/');
        $branch = trim((string) Setting::get('update_branch', '')) ?: 'main';
        if (preg_match('#github\.com/([^/]+/[^/]+?)(?:\.git)?$#i', $repo, $m)) {
            return 'https://codeload.github.com/' . $m[1] . '/tar.gz/refs/heads/' . rawurlencode($branch);
        }
        return '';
    }

    public static function available(): bool
    {
        return function_exists('shell_exec') && self::tarballUrl() !== '' && is_writable(self::root());
    }

    /**
     * Выполняет обновление. Возвращает подробный результат с журналом шагов.
     * @return array{ok:bool,from:string,to:?string,applied_migrations:array,log:array,error:?string}
     */
    public static function run(?callable $fetcher = null): array
    {
        $log = [];
        $from = Version::current();
        $root = self::root();
        $work = self::workDir();
        $extract = $work . '/extract';
        $tgz = $work . '/download.tgz';
        $backup = $work . '/backup-' . gmdate('Ymd-His') . '.tgz';

        $fail = function (string $msg) use (&$log, $from) {
            $log[] = 'ОШИБКА: ' . $msg;
            return ['ok' => false, 'from' => $from, 'to' => null, 'applied_migrations' => [], 'log' => $log, 'error' => $msg];
        };

        if (!function_exists('shell_exec')) {
            return $fail('shell_exec недоступен — самообновление невозможно.');
        }
        $url = self::tarballUrl();
        if ($url === '') {
            return $fail('Не задан адрес архива (укажите репозиторий GitHub в Настройках или update_tarball_url).');
        }
        if (!preg_match('#^https://#i', $url)) {
            return $fail('Адрес архива должен быть https://');
        }
        if (!is_writable($root)) {
            return $fail('Нет прав на запись в ' . $root);
        }

        // Чистим и готовим рабочую папку.
        self::rrmdir($work);
        if (!@mkdir($extract, 0700, true) && !is_dir($extract)) {
            return $fail('Не удалось создать рабочую папку ' . $work);
        }
        $log[] = 'Рабочая папка: ' . $work;

        // 1. Скачиваем архив.
        $data = $fetcher ? $fetcher($url) : self::download($url);
        if ($data === null || strlen($data) < 1024) {
            return $fail('Не удалось скачать архив по ' . $url . ' (сеть/таймаут/блокировка). '
                . 'Если прямого доступа к репозиторию нет — включите загрузку через exit.');
        }
        if (file_put_contents($tgz, $data) === false) {
            return $fail('Не удалось сохранить архив во временную папку.');
        }
        $log[] = 'Скачано: ' . self::human(strlen($data));

        // 2. Распаковываем.
        [$rc, $out] = self::sh('tar xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($extract) . ' 2>&1');
        if ($rc !== 0) {
            return $fail('Распаковка не удалась: ' . trim($out));
        }
        // Находим <что-то>/panel/ внутри архива.
        $panelDir = self::findPanelDir($extract);
        if ($panelDir === null) {
            return $fail('В архиве не найден каталог panel/ — неожиданная структура репозитория.');
        }
        $log[] = 'Распаковано, код в: ' . basename(dirname($panelDir)) . '/panel';

        // 3. Санити-проверка нового кода и его версии.
        if (!is_file($panelDir . '/VERSION') || !is_file($panelDir . '/src/bootstrap.php')) {
            return $fail('В архиве нет panel/VERSION или panel/src/bootstrap.php — архив не похож на панель.');
        }
        $to = Version::normalize((string) @file_get_contents($panelDir . '/VERSION'));
        if ($to === '' || !preg_match('/\d/', $to)) {
            return $fail('Не удалось прочитать версию из нового VERSION.');
        }
        $log[] = 'Версия в архиве: ' . $to . ' (текущая: ' . $from . ')';

        // 4. Бэкап текущего кода (без vendor и .update).
        $present = array_values(array_filter(self::CODE_PATHS, fn($p) => file_exists($root . '/' . $p)));
        [$rc, $out] = self::sh('tar czf ' . escapeshellarg($backup) . ' -C ' . escapeshellarg($root)
            . ' ' . implode(' ', array_map('escapeshellarg', $present)) . ' 2>&1');
        if ($rc !== 0 || !is_file($backup)) {
            return $fail('Бэкап не создан, обновление прервано: ' . trim($out));
        }
        $log[] = 'Бэкап текущего кода: ' . basename($backup) . ' (' . self::human((int) @filesize($backup)) . ')';

        // 5. Накладываем новый код поверх (cp -rf обновляет mtime → OPcache подхватит).
        [$rc, $out] = self::sh('cp -rf ' . escapeshellarg($panelDir) . '/. ' . escapeshellarg($root) . '/ 2>&1');
        if ($rc !== 0) {
            $log[] = 'Наложение кода не удалось — откатываю.';
            self::restore($backup, $log);
            return $fail('Копирование нового кода не удалось: ' . trim($out));
        }
        $log[] = 'Новый код наложен (vendor/, config.php и БД не затронуты).';

        // 6. OPcache сброс (если доступно в этом SAPI) + миграции.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $log[] = 'OPcache сброшен.';
        }

        $applied = [];
        try {
            $before = Version::pendingMigrations();
            Database::migrate();
            $after = Version::pendingMigrations();
            $applied = array_values(array_diff($before, $after));
            $log[] = $applied ? ('Накатаны миграции: ' . implode(', ', $applied)) : 'Новых миграций нет.';
            if ($after) {
                throw new \RuntimeException('остались не накатанные миграции: ' . implode(', ', $after));
            }
        } catch (\Throwable $e) {
            $log[] = 'Миграции упали — откатываю код: ' . $e->getMessage();
            self::restore($backup, $log);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            return $fail('Миграции не применились, выполнен откат кода: ' . $e->getMessage());
        }

        // 7. Проверяем версию на диске.
        $now = Version::current();
        $log[] = 'Версия после обновления: ' . $now;

        // Прибираем временные файлы (бэкап оставляем на всякий случай).
        @unlink($tgz);
        self::rrmdir($extract);

        return ['ok' => true, 'from' => $from, 'to' => $to, 'applied_migrations' => $applied, 'log' => $log, 'error' => null];
    }

    /** Восстановление кода из бэкапа (best-effort). */
    private static function restore(string $backup, array &$log): void
    {
        if (!is_file($backup)) {
            $log[] = 'Бэкап отсутствует — откат невозможен!';
            return;
        }
        [$rc, $out] = self::sh('tar xzf ' . escapeshellarg($backup) . ' -C ' . escapeshellarg(self::root()) . ' 2>&1');
        $log[] = $rc === 0 ? 'Откат из бэкапа выполнен.' : ('Откат не удался: ' . trim($out));
    }

    /** Скачивание тела архива (через exit SOCKS если включено, иначе напрямую). */
    private static function download(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => self::DL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_USERAGENT => 'vps_router-self-updater',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if (Setting::get('list_fetch_via_exit', '0') === '1') {
                $opts[CURLOPT_PROXY] = '127.0.0.1:' . SingboxConfigBuilder::LIST_FETCH_PORT;
                $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
            curl_setopt_array($ch, $opts);
            $data = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err === 0 && is_string($data) && $data !== '' && strlen($data) <= self::MAX_BYTES) {
                return $data;
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => self::DL_TIMEOUT, 'follow_location' => 1, 'max_redirects' => 5,
            'header' => "User-Agent: vps_router-self-updater\r\n",
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        return $data === false ? null : $data;
    }

    /** Ищет каталог .../panel внутри распакованного архива (архив GitHub: <repo>-<ref>/panel). */
    private static function findPanelDir(string $extract): ?string
    {
        if (is_dir($extract . '/panel')) {
            return $extract . '/panel';
        }
        foreach (glob($extract . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (is_dir($d . '/panel')) {
                return $d . '/panel';
            }
        }
        return null;
    }

    /** Выполнить shell-команду, вернуть [код возврата, вывод]. */
    private static function sh(string $cmd): array
    {
        $out = [];
        $rc = 1;
        if (function_exists('proc_open')) {
            $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) {
                $o = stream_get_contents($pipes[1]);
                $e = stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) {
                    is_resource($pipe) && fclose($pipe);
                }
                $rc = proc_close($p);
                return [$rc, trim($o . $e)];
            }
        }
        $res = @shell_exec($cmd . '; echo "__RC__$?"');
        if ($res !== null && preg_match('/__RC__(\d+)\s*$/', $res, $m)) {
            $rc = (int) $m[1];
            $res = preg_replace('/__RC__\d+\s*$/', '', $res);
        }
        return [$rc, trim((string) $res)];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private static function human(int $b): string
    {
        $u = ['Б', 'КБ', 'МБ', 'ГБ'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return round($b, 1) . ' ' . $u[$i];
    }
}
