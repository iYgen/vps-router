<?php

namespace App;

use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\ServerSet;

/**
 * Выгрузка маршрутов «для роутера Keenetic»: список назначений, которые в панели
 * направлены НЕ напрямую, а через exit (домены и IP-подсети). На Keenetic их
 * добавляют в политику маршрутизации, которая заворачивает трафик в
 * WireGuard-подключение к входному серверу. Панель не может применить это по API
 * (доменные маршруты Keenetic через RCI не принимает — см. KeeneticSyncService),
 * поэтому отдаём удобный текстовый список для ручной/скриптовой настройки.
 */
class KeeneticExport
{
    /**
     * @return array{domains:string[],ips:string[],geosite:string[]}
     */
    public static function routedDestinations(?int $routerId, bool $includeNull): array
    {
        $domains = [];
        $ips = [];
        $geosite = [];
        foreach (RuleGroup::all($routerId, $includeNull) as $g) {
            if (!$g['enabled']) {
                continue;
            }
            // Только группы, ведущие на exit (не «напрямую»).
            $viaExit = false;
            if (!empty($g['server_set_id'])) {
                $viaExit = ServerSet::resolveExitServerId((int) $g['server_set_id']) !== null;
            } elseif (!empty($g['exit_server_id'])) {
                $viaExit = true;
            }
            if (!$viaExit) {
                continue;
            }
            foreach (Rule::forGroup((int) $g['id']) as $r) {
                $v = trim((string) $r['value']);
                if ($v === '') {
                    continue;
                }
                switch ($r['type']) {
                    case 'domain_suffix':
                    case 'domain_full':
                    case 'domain_keyword':
                        $domains[$v] = true;
                        break;
                    case 'ip_cidr':
                        $ips[$v] = true;
                        break;
                    case 'geosite':
                        $geosite[$v] = true;
                        break;
                }
            }
        }
        $d = array_keys($domains);
        $i = array_keys($ips);
        $g = array_keys($geosite);
        sort($d);
        sort($i);
        sort($g);
        return ['domains' => $d, 'ips' => $i, 'geosite' => $g];
    }

    /** Человекочитаемый текст для скачивания. */
    public static function asText(?int $routerId, bool $includeNull): string
    {
        $x = self::routedDestinations($routerId, $includeNull);
        $out = [];
        $out[] = '# Маршруты для роутера Keenetic';
        $out[] = '# Эти назначения в панели идут через exit-сервер. На Keenetic добавьте их';
        $out[] = '# в политику маршрутизации, привязанную к WireGuard-подключению к входному серверу:';
        $out[] = '#   Интернет -> Политики маршрутизации -> выбрать политику с WG-подключением.';
        $out[] = '# Сгенерировано: ' . date('c');
        $out[] = '';
        $out[] = '## Домены (' . count($x['domains']) . ') — добавить как domain-based routes в политику';
        foreach ($x['domains'] as $d) {
            $out[] = $d;
        }
        $out[] = '';
        $out[] = '## IP-подсети (' . count($x['ips']) . ') — статические маршруты через WG-интерфейс';
        foreach ($x['ips'] as $ip) {
            $out[] = $ip;
        }
        if ($x['geosite']) {
            $out[] = '';
            $out[] = '## Geosite-категории (' . count($x['geosite']) . ') — на Keenetic напрямую НЕ поддерживаются';
            $out[] = '# Разверните их в конкретные домены или используйте только на стороне панели:';
            foreach ($x['geosite'] as $g) {
                $out[] = '# ' . $g;
            }
        }
        $out[] = '';
        return implode("\n", $out) . "\n";
    }

