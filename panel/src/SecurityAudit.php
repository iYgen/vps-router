<?php

namespace App;

use App\Models\Setting;

/**
 * Проверки безопасности САМОГО сервера панели (self): устаревшая ОС/PHP и
 * непоставленные обновления пакетов. Мгновенные проверки (EOL ОС/PHP) считаются
 * на лету без exec; счётчик обновлений пакетов берётся из кэша, который
 * заполняет bin/security_scan.php по cron (пакетный менеджер в веб-запросе не
 * запускаем — он медленный и требует прав).
 *
 * Возвращает findings в виде ключей i18n + аргументов, чтобы баннер/страница
 * показывали их на языке пользователя (см. lang/*.php, ключи sec.*).
 */
class SecurityAudit
{
    /** Даты окончания поддержки популярных дистрибутивов (ISO, конец жизни). */
    private const OS_EOL = [
        'centos:7' => '2024-06-30',
        'centos:8' => '2021-12-31',
        'centos:6' => '2020-11-30',
        'debian:8' => '2020-06-30',
        'debian:9' => '2022-06-30',
        'debian:10' => '2024-06-30',
        'debian:11' => '2026-08-31',
        'ubuntu:16.04' => '2021-04-30',
        'ubuntu:18.04' => '2023-05-31',
        'ubuntu:20.04' => '2025-05-31',
        'ubuntu:22.04' => '2027-06-01',
    ];

    /** PHP-ветки и дата окончания их поддержки безопасности. */
    private const PHP_EOL = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** Последний выпуск ветки PHP (php.net) — JSON вида {"version":"8.2.35",...}. */
    private const PHP_RELEASES_URL = 'https://www.php.net/releases/?json&version=%s';

    /**
     * Опубликованные уязвимости php-src (GitHub Security Advisories): severity,
     * CVE и исправленные версии по веткам — по ним видно, закрыта ли уязвимость
     * в текущей версии PHP.
     */
    private const PHP_ADVISORIES_URL = 'https://api.github.com/repos/php/php-src/security-advisories?per_page=100&sort=published&direction=desc';

    private const HTTP_TIMEOUT = 15;
    private const HTTP_MAX_BYTES = 4194304;

    /**
     * Сводка для страницы настроек: ОС, PHP, обновления, дата последнего скана.
     */
    public static function report(): array
    {
        return [
            'os' => self::osStatus(),
            'php' => self::phpStatus(),
            'updates' => self::pendingUpdates(),
            'findings' => self::findings(),
        ];
    }

    /**
     * Только заметные проблемы — для баннера. Каждый элемент:
     * ['level' => 'warning'|'error', 'key' => 'sec.*', 'args' => [...]].
     *
     * @return array<int,array{level:string,key:string,args:array}>
     */
    public static function findings(): array
    {
        $out = [];

        $os = self::osStatus();
        if ($os['eol']) {
            $out[] = [
                'level' => 'error',
                'key' => 'sec.os_eol',
                'args' => [$os['pretty'], $os['eol_date']],
            ];
        }

        $php = self::phpStatus();
        $severe = array_values(array_filter($php['vulns'], fn($v) => in_array($v['severity'], ['critical', 'high'], true)));
        if ($severe) {
            $cves = array_map(fn($v) => $v['cve'] ?: $v['id'], array_slice($severe, 0, 4));
            $out[] = [
                'level' => 'error',
                'key' => 'sec.php_vulns',
                'args' => [$php['version'], count($severe), implode(', ', $cves), $php['latest'] ?? '—'],
            ];
        } elseif ($php['outdated']) {
            $out[] = [
                'level' => 'warning',
                'key' => 'sec.php_outdated',
                'args' => [$php['version'], $php['latest']],
            ];
        }
        if ($php['eol']) {
            $out[] = [
                'level' => 'warning',
                'key' => 'sec.php_eol',
                'args' => [$php['version'], $php['eol_date']],
            ];
        }

        $upd = self::pendingUpdates();
        if (($upd['security'] ?? 0) > 0) {
            $out[] = ['level' => 'error', 'key' => 'sec.updates_security', 'args' => [$upd['security']]];
        } elseif (($upd['total'] ?? 0) > 0) {
            $out[] = ['level' => 'warning', 'key' => 'sec.updates_total', 'args' => [$upd['total']]];
        }

        return $out;
    }

