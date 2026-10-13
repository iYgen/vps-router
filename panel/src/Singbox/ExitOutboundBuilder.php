<?php

namespace App\Singbox;

use App\RealityKeys;

/**
 * Строит sing-box outbound/endpoint для одной exit-цели по её протоколу и
 * проверяет полноту её полей. Вынесено из SingboxConfigBuilder как отдельный
 * модуль: логика на протокол самодостаточна (чистая функция от строки exit_servers).
 */
class ExitOutboundBuilder
{
    /** Допустимые значения uTLS-фингерпринта (sing-box). */
    private const UTLS_FINGERPRINTS = ['chrome', 'firefox', 'edge', 'safari', 'ios', 'android', 'random', 'randomized'];

    /** Сколько резервных портов максимум принимаем на один exit. */
    public const MAX_ALT_PORTS = 4;

    /** Проба для urltest-группы портов (лёгкий 204 без тела). */
    public const PROBE_URL = 'https://www.gstatic.com/generate_204';

    /**
     * Мультиплекс входная VPS → exit: соединения устройств идут потоками внутри
     * нескольких долгоживущих TLS-сессий, а не новым Reality-рукопожатием на
     * каждое. Сотни рукопожатий в минуту с одного IP на один зарубежный IP —
     * именно то, что DPI на пути душит. Exit-inbound принимает и mux, и
     * обычные соединения, так что порядок выката не важен.
     * С VLESS flow (xtls-rprx-vision) несовместим — тогда не включаем.
     */
    public const MULTIPLEX = ['enabled' => true, 'protocol' => 'h2mux', 'max_connections' => 4, 'min_streams' => 8];

    /** Мультиплекс до exit'ов (Setting exit_multiplex, по умолчанию включён). */
    public static function multiplexEnabled(): bool
    {
        return \App\Models\Setting::get('exit_multiplex', '1') !== '0';
    }

    /** Тег outbound/endpoint в конфиге route для exit-сервера. */
    public static function tag(int $exitServerId): string
    {
        return 'exit-' . $exitServerId;
    }

    /** Тег outbound'а одного порта exit-сервера внутри urltest-группы. */
    public static function memberTag(int $exitServerId, int $port): string
    {
        return self::tag($exitServerId) . '~p' . $port;
    }

    /** exit-6~p47443 → exit-6: в статистике/графиках порт-участник = сам exit. */
    public static function canonicalTag(string $tag): string
    {
        return (string) preg_replace('/~p\d+$/', '', $tag);
    }

    /**
     * Резервные порты exit-сервера (protocol_params.alt_ports): exit слушает
     * их тем же inbound'ом, что и основной порт. Нужны, когда DPI на пути
     * до exit начинает резать конкретный порт (бывает, что «умирает» :443 —
     * Reality-рукопожатия портились, а на других портах всё проходило).
     * Только VLESS: остальные протоколы пока без резервных портов.
     *
     * @return int[] валидные, уникальные, без основного порта
     */
    public static function altPorts(array $es): array
    {
        if (($es['protocol'] ?? '') !== 'vless') {
            return [];
        }
        $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
        if (!empty($params['_raw'])) {
            return [];
        }
        $main = (int) ($es['endpoint_port'] ?? 0);
        $ports = [];
        foreach ((array) ($params['alt_ports'] ?? []) as $p) {
            $p = (int) $p;
            if ($p >= 1 && $p <= 65535 && $p !== $main && !in_array($p, $ports, true)) {
                $ports[] = $p;
            }
        }
        return array_slice($ports, 0, self::MAX_ALT_PORTS);
    }

