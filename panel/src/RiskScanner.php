<?php

namespace App;

/**
 * Эвристическая оценка риска блокировки IP по методу Active Probing:
 * сканирование сервера теми же средствами, что доступны внешнему наблюдателю
 * (обычные TCP-подключения с исхода панели) — ищем то же, что ищет Active Blocking System:
 * открытые порты известных панелей обхода блокировок и TLS-сертификаты,
 * маскирующиеся под известные бренды на серверах, которые этим брендам не
 * принадлежат. Чисто диагностический инструмент, ничего не меняет на
 * сканируемом сервере.
 *
 * Это не догма и не гарантия — эвристика по открытым источникам (см.
 * docs/infrastructure-ui.md, раздел про риск блокировки). Задача — поймать
 * очевидные красные флаги ДО того, как их найдёт RKN, а не подменить собой
 * официальные инструменты вроде Censys.
 */
class RiskScanner
{
    private const CONNECT_TIMEOUT = 2;

    /** Порты, ассоциирующиеся с панелями обхода блокировок (3x-ui, Marzban и т.п.) — сами по себе не запрещены, но частая причина блокировок. */
    private const SUSPICIOUS_PORTS = [
        2096 => '3x-ui / общая VPN-панель (частый дефолт)',
        2087 => 'cPanel/WHM или VPN-панель на нестандартном порту',
        8443 => 'Частый порт VPN-панелей/Reality',
        9443 => 'Частый порт VPN-панелей',
        8880 => 'Частый порт VPN-панелей',
        10000 => 'Webmin или VPN-панель',
        20000 => 'Диапазон панелей управления хостингом',
    ];

    /** Публичный доступ к списку без возможности его подменить извне — нужен UI-предупреждениям (например при выборе порта в Настройках), не только самому сканеру. */
    public static function suspiciousPorts(): array
    {
        return self::SUSPICIOUS_PORTS;
    }

    /** Известные бренды — если сертификат на непринадлежащем им сервере называет себя одним из них, это типичный камуфляж Reality/VLESS. */
    private const IMPERSONATION_BRANDS = [
        'yandex.ru', 'yandex.net', 'apple.com', 'google.com', 'rutube.ru',
        'microsoft.com', 'vk.com', 'sberbank.ru', 'cloudflare.com',
    ];

    /**
     * @return array{score:int,findings:array<int,array{level:string,message:string}>,checked_at:string}
     */
    public static function scan(string $host, string $expectedHostname = '', array $ownCamouflage = []): array
    {
        $findings = [];
        $score = 0;

        // --- SSH на 22: информационно, само по себе не риск (нужен для управления). ---
        if (self::portOpen($host, 22)) {
            $findings[] = ['level' => 'info', 'message' => 'Порт 22 (SSH) открыт — ожидаемо, если нужен доступ для управления.'];
        }

        // --- Подозрительные порты панелей обхода. ---
        foreach (self::SUSPICIOUS_PORTS as $port => $label) {
            if (self::portOpen($host, $port)) {
                $findings[] = ['level' => 'warning', 'message' => "Открыт порт $port ($label) — если не используется, закройте: это частая причина попадания IP в веерный анализ Active Blocking System."];
                $score += 15;
            }
        }

        // --- TLS-сертификаты на 443 и на всех подозрительных портах: маскировка под чужой бренд. ---
        $tlsPorts = array_merge([443], array_keys(self::SUSPICIOUS_PORTS));
        foreach ($tlsPorts as $port) {
            $finding = self::brandImpersonation($port, self::fetchCertNames($host, $port) ?? [], $expectedHostname, $ownCamouflage);
            if ($finding) {
                // Одна находка на порт, сколько бы имён ни было в сертификате
                // (у сертификатов крупных брендов их десятки).
                $findings[] = $finding;
                $score += $finding['level'] === 'error' ? 40 : 20;
            }
        }

        return [
            'score' => min(100, $score),
            'findings' => $findings ?: [['level' => 'info', 'message' => 'Явных красных флагов не найдено — но это не гарантия, RKN использует и другие методы (см. Censys/dpi-checkers вручную).']],
            'checked_at' => date('c'),
        ];
    }

