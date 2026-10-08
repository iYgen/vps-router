<?php

namespace App;

use App\Models\NodeSetting;
use App\Models\Setting;

/**
 * Протоколы, по которым УСТРОЙСТВА (телефон, ПК, роутер) подключаются к
 * входной VPS. Раньше был только VLESS+Reality; если он не проходит у
 * конкретного оператора/сети — устройство может взять любой другой
 * включённый протокол из того же списка, маршрутизация (что идёт через
 * exit, что напрямую) у всех одна — её делает sing-box.
 *
 *  vless       — sing-box vless inbound + Reality (настройки reality_* — как раньше)
 *  shadowsocks — sing-box shadowsocks inbound, 2022-blake3-aes-128-gcm, мультипользовательский
 *  trojan      — sing-box trojan inbound, TLS с самоподписанным сертификатом
 *  hysteria2   — sing-box hysteria2 inbound (QUIC/UDP) + обфускация salamander
 *  wireguard   — sing-box wireguard endpoint в роли сервера (без системного интерфейса)
 *  amneziawg   — системный интерфейс awg-in (awg-quick), TCP заворачивается в
 *                sing-box redirect-inbound iptables-правилами (apply-router.sh),
 *                потому что обфускацию AmneziaWG sing-box сам не умеет.
 *
 * Состояние — только в server_settings (inbound_*), учётки устройств — в clients.
 */
class DeviceInbounds
{
    public const PROTOCOLS = [
        'vless' => [
            'label' => 'VLESS + Reality',
            'transport' => 'tcp',
            'default_port' => 443,
            'desc' => 'Маскировка под обычный HTTPS чужого сайта. Приложения: v2rayNG, Hiddify, NekoBox, Streisand, sing-box.',
        ],
        'shadowsocks' => [
            'label' => 'Shadowsocks-2022',
            'transport' => 'tcp+udp',
            'default_port' => 9443,
            'desc' => 'Лёгкий, быстрый, поддерживается почти всеми клиентами (v2rayNG, Hiddify, Shadowrocket, Outline-совместимые с 2022).',
        ],
        'trojan' => [
            'label' => 'Trojan (TLS)',
            'transport' => 'tcp',
            'default_port' => 2053,
            'desc' => 'TLS-туннель. Сертификат самоподписанный — в ссылке уже стоит allowInsecure.',
        ],
        'hysteria2' => [
            'label' => 'Hysteria2 (QUIC/UDP)',
            'transport' => 'udp',
            'default_port' => 8443,
            'desc' => 'Работает по UDP — выручает, когда TCP-протоколы режут. Отлично на мобильном интернете.',
        ],
        'wireguard' => [
            'label' => 'WireGuard',
            'transport' => 'udp',
            'default_port' => 38020,
            'desc' => 'Обычный WireGuard: официальное приложение WireGuard, роутеры Keenetic/MikroTik/OpenWrt — готовый .conf и QR.',
        ],
        'amneziawg' => [
            'label' => 'AmneziaWG',
            'transport' => 'udp',
            'default_port' => 38021,
            'desc' => 'WireGuard с обфускацией. Приложения AmneziaVPN / AmneziaWG. Нужен amneziawg на сервере.',
        ],
    ];

    /** Loopback-порты VLESS-WS / VLESS-gRPC для мультиплекса на 443 за фронтом. */
    public const MUX_WS_PORT = 8444;
    public const MUX_GRPC_PORT = 8445;

    /** Порт sing-box redirect-inbound для TCP из awg-in (снаружи закрыт iptables). */
    public const AWG_REDIRECT_PORT = 7893;
    public const AWG_INTERFACE = 'awg-in';
    public const WG_SUBNET_BASE = '10.66.0.0';
    public const AWG_SUBNET_BASE = '10.67.0.0';

    private const DEFAULT_TLS_SNI = 'www.bing.com';
    private const SS_METHOD = '2022-blake3-aes-128-gcm';

    /**
     * Multi-router: while a router context is set, read-side settings resolve
     * from that router's node_settings (with fallback to the global store).
     * null = the self-router / global store — exactly today's behaviour.
     */
    private static ?int $ctxRouter = null;