    /**
     * Как build(), но с резервными портами: если они заданы, вместо одного
     * outbound'а exit-N получаем по outbound'у на порт (exit-N~pPORT) и
     * urltest-группу с тегом exit-N поверх них. Маршруты/DNS по-прежнему
     * ссылаются на exit-N, а sing-box сам раз в минуту проверяет порты и
     * уводит трафик на живой — без участия панели и без Apply.
     *
     * @return array{outbounds: array[], endpoint: ?array}
     */
    public static function buildAll(array $es): array
    {
        [$outbound, $endpoint] = self::build($es);
        $alt = self::altPorts($es);
        if (!$outbound || !$alt) {
            return ['outbounds' => $outbound ? [$outbound] : [], 'endpoint' => $endpoint];
        }

        $id = (int) $es['id'];
        $members = [];
        $outbounds = [];
        foreach (array_merge([(int) $es['endpoint_port']], $alt) as $port) {
            $o = $outbound;
            $o['tag'] = self::memberTag($id, $port);
            $o['server_port'] = $port;
            $outbounds[] = $o;
            $members[] = $o['tag'];
        }
        // Группа идёт первой — «первый exit» (например, для загрузки списков)
        // остаётся самой группой, а не случайным портом.
        array_unshift($outbounds, [
            'type' => 'urltest',
            'tag' => self::tag($id),
            'outbounds' => $members,
            'url' => self::PROBE_URL,
            'interval' => '1m',
            // Не прыгаем между живыми портами из-за десятков мс разницы:
            // переключение — когда текущий порт умер или заметно хуже.
            'tolerance' => 300,
            'interrupt_exist_connections' => true,
        ]);
        return ['outbounds' => $outbounds, 'endpoint' => $endpoint];
    }

    /** uTLS-фингерпринт из настроек панели (анти-DPI), с валидацией. По умолчанию chrome. */
    private static function antiDpiFingerprint(): string
    {
        $fp = (string) \App\Models\Setting::get('antidpi_utls_fingerprint', 'chrome');
        return in_array($fp, self::UTLS_FINGERPRINTS, true) ? $fp : 'chrome';
    }

    /** Включена ли фрагментация TLS ClientHello по TCP-сегментам (анти-DPI). */
    private static function antiDpiFragment(): bool
    {
        return \App\Models\Setting::get('antidpi_tls_fragment', '0') === '1';
    }

    /** Доп. слой: дробление ClientHello на несколько TLS-записей (record_fragment). */
    private static function antiDpiRecordFragment(): bool
    {
        return \App\Models\Setting::get('antidpi_record_fragment', '0') === '1';
    }

    /** Тайминг фрагментации (fragment_fallback_delay), напр. "500ms". '' = не задавать. */
    private static function antiDpiFragmentDelay(): string
    {
        $d = trim((string) \App\Models\Setting::get('antidpi_fragment_delay', ''));
        // Допускаем только длительность sing-box: число + ms|s (напр. 500ms, 1s).
        return preg_match('/^[0-9]{1,6}(ms|s)$/', $d) ? $d : '';
    }

    /**
     * Собирает outbound для одной exit-цели по её protocol. amneziawg —
     * единственный протокол, идущий через внешний интерфейс (awg-quick,
     * поднятый AmneziaConfigBuilder+apply-router.sh) и bind_interface —
     * потому что обфускация AmneziaWG не реализована в самом sing-box.
     * wireguard/vless/shadowsocks/hysteria2/tuic/trojan — нативные
     * sing-box outbound'ы, никакого внешнего процесса не требуют.
     *
     * @return array{0:?array,1:?array} [outbound, endpoint|null]
     */
    public static function build(array $es): array
    {
        $tag = self::tag((int) $es['id']);
        $protocol = $es['protocol'] ?? 'amneziawg';
        $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];

        // Бесплатные exit'ы (free-vpn-subscriptions): в protocol_params лежит уже
        // готовый, протестированный sing-box outbound — используем его как есть,
        // только переставляем tag на exit-N. Никакого провижининга/WG-интерфейса.
        if (!empty($params['_raw']) && is_array($params['_raw'])) {
            $outbound = $params['_raw'];
            $outbound['tag'] = $tag;
            return [$outbound, null];
        }