    /**
     * .bat со статическими маршрутами: route ADD <сеть> MASK <маска> %GW%.
     *
     * Роутер (Keenetic и таблица маршрутизации Windows) принимает только IP, а не
     * домены. Поэтому:
     *  - ip_cidr берём как есть (сеть + маска);
     *  - домены (domain_full/domain_suffix) резолвим в A-записи и добавляем как /32.
     *    Внимание: IP CDN-сервисов (YouTube, Google) часто меняются — .bat стоит
     *    периодически перегенерировать. domain_keyword и geosite развернуть в IP
     *    нельзя — они пропускаются.
     * Файл содержит ТОЛЬКО строки маршрутов (route ADD ... 0.0.0.0), без @echo off,
     * REM и прочих команд — роутер ругается на всё, кроме route. CRLF-переводы строк.
     */
    public static function asBat(?int $routerId, bool $includeNull): string
    {
        $lines = self::batRouteLines($routerId, $includeNull);
        $lines[] = '';
        // Только строки маршрутов, без комментариев. .bat под Windows — CRLF.
        return implode("\r\n", $lines);
    }

    /**
     * Строки "route ADD ... 0.0.0.0" (без завершающей пустой), отсортированные и
     * без дублей. Отдельный метод — чтобы делить их на файлы по лимиту Keenetic
     * (макс. 1024 статических маршрута на файл, см. asBatChunks()).
     *
     * @return string[]
     */
    public static function batRouteLines(?int $routerId, bool $includeNull): array
    {
        $x = self::routedDestinations($routerId, $includeNull);

        // Собираем уникальные "сеть/маска" -> подпись (что за домен/подсеть).
        $routes = []; // key "net|mask" => comment
        foreach ($x['ips'] as $cidr) {
            [$net, $mask] = self::cidrToRoute($cidr);
            if ($net === null) {
                continue; // IPv6/некорректные пропускаем
            }
            $routes["$net|$mask"] = $cidr;
        }
        foreach ($x['domains'] as $d) {
            foreach (self::resolveDomain($d) as $ip) {
                $routes["$ip|255.255.255.255"] = $d;
            }
        }

        $gw = '0.0.0.0';
        $lines = [];
        ksort($routes);
        foreach (array_keys($routes) as $key) {
            [$net, $mask] = explode('|', $key);
            $lines[] = "route ADD $net MASK $mask $gw";
        }
        return $lines;
    }