    /**
     * Устанавливает роутер-контекст на весь запрос (для страниц, работающих с
     * одним роутером). null = self/глобальные настройки. В отличие от
     * withRouter() не восстанавливает предыдущий — PHP-запрос изолирован.
     */
    public static function useRouter(?int $routerServerId): void
    {
        self::$ctxRouter = $routerServerId;
    }

    /** Run $fn with settings resolved for one router node (null = self/global). */
    public static function withRouter(?int $routerServerId, callable $fn)
    {
        $prev = self::$ctxRouter;
        self::$ctxRouter = $routerServerId;
        try {
            return $fn();
        } finally {
            self::$ctxRouter = $prev;
        }
    }

    /** Read a setting scoped to the active router context, else global. */
    private static function cfg(string $key, ?string $default = null): ?string
    {
        if (self::$ctxRouter !== null) {
            return NodeSetting::get(self::$ctxRouter, $key, $default);
        }
        return Setting::get($key, $default);
    }

    /** Write a setting scoped to the active router context, else global. */
    private static function cfgset(string $key, string $value): void
    {
        if (self::$ctxRouter !== null) {
            NodeSetting::set(self::$ctxRouter, $key, $value);
        } else {
            Setting::set($key, $value);
        }
    }

    public static function isEnabled(string $protocol): bool
    {
        if (!isset(self::PROTOCOLS[$protocol])) {
            return false;
        }
        // VLESS включён по умолчанию (так панель работала всегда), остальные — по явному выбору.
        $default = $protocol === 'vless' ? '1' : '0';
        return self::cfg("inbound_{$protocol}_enabled", $default) === '1';
    }

    /** @return string[] включённые протоколы в порядке PROTOCOLS */
    public static function enabled(): array
    {
        return array_values(array_filter(array_keys(self::PROTOCOLS), [self::class, 'isEnabled']));
    }

    public static function port(string $protocol): int
    {
        if ($protocol === 'vless') {
            return (int) self::cfg('reality_listen_port', (string) (App::config()['reality_listen_port'] ?? 443));
        }
        return (int) self::cfg("inbound_{$protocol}_port", (string) self::PROTOCOLS[$protocol]['default_port']);
    }

    public static function listenIp(): string
    {
        return self::cfg('reality_listen_ip') ?: '0.0.0.0';
    }

    // --- Мультиплекс на 443: VLESS-WS / VLESS-gRPC за TLS-терминирующим фронтом ---
    // Эти inbound'ы слушают ТОЛЬКО 127.0.0.1 и БЕЗ TLS — расшифровку TLS и разбор
    // ALPN/пути делает nginx-фронт (deploy/router/share-443.sh --tls-mux), который
    // делит 443 по SNI: Reality-домен → sing-box Reality (raw), TLS-домен →
    // терминируется и проксируется сюда по пути (WS) / ALPN+service (gRPC).
    public static function muxWsEnabled(): bool
    {
        return self::cfg('inbound_mux_ws_enabled') === '1';
    }

    public static function muxGrpcEnabled(): bool
    {
        return self::cfg('inbound_mux_grpc_enabled') === '1';
    }

    /** Путь WebSocket. По умолчанию /vpnws; принимает только безопасный формат. */
    public static function muxWsPath(): string
    {
        $p = trim((string) self::cfg('inbound_mux_ws_path', '/vpnws'));
        return preg_match('#^/[A-Za-z0-9/_-]{1,64}$#', $p) ? $p : '/vpnws';
    }

