<?php

namespace App;

use App\Models\Connection;
use App\Models\Server;

/**
 * Ретрансляторы (relay): «лёгкие» узлы, которые только пересылают порты входов
 * роутера (nftables DNAT, deploy/provision/relay-forward.sh). В графе это связь
 * type=relay: source = узел-ретранслятор, target = роутер (входной VPS).
 *
 * Устройства получают для каждого ретранслятора копию своих ссылок, где адрес
 * сервера заменён на адрес ретранслятора, — всё остальное (ключи, SNI, путь)
 * прежнее, т.к. трафик доходит до того же роутера без изменений.
 *
 * Служебное состояние хранится в connections.config (JSON):
 *   provision_status (not_provisioned|provisioning|provisioned|failed),
 *   last_provision_at, last_provision_log, target_ip, ports (["443/tcp",...]).
 */
class Relay
{
    public const SCRIPT = 'relay-forward.sh';

    /**
     * Связи-ретрансляторы, ведущие в роутер.
     *
     * @param int|null $routerServerId null = сам входной VPS (self)
     * @return array<int,array> строки connections + relay_name/relay_host/relay_enabled + cfg (декодированный config)
     */
    public static function forRouter(?int $routerServerId): array
    {
        $rid = $routerServerId ?? (($self = Server::self()) ? (int) $self['id'] : null);
        if ($rid === null) {
            return [];
        }
        $stmt = Database::get()->prepare(
            "SELECT c.*, s.name AS relay_name, s.host AS relay_host, s.enabled AS relay_enabled
             FROM connections c JOIN servers s ON s.id = c.source_server_id
             WHERE c.type = 'relay' AND c.target_server_id = ?
             ORDER BY c.id"
        );
        $stmt->execute([$rid]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['cfg'] = json_decode((string) ($row['config'] ?? ''), true) ?: [];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Ретрансляторы, через которые устройству стоит выдать ссылки: связь
     * настроена (provisioned), узел включён, адрес задан.
     *
     * @return array<int,array{id:int,name:string,host:string}>
     */
    public static function activeVia(?int $routerServerId): array
    {
        $out = [];
        foreach (self::forRouter($routerServerId) as $r) {
            if (($r['cfg']['provision_status'] ?? '') !== 'provisioned' || !(int) $r['relay_enabled'] || trim((string) $r['relay_host']) === '') {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['relay_name'], 'host' => trim((string) $r['relay_host'])];
        }
        return $out;
    }

    /** Роутер связи как «контекст настроек» DeviceInbounds: null = self. */
    private static function routerCtx(array $router): ?int
    {
        return !empty($router['is_self']) ? null : (int) $router['id'];
    }

    /** IPv4 роутера, на который пересылать. */
    public static function targetIp(array $router): string
    {
        $candidates = [];
        if (!empty($router['is_self'])) {
            $candidates[] = DeviceInbounds::withRouter(null, fn() => DeviceInbounds::listenIp());
        }
        $candidates[] = (string) $router['host'];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c === '' || $c === '0.0.0.0') {
                continue;
            }
            if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $c;
            }
            $ip = gethostbyname($c);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        throw new \InvalidArgumentException('Не удалось определить IPv4 роутера «' . $router['name'] . '» — укажите IP в поле host узла');
    }

    /** @return string[] порты входов роутера, например ["443/tcp","2222/tcp","38020/udp"] */
    public static function ports(array $router): array
    {
        $ports = DeviceInbounds::withRouter(self::routerCtx($router), fn() => DeviceInbounds::openPorts());
        sort($ports);
        return $ports;
    }

    /** @return array{0:array,1:array,2:array} [connection, relay-server, router-server] */
    private static function load(int $connectionId): array
    {
        $conn = Connection::find($connectionId);
        if (!$conn || $conn['type'] !== 'relay') {
            throw new \InvalidArgumentException('Связь-ретранслятор не найдена');
        }
        $relay = Server::find((int) $conn['source_server_id']);
        $router = Server::find((int) $conn['target_server_id']);
        if (!$relay || !$router) {
            throw new \InvalidArgumentException('Не найдены узлы связи');
        }
        if (($router['role'] ?? '') !== 'router' && empty($router['is_self'])) {
            throw new \InvalidArgumentException('Ретранслятор ведётся в роутер (входной узел): «' . $router['name'] . '» не роутер');
        }
        return [$conn, $relay, $router];
    }

    /**
     * Применить пересылку на узле-ретрансляторе по SSH.
     *
     * @param bool $disable true — снять пересылку (перед удалением связи)
     * @return array{ok:bool,exit_code:?int,stdout:string,stderr:string,raw_error:?string}
     */
    public static function sync(int $connectionId, bool $disable = false): array
    {
        [$conn, $relay, $router] = self::load($connectionId);
        $cfg = json_decode((string) ($conn['config'] ?? ''), true) ?: [];

        $key = Server::sshPrivateKey((int) $relay['id']);
        if (!$key) {
            throw new \InvalidArgumentException('Для узла «' . $relay['name'] . '» не сохранён SSH-ключ — добавьте его в карточке узла');
        }
        $ports = $disable ? [] : self::ports($router);
        $target = self::targetIp($router);

        $params = [
            'target_ip' => $target,
            'ssh_port' => (int) $relay['ssh_port'],
            'log_new' => true,
            'ports' => array_map(function (string $p) {
                [$port, $proto] = explode('/', $p);
                return ['port' => (int) $port, 'proto' => $proto];
            }, $ports),
        ];

        if (!$disable) {
            Connection::setConfig($connectionId, ['provision_status' => 'provisioning'] + $cfg);
        }
        Provisioner::knockIfConfigured($relay);
        try {
            $result = Ssh::runProvisionScript(
                $relay['host'],
                (int) $relay['ssh_port'],
                $relay['ssh_user'],
                $key,
                Provisioner::scriptsDir() . '/' . self::SCRIPT,
                $params
            );
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'exit_code' => null, 'stdout' => '', 'stderr' => '', 'raw_error' => $e->getMessage()];
        }
        if ($disable) {
            return $result;
        }

        $log = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '') . ($result['raw_error'] ?? ''));
        Connection::setConfig($connectionId, [
            'provision_status' => $result['ok'] ? 'provisioned' : 'failed',
            'last_provision_at' => date('Y-m-d H:i:s'),
            'last_provision_log' => mb_substr($log, -4000),
            'target_ip' => $target,
            'ports' => $ports,
        ] + $cfg);
        return $result;
    }

    /**
     * После apply роутера: перенастроить ретрансляторы, у которых изменился
     * набор портов или адрес роутера. Ошибки не роняют apply — возвращаются строкой.
     *
     * @return string[] строки для лога apply
     */
    public static function syncChanged(?int $routerServerId): array
    {
        $lines = [];
        foreach (self::forRouter($routerServerId) as $r) {
            $cfg = $r['cfg'];
            if (($cfg['provision_status'] ?? '') !== 'provisioned') {
                continue; // ещё не настраивали — это делает пользователь кнопкой
            }
            try {
                $router = Server::find((int) $r['target_server_id']);
                if (self::ports($router) === ($cfg['ports'] ?? null) && self::targetIp($router) === ($cfg['target_ip'] ?? null)) {
                    continue;
                }
                $res = self::sync((int) $r['id']);
                $lines[] = 'relay ' . $r['relay_name'] . ': ' . ($res['ok'] ? 'пересылка обновлена' : 'ОШИБКА обновления пересылки');
            } catch (\Throwable $e) {
                $lines[] = 'relay ' . $r['relay_name'] . ': ' . $e->getMessage();
            }
        }
        return $lines;
    }
}