    /**
     * Делит маршруты на несколько .bat по $perFile строк (по умолчанию 1000 —
     * ниже лимита Keenetic в 1024). Возвращает [имя_файла => содержимое].
     * Если файл один — имя без индекса. CRLF, только строки маршрутов.
     *
     * @return array<string,string>
     */
    public static function asBatChunks(?int $routerId, bool $includeNull, int $perFile = 1000): array
    {
        $lines = self::batRouteLines($routerId, $includeNull);
        $perFile = max(1, $perFile);
        $ts = date('Ymd-His');
        if (count($lines) <= $perFile) {
            return ["keenetic-routes-$ts.bat" => implode("\r\n", array_merge($lines, ['']))];
        }
        $chunks = array_chunk($lines, $perFile);
        $total = count($chunks);
        $files = [];
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            $files["keenetic-routes-$ts-part$n-of-$total.bat"] = implode("\r\n", array_merge($chunk, ['']));
        }
        return $files;
    }

    /**
     * Нормализованный список IPv4-CIDR назначений, идущих через exit: ip_cidr-правила
     * + домены, резолвленные в /32. Отсортировано, без дублей, IPv6/мусор отброшены.
     * Переиспользуется экспортом под другие роутеры (App\RouterExport).
     *
     * @return string[] напр. ["1.2.3.4/32", "203.0.113.0/24"]
     */
    public static function routedCidrs(?int $routerId, bool $includeNull): array
    {
        $x = self::routedDestinations($routerId, $includeNull);
        $cidrs = [];
        foreach ($x['ips'] as $cidr) {
            $norm = self::normalizeCidr($cidr);
            if ($norm !== null) {
                $cidrs[$norm] = true;
            }
        }
        foreach ($x['domains'] as $d) {
            foreach (self::resolveDomain($d) as $ip) {
                $cidrs["$ip/32"] = true;
            }
        }
        $list = array_keys($cidrs);
        sort($list);
        return $list;
    }

    /** "1.2.3.4" -> "1.2.3.4/32", "10.0.0.0/8" -> как есть; IPv6/мусор -> null. */
    private static function normalizeCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if ($cidr === '' || str_contains($cidr, ':')) {
            return null; // IPv6 не поддерживаем в этих экспортах
        }
        $prefix = 32;
        $net = $cidr;
        if (str_contains($cidr, '/')) {
            [$net, $p] = explode('/', $cidr, 2);
            $prefix = (int) $p;
        }
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 0 || $prefix > 32) {
            return null;
        }
        return "$net/$prefix";
    }

    /** Резолвит домен в список уникальных IPv4 (A-записи). */
    private static function resolveDomain(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return [];
        }
        $ips = [];
        // dns_get_record надёжнее gethostbynamel для нескольких A-записей.
        $records = @dns_get_record($domain, DNS_A);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[$rec['ip']] = true;
                }
            }
        }
        if (!$ips) {
            $list = @gethostbynamel($domain);
            if (is_array($list)) {
                foreach ($list as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[$ip] = true;
                    }
                }
            }
        }
        return array_keys($ips);
    }

    /**
     * "203.0.113.0/24" -> ["203.0.113.0","255.255.255.0"]. Одиночный IP без
     * префикса трактуется как /32. IPv6/мусор -> [null,null].
     *
     * @return array{0:?string,1:?string}
     */
    private static function cidrToRoute(string $cidr): array
    {
        $cidr = trim($cidr);
        if (str_contains($cidr, ':')) {
            return [null, null]; // IPv6 — route ADD MASK не подходит
        }
        $prefix = 32;
        $net = $cidr;
        if (str_contains($cidr, '/')) {
            [$net, $p] = explode('/', $cidr, 2);
            $prefix = (int) $p;
        }
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 0 || $prefix > 32) {
            return [null, null];
        }
        $maskLong = $prefix === 0 ? 0 : (0xffffffff << (32 - $prefix)) & 0xffffffff;
        return [$net, long2ip($maskLong)];
    }

    /**
     * Собирает ZIP-архив (метод STORE, без сжатия) из [имя => содержимое].
     * Чистый PHP — на сервере нет расширения zip. Файлы небольшие (текст),
     * сжатие не нужно.
     *
     * @param array<string,string> $files
     */
    public static function zip(array $files): string
    {
        $entries = [];   // локальные записи + данные
        $central = [];   // записи центрального каталога
        $offset = 0;
        [$dosTime, $dosDate] = self::dosDateTime();

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = crc32($content);
            $len = strlen($content);

            // Local file header.
            $local = pack('V', 0x04034b50)  // signature
                . pack('v', 20)             // version needed
                . pack('v', 0)              // flags
                . pack('v', 0)              // compression = store
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $len)           // compressed size
                . pack('V', $len)           // uncompressed size
                . pack('v', strlen($name))
                . pack('v', 0)              // extra len
                . $name;
            $entries[] = $local . $content;

            // Central directory record.
            $central[] = pack('V', 0x02014b50)  // signature
                . pack('v', 20)             // version made by
                . pack('v', 20)             // version needed
                . pack('v', 0)              // flags
                . pack('v', 0)              // compression
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $len)
                . pack('V', $len)
                . pack('v', strlen($name))
                . pack('v', 0)              // extra len
                . pack('v', 0)              // comment len
                . pack('v', 0)              // disk number start
                . pack('v', 0)              // internal attrs
                . pack('V', 0)              // external attrs
                . pack('V', $offset)        // offset of local header
                . $name;

            $offset += strlen($local) + $len;
        }

        $centralData = implode('', $central);
        $eocd = pack('V', 0x06054b50)  // signature
            . pack('v', 0)             // disk number
            . pack('v', 0)             // disk with central dir
            . pack('v', count($files)) // entries on this disk
            . pack('v', count($files)) // total entries
            . pack('V', strlen($centralData))
            . pack('V', $offset)       // offset of central dir
            . pack('v', 0);            // comment len

        return implode('', $entries) . $centralData . $eocd;
    }

    /** @return array{0:int,1:int} DOS-время и DOS-дата для ZIP-заголовков. */
    private static function dosDateTime(): array
    {
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | (intdiv($t['seconds'], 2));
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        return [$dosTime, $dosDate];
    }
}
