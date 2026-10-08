<?php

namespace App;

/**
 * Интеграция с публичным агрегатором бесплатных прокси free-vpn-subscriptions
 * (github.com/Au1rxx/free-vpn-subscriptions). Он ежечасно тестирует публичные
 * ноды через sing-box и отдаёт готовые sing-box outbound'ы (vless/vmess/trojan/
 * shadowsocks/hysteria2/tuic/...). Мы качаем их и предлагаем как «бесплатные
 * exit'ы» — без SSH и провижининга, сразу как outbound в конфиге входной роутера.
 *
 * ВАЖНО: это ЧУЖИЕ недоверенные прокси — трафик виден их операторам. Годится
 * для обхода блокировок (видео/сайты), НЕ для персональных данных. Пометки об
 * этом обязательны в UI (см. App\DomainSensitivity).
 */
class FreeSubscriptions
{
    private const BASE = 'https://raw.githubusercontent.com/Au1rxx/free-vpn-subscriptions/main/output';

    /** Реальные прокси-outbound'ы (не selector/urltest/direct/block/dns). */
    public const IMPORTABLE_TYPES = ['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria2', 'tuic'];

    /** Страны с готовыми списками (output/by-country/singbox-XX.json). */
    public const COUNTRIES = ['AE', 'AT', 'AU', 'CA', 'CH', 'DE', 'EE', 'ES', 'FI', 'FR', 'GB', 'HK', 'ID', 'IL', 'IN', 'IT', 'JP', 'KR', 'LV', 'NL', 'PL', 'RO', 'RU', 'SE', 'SG', 'SK', 'TR', 'TW', 'US'];

    /**
     * Тянет и парсит список нод. $country — двухбуквенный код (см. COUNTRIES)
     * или null для общего списка.
     *
     * @return array<int,array{tag:string,type:string,server:string,port:int,country:?string,raw:array}>
     */
    public static function fetch(?string $country = null): array
    {
        $country = $country ? strtoupper($country) : null;
        if ($country !== null && !in_array($country, self::COUNTRIES, true)) {
            throw new \InvalidArgumentException('Неизвестный код страны');
        }
        $url = $country ? self::BASE . "/by-country/singbox-$country.json" : self::BASE . '/singbox.json';
        $json = self::httpGet($url);
        if ($json === null) {
            throw new \RuntimeException('Не удалось загрузить список бесплатных серверов (нет сети или GitHub недоступен).');
        }
        return self::parse($json, $country);
    }

    /**
     * Разбор sing-box JSON (полного или {outbounds,route}) в список импортируемых
     * нод. Выделено отдельно от сети — тестируется на фикстуре.
     *
     * @return array<int,array{tag:string,type:string,server:string,port:int,country:?string,raw:array}>
     */
    public static function parse(string $json, ?string $country = null): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Список серверов повреждён (не JSON).');
        }
        $outbounds = $data['outbounds'] ?? (isset($data[0]) ? $data : []);
        $out = [];
        foreach ($outbounds as $ob) {
            if (!is_array($ob) || !isset($ob['type'])) {
                continue;
            }
            if (!in_array($ob['type'], self::IMPORTABLE_TYPES, true)) {
                continue; // selector/urltest/direct/block/dns/wireguard пропускаем
            }
            if (empty($ob['server']) || empty($ob['server_port'])) {
                continue;
            }
            $out[] = [
                'tag' => (string) ($ob['tag'] ?? ($ob['type'] . '-' . substr(md5(json_encode($ob)), 0, 8))),
                'type' => (string) $ob['type'],
                'server' => (string) $ob['server'],
                'port' => (int) $ob['server_port'],
                'country' => $country,
                'raw' => $ob,
            ];
        }
        return $out;
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_USERAGENT => 'vps_router-panel',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $code >= 200 && $code < 300) {
                return (string) $body;
            }
            return null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: vps_router-panel\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
