<?php

namespace App;

use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\ExitServerPeer;
use App\Models\Server;

/**
 * Оркестрирует автоустановку ПО: локально на входной VPS (через тот же sudo-паттерн,
 * что и Applier/apply-router.sh) и удалённо на exit-серверах (через
 * Ssh::runProvisionScript + фиксированные скрипты из deploy/provision/).
 *
 * Для amneziawg/wireguard сам генерирует свежую пару ключей (клиент+сервер)
 * при каждом провижининге — пользователю не нужно вручную вписывать ключи,
 * поэтому вызов "Установить и настроить" из UI полностью самодостаточен.
 */
class Provisioner
{
    /**
     * deploy/provision/*.sh на сервере, куда панель залита, лежат отдельно от
     * PHP-кода (см. deploy/router/README.md — в /var/www/panel копируется
     * только содержимое panel/, а не весь репозиторий), поэтому путь берём
     * из config.php с fallback на репозиторий для локальной разработки/тестов.
     */
    private static function scriptsDir(): string
    {
        return App::config()['provision_scripts_dir'] ?? (__DIR__ . '/../../deploy/provision');
    }

    /**
     * Прячет управляющий порт сервера (обычно SSH/22) от массовых сканеров
     * (Censys/Shodan/Active Probing) через port knocking — см.
     * deploy/provision/harden-portknock.sh. НЕ вызывается автоматически
     * нигде в панели — только явным действием пользователя, т.к. это
     * правка firewall с риском потери доступа при ошибке в параметрах.
     */
    public static function hardenPortKnock(int $serverId, int $protectedPort, array $knockPorts): array
    {
        if (count($knockPorts) < 1) {
            throw new \InvalidArgumentException('Нужен хотя бы 1 порт для последовательности стука');
        }
        $server = Server::find($serverId);
        if (!$server) {
            throw new \InvalidArgumentException('Сервер не найден');
        }
        $privateKey = Server::sshPrivateKey($serverId);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Для сервера «' . $server['name'] . '» не сохранён SSH-приватный ключ');
        }

        // Если защита уже включена раньше (перевключение с новыми портами) —
        // сервер ждёт СТАРУЮ последовательность, иначе это подключение и есть
        // тот самый "заблокировали сами себя" сценарий.
        self::knockIfConfigured($server);

        $result = Ssh::runProvisionScript(
            $server['host'],
            (int) $server['ssh_port'],
            $server['ssh_user'],
            $privateKey,
            self::scriptsDir() . '/harden-portknock.sh',
            [
                'protected_port' => $protectedPort,
                'knock_ports' => array_values($knockPorts),
                'knock_window' => 10,
                'whitelist_seconds' => 3600,
            ]
        );

        if ($result['ok'] ?? false) {
            Server::setKnockConfig($serverId, true, $protectedPort, $knockPorts);
        }

