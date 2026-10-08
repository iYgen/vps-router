<?php

namespace App;

use App\Models\Rule;
use App\Models\RuleGroup;

/**
 * Импорт внешних списков (домены и/или CIDR) в маршрут-группу: курируемые
 * источники (Antizapret и т.п.) или произвольный URL. Загрузка идёт с сервера
 * панели (публичные блок-листы — не пользовательские данные), с таймаутом и
 * жёстким лимитом на размер/число строк, валидацией и дедупликацией.
 *
 * Для ОЧЕНЬ больших списков (весь Antizapret — сотни тысяч записей) лучше
 * geosite/geoip (их sing-box качает сам как rule-set), поэтому здесь есть
 * потолок: при превышении список обрезается с пометкой.
 */
class ExternalListImporter
{
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 МБ на загрузку
    private const MAX_ENTRIES = 20000;         // потолок записей на импорт
    private const TIMEOUT = 25;

    /** Курируемые источники: id => [name, url, type(domains|cidr|auto), note]. */
    private const SOURCES = [
        'antizapret-domains' => [
            'name' => 'Antizapret — домены',
            'url' => 'https://antizapret.prostovpn.org/domains-export.txt',
            'type' => 'domains',
            'note' => 'Заблокированные локально домены (большой список, может обрезаться).',
        ],
        'antizapret-subnets' => [
            'name' => 'Antizapret — подсети (CIDR)',
            'url' => 'https://antizapret.prostovpn.org/subnet-export.txt',
            'type' => 'cidr',
            'note' => 'Заблокированные подсети (CIDR).',
        ],
    ];

    /** @return array<int,array{id:string,name:string,type:string,note:string}> */
    public static function sources(): array
    {
        $out = [];
        foreach (self::SOURCES as $id => $s) {
            $out[] = ['id' => $id, 'name' => $s['name'], 'type' => $s['type'], 'note' => $s['note']];
        }
        return $out;
    }

    /**
     * Импортирует список в новую группу маршрутов, привязанную к exit/набору.
     *
     * @param string $sourceOrUrl id курируемого источника или http(s)-URL
     * @return array{group_id:int,name:string,domains:int,ips:int,truncated:bool}
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function import(string $sourceOrUrl, ?int $exitServerId, ?int $serverSetId, ?int $routerId): array
    {
        if (!$exitServerId && !$serverSetId) {
            throw new \InvalidArgumentException('Не выбран выходной сервер или набор');
        }

        [$url, $name, $type] = self::resolve($sourceOrUrl);
        $raw = self::httpGet($url);
        if ($raw === null || trim($raw) === '') {
            throw new \RuntimeException('Не удалось загрузить список (пусто/недоступно): ' . $url);
        }

        ['domains' => $domains, 'ips' => $ips, 'truncated' => $truncated] = self::parse($raw, $type);
        if (!$domains && !$ips) {
            throw new \RuntimeException('В списке не найдено валидных доменов/подсетей');
        }

        $groupName = 'Импорт: ' . $name . ' (' . date('d.m H:i') . ')';
        $gid = RuleGroup::create($groupName, $exitServerId, $serverSetId, 'import:' . $url, null, 'import', $url, $routerId);
        if ($domains) {
            Rule::createMany($gid, 'domain_suffix', $domains);
        }
        if ($ips) {
            Rule::createMany($gid, 'ip_cidr', $ips);
        }

        return [
            'group_id' => $gid, 'name' => $groupName,
            'domains' => count($domains), 'ips' => count($ips), 'truncated' => $truncated,
        ];
    }

    /** @return array{0:string,1:string,2:string} [url, name, type] */
    private static function resolve(string $sourceOrUrl): array
    {
        if (isset(self::SOURCES[$sourceOrUrl])) {
            $s = self::SOURCES[$sourceOrUrl];
            return [$s['url'], $s['name'], $s['type']];
        }
        if (!preg_match('~^https?://~i', $sourceOrUrl)) {
            throw new \InvalidArgumentException('Ожидался id источника или http(s)-URL');
        }
        // SSRF-минимизация: только http/https, без явных localhost/приватных хостов.
        $host = parse_url($sourceOrUrl, PHP_URL_HOST) ?: '';
        if ($host === '' || preg_match('~^(localhost|127\.|10\.|192\.168\.|169\.254\.|::1)~i', $host)) {
            throw new \InvalidArgumentException('Недопустимый хост URL');
        }
        return [$sourceOrUrl, parse_url($sourceOrUrl, PHP_URL_HOST) ?: 'URL', 'auto'];
    }

    /**
     * @return array{domains:string[],ips:string[],truncated:bool}
     */
    public static function parse(string $raw, string $type): array
    {
        $domains = [];
        $ips = [];
        $truncated = false;
        $count = 0;

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }
            // Antizapret иногда отдаёт "domain;..." — берём первое поле.
            $line = trim(explode(';', $line)[0]);
            $line = ltrim($line, '*.'); // *.example.com / .example.com -> example.com
            if ($line === '') {
                continue;
            }

            $isCidr = str_contains($line, '/');
            if (($type === 'cidr' || $type === 'auto') && $isCidr && self::validCidr($line)) {
                $ips[$line] = true;
            } elseif (($type === 'cidr' || $type === 'auto') && !$isCidr && filter_var($line, FILTER_VALIDATE_IP)) {
                $ips[$line . (str_contains($line, ':') ? '/128' : '/32')] = true;
            } elseif (($type === 'domains' || $type === 'auto') && self::validDomain($line)) {
                $domains[strtolower($line)] = true;
            } else {
                continue;
            }

            if (++$count >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
        }

        $d = array_keys($domains);
        $i = array_keys($ips);
        sort($d);
        sort($i);
        return ['domains' => $d, 'ips' => $i, 'truncated' => $truncated];
    }

    private static function validDomain(string $s): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $s);
    }

    private static function validCidr(string $s): bool
    {
        if (!str_contains($s, '/')) {
            return false;
        }
        [$ip, $bits] = explode('/', $s, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    private static function httpGet(string $url): ?string
    {
        // Если включена «загрузка через exit» — сначала пробуем SOCKS локального
        // инбаунда sing-box (list-fetch-in), чтобы достать недоступные локально
        // источники с внешней точки. При неудаче — обычная прямая загрузка.
        if (\App\Models\Setting::get('list_fetch_via_exit', '0') === '1' && function_exists('curl_init')) {
            $viaExit = self::curlGet($url, '127.0.0.1:' . \App\SingboxConfigBuilder::LIST_FETCH_PORT);
            if ($viaExit !== null) {
                return $viaExit;
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => "User-Agent: vps_router-list-importer\r\n",
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        return $data === false ? null : $data;
    }

    /** Загрузка через SOCKS5-прокси (exit sing-box). null при ошибке/недоступности. */
    private static function curlGet(string $url, string $socks): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROXY => $socks,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_USERAGENT => 'vps_router-list-importer',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 || !is_string($data) || $data === '') {
            return null;
        }
        return strlen($data) > self::MAX_BYTES ? substr($data, 0, self::MAX_BYTES) : $data;
    }
}
