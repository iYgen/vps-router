<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Setting;

/**
 * Прокси для Telegram на выбранном узле: MTProto (mtg) и/или SOCKS5 (sing-box).
 * По образцу App\Zapret: параметры пишутся в /var/lib/panel/tgproxy.conf, запуск —
 * whitelisted-скрипт по sudo. На self — локально; на удалённом узле — тот же скрипт
 * доставляется по SSH (base64) и исполняется под sudo (как App\PackageManager).
 *
 * Конфиг каждого сервера хранится в Setting `tgproxy_<id>` (JSON).
 */
class TgProxy
{
    private const MODES = ['status', 'mtg-up', 'mtg-down', 'socks-up', 'socks-down'];

    private static function script(): string
    {
        return (string) (App::config()['tgproxy_script'] ?? '/usr/local/sbin/vpsrouter-tgproxy.sh');
    }

    /** @return array конфиг сервера с дефолтами */
    public static function conf(int $serverId): array
    {
        $raw = json_decode((string) Setting::get("tgproxy_$serverId", '{}'), true);
        $c = is_array($raw) ? $raw : [];
        return $c + [
            'mtg_enabled' => 0, 'mtg_port' => 8443, 'mtg_domain' => 'www.cloudflare.com', 'mtg_secret' => '',
            'socks_enabled' => 0, 'socks_port' => 1080, 'socks_user' => 'tg', 'socks_pass' => '',
        ];
    }

    private static function save(int $serverId, array $c): void
    {
        Setting::set("tgproxy_$serverId", json_encode($c, JSON_UNESCAPED_UNICODE));
    }