    /**
     * Сертификат на порту выдаёт себя за известный бренд, к которому сервер
     * не относится. $ownCamouflage — домены маскировки Reality, заданные для
     * этого сервера в самой панели: тогда это не «чужой» сертификат, а наша
     * же настройка, и совет — какой домен выбрать вместо.
     *
     * @param string[] $names CN + SAN
     * @return array{level:string,message:string}|null
     */
    public static function brandImpersonation(int $port, array $names, string $expectedHostname = '', array $ownCamouflage = []): ?array
    {
        foreach (self::IMPERSONATION_BRANDS as $brand) {
            $matched = array_values(array_filter($names, fn($n) => str_contains($n, $brand) && !self::hostnameMatches($expectedHostname, $n)));
            if (!$matched) {
                continue;
            }
            $shown = implode(', ', array_slice(array_unique($matched), 0, 3)) . (count(array_unique($matched)) > 3 ? ' и др.' : '');
            $ours = array_filter($ownCamouflage, fn($d) => $d !== '' && (str_contains($d, $brand) || in_array(strtolower($d), $matched, true)));
            if ($ours) {
                return [
                    'level' => 'warning',
                    'message' => "Порт $port: это маскировка Reality, настроенная в панели под «" . reset($ours) . "» — сканер получает сертификат $brand от сервера, который $brand не принадлежит. "
                        . 'Схема распространённая и обычно работает, но это известный признак для Active Probing. Надёжнее маскироваться под свой сайт на этом сервере или под сайт, размещённый у того же хостера.',
                ];
            }
            return [
                'level' => 'error',
                'message' => "Порт $port: сертификат выдаёт себя за $brand ($shown), но сервер к $brand не относится — именно так Active Probing находит camouflage-серверы Reality/VLESS.",
            ];
        }
        return null;
    }

    /** Домены маскировки Reality, которые панель сама настроила для этого сервера. */
    public static function camouflageFor(array $server): array
    {
        $domains = [];
        if (!empty($server['is_self'])) {
            $domains[] = (string) Models\Setting::get('reality_server_name', '');
        }
        foreach (Models\Connection::forServer((int) $server['id']) as $c) {
            if ((int) $c['target_server_id'] !== (int) $server['id'] || empty($c['exit_server_id'])) {
                continue;
            }
            $es = Models\ExitServer::find((int) $c['exit_server_id']);
            $params = $es && !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
            if (!empty($params['reality']['server_name'])) {
                $domains[] = strtolower($params['reality']['server_name']);
            }
        }
        return array_values(array_unique(array_filter($domains)));
    }

    private static function portOpen(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        if ($conn) {
            fclose($conn);
            return true;
        }
        return false;
    }

    /** @return string[]|null CN + все SAN-имена сертификата, либо null если TLS недоступен на этом порту. */
    private static function fetchCertNames(string $host, int $port): ?array
    {
        $context = stream_context_create([
            'ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $client = @stream_socket_client(
            "ssl://$host:$port",
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$client) {
            return null;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        if (empty($params['options']['ssl']['peer_certificate'])) {
            return null;
        }
        $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!$parsed) {
            return null;
        }

        $names = [];
        if (!empty($parsed['subject']['CN'])) {
            $names[] = strtolower($parsed['subject']['CN']);
        }
        if (!empty($parsed['extensions']['subjectAltName'])) {
            foreach (explode(',', $parsed['extensions']['subjectAltName']) as $san) {
                $san = trim(str_ireplace('DNS:', '', $san));
                if ($san !== '') {
                    $names[] = strtolower($san);
                }
            }
        }
        return $names;
    }

    private static function hostnameMatches(string $expected, string $certName): bool
    {
        if ($expected === '') {
            return false;
        }
        $expected = strtolower($expected);
        return $certName === $expected || str_ends_with($certName, '.' . $expected);
    }
}