    /** gRPC service name. По умолчанию vpngrpc; только буквы/цифры/._-. */
    public static function muxGrpcService(): string
    {
        $s = trim((string) self::cfg('inbound_mux_grpc_service', 'vpngrpc'));
        return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $s) ? $s : 'vpngrpc';
    }

    public static function muxWsPort(): int
    {
        $p = (int) self::cfg('inbound_mux_ws_port', (string) self::MUX_WS_PORT);
        return ($p >= 1 && $p <= 65535) ? $p : self::MUX_WS_PORT;
    }

    public static function muxGrpcPort(): int
    {
        $p = (int) self::cfg('inbound_mux_grpc_port', (string) self::MUX_GRPC_PORT);
        return ($p >= 1 && $p <= 65535) ? $p : self::MUX_GRPC_PORT;
    }

    public static function publicHost(): ?string
    {
        return self::cfg('reality_public_host') ?: (self::cfg('reality_listen_ip') ?: (App::config()['reality_listen_ip'] ?? null));
    }

    /**
     * Сохраняет включённость/порты из формы «Настройки» и генерирует
     * недостающие серверные секреты. Бросает InvalidArgumentException при
     * конфликте портов.
     *
     * @param array<string,array{enabled:bool,port:int}> $values
     */
    public static function saveSettings(array $values): void
    {
        $taken = [];
        foreach (self::PROTOCOLS as $protocol => $meta) {
            $enabled = !empty($values[$protocol]['enabled']);
            $port = $protocol === 'vless' ? self::port('vless') : (int) ($values[$protocol]['port'] ?? $meta['default_port']);
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException("{$meta['label']}: некорректный порт $port");
            }
            if ($port === self::AWG_REDIRECT_PORT) {
                throw new \InvalidArgumentException("Порт " . self::AWG_REDIRECT_PORT . " зарезервирован панелью");
            }
            if ($enabled) {
                foreach (self::protoTransports($protocol) as $t) {
                    if (isset($taken["$port/$t"])) {
                        throw new \InvalidArgumentException("{$meta['label']} и {$taken["$port/$t"]} не могут слушать один и тот же порт $port/$t");
                    }
                    $taken["$port/$t"] = $meta['label'];
                }
            }
        }

        foreach (self::PROTOCOLS as $protocol => $meta) {
            self::cfgset("inbound_{$protocol}_enabled", !empty($values[$protocol]['enabled']) ? '1' : '0');
            if ($protocol !== 'vless' && isset($values[$protocol]['port'])) {
                self::cfgset("inbound_{$protocol}_port", (string) (int) $values[$protocol]['port']);
            }
        }
        self::ensureSecrets();
    }

    /**
     * Включает один протокол входа устройств (для «мастера добавления устройства»)
     * и догенерирует его секреты. Уважает роутер-контекст (useRouter): для не-self
     * пишет в node_settings. Остальные протоколы не трогает.
     */
    public static function enableProtocol(string $protocol): void
    {
        if (!isset(self::PROTOCOLS[$protocol])) {
            throw new \InvalidArgumentException('Неизвестный протокол: ' . $protocol);
        }
        self::cfgset("inbound_{$protocol}_enabled", '1');
        self::ensureSecrets();
    }

    /** Генерирует серверные ключи/пароли/сертификат для включённых протоколов, если их ещё нет. */
    public static function ensureSecrets(): void
    {
        if (self::isEnabled('shadowsocks') && !self::cfg('inbound_ss_server_psk')) {
            self::cfgset('inbound_ss_server_psk', base64_encode(random_bytes(16)));
        }
        if (self::isEnabled('hysteria2') && !self::cfg('inbound_hysteria2_obfs')) {
            self::cfgset('inbound_hysteria2_obfs', bin2hex(random_bytes(12)));
        }
        if ((self::isEnabled('trojan') || self::isEnabled('hysteria2')) && !self::cfg('inbound_tls_cert')) {
            self::generateSelfSignedCert();
        }
        foreach (['wireguard', 'amneziawg'] as $wg) {
            if (self::isEnabled($wg) && !self::cfg("inbound_{$wg}_private_key")) {
                $kp = sodium_crypto_box_keypair();
                self::cfgset("inbound_{$wg}_private_key", base64_encode(sodium_crypto_box_secretkey($kp)));
                self::cfgset("inbound_{$wg}_public_key", base64_encode(sodium_crypto_box_publickey($kp)));
            }
        }
        if (self::isEnabled('amneziawg') && !self::cfg('inbound_amneziawg_params')) {
            self::cfgset('inbound_amneziawg_params', json_encode(self::randomAmneziaParams()));
        }
    }

    /**
     * Что должно быть включено в sing-box кроме VLESS-инбаунда (его по-прежнему
     * собирает SingboxConfigBuilder). Протокол без единого активного
     * устройства не поднимается — sing-box не любит пустые списки пиров/юзеров.
     *
     * @param array<int,array> $clients активные устройства
     * @return array{inbounds:array,endpoints:array}
     */
    public static function singboxInbounds(array $clients): array
    {
        $inbounds = [];
        $endpoints = [];
        if (!$clients) {
            return ['inbounds' => [], 'endpoints' => []];
        }
        $listen = self::listenIp();

        if (self::isEnabled('shadowsocks') && self::cfg('inbound_ss_server_psk')) {
            $inbounds[] = [
                'type' => 'shadowsocks',
                'tag' => 'ss-in',
                'listen' => $listen,
                'listen_port' => self::port('shadowsocks'),
                'method' => self::SS_METHOD,
                'password' => self::cfg('inbound_ss_server_psk'),
                'users' => array_map(fn($c) => ['name' => self::userName($c), 'password' => $c['ss_psk']], $clients),
            ];
        }

        $tls = self::tlsServer();
        if (self::isEnabled('trojan') && $tls) {
            $inbounds[] = [
                'type' => 'trojan',
                'tag' => 'trojan-in',
                'listen' => $listen,
                'listen_port' => self::port('trojan'),
                'users' => array_map(fn($c) => ['name' => self::userName($c), 'password' => $c['password']], $clients),
                'tls' => $tls,
            ];
        }
        if (self::isEnabled('hysteria2') && $tls) {
            $inbound = [
                'type' => 'hysteria2',
                'tag' => 'hy2-in',
                'listen' => $listen,
                'listen_port' => self::port('hysteria2'),
                'users' => array_map(fn($c) => ['name' => self::userName($c), 'password' => $c['password']], $clients),
                'tls' => $tls + ['alpn' => ['h3']],
            ];
            if ($obfs = self::cfg('inbound_hysteria2_obfs')) {
                $inbound['obfs'] = ['type' => 'salamander', 'password' => $obfs];
            }
            $inbounds[] = $inbound;
        }

        // Мультиплекс на 443: VLESS-WS / VLESS-gRPC на loopback без TLS (TLS
        // терминирует фронт). Те же VLESS-uuid, что и у Reality. name=c<id> для
        // учёта трафика. Поднимаются только при явном включении в настройках.
        if (self::muxWsEnabled() || self::muxGrpcEnabled()) {
            $vlessUsers = array_map(fn($c) => ['uuid' => $c['uuid'], 'name' => self::userName($c)], $clients);
            if (self::muxWsEnabled()) {
                $inbounds[] = [
                    'type' => 'vless',
                    'tag' => 'vless-ws-in',
                    'listen' => '127.0.0.1',
                    'listen_port' => self::muxWsPort(),
                    'users' => $vlessUsers,
                    'transport' => ['type' => 'ws', 'path' => self::muxWsPath()],
                ];
            }
            if (self::muxGrpcEnabled()) {
                $inbounds[] = [
                    'type' => 'vless',
                    'tag' => 'vless-grpc-in',
                    'listen' => '127.0.0.1',
                    'listen_port' => self::muxGrpcPort(),
                    'users' => $vlessUsers,
                    'transport' => ['type' => 'grpc', 'service_name' => self::muxGrpcService()],
                ];
            }
        }

        if (self::isEnabled('wireguard') && self::cfg('inbound_wireguard_private_key')) {
            $endpoints[] = [
                'type' => 'wireguard',
                'tag' => 'wg-in',
                'system' => false,
                'mtu' => 1420,
                'address' => [self::serverAddress('wireguard') . '/16'],
                'private_key' => self::cfg('inbound_wireguard_private_key'),
                'listen_port' => self::port('wireguard'),
                'peers' => array_map(fn($c) => [
                    'public_key' => $c['wg_public_key'],
                    'allowed_ips' => [self::tunnelAddress('wireguard', (int) $c['id']) . '/32'],
                ], $clients),
            ];
        }

        if (self::isEnabled('amneziawg') && self::cfg('inbound_amneziawg_private_key')) {
            // TCP из awg-in заворачивается сюда iptables REDIRECT'ом (apply-router.sh);
            // снаружи порт закрыт правилом INPUT там же.
            $inbounds[] = [
                'type' => 'redirect',
                'tag' => 'awg-redirect',
                'listen' => '0.0.0.0',
                'listen_port' => self::AWG_REDIRECT_PORT,
            ];
        }

        return ['inbounds' => $inbounds, 'endpoints' => $endpoints];
    }

    /** awg-quick конфиг серверного интерфейса awg-in, либо null если AmneziaWG-вход выключен/нет устройств. */
    public static function amneziaServerConfig(array $clients): ?string
    {
        if (!self::isEnabled('amneziawg') || !$clients || !self::cfg('inbound_amneziawg_private_key')) {
            return null;
        }
        $lines = [
            '# Сгенерировано панелью: вход устройств по AmneziaWG. Ручные правки будут перезаписаны.',
            '[Interface]',
            'PrivateKey = ' . self::cfg('inbound_amneziawg_private_key'),
            'Address = ' . self::serverAddress('amneziawg') . '/16',
            'ListenPort = ' . self::port('amneziawg'),
        ];
        foreach (self::amneziaParams() as $k => $v) {
            $lines[] = "$k = $v";
        }
        foreach ($clients as $c) {
            $lines[] = '';
            $lines[] = '[Peer]';
            $lines[] = '# ' . preg_replace('/[\r\n]+/', ' ', (string) $c['name']);
            $lines[] = 'PublicKey = ' . $c['wg_public_key'];
            $lines[] = 'AllowedIPs = ' . self::tunnelAddress('amneziawg', (int) $c['id']) . '/32';
        }
        return implode("\n", $lines) . "\n";
    }

    /** Строка для apply-router.sh: что нужно завернуть в sing-box. Пустая — AmneziaWG-вход выключен. */
    public static function amneziaInboundEnv(bool $active): string
    {
        if (!$active) {
            return '';
        }
        return 'IFACE=' . self::AWG_INTERFACE . "\n"
            . 'SUBNET=' . self::AWG_SUBNET_BASE . "/16\n"
            . 'REDIRECT_PORT=' . self::AWG_REDIRECT_PORT . "\n";
    }

    /** @return string[] например ["443/tcp", "8443/udp"] — для firewall в apply-router.sh */
    public static function openPorts(): array
    {
        $out = [];
        foreach (self::enabled() as $protocol) {
            foreach (self::protoTransports($protocol) as $t) {
                $out[] = self::port($protocol) . '/' . $t;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return int[] порты включённых протоколов — чтобы защита от сканеров не забанила их как приманки */
    public static function usedPorts(): array
    {
        return array_values(array_unique(array_map([self::class, 'port'], self::enabled())));
    }

    /**
     * Готовые конфиги подключения для одного устройства по всем включённым
     * протоколам. uri — для импорта ссылкой/QR, file — для импорта файлом.
     *
     * @return array<string,array{label:string,uri:?string,file:?string,filename:string,qr:string,hint:string}>
     */
    public static function clientConfigs(array $client): array
    {
        $host = self::publicHost();
        if (!$host) {
            return [];
        }
        $name = (string) $client['name'];
        $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name) ?: 'device';
        $out = [];

        foreach (self::enabled() as $protocol) {
            $port = self::port($protocol);
            $label = self::PROTOCOLS[$protocol]['label'];
            switch ($protocol) {
                case 'vless':
                    $pbk = self::cfg('reality_public_key');
                    $sid = self::cfg('reality_short_id');
                    $sni = self::cfg('reality_server_name');
                    if (!$pbk || !$sid || !$sni) {
                        break;
                    }
                    $uri = sprintf(
                        'vless://%s@%s:%d?encryption=none&flow=xtls-rprx-vision&security=reality&sni=%s&fp=chrome&pbk=%s&sid=%s&type=tcp#%s',
                        $client['uuid'], self::uriHost($host), $port, rawurlencode($sni), rawurlencode($pbk), rawurlencode($sid), rawurlencode($name)
                    );
                    $out[$protocol] = self::entry($label, $uri, null, "$safe-vless.txt");
                    break;

                case 'shadowsocks':
                    $serverPsk = self::cfg('inbound_ss_server_psk');
                    if (!$serverPsk || empty($client['ss_psk'])) {
                        break;
                    }
                    // SIP002 для 2022-методов: userinfo — percent-encoded "method:serverPSK:userPSK", не base64.
                    $uri = 'ss://' . self::SS_METHOD . ':' . rawurlencode($serverPsk . ':' . $client['ss_psk'])
                        . '@' . self::uriHost($host) . ':' . $port . '#' . rawurlencode($name);
                    $out[$protocol] = self::entry($label, $uri, null, "$safe-shadowsocks.txt");
                    break;

                case 'trojan':
                    if (!self::cfg('inbound_tls_cert') || empty($client['password'])) {
                        break;
                    }
                    $sni = self::tlsSni();
                    $uri = sprintf(
                        'trojan://%s@%s:%d?security=tls&sni=%s&allowInsecure=1&insecure=1&type=tcp#%s',
                        rawurlencode($client['password']), self::uriHost($host), $port, rawurlencode($sni), rawurlencode($name)
                    );
                    $out[$protocol] = self::entry($label, $uri, null, "$safe-trojan.txt");
                    break;

                case 'hysteria2':
                    if (!self::cfg('inbound_tls_cert') || empty($client['password'])) {
                        break;
                    }
                    $query = ['sni' => self::tlsSni(), 'insecure' => '1'];
                    if ($obfs = self::cfg('inbound_hysteria2_obfs')) {
                        $query['obfs'] = 'salamander';
                        $query['obfs-password'] = $obfs;
                    }
                    if ($pin = self::certPin()) {
                        $query['pinSHA256'] = $pin;
                    }
                    $uri = 'hysteria2://' . rawurlencode($client['password']) . '@' . self::uriHost($host) . ':' . $port
                        . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . '#' . rawurlencode($name);
                    $out[$protocol] = self::entry($label, $uri, null, "$safe-hysteria2.txt");
                    break;

                case 'wireguard':
                case 'amneziawg':
                    $serverPub = self::cfg("inbound_{$protocol}_public_key");
                    if (!$serverPub || empty($client['wg_private_key'])) {
                        break;
                    }
                    $lines = [
                        '[Interface]',
                        'PrivateKey = ' . $client['wg_private_key'],
                        'Address = ' . self::tunnelAddress($protocol, (int) $client['id']) . '/32',
                        'DNS = 1.1.1.1, 8.8.8.8',
                        'MTU = 1280',
                    ];
                    if ($protocol === 'amneziawg') {
                        foreach (self::amneziaParams() as $k => $v) {
                            $lines[] = "$k = $v";
                        }
                    }
                    array_push(
                        $lines,
                        '',
                        '[Peer]',
                        'PublicKey = ' . $serverPub,
                        'Endpoint = ' . self::uriHost($host) . ':' . $port,
                        'AllowedIPs = 0.0.0.0/0',
                        'PersistentKeepalive = 25'
                    );
                    $conf = implode("\n", $lines) . "\n";
                    // Имя файла = имя туннеля в приложении: только [A-Za-z0-9_=+.-], до 15 символов.
                    $tunnel = substr(($protocol === 'amneziawg' ? 'awg-' : 'wg-') . $safe, 0, 15);
                    $out[$protocol] = self::entry($label, null, $conf, "$tunnel.conf");
                    break;
            }
        }
        return $out;
    }

    public static function tunnelAddress(string $protocol, int $clientId): string
    {
        $base = $protocol === 'amneziawg' ? self::AWG_SUBNET_BASE : self::WG_SUBNET_BASE;
        // .0.1 — сервер, устройства — .0.2 и дальше подряд по id (до ~65k устройств в /16).
        return long2ip(ip2long($base) + 1 + $clientId);
    }

    private static function serverAddress(string $protocol): string
    {
        $base = $protocol === 'amneziawg' ? self::AWG_SUBNET_BASE : self::WG_SUBNET_BASE;
        return long2ip(ip2long($base) + 1);
    }

    private static function entry(string $label, ?string $uri, ?string $file, string $filename): array
    {
        return [
            'label' => $label,
            'uri' => $uri,
            'file' => $file,
            'filename' => $filename,
            'qr' => $uri ?? $file,
        ];
    }

    private static function protoTransports(string $protocol): array
    {
        return match (self::PROTOCOLS[$protocol]['transport']) {
            'tcp' => ['tcp'],
            'udp' => ['udp'],
            default => ['tcp', 'udp'],
        };
    }

    private static function userName(array $client): string
    {
        return 'c' . $client['id'];
    }

    private static function uriHost(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host;
    }

    private static function tlsSni(): string
    {
        return self::cfg('inbound_tls_sni') ?: self::DEFAULT_TLS_SNI;
    }

    /** TLS-блок для trojan/hysteria2 — сертификат и ключ inline (PEM), без файлов на диске. */
    private static function tlsServer(): ?array
    {
        $cert = self::cfg('inbound_tls_cert');
        $key = self::cfg('inbound_tls_key');
        if (!$cert || !$key) {
            return null;
        }
        return [
            'enabled' => true,
            'server_name' => self::tlsSni(),
            'certificate' => self::pemLines($cert),
            'key' => self::pemLines($key),
        ];
    }

    private static function pemLines(string $pem): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $pem)), fn($l) => $l !== ''));
    }

    private static function certPin(): ?string
    {
        $cert = self::cfg('inbound_tls_cert');
        if (!$cert || !function_exists('openssl_x509_fingerprint')) {
            return null;
        }
        $fp = @openssl_x509_fingerprint($cert, 'sha256');
        return $fp ?: null;
    }

    private static function generateSelfSignedCert(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('Для Trojan/Hysteria2 нужен PHP-модуль openssl (apt install php-openssl / он обычно встроен) — не удалось создать TLS-сертификат.');
        }
        $sni = self::tlsSni();
        $opts = ['digest_alg' => 'sha256'];
        // PHP под Windows (локальная разработка/тесты) не находит openssl.cnf сам.
        $bundledCnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (!getenv('OPENSSL_CONF') && is_file($bundledCnf)) {
            $opts['config'] = $bundledCnf;
        }
        $key = openssl_pkey_new($opts + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key) {
            throw new \RuntimeException('openssl не смог создать ключ для TLS-сертификата: ' . openssl_error_string());
        }
        $csr = openssl_csr_new(['commonName' => $sni], $key, $opts);
        $x509 = $csr ? openssl_csr_sign($csr, null, $key, 3650, $opts, random_int(1, PHP_INT_MAX)) : false;
        if (!$x509) {
            throw new \RuntimeException('openssl не смог подписать TLS-сертификат: ' . openssl_error_string());
        }
        if (!openssl_x509_export($x509, $certPem) || !openssl_pkey_export($key, $keyPem, null, $opts)) {
            throw new \RuntimeException('openssl не смог сохранить TLS-сертификат: ' . openssl_error_string());
        }
        self::cfgset('inbound_tls_cert', $certPem);
        self::cfgset('inbound_tls_key', $keyPem);
        self::cfgset('inbound_tls_sni', $sni);
    }

    private static function amneziaParams(): array
    {
        $params = json_decode((string) self::cfg('inbound_amneziawg_params'), true);
        return is_array($params) ? $params : self::randomAmneziaParams();
    }

    /** Случайные параметры обфускации: у каждой установки свои — сложнее сигнатурно детектировать. */
    private static function randomAmneziaParams(): array
    {
        $s1 = random_int(15, 150);
        do {
            $s2 = random_int(15, 150);
        } while ($s1 + 56 === $s2);
        $h = [];
        while (count($h) < 4) {
            $h[random_int(5, 2147483647)] = true;
        }
        $h = array_keys($h);
        return [
            'Jc' => random_int(3, 6), 'Jmin' => 40, 'Jmax' => 70,
            'S1' => $s1, 'S2' => $s2,
            'H1' => $h[0], 'H2' => $h[1], 'H3' => $h[2], 'H4' => $h[3],
        ];
    }
}