    public static function osStatus(): array
    {
        [$id, $version, $pretty] = self::osRelease();
        $eolDate = null;
        if ($id !== '' && $version !== '') {
            $key = $id . ':' . $version;
            // Ubuntu хранит VERSION_ID как "22.04", CentOS/Debian — как "7"/"11".
            $eolDate = self::OS_EOL[$key] ?? null;
            if ($eolDate === null && str_contains($version, '.')) {
                $eolDate = self::OS_EOL[$id . ':' . explode('.', $version)[0]] ?? null;
            }
        }
        $eol = $eolDate !== null && strtotime($eolDate) < time();

        return [
            'id' => $id,
            'version' => $version,
            'pretty' => $pretty,
            'eol_date' => $eolDate,
            'eol' => $eol,
        ];
    }

    public static function phpStatus(): array
    {
        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $eolDate = self::PHP_EOL[$branch] ?? null;
        $eol = $eolDate !== null && strtotime($eolDate) < time();

        // Сверка с апстримом — из кэша bin/security_scan.php. Кэш снят для
        // другой версии (PHP обновили, а cron ещё не прошёл) — не показываем.
        $raw = Setting::get('security_php_upstream');
        $up = $raw ? json_decode($raw, true) : null;
        if (!is_array($up) || ($up['version'] ?? '') !== PHP_VERSION) {
            $up = [];
        }
        $latest = isset($up['latest']) && $up['latest'] !== '' ? (string) $up['latest'] : null;

        return [
            'version' => PHP_VERSION,
            'branch' => $branch,
            'eol_date' => $eolDate,
            'eol' => $eol,
            'latest' => $latest,
            'outdated' => $latest !== null && version_compare(PHP_VERSION, $latest, '<'),
            'vulns' => is_array($up['vulns'] ?? null) ? $up['vulns'] : [],
            'checked_at' => $up['checked_at'] ?? null,
            'error' => $up['error'] ?? null,
        ];
    }

    /**
     * Сверяет PHP панели с апстримом: последний патч ветки (php.net) и
     * опубликованные уязвимости php-src, не закрытые в текущей версии. Пакетный
     * менеджер этого не покажет, если репозиторий ОС больше не обновляется
     * (CentOS 7: remi заморожен на 8.2.20) — поэтому смотрим на источник.
     * Вызывается из bin/security_scan.php (cron).
     *
     * @param callable|null $fetcher fn(string $url): ?string — для тестов
     */
    public static function scanPhpUpstream(?callable $fetcher = null, string $version = PHP_VERSION): array
    {
        $fetch = $fetcher ?? [self::class, 'httpGet'];
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $out = ['version' => $version, 'latest' => null, 'vulns' => [], 'checked_at' => date('c'), 'error' => null];
        $errors = [];

        $rel = json_decode((string) $fetch(sprintf(self::PHP_RELEASES_URL, rawurlencode($branch))), true);
        if (is_array($rel) && preg_match('/^\d+\.\d+\.\d+$/', (string) ($rel['version'] ?? ''))) {
            $out['latest'] = $rel['version'];
        } else {
            $errors[] = 'php.net';
        }

        $adv = json_decode((string) $fetch(self::PHP_ADVISORIES_URL), true);
        if (is_array($adv) && array_is_list($adv)) {
            $out['vulns'] = self::affectingAdvisories($adv, $version);
        } else {
            $errors[] = 'github advisories';
        }

        if ($errors) {
            $out['error'] = 'fetch failed: ' . implode(', ', $errors);
        }
        Setting::set('security_php_upstream', json_encode($out));
        return $out;
    }

    /**
     * Отбирает advisories, которые затрагивают $version. Для ветки, у которой
     * есть исправленная версия, — уязвима, если текущая ниже её. Для ветки без
     * исправления (снята с поддержки) — уязвима, если она старше всех веток с
     * исправлением: патча для неё не будет.
     *
     * @return array<int,array{id:string,cve:string,severity:string,summary:string,fixed:?string}>
     */
    public static function affectingAdvisories(array $advisories, string $version): array
    {
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $out = [];
        foreach ($advisories as $a) {
            if (!is_array($a) || !empty($a['withdrawn_at'])) {
                continue;
            }
            $fixes = [];
            foreach ((array) ($a['vulnerabilities'] ?? []) as $v) {
                $src = ($v['patched_versions'] ?? '') ?: ($v['vulnerable_version_range'] ?? '');
                if (preg_match_all('/\d+\.\d+\.\d+/', (string) $src, $m)) {
                    array_push($fixes, ...$m[0]);
                }
            }
            if (!$fixes) {
                continue;
            }
            $fixed = null;
            $affected = false;
            foreach ($fixes as $f) {
                if (str_starts_with($f, $branch . '.')) {
                    $fixed = $f;
                    $affected = version_compare($version, $f, '<');
                    break;
                }
            }
            if ($fixed === null) {
                usort($fixes, 'version_compare');
                $affected = version_compare($version, $fixes[0], '<');
            }
            if (!$affected) {
                continue;
            }
            $out[] = [
                'id' => (string) ($a['ghsa_id'] ?? ''),
                'cve' => (string) ($a['cve_id'] ?? ''),
                'severity' => strtolower((string) ($a['severity'] ?? 'unknown')),
                'summary' => mb_substr((string) ($a['summary'] ?? ''), 0, 160),
                'fixed' => $fixed,
            ];
        }
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($out, fn($x, $y) => ($rank[$x['severity']] ?? 4) <=> ($rank[$y['severity']] ?? 4));
        return $out;
    }