    /** FakeTLS-секрет mtg: ee + 16 случайных байт + hex(домен). */
    public static function genSecret(string $domain): string
    {
        $domain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $domain) ?: 'www.cloudflare.com';
        return 'ee' . bin2hex(random_bytes(16)) . bin2hex($domain);
    }

    private static function confText(array $c): string
    {
        $lines = [
            'mtg_enabled=' . ((int) $c['mtg_enabled']),
            'mtg_port=' . ((int) $c['mtg_port']),
            'mtg_secret=' . preg_replace('/[^0-9a-fA-F]/', '', (string) $c['mtg_secret']),
            'socks_enabled=' . ((int) $c['socks_enabled']),
            'socks_port=' . ((int) $c['socks_port']),
            'socks_user=' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $c['socks_user']),
            'socks_pass=' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $c['socks_pass']),
        ];
        return implode("\n", $lines) . "\n";
    }

    /** Выполнить режим на узле: self — локально по sudo, удалённый — по SSH. */
    private static function run(array $server, string $mode, array $c): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'bad mode'];
        }
        $confText = self::confText($c);

        if ((int) ($server['is_self'] ?? 0) === 1) {
            @file_put_contents('/var/lib/panel/tgproxy.conf', $confText);
            @chmod('/var/lib/panel/tgproxy.conf', 0600);
            $out = [];
            $code = 1;
            @exec('sudo -n ' . escapeshellarg(self::script()) . ' ' . escapeshellarg($mode) . ' 2>&1', $out, $code);
            return ['ok' => $code === 0, 'output' => implode("\n", $out)];
        }

        if (empty($server['host'])) {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_host')];
        }
        $priv = Server::sshPrivateKey((int) $server['id']);
        if (!$priv) {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_key')];
        }
        $body = is_readable(self::script()) ? (string) file_get_contents(self::script()) : '';
        if ($body === '') {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_script')];
        }
        $cmd = 'mkdir -p /var/lib/panel && printf %s ' . escapeshellarg(base64_encode($confText)) . ' | base64 -d > /var/lib/panel/tgproxy.conf && chmod 600 /var/lib/panel/tgproxy.conf && '
            . 'printf %s ' . escapeshellarg(base64_encode($body)) . ' | base64 -d | sudo -n bash -s -- ' . escapeshellarg($mode) . ' 2>&1';
        $res = Ssh::runCommand((string) $server['host'], (int) ($server['ssh_port'] ?? 22), (string) ($server['ssh_user'] ?? 'root'), $priv, $cmd);
        return $res['ok'] ? ['ok' => true, 'output' => (string) $res['output']] : ['ok' => false, 'error' => $res['error'] ?? 'ssh failed'];
    }

    public static function status(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $c = self::conf($serverId);
        $r = self::run($server, 'status', $c);
        $parsed = [];
        foreach (preg_split('/\r?\n/', (string) ($r['output'] ?? '')) as $line) {
            if (preg_match('/^(\w+)=(.*)$/', $line, $m)) {
                $parsed[$m[1]] = $m[2];
            }
        }
        return [
            'ok'         => $r['ok'] ?? false,
            'conf'       => $c,
            'mtg_active' => ($parsed['mtg_active'] ?? '') === 'active',
            'socks_active' => ($parsed['socks_active'] ?? '') === 'active',
            'singbox'    => ($parsed['singbox'] ?? 'no') === 'yes',
            'mtg_link'   => self::mtgLink($server, $c),
            'socks_link' => self::socksLink($server, $c),
        ];
    }

    private static function host(array $server): string
    {
        $h = (string) ($server['host'] ?? '');
        if ($h === '' && (int) ($server['is_self'] ?? 0) === 1) {
            $h = (string) (Setting::get('reality_public_host') ?: Setting::get('reality_listen_ip') ?: '');
        }
        return $h;
    }

    public static function mtgLink(array $server, array $c): string
    {
        if (empty($c['mtg_enabled']) || empty($c['mtg_secret'])) {
            return '';
        }
        $h = self::host($server);
        return $h ? "tg://proxy?server=$h&port=" . (int) $c['mtg_port'] . '&secret=' . $c['mtg_secret'] : '';
    }

    public static function socksLink(array $server, array $c): string
    {
        if (empty($c['socks_enabled'])) {
            return '';
        }
        $h = self::host($server);
        return $h ? "tg://socks?server=$h&port=" . (int) $c['socks_port'] . '&user=' . rawurlencode((string) $c['socks_user']) . '&pass=' . rawurlencode((string) $c['socks_pass']) : '';
    }

    // ---- действия (возвращают результат скрипта) ----

    public static function enableMtg(int $serverId, int $port, string $domain): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $c = self::conf($serverId);
        $c['mtg_port'] = $port >= 1 && $port <= 65535 ? $port : 8443;
        $c['mtg_domain'] = $domain ?: $c['mtg_domain'];
        if (empty($c['mtg_secret'])) {
            $c['mtg_secret'] = self::genSecret((string) $c['mtg_domain']);
        }
        $c['mtg_enabled'] = 1;
        $r = self::run($server, 'mtg-up', $c);
        if ($r['ok']) {
            self::save($serverId, $c);
            AuditLog::record('tgproxy.mtg_enable', "server=$serverId port={$c['mtg_port']}");
        }
        return $r;
    }

    public static function disableMtg(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $c = self::conf($serverId);
        $c['mtg_enabled'] = 0;
        $r = self::run($server, 'mtg-down', $c);
        if ($r['ok']) {
            self::save($serverId, $c);
            AuditLog::record('tgproxy.mtg_disable', "server=$serverId");
        }
        return $r;
    }

    public static function regenSecret(int $serverId): array
    {
        $c = self::conf($serverId);
        $c['mtg_secret'] = self::genSecret((string) $c['mtg_domain']);
        self::save($serverId, $c);
        if (!empty($c['mtg_enabled'])) {
            return self::enableMtg($serverId, (int) $c['mtg_port'], (string) $c['mtg_domain']);
        }
        return ['ok' => true];
    }

    public static function enableSocks(int $serverId, int $port, string $user, string $pass): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $c = self::conf($serverId);
        $c['socks_port'] = $port >= 1 && $port <= 65535 ? $port : 1080;
        $c['socks_user'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $user) ?: 'tg';
        $c['socks_pass'] = $pass !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $pass) : bin2hex(random_bytes(6));
        $c['socks_enabled'] = 1;
        $r = self::run($server, 'socks-up', $c);
        if ($r['ok']) {
            self::save($serverId, $c);
            AuditLog::record('tgproxy.socks_enable', "server=$serverId port={$c['socks_port']}");
        }
        return $r;
    }

    public static function disableSocks(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $c = self::conf($serverId);
        $c['socks_enabled'] = 0;
        $r = self::run($server, 'socks-down', $c);
        if ($r['ok']) {
            self::save($serverId, $c);
            AuditLog::record('tgproxy.socks_disable', "server=$serverId");
        }
        return $r;
    }
}
