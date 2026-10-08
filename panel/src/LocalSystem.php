<?php

namespace App;

/**
 * Сведения о сервере, на котором работает сама панель (is_self, входной VPS) —
 * без SSH «к самому себе»: читаем /proc, /etc/os-release и т.п. напрямую.
 * Формат ответа совпадает с App\Ssh::testConnection() (+ metrics как у
 * App\Ssh::fetchMetrics()), поэтому UI и health-check не различают,
 * откуда пришли данные.
 */
class LocalSystem
{
    public static function info(): array
    {
        $uname = php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');

        return [
            'ok' => true,
            'hostname' => (string) gethostname(),
            'kernel' => $uname,
            'uptime' => self::uptime(),
            'os' => self::osRelease(),
            'ipv4' => self::ipv4(),
            'sudo' => false,
            'metrics' => self::metrics(),
            'net' => ServerTraffic::localCounters(),
            'singbox_active' => self::singboxActive(),
            'local' => true,
            'raw_error' => null,
        ];
    }

    /**
     * Запущена ли служба sing-box (движок маршрутизации) на этом узле.
     * `systemctl is-active` — read-only, работает без root. Нужно, чтобы
     * различать «узел жив, но ядро упало» (2S-UI-style core-stopped).
     */
    public static function singboxActive(): bool
    {
        if (!function_exists('shell_exec')) {
            return true; // не можем проверить — не поднимаем ложную тревогу
        }
        $out = @shell_exec('systemctl is-active sing-box 2>/dev/null');
        return trim((string) $out) === 'active';
    }

    /**
     * Публичный (global unicast, 2000::/3) IPv6-адрес узла или null. Нужно для
     * предупреждения об утечке: если у сервера есть маршрутизируемый IPv6, а exit
     * только IPv4, трафик устройств по IPv6 может обойти туннель.
     */
    public static function globalIpv6(): ?string
    {
        if (!function_exists('shell_exec') || DIRECTORY_SEPARATOR !== '/') {
            return null;
        }
        $out = @shell_exec('ip -6 -o addr show scope global 2>/dev/null');
        return $out ? self::parseGlobalIpv6((string) $out) : null;
    }

    /** Первый global unicast IPv6 из вывода `ip -6 -o addr show scope global` (или null). */
    public static function parseGlobalIpv6(string $out): ?string
    {
        foreach (explode("\n", trim($out)) as $line) {
            if (!preg_match('/\binet6\s+([0-9a-fA-F:]+)\/\d+/', $line, $m)) {
                continue;
            }
            $addr = strtolower($m[1]);
            // Исключаем link-local (fe80), loopback (::1), ULA (fc00::/7 — fc/fd).
            if (str_starts_with($addr, 'fe80') || $addr === '::1'
                || str_starts_with($addr, 'fc') || str_starts_with($addr, 'fd')) {
                continue;
            }
            // Global unicast 2000::/3 — первый ниббл 2 или 3.
            if (isset($addr[0]) && ($addr[0] === '2' || $addr[0] === '3')) {
                return $addr;
            }
        }
        return null;
    }

    /** Та же структура, что App\Ssh::fetchMetrics(). */
    public static function metrics(): array
    {
        $load = null;
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw !== false && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($raw), $m)) {
            $load = ['1min' => (float) $m[1], '5min' => (float) $m[2], '15min' => (float) $m[3]];
        } elseif (function_exists('sys_getloadavg') && ($l = @sys_getloadavg())) {
            $load = ['1min' => round($l[0], 2), '5min' => round($l[1], 2), '15min' => round($l[2], 2)];
        }

        $cpus = self::cpuCount();

        $mem = null;
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false
            && preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $t)
            && preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $a)) {
            $total = intdiv((int) $t[1], 1024);
            $available = intdiv((int) $a[1], 1024);
            $used = max(0, $total - $available);
            $mem = [
                'total_mb' => $total,
                'used_mb' => $used,
                'available_mb' => $available,
                'used_percent' => $total > 0 ? (int) round($used / $total * 100) : 0,
            ];
        }

        $disk = null;
        $root = DIRECTORY_SEPARATOR === '/' ? '/' : getcwd();
        $totalBytes = @disk_total_space($root);
        $freeBytes = @disk_free_space($root);
        if ($totalBytes && $freeBytes !== false) {
            $totalKb = (int) ($totalBytes / 1024);
            $availKb = (int) ($freeBytes / 1024);
            $usedKb = max(0, $totalKb - $availKb);
            $disk = [
                'total_kb' => $totalKb,
                'used_kb' => $usedKb,
                'avail_kb' => $availKb,
                'used_percent' => $totalKb > 0 ? (int) round($usedKb / $totalKb * 100) : 0,
            ];
        }

        $ok = $load !== null || $mem !== null || $disk !== null;
        return [
            'ok' => $ok,
            'load' => $load,
            'cpus' => $cpus,
            'cpu_percent' => $load !== null ? (int) min(100, round($load['1min'] / ($cpus ?: 1) * 100)) : null,
            'mem' => $mem,
            'disk' => $disk,
            'raw_error' => $ok ? null : 'Не удалось прочитать /proc на этом сервере',
        ];
    }

    private static function cpuCount(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $n = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($n > 0) {
                return $n;
            }
        }
        $env = getenv('NUMBER_OF_PROCESSORS');
        return $env && ctype_digit($env) ? (int) $env : null;
    }

    private static function uptime(): string
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return '';
        }
        $seconds = (int) explode(' ', trim($raw))[0];
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days) {
            $parts[] = "$days d";
        }
        if ($hours) {
            $parts[] = "$hours h";
        }
        $parts[] = "$minutes min";
        return 'up ' . implode(' ', $parts);
    }

    private static function osRelease(): string
    {
        $raw = @file_get_contents('/etc/os-release');
        if ($raw === false) {
            return PHP_OS_FAMILY;
        }
        if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $raw, $m)) {
            return $m[1];
        }
        return trim(implode(' ', array_slice(explode("\n", $raw), 0, 2)));
    }

    /** Адреса интерфейсов — через `ip`, если exec доступен; иначе пусто. */
    private static function ipv4(): array
    {
        if (!function_exists('shell_exec') || DIRECTORY_SEPARATOR !== '/') {
            return [];
        }
        $out = @shell_exec('ip -4 -o addr show 2>/dev/null');
        if (!$out) {
            return [];
        }
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 4 && $cols[1] !== 'lo') {
                $rows[] = $cols[1] . ' ' . $cols[3];
            }
        }
        return $rows;
    }
}
