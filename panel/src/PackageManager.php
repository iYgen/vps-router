<?php

namespace App;

use App\Models\Server;

/**
 * Обновление ОС-пакетов узлов из панели (раздел «Система»). Логика живёт в
 * whitelisted-скрипте vpsrouter-pkg-manage.sh:
 *   • self-узел — скрипт запускается локально по sudo;
 *   • удалённые серверы — содержимое того же скрипта панель передаёт по SSH
 *     (base64) и исполняет под ssh-пользователем через sudo, так что ставить
 *     скрипт на каждый сервер заранее не нужно (единый источник правды).
 *
 * Долгий upgrade скрипт запускает в фоне (setsid) и сразу возвращает управление —
 * веб-запрос не висит на apt/yum. Прогресс виден через log().
 */
class PackageManager
{
    private const MODES = ['count', 'upgrade', 'log'];

    private static function script(): string
    {
        return (string) (App::config()['pkg_manage_script'] ?? '/usr/local/sbin/vpsrouter-pkg-manage.sh');
    }

    /** Разбирает key=value строки из вывода скрипта, остальное — в raw. */
    private static function parse(string $out): array
    {
        $kv = [];
        $raw = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (preg_match('/^(manager|count|security|running|status|msg)=(.*)$/', $line, $m)) {
                $kv[$m[1]] = $m[2];
            } else {
                $raw[] = $line;
            }
        }
        $kv['_raw'] = trim(implode("\n", $raw));
        return $kv;
    }

    /** Выполнить режим на узле: локально (self) или по SSH (удалённый). */
    private static function run(array $server, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'bad mode'];
        }

        if ((int) ($server['is_self'] ?? 0) === 1) {
            $out = [];
            $code = 1;
            @exec('sudo -n ' . escapeshellarg(self::script()) . ' ' . escapeshellarg($mode) . ' 2>&1', $out, $code);
            return ['ok' => $code === 0 || $code === 100, 'data' => self::parse(implode("\n", $out))];
        }

        // Удалённый сервер: нужен host + ключ.
        if (empty($server['host'])) {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_host')];
        }
        $priv = Server::sshPrivateKey((int) $server['id']);
        if (!$priv) {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_key')];
        }
        $local = self::script();
        $body = is_readable($local) ? (string) file_get_contents($local) : '';
        if ($body === '') {
            return ['ok' => false, 'error' => I18n::t('pkg.err_no_script')];
        }
        // Передаём скрипт по SSH и исполняем под sudo (ssh-пользователь обычно root).
        $b64 = base64_encode($body);
        $cmd = 'printf %s ' . escapeshellarg($b64) . ' | base64 -d | sudo -n bash -s -- ' . escapeshellarg($mode)
            . ' 2>&1 || printf %s ' . escapeshellarg($b64) . ' | base64 -d | bash -s -- ' . escapeshellarg($mode) . ' 2>&1';
        $res = Ssh::runCommand(
            (string) $server['host'],
            (int) ($server['ssh_port'] ?? 22),
            (string) ($server['ssh_user'] ?? 'root'),
            $priv,
            $cmd
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'ssh failed'];
        }
        return ['ok' => true, 'data' => self::parse((string) $res['output'])];
    }

    /** Сколько пакетов можно обновить (+ security) и идёт ли уже обновление. */
    public static function status(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $r = self::run($server, 'count');
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'failed'];
        }
        $d = $r['data'];
        return [
            'ok'       => true,
            'manager'  => $d['manager'] ?? 'none',
            'count'    => (int) ($d['count'] ?? 0),
            'security' => (int) ($d['security'] ?? 0),
            'running'  => ((int) ($d['running'] ?? 0)) === 1,
        ];
    }

    /** Запустить обновление в фоне. Возвращает started|running|error. */
    public static function upgrade(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $r = self::run($server, 'upgrade');
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'failed'];
        }
        return ['ok' => true, 'status' => $r['data']['status'] ?? 'unknown', 'msg' => $r['data']['msg'] ?? ''];
    }

    /** Состояние фонового обновления + хвост лога. */
    public static function log(int $serverId): array
    {
        $server = Server::find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $r = self::run($server, 'log');
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'failed'];
        }
        return ['ok' => true, 'running' => ((int) ($r['data']['running'] ?? 0)) === 1, 'log' => $r['data']['_raw'] ?? ''];
    }
}