        return $result;
    }

    /**
     * Банит источник, который трогает "порты-приманки" на сервере — см.
     * deploy/provision/harden-portscan-ban.sh. НЕ вызывается автоматически
     * нигде в панели — только явным действием пользователя (та же
     * осторожность, что у hardenPortKnock(): правка firewall с риском
     * забанить легитимный трафик при ошибке в списке портов).
     *
     * decoy_ports НИКОГДА не должен пересекаться с реально используемыми
     * портами — собираем их автоматически (SSH-порт, порт(ы) всех
     * exit_servers, подключённых к этому серверу, порт Reality если это
     * self-сервер) и передаём скрипту для встроенной проверки пересечения.
     */
    public static function hardenPortscanBan(int $serverId, array $decoyPorts, int $banSeconds = 86400): array
    {
        if (count($decoyPorts) < 1) {
            throw new \InvalidArgumentException('Нужен хотя бы 1 порт-приманка');
        }
        $server = Server::find($serverId);
        if (!$server) {
            throw new \InvalidArgumentException('Сервер не найден');
        }
        $privateKey = Server::sshPrivateKey($serverId);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Для сервера «' . $server['name'] . '» не сохранён SSH-приватный ключ');
        }

        $protectedPorts = [(int) $server['ssh_port']];
        foreach (Connection::forServer($serverId) as $conn) {
            if (empty($conn['exit_server_id'])) {
                continue;
            }
            $es = ExitServer::find((int) $conn['exit_server_id']);
            if ($es && !empty($es['endpoint_port'])) {
                $protectedPorts[] = (int) $es['endpoint_port'];
            }
        }
        if ($server['is_self']) {
            $realityPort = \App\Models\Setting::get('reality_listen_port');
            if ($realityPort) {
                $protectedPorts[] = (int) $realityPort;
            }
            // Порты всех включённых протоколов входа устройств — не приманки.
            $protectedPorts = array_merge($protectedPorts, DeviceInbounds::usedPorts());
        }
        $protectedPorts = array_values(array_unique($protectedPorts));

        self::knockIfConfigured($server);

        $result = Ssh::runProvisionScript(
            $server['host'],
            (int) $server['ssh_port'],
            $server['ssh_user'],
            $privateKey,
            self::scriptsDir() . '/harden-portscan-ban.sh',
            [
                'decoy_ports' => array_values($decoyPorts),
                'ban_seconds' => $banSeconds,
                'protected_ports' => $protectedPorts,
            ]
        );

        if ($result['ok'] ?? false) {
            Server::setPortscanBanConfig($serverId, true, $decoyPorts, $banSeconds);
        }

        return $result;
    }

    /**
     * Стучит в сохранённую для сервера последовательность портов ПЕРЕД
     * любым SSH-подключением к нему — иначе включение port knocking (см.
     * hardenPortKnock()) ломает test-connection/metrics/provision для этого
     * сервера, пока кто-то не постучит вручную. Тихо ничего не делает, если
     * knock не настроен — безопасно вызывать для любого сервера всегда.
     */
    public static function knockIfConfigured(array $server): void
    {
        if (empty($server['knock_enabled']) || empty($server['host']) || empty($server['knock_ports'])) {
            return;
        }
        $ports = json_decode((string) $server['knock_ports'], true);
        if (!is_array($ports) || empty($ports)) {
            return;
        }
        Ssh::knock($server['host'], $ports);
    }

    public static function provisionRfVps(): array
    {
        $script = App::config()['provision_install_script'] ?? '/usr/local/sbin/vpsrouter-vps-install.sh';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['sudo', '-n', $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить entry-vps-install.sh');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['ok' => $exitCode === 0, 'exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Показывает на графе exit-серверы, заведённые напрямую в таблице
     * exit_servers (например через страницу «Exit-серверы») без узла и связи.
     * Для каждого такого exit-сервера создаёт узел (role=exit) и связь
     * self→узел с его протоколом. Идемпотентно: exit с уже существующей
     * связью пропускается. Возвращает число добавленных.
     */
    public static function syncOrphanExitServers(): int
    {
        $self = Server::self();
        if (!$self) {
            return 0;
        }
        $linkedExitIds = [];
        foreach (Connection::all() as $c) {
            if (!empty($c['exit_server_id'])) {
                $linkedExitIds[(int) $c['exit_server_id']] = true;
            }
        }
        $added = 0;
        foreach (ExitServer::all() as $es) {
            if (isset($linkedExitIds[(int) $es['id']])) {
                continue;
            }
            $nodeId = Server::create([
                'name' => $es['name'],
                'role' => 'exit',
                'host' => $es['endpoint_host'] ?? '',
                'position_x' => 300 + ($added * 40),
                'position_y' => 120 + ($added * 90),
            ]);
            Connection::create((int) $self['id'], $nodeId, $es['protocol'] ?? 'amneziawg', (int) $es['id'], null, null);
            $added++;
        }
        return $added;
    }

    public static function provisionConnection(int $connectionId): array
    {
        $conn = Connection::find($connectionId);
        if (!$conn) {
            throw new \InvalidArgumentException('Соединение не найдено');
        }
        if (!in_array($conn['type'], Connection::EXECUTABLE_TYPES, true)) {
            throw new \InvalidArgumentException('Для соединения типа «' . $conn['type'] . '» автопровижининг не поддерживается');
        }

        $exitServer = ExitServer::find((int) $conn['exit_server_id']);
        $target = Server::find((int) $conn['target_server_id']);
        if (!$exitServer || !$target) {
            throw new \InvalidArgumentException('Не найдены связанные сервер/exit-цель');
        }

        $privateKey = Server::sshPrivateKey((int) $target['id']);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Для сервера «' . $target['name'] . '» не сохранён SSH-приватный ключ — добавьте его в карточке сервера');
        }
        self::knockIfConfigured($target);

        ExitServer::setProvisionStatus((int) $exitServer['id'], 'provisioning');

        try {
            if ($conn['type'] === 'vless') {
                $exitServer = self::ensureVlessRealityKeys($exitServer);
            }

            [$scriptPath, $params, $dbUpdate] = match ($conn['type']) {
                'amneziawg' => self::amneziawgParams($exitServer),
                'wireguard' => self::wireguardParams($exitServer),
                'vless', 'shadowsocks' => self::singboxParams((int) $target['id']),
                default => throw new \InvalidArgumentException('Неизвестный протокол'),
            };

            if ($dbUpdate) {
                \App\Models\ExitServer::update((int) $exitServer['id'], $dbUpdate + self::currentRow($exitServer));
            }

            $result = Ssh::runProvisionScript(
                $target['host'],
                (int) $target['ssh_port'],
                $target['ssh_user'],
                $privateKey,
                $scriptPath,
                $params
            );

            $log = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '') . ($result['raw_error'] ?? ''));
            ExitServer::setProvisionStatus((int) $exitServer['id'], $result['ok'] ? 'provisioned' : 'failed', $log);

            return $result;
        } catch (\Throwable $e) {
            ExitServer::setProvisionStatus((int) $exitServer['id'], 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Добавляет ДОПОЛНИТЕЛЬНОГО WireGuard-пира (домашнее устройство — Keenetic
     * и т.п.) на уже настроенном exit-сервере с protocol='wireguard', в обход
     * входной VPS. Только для protocol='wireguard' — встроенные WG-клиенты (в т.ч.
     * Keenetic) не понимают AmneziaWG-обфускацию, поэтому amneziawg-серверы
     * сюда осознанно не допускаются (см. docs/infrastructure-ui.md).
     *
     * @return array{peer: array, apply: array} peer-строка из БД + результат SSH-применения
     */
    public static function addWireguardPeer(int $exitServerId, string $name): array
    {
        $es = ExitServer::find($exitServerId);
        if (!$es) {
            throw new \InvalidArgumentException('Exit-сервер не найден');
        }
        if (($es['protocol'] ?? '') !== 'wireguard') {
            throw new \InvalidArgumentException('Дополнительные пиры поддерживаются только для protocol=wireguard (обычный WireGuard, не AmneziaWG) — встроенный клиент Keenetic не умеет обфускацию AmneziaWG');
        }
        if (($es['provision_status'] ?? '') !== 'provisioned') {
            throw new \InvalidArgumentException('Сначала установите и настройте сам exit-сервер («Установить и настроить» в связи) — интерфейс WireGuard ещё не поднят');
        }

        $conn = Connection::forExitServer($exitServerId);
        if (!$conn) {
            throw new \InvalidArgumentException('Не найдена связь, ссылающаяся на этот exit-сервер');
        }
        $target = Server::find((int) $conn['target_server_id']);
        $privateKeySsh = $target ? Server::sshPrivateKey((int) $target['id']) : null;
        if (!$target || !$privateKeySsh) {
            throw new \InvalidArgumentException('Для сервера «' . ($target['name'] ?? '?') . '» не сохранён SSH-приватный ключ');
        }
        self::knockIfConfigured($target);

        $peerKeys = self::generateX25519Keypair();
        $subnet = self::deterministicSubnet($exitServerId);
        $octet = ExitServerPeer::nextTunnelOctet($exitServerId);
        $tunnelAddress = "$subnet.$octet/32";
        $iface = 'wg-panel-' . $exitServerId;

        $peerId = ExitServerPeer::create($exitServerId, $name, $peerKeys['public'], $peerKeys['private'], $tunnelAddress);

        $result = Ssh::runProvisionScript(
            $target['host'],
            (int) $target['ssh_port'],
            $target['ssh_user'],
            $privateKeySsh,
            self::scriptsDir() . '/wg-peer-apply.sh',
            [
                'interface_name' => $iface,
                'peer_public_key' => $peerKeys['public'],
                'peer_allowed_ip' => $tunnelAddress,
                'action' => 'add',
            ]
        );

        $log = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '') . ($result['raw_error'] ?? ''));
        ExitServerPeer::setApplied($peerId, $log);

        if (!($result['ok'] ?? false)) {
            // Не удалось применить на сервере — не оставляем "мёртвую" запись,
            // которая выглядела бы как рабочее устройство.
            ExitServerPeer::delete($peerId);
            throw new \RuntimeException('Не удалось применить пира на сервере: ' . $log);
        }

        return ['peer' => ExitServerPeer::find($peerId), 'apply' => $result];
    }

    public static function removeWireguardPeer(int $peerId): array
    {
        $peer = ExitServerPeer::find($peerId);
        if (!$peer) {
            throw new \InvalidArgumentException('Пир не найден');
        }
        $es = ExitServer::find((int) $peer['exit_server_id']);
        $conn = $es ? Connection::forExitServer((int) $es['id']) : null;
        $target = $conn ? Server::find((int) $conn['target_server_id']) : null;
        $privateKeySsh = $target ? Server::sshPrivateKey((int) $target['id']) : null;
        if (!$target || !$privateKeySsh) {
            throw new \InvalidArgumentException('Для целевого сервера не сохранён SSH-приватный ключ — удалите пира вручную на сервере');
        }
        self::knockIfConfigured($target);

        $result = Ssh::runProvisionScript(
            $target['host'],
            (int) $target['ssh_port'],
            $target['ssh_user'],
            $privateKeySsh,
            self::scriptsDir() . '/wg-peer-apply.sh',
            [
                'interface_name' => 'wg-panel-' . $peer['exit_server_id'],
                'peer_public_key' => $peer['public_key'],
                'peer_allowed_ip' => $peer['tunnel_address'],
                'action' => 'remove',
            ]
        );

        ExitServerPeer::delete($peerId);
        return $result;
    }

    /** @return array{0:string,1:array,2:?array} [путь к скрипту, параметры для скрипта, обновление в БД|null] */
    private static function amneziawgParams(array $es): array
    {
        $server = self::generateX25519Keypair();
        $client = self::generateX25519Keypair();
        $port = (int) ($es['endpoint_port'] ?: 51900);
        $subnet = self::deterministicSubnet((int) $es['id']);

        $params = [
            'interface_name' => $es['interface_name'] ?: ('awg-panel-' . $es['id']),
            'listen_port' => $port,
            'server_private_key' => $server['private'],
            'local_address' => "$subnet.1/24",
            'peer_public_key' => $client['public'],
            'peer_allowed_ip' => "$subnet.2/32",
            'amnezia_params' => !empty($es['amnezia_params']) ? json_decode($es['amnezia_params'], true) : null,
        ];

        $dbUpdate = [
            'wg_peer_pubkey' => $server['public'],
            'wg_local_privkey' => $client['private'],
            'wg_local_address' => "$subnet.2/32",
            'interface_name' => $params['interface_name'],
        ];

        return [self::scriptsDir() . '/exit-amneziawg.sh', $params, $dbUpdate];
    }

    private static function wireguardParams(array $es): array
    {
        $server = self::generateX25519Keypair();
        $client = self::generateX25519Keypair();
        $port = (int) ($es['endpoint_port'] ?: 51820);
        $subnet = self::deterministicSubnet((int) $es['id']);
        $existingParams = !empty($es['protocol_params']) ? json_decode($es['protocol_params'], true) : [];

        $params = [
            'interface_name' => 'wg-panel-' . $es['id'],
            'listen_port' => $port,
            'server_private_key' => $server['private'],
            'local_address' => "$subnet.1/24",
            'peer_public_key' => $client['public'],
            'peer_allowed_ip' => "$subnet.2/32",
        ];

        $dbUpdate = [
            'protocol' => 'wireguard',
            'protocol_params' => array_merge($existingParams, [
                'peer_pubkey' => $server['public'],
                'local_privkey' => $client['private'],
                'local_address' => "$subnet.2/32",
                'mtu' => $existingParams['mtu'] ?? 1420,
            ]),
        ];

        return [self::scriptsDir() . '/exit-wireguard.sh', $params, $dbUpdate];
    }

    /**
     * Маскировка Reality для VLESS-связи через панель.
     *  - $domain — Camouflage-домен (server_name), под который маскируется VLESS.
     *  - $preset:
     *      null/''  — просто сменить домен (рукопожатие идёт напрямую на domain:443,
     *                 домен должен быть чужим настоящим сайтом с TLS 1.3);
     *      иначе    — поднять на самом exit-сервере сайт-прикрытие (Caddy, сайт
     *                 из App\CamouflageFront) и направить рукопожатие на него
     *                 (127.0.0.1:8443). Тогда domain — свой (A-запись на exit),
     *                 сканер видит правдоподобный сайт с валидным сертификатом.
     * После изменения переприменяет конфиг exit-сервера.
     *
     * @return array{ok:bool,front:?array,provision:array,message:string}
     */
    public static function setExitCamouflage(int $connectionId, string $domain, ?string $preset = null, string $brand = 'Service', string $customHtml = ''): array
    {
        $conn = Connection::find($connectionId);
        if (!$conn) {
            throw new \InvalidArgumentException('Соединение не найдено');
        }
        if ($conn['type'] !== 'vless') {
            throw new \InvalidArgumentException('Маскировка Reality настраивается только для VLESS-связей');
        }
        $domain = strtolower(trim($domain));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
            throw new \InvalidArgumentException('Некорректный домен маскировки');
        }
        $es = ExitServer::find((int) $conn['exit_server_id']);
        $target = Server::find((int) $conn['target_server_id']);
        if (!$es || !$target) {
            throw new \InvalidArgumentException('Не найдены связанные сервер/exit-цель');
        }
        $privateKey = Server::sshPrivateKey((int) $target['id']);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Для сервера «' . $target['name'] . '» не сохранён SSH-приватный ключ');
        }

        $front = null;
        if ($preset !== null && $preset !== '') {
            if (!isset(\App\CamouflageFront::presets()[$preset])) {
                throw new \InvalidArgumentException('Неизвестный пресет сайта-прикрытия');
            }
            self::knockIfConfigured($target);
            $front = Ssh::runProvisionScript(
                $target['host'],
                (int) $target['ssh_port'],
                $target['ssh_user'],
                $privateKey,
                self::scriptsDir() . '/exit-camouflage-front.sh',
                ['domain' => $domain, 'files' => \App\CamouflageFront::files($preset, $brand, $customHtml)]
            );
            if (!($front['ok'] ?? false)) {
                $log = trim(($front['stdout'] ?? '') . "\n" . ($front['stderr'] ?? '') . ($front['raw_error'] ?? ''));
                throw new \RuntimeException('Не удалось поднять сайт-прикрытие: ' . $log);
            }
        }

        // Обновляем Reality-параметры exit-сервера.
        $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
        $params['reality']['server_name'] = $domain;
        if ($front) {
            $params['reality']['handshake_host'] = '127.0.0.1';
            $params['reality']['handshake_port'] = 8443;
        } else {
            unset($params['reality']['handshake_host'], $params['reality']['handshake_port']);
        }
        ExitServer::update((int) $es['id'], ['protocol_params' => $params] + self::currentRow($es));

        // Переприменяем конфиг exit-сервера с новым server_name/handshake.
        $provision = self::provisionConnection($connectionId);

        return [
            'ok' => $provision['ok'] ?? false,
            'front' => $front,
            'provision' => $provision,
            'message' => $front
                ? "Сайт-прикрытие на $domain поднят, Reality маскируется под него. Не забудьте Apply на входной VPS."
                : "Домен маскировки изменён на $domain. Не забудьте Apply на входной VPS.",
        ];
    }

    /** Генерирует Reality-keypair сервера, если он ещё не задан, и сохраняет в БД. */
    private static function ensureVlessRealityKeys(array $es): array
    {
        $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
        if (($params['transport'] ?? 'reality') !== 'reality' || !empty($params['reality']['private_key'])) {
            return $es;
        }

        // Reality — URL-safe base64 без padding (формат sing-box), не как у WireGuard.
        $keypair = RealityKeys::generateKeypair();
        $params['reality'] = array_merge($params['reality'] ?? [], [
            'private_key' => $keypair['private'],
            'public_key' => $keypair['public'],
            'short_id' => $params['reality']['short_id'] ?: bin2hex(random_bytes(4)),
            'server_name' => $params['reality']['server_name'] ?: 'www.microsoft.com',
        ]);

        ExitServer::update((int) $es['id'], ['protocol_params' => $params] + self::currentRow($es));
        return ExitServer::find((int) $es['id']);
    }

    /**
     * VLESS/Shadowsocks на одну и ту же exit-цель обслуживает ОДИН sing-box —
     * собираем полный список inbound'ов для этого exit-сервера (может
     * включать несколько exit_servers-строк с разными протоколами/портами,
     * указывающих на один и тот же target_server_id) и каждый раз
     * перезаписываем конфиг целиком (идемпотентно, как и на стороне входной VPS).
     */
    private const SINGBOX_INBOUND_PROTOCOLS = ['vless', 'shadowsocks', 'hysteria2', 'tuic', 'trojan'];

    /**
     * hysteria2/tuic/trojan и VLESS+ws/grpc (в отличие от VLESS+Reality)
     * предъявляют клиенту РЕАЛЬНЫЙ TLS-сертификат — путь всегда по
     * стандартной certbot-раскладке, которую сам же и просим получить
     * (см. exit-singbox.sh: домены передаются отдельным полем "domains",
     * скрипт гоняет certbot certonly --standalone для каждого перед стартом).
     */
    private static function certPaths(string $domain): array
    {
        return [
            'certificate_path' => "/etc/letsencrypt/live/$domain/fullchain.pem",
            'key_path' => "/etc/letsencrypt/live/$domain/privkey.pem",
        ];
    }

    private static function singboxParams(int $targetServerId): array
    {
        $inbounds = [];
        $domains = [];

        foreach (self::exitServersForTarget($targetServerId) as $es) {
            $protocol = $es['protocol'] ?? '';
            if (!in_array($protocol, self::SINGBOX_INBOUND_PROTOCOLS, true)) {
                continue;
            }
            $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
            $tag = 'exit-' . $es['id'] . '-in';
            $port = (int) $es['endpoint_port'];

            switch ($protocol) {
                case 'vless':
                    $inbound = [
                        'type' => 'vless',
                        'tag' => $tag,
                        'listen' => '0.0.0.0',
                        'listen_port' => $port,
                        'users' => [['uuid' => $params['uuid'] ?? '', 'flow' => $params['flow'] ?? '']],
                    ];
                    if (($params['transport'] ?? 'reality') === 'reality') {
                        // handshake_host/port (если заданы) — локальный TLS 1.3-фронт
                        // на exit-сервере (Caddy на 127.0.0.1:8443 с сайтом-прикрытием),
                        // иначе рукопожатие идёт напрямую на server_name:443.
                        $sni = $params['reality']['server_name'] ?? '';
                        $inbound['tls'] = [
                            'enabled' => true,
                            'server_name' => $sni,
                            'reality' => [
                                'enabled' => true,
                                'handshake' => [
                                    'server' => $params['reality']['handshake_host'] ?? $sni,
                                    'server_port' => (int) ($params['reality']['handshake_port'] ?? 443),
                                ],
                                'private_key' => RealityKeys::normalizeBase64Url($params['reality']['private_key'] ?? ''),
                                'short_id' => [$params['reality']['short_id'] ?? ''],
                            ],
                        ];
                    } else {
                        // ws/grpc идут поверх настоящего TLS (не Reality) — нужен реальный сертификат.
                        $domain = $params['domain'] ?? '';
                        if ($domain) {
                            $domains[] = $domain;
                            $inbound['tls'] = ['enabled' => true, 'server_name' => $domain] + self::certPaths($domain);
                        }
                        if (($params['transport'] ?? '') === 'ws') {
                            $inbound['transport'] = ['type' => 'ws', 'path' => $params['ws']['path'] ?? '/'];
                        } elseif (($params['transport'] ?? '') === 'grpc') {
                            $inbound['transport'] = ['type' => 'grpc', 'service_name' => $params['grpc']['service_name'] ?? ''];
                        }
                    }
                    $inbounds[] = $inbound;
                    break;

                case 'shadowsocks':
                    $inbounds[] = [
                        'type' => 'shadowsocks',
                        'tag' => $tag,
                        'listen' => '0.0.0.0',
                        'listen_port' => $port,
                        'method' => $params['method'] ?? 'chacha20-ietf-poly1305',
                        'password' => $params['password'] ?? '',
                    ];
                    break;

                case 'hysteria2':
                    $domain = $params['domain'] ?? '';
                    $domains[] = $domain;
                    $inbound = [
                        'type' => 'hysteria2',
                        'tag' => $tag,
                        'listen' => '0.0.0.0',
                        'listen_port' => $port,
                        'users' => [['password' => $params['password'] ?? '']],
                        'tls' => ['enabled' => true, 'server_name' => $domain] + self::certPaths($domain),
                    ];
                    if (!empty($params['obfs_password'])) {
                        $inbound['obfs'] = ['type' => 'salamander', 'password' => $params['obfs_password']];
                    }
                    $inbounds[] = $inbound;
                    break;

                case 'tuic':
                    $domain = $params['domain'] ?? '';
                    $domains[] = $domain;
                    $inbounds[] = [
                        'type' => 'tuic',
                        'tag' => $tag,
                        'listen' => '0.0.0.0',
                        'listen_port' => $port,
                        'users' => [['uuid' => $params['uuid'] ?? '', 'password' => $params['password'] ?? '']],
                        'congestion_control' => $params['congestion_control'] ?? 'bbr',
                        'tls' => ['enabled' => true, 'server_name' => $domain] + self::certPaths($domain),
                    ];
                    break;

                case 'trojan':
                    $domain = $params['domain'] ?? '';
                    $domains[] = $domain;
                    $inbounds[] = [
                        'type' => 'trojan',
                        'tag' => $tag,
                        'listen' => '0.0.0.0',
                        'listen_port' => $port,
                        'users' => [['password' => $params['password'] ?? '']],
                        'tls' => ['enabled' => true, 'server_name' => $domain] + self::certPaths($domain),
                    ];
                    break;
            }
        }

        $params = ['inbounds' => $inbounds, 'domains' => array_values(array_unique(array_filter($domains)))];
        return [self::scriptsDir() . '/exit-singbox.sh', $params, null];
    }

    private static function exitServersForTarget(int $targetServerId): array
    {
        $rows = [];
        foreach (Connection::forServer($targetServerId) as $c) {
            if ((int) $c['target_server_id'] !== $targetServerId || empty($c['exit_server_id'])) {
                continue;
            }
            $es = ExitServer::find((int) $c['exit_server_id']);
            if ($es) {
                $rows[] = $es;
            }
        }
        return $rows;
    }

    /** X25519-кейпара тем же способом, что и WireGuard/AmneziaWG/Reality — все три используют Curve25519. */
    private static function generateX25519Keypair(): array
    {
        $kp = sodium_crypto_box_keypair();
        return [
            'private' => base64_encode(sodium_crypto_box_secretkey($kp)),
            'public' => base64_encode(sodium_crypto_box_publickey($kp)),
        ];
    }

    /** Детерминированная /24-подсеть 10.90.{100+id%150}.0 — без коллизий между exit-серверами панели. */
    private static function deterministicSubnet(int $exitServerId): string
    {
        return '10.90.' . (100 + ($exitServerId % 150));
    }

    /** Полный снимок текущей строки под ключи, которые ест ExitServer::update() —
     *  чтобы $dbUpdate (частичный) не затирал остальные поля значениями по умолчанию. */
    private static function currentRow(array $es): array
    {
        return [
            'name' => $es['name'],
            'endpoint_host' => $es['endpoint_host'],
            'endpoint_port' => $es['endpoint_port'],
            'status' => $es['status'],
            'wg_peer_pubkey' => $es['wg_peer_pubkey'] ?? null,
            'wg_peer_psk' => $es['wg_peer_psk'] ?? null,
            'wg_local_privkey' => $es['wg_local_privkey'] ?? null,
            'wg_local_address' => $es['wg_local_address'] ?? null,
            'interface_name' => $es['interface_name'] ?? null,
            'amnezia_params' => $es['amnezia_params'] ?? null,
            'protocol' => $es['protocol'] ?? 'amneziawg',
            'protocol_params' => $es['protocol_params'] ?? null,
        ];
    }
}
