<?php

namespace App;

use App\Models\Server;

/**
 * «Сервисы на узле» — что ещё крутится на сервере помимо компонентов панели:
 * Docker-контейнеры (в первую очередь Amnezia, поставленная своим приложением, а
 * не панелью) и ключевые systemd-службы. Только чтение: одна фиксированная
 * команда без пользовательских данных, по SSH (или локально для self).
 *
 * Для контейнеров Amnezia на базе WireGuard/AmneziaWG дополнительно считается,
 * сколько пиров «живые» (рукопожатие за последние 3 минуты) — видно, пользуется
 * ли кто-то этим входом.
 */
class NodeServices
{
    private const FRESH_SEC = 180;

    /** Протоколы Amnezia по имени контейнера (как их создаёт приложение AmneziaVPN). */
    private const AMNEZIA = [
        'amnezia-wireguard'      => 'WireGuard',
        'amnezia-awg'            => 'AmneziaWG',
        'amnezia-awg2'           => 'AmneziaWG 2.0',
        'amnezia-xray'           => 'XRay (VLESS Reality)',
        'amnezia-openvpn'        => 'OpenVPN',
        'amnezia-shadowsocks'    => 'OpenVPN over Shadowsocks',
        'amnezia-openvpn-cloak'  => 'OpenVPN over Cloak',
        'amnezia-ipsec'          => 'IKEv2 / IPsec',
        'amnezia-dns'            => 'AmneziaDNS',
        'amnezia-tor'            => 'Tor website',
        'amnezia-sftp'           => 'SFTP',
        'amnezia-socks5proxy'    => 'SOCKS5 proxy',
    ];

    /** Службы, которые имеет смысл показывать (если установлены). */
    private const UNITS = [
        'sing-box', 'sing-box-exit', 'xray', 'vpsrouter-mtg', 'update-site', 'reality-front',
        'caddy', 'nginx', 'docker', 'fail2ban', 'wg-quick@wg0', 'awg-quick@awg0', 'amneziawg-quick@awg0',
    ];

    public static function remoteCommand(): string
    {
        $fmt = "'{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Ports}}'";
        $units = implode(' ', self::UNITS);
        return 'D=docker; command -v docker >/dev/null 2>&1 || D=""; '
            . '[ -n "$D" ] && ! docker info >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1 && D="sudo -n docker"; '
            . 'echo "---NOW---"; date +%s; '
            . 'echo "---DOCKER---"; [ -n "$D" ] && $D ps -a --format ' . $fmt . ' 2>/dev/null; '
            . 'echo "---WG---"; [ -n "$D" ] && for c in $($D ps --format "{{.Names}}" 2>/dev/null | grep -E "^amnezia-(wireguard|awg)"); do '
            // Наружу — ТОЛЬКО время рукопожатия каждого пира (5-е поле allowed-ips с «/»):
            // в dump есть приватный ключ интерфейса, по SSH его не гоняем.
            . 'echo "## $c"; $D exec "$c" sh -c "wg show all dump 2>/dev/null || awg show all dump 2>/dev/null" 2>/dev/null'
            . ' | awk -F "\t" \'NF>=8 && $5 ~ /\// {print $6}\'; done; '
            . 'echo "---UNITS---"; for u in ' . $units . '; do '
            . 's=$(systemctl show -p LoadState --value "$u" 2>/dev/null); '
            . '[ "$s" = loaded ] && echo "$u $(systemctl is-active "$u" 2>/dev/null)"; done; true';
    }

    /**
     * Разбор вывода remoteCommand().
     *
     * @return array{amnezia: array<int,array>, containers: array<int,array>, units: array<int,array{name:string,state:string}>, docker: bool}
     */
    public static function parse(string $out): array
    {
        $sec = [];
        $cur = null;
        foreach (explode("\n", str_replace("\r", '', $out)) as $line) {
            if (preg_match('/^---(NOW|DOCKER|WG|UNITS)---$/', trim($line), $m)) {
                $cur = $m[1];
                $sec[$cur] = [];
                continue;
            }
            if ($cur !== null && trim($line) !== '') {
                $sec[$cur][] = $line;
            }
        }
        $now = (int) ($sec['NOW'][0] ?? time());

        // WG/AWG-пиры по контейнерам: всего / живых.
        $peers = [];
        $c = null;
        foreach ($sec['WG'] ?? [] as $l) {
            if (str_starts_with($l, '## ')) {
                $c = trim(substr($l, 3));
                $peers[$c] = ['total' => 0, 'fresh' => 0];
                continue;
            }
            // одна строка = latest-handshake (unix time, 0 = никогда) одного пира
            $l = trim($l);
            if ($c !== null && ctype_digit($l)) {
                $peers[$c]['total']++;
                $hs = (int) $l;
                if ($hs > 0 && $now - $hs <= self::FRESH_SEC) {
                    $peers[$c]['fresh']++;
                }
            }
        }

        $amnezia = [];
        $containers = [];
        foreach ($sec['DOCKER'] ?? [] as $l) {
            $f = explode('|', $l, 5);
            if (count($f) < 4) {
                continue;
            }
            [$name, $image, $state, $status] = $f;
            $row = [
                'name'   => $name,
                'image'  => $image,
                'state'  => $state,
                'status' => $status,
                'ports'  => self::ports($f[4] ?? ''),
            ];
            if (isset(self::AMNEZIA[$name]) || str_starts_with($name, 'amnezia-')) {
                $row['protocol'] = self::AMNEZIA[$name] ?? ucfirst(substr($name, 8));
                if (isset($peers[$name])) {
                    $row['peers'] = $peers[$name];
                }
                $amnezia[] = $row;
            } else {
                $containers[] = $row;
            }
        }

        $units = [];
        foreach ($sec['UNITS'] ?? [] as $l) {
            $p = preg_split('/\s+/', trim($l), 2);
            if (count($p) === 2) {
                $units[] = ['name' => $p[0], 'state' => $p[1]];
            }
        }

        return [
            'amnezia'    => $amnezia,
            'containers' => $containers,
            'units'      => $units,
            'docker'     => isset($sec['DOCKER']) && ($amnezia || $containers),
        ];
    }

    /** "0.0.0.0:30313->30313/udp, :::30313->30313/udp" → ["30313/udp"] */
    public static function ports(string $s): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $s))) as $p) {
            if (preg_match('/:(\d+)->\d+\/(tcp|udp)$/', $p, $m)) {
                $out[$m[1] . '/' . $m[2]] = true;
            }
        }
        return array_keys($out);
    }

    public static function fetch(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        if ((int) ($server['is_self'] ?? 0) === 1) {
            $out = (string) @shell_exec('/bin/sh -c ' . escapeshellarg(self::remoteCommand()) . ' 2>/dev/null');
            return ['ok' => true] + self::parse($out);
        }
        if (empty($server['host'])) {
            return ['ok' => false, 'error' => 'no host'];
        }
        $priv = Server::sshPrivateKey($serverId);
        if (!$priv) {
            return ['ok' => false, 'error' => 'no ssh key'];
        }
        Provisioner::knockIfConfigured($server);
        $r = Ssh::runCommand(
            (string) $server['host'],
            (int) ($server['ssh_port'] ?? 22),
            (string) ($server['ssh_user'] ?? 'root'),
            $priv,
            self::remoteCommand()
        );
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'ssh failed'];
        }
        return ['ok' => true] + self::parse((string) $r['output']);
    }
}