        switch ($protocol) {
            case 'wireguard':
                // Начиная с sing-box 1.11 (полностью — с 1.13) WireGuard больше
                // не отдельный тип outbound, а отдельная сущность "endpoint":
                // https://sing-box.sagernet.org/configuration/endpoint/wireguard/
                // Endpoint и outbound делят одно пространство тегов в route —
                // правила ссылаются на endpoint напрямую по тегу exit-N,
                // отдельный outbound-обёртка не нужна (и не поддерживается:
                // "direct" outbound с полем endpoint sing-box 1.14 отклоняет
                // как неизвестное поле — проверено вживую на реальном сервере).
                $peer = [
                    'address' => $es['endpoint_host'],
                    'port' => (int) $es['endpoint_port'],
                    'public_key' => $params['peer_pubkey'] ?? '',
                    'allowed_ips' => ['0.0.0.0/0', '::/0'],
                    'persistent_keepalive_interval' => 25,
                ];
                if (!empty($params['psk'])) {
                    $peer['pre_shared_key'] = $params['psk'];
                }
                $endpoint = [
                    'type' => 'wireguard',
                    'tag' => $tag,
                    'address' => [$params['local_address'] ?? ''],
                    'private_key' => $params['local_privkey'] ?? '',
                    'mtu' => (int) ($params['mtu'] ?? 1420),
                    'peers' => [$peer],
                ];
                return [null, $endpoint];

            case 'vless':
                $tls = ['enabled' => true, 'server_name' => $params['reality']['server_name'] ?? ($params['ws']['server_name'] ?? '')];
                $transport = null;
                switch ($params['transport'] ?? 'reality') {
                    case 'reality':
                        $tls['reality'] = [
                            'enabled' => true,
                            // Старые ключи exit-серверов сохранены в обычном base64 (+/=) — sing-box их не принимает.
                            'public_key' => RealityKeys::normalizeBase64Url($params['reality']['public_key'] ?? ''),
                            'short_id' => $params['reality']['short_id'] ?? '',
                        ];
                        // sing-box 1.14+ отклоняет Reality-клиент без uTLS
                        // ("uTLS is required by reality client") — весь конфиг
                        // не проходил check, и Apply молча не применялся.
                        // Фингерпринт uTLS настраивается в панели (анти-DPI).
                        $tls['utls'] = ['enabled' => true, 'fingerprint' => self::antiDpiFingerprint()];
                        break;
                    case 'ws':
                        $transport = ['type' => 'ws', 'path' => $params['ws']['path'] ?? '/'];
                        break;
                    case 'grpc':
                        $transport = ['type' => 'grpc', 'service_name' => $params['grpc']['service_name'] ?? ''];
                        break;
                }
                // Анти-DPI: фрагментация TLS ClientHello (sing-box tls.fragment) —
                // разбивает приветствие на сегменты, чтобы DPI/Active Blocking System не прочитал SNI
                // и не подрезал хендшейк. Включается в панели (Настройки → Обход).
                if (self::antiDpiFragment()) {
                    $tls['fragment'] = true;
                    $delay = self::antiDpiFragmentDelay();
                    if ($delay !== '') {
                        $tls['fragment_fallback_delay'] = $delay;
                    }
                }
                // Доп. слой дробления: ClientHello разбивается на несколько TLS-записей.
                if (self::antiDpiRecordFragment()) {
                    $tls['record_fragment'] = true;
                }
                $outbound = [
                    'type' => 'vless',
                    'tag' => $tag,
                    'server' => $es['endpoint_host'],
                    'server_port' => (int) $es['endpoint_port'],
                    'uuid' => $params['uuid'] ?? '',
                    'tls' => $tls,
                ];
                if (!empty($params['flow'])) {
                    $outbound['flow'] = $params['flow'];
                } elseif (self::multiplexEnabled()) {
                    $outbound['multiplex'] = self::MULTIPLEX;
                }
                if ($transport) {
                    $outbound['transport'] = $transport;
                }
                return [$outbound, null];

            case 'shadowsocks':
                // Shadowsocks-2022 — тот же протокол, просто более новый
                // AEAD-метод (2022-blake3-*), sing-box поддерживает его в
                // том же outbound без отдельного кода — выбор метода за пользователем.
                return [[
                    'type' => 'shadowsocks',
                    'tag' => $tag,
                    'server' => $es['endpoint_host'],
                    'server_port' => (int) $es['endpoint_port'],
                    'method' => $params['method'] ?? '',
                    'password' => $params['password'] ?? '',
                ], null];

            case 'hysteria2':
                $outbound = [
                    'type' => 'hysteria2',
                    'tag' => $tag,
                    'server' => $es['endpoint_host'],
                    'server_port' => (int) $es['endpoint_port'],
                    'password' => $params['password'] ?? '',
                    'tls' => ['enabled' => true, 'server_name' => $params['domain'] ?? ''],
                ];
                if (!empty($params['obfs_password'])) {
                    $outbound['obfs'] = ['type' => 'salamander', 'password' => $params['obfs_password']];
                }
                return [$outbound, null];

            case 'tuic':
                return [[
                    'type' => 'tuic',
                    'tag' => $tag,
                    'server' => $es['endpoint_host'],
                    'server_port' => (int) $es['endpoint_port'],
                    'uuid' => $params['uuid'] ?? '',
                    'password' => $params['password'] ?? '',
                    'congestion_control' => $params['congestion_control'] ?? 'bbr',
                    'tls' => ['enabled' => true, 'server_name' => $params['domain'] ?? ''],
                ], null];

            case 'trojan':
                return [[
                    'type' => 'trojan',
                    'tag' => $tag,
                    'server' => $es['endpoint_host'],
                    'server_port' => (int) $es['endpoint_port'],
                    'password' => $params['password'] ?? '',
                    'tls' => ['enabled' => true, 'server_name' => $params['domain'] ?? ''],
                ], null];

            case 'amneziawg':
            default:
                return [[
                    'type' => 'direct',
                    'tag' => $tag,
                    'bind_interface' => $es['interface_name'],
                ], null];
        }
    }

    /**
     * Проверяет, что у exit-сервера реально заполнены поля, без которых
     * sing-box откажется собрать конфиг (пустая строка вместо ключа и т.п.).
     * Черновики (созданные через ExitServerFactory без провижининга) имеют
     * такие поля пустыми намеренно — до нажатия «Установить и настроить».
     * Возвращает null если всё в порядке, иначе — человекочитаемое объяснение чего не хватает.
     */
    public static function incompleteness(array $es): ?string
    {
        $protocol = $es['protocol'] ?? 'amneziawg';
        $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];

        // Бесплатные exit'ы приходят уже готовым sing-box outbound'ом — всегда «полные».
        if (!empty($params['_raw'])) {
            return null;
        }

        switch ($protocol) {
            case 'wireguard':
                if (empty($params['peer_pubkey']) || empty($params['local_privkey']) || empty($params['local_address'])) {
                    return 'WireGuard-ключи ещё не сгенерированы.';
                }
                return null;

            case 'vless':
                if (empty($params['uuid'])) {
                    return 'не задан UUID.';
                }
                if (($params['transport'] ?? 'reality') === 'reality' && empty($params['reality']['public_key'] ?? null)) {
                    return 'Reality-ключи ещё не сгенерированы.';
                }
                return null;

            case 'shadowsocks':
                if (empty($params['password'])) {
                    return 'не задан пароль.';
                }
                return null;

            case 'hysteria2':
            case 'trojan':
                if (empty($params['password'])) {
                    return 'не задан пароль.';
                }
                if (empty($params['domain'])) {
                    return 'не задан домен для TLS-сертификата.';
                }
                return null;

            case 'tuic':
                if (empty($params['uuid']) || empty($params['password'])) {
                    return 'не заданы UUID/пароль.';
                }
                if (empty($params['domain'])) {
                    return 'не задан домен для TLS-сертификата.';
                }
                return null;

            case 'amneziawg':
            default:
                if (empty($es['wg_peer_pubkey']) || empty($es['wg_local_privkey']) || empty($es['wg_local_address'])) {
                    return 'AmneziaWG-ключи ещё не сгенерированы.';
                }
                return null;
        }
    }
}