    private static function httpGet(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 3,
                // GitHub API без User-Agent отвечает 403.
                'header' => "User-Agent: vps_router-security-audit\r\nAccept: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx, 0, self::HTTP_MAX_BYTES);
        return $data === false ? null : $data;
    }

    /**
     * Кэш последнего скана обновлений (bin/security_scan.php).
     *
     * @return array{total:int,security:int,checked_at:?string,manager:?string}
     */
    public static function pendingUpdates(): array
    {
        $raw = Setting::get('security_pending_updates');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['total' => 0, 'security' => 0, 'checked_at' => null, 'manager' => null];
        }
        return [
            'total' => (int) ($data['total'] ?? 0),
            'security' => (int) ($data['security'] ?? 0),
            'checked_at' => $data['checked_at'] ?? null,
            'manager' => $data['manager'] ?? null,
        ];
    }

    /**
     * Считает доступные обновления пакетов через системный менеджер и кладёт в
     * кэш. Вызывается из bin/security_scan.php (cron), не из веб-запроса.
     */
    public static function scanUpdates(): array
    {
        $result = self::runUpdateCheck();
        Setting::set('security_pending_updates', json_encode($result));
        return $result;
    }

    private static function runUpdateCheck(): array
    {
        $out = ['total' => 0, 'security' => 0, 'checked_at' => date('c'), 'manager' => null];
        if (!function_exists('shell_exec') || DIRECTORY_SEPARATOR !== '/') {
            return $out;
        }

        if (self::hasCmd('apt-get')) {
            $out['manager'] = 'apt';
            $list = (string) @shell_exec('LC_ALL=C apt-get -s upgrade 2>/dev/null');
            // "N upgraded, ..." — итоговая строка симуляции.
            if (preg_match('/^(\d+) upgraded/m', $list, $m)) {
                $out['total'] = (int) $m[1];
            }
            $sec = (string) @shell_exec('LC_ALL=C apt-get -s upgrade 2>/dev/null | grep -ci security');
            $out['security'] = (int) trim($sec);
        } elseif (self::hasCmd('dnf') || self::hasCmd('yum')) {
            $mgr = self::hasCmd('dnf') ? 'dnf' : 'yum';
            $out['manager'] = $mgr;
            // check-update: код возврата 100 = есть обновления; строки "pkg ver repo".
            $list = (string) @shell_exec("LC_ALL=C $mgr -q check-update 2>/dev/null");
            $lines = array_filter(array_map('trim', explode("\n", $list)), fn($l) => $l !== '' && preg_match('/^\S+\s+\S+\s+\S+$/', $l));
            $out['total'] = count($lines);
            $sec = (string) @shell_exec("LC_ALL=C $mgr -q --security check-update 2>/dev/null");
            $secLines = array_filter(array_map('trim', explode("\n", $sec)), fn($l) => $l !== '' && preg_match('/^\S+\s+\S+\s+\S+$/', $l));
            $out['security'] = count($secLines);
        }

        return $out;
    }

    private static function hasCmd(string $cmd): bool
    {
        return trim((string) @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null')) !== '';
    }

    /** @return array{0:string,1:string,2:string} [id, version_id, pretty_name] */
    private static function osRelease(): array
    {
        $raw = @file_get_contents('/etc/os-release');
        if ($raw === false) {
            return ['', '', PHP_OS_FAMILY];
        }
        $get = function (string $field) use ($raw): string {
            return preg_match('/^' . $field . '="?([^"\n]+)"?/m', $raw, $m) ? trim($m[1]) : '';
        };
        return [
            strtolower($get('ID')),
            $get('VERSION_ID'),
            $get('PRETTY_NAME') ?: PHP_OS_FAMILY,
        ];
    }
}
