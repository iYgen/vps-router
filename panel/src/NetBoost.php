<?php

namespace App;

use App\Models\Server;
use App\Models\Setting;

/**
 * «Ускорение»: BBR + fq на серверах (см. net_boost_* в deploy/provision/_lib.sh).
 *
 * Сервер панели — через узкую sudo-запись /usr/local/sbin/vpsrouter-net-boost.sh
 * (ставит deploy/bootstrap.sh); остальные — по SSH скриптом net-boost.sh.
 * Серверы без сохранённого SSH-ключа пропускаются с понятной причиной.
 */
class NetBoost
{
    public const LOCAL_SCRIPT = '/usr/local/sbin/vpsrouter-net-boost.sh';

    /** Разобрать строку «NETBOOST enabled=1 cc=bbr qdisc=fq kernel=…». */
    public static function parseStatus(string $out): ?array
    {
        if (!preg_match('/NETBOOST enabled=(\d) cc=(\S+) qdisc=(\S+) kernel=(\S+)/', $out, $m)) {
            return null;
        }
        return ['enabled' => $m[1] === '1', 'cc' => $m[2], 'qdisc' => $m[3], 'kernel' => $m[4], 'active' => $m[2] === 'bbr'];
    }

    /** Желаемое состояние (что выбрал пользователь) — без обращения к серверам. */
    public static function wanted(): bool
    {
        return Setting::get('net_boost', '0') === '1';
    }

    /**
     * Применить (on/off) или узнать состояние (status) на всех серверах.
     * @return array{wanted: bool, servers: list<array>}
     */
    public static function run(string $action): array
    {
        if (!in_array($action, ['on', 'off', 'status'], true)) {
            throw new \InvalidArgumentException('action: on | off | status');
        }
        if ($action !== 'status') {
            Setting::set('net_boost', $action === 'on' ? '1' : '0');
        }
        $out = [];
        foreach (Server::all() as $s) {
            $out[] = ['id' => (int) $s['id'], 'name' => $s['name']] + self::one($s, $action);
        }
        return ['wanted' => self::wanted(), 'servers' => $out];
    }

    private static function one(array $s, string $action): array
    {
        try {
            if (!empty($s['is_self'])) {
                return self::local($action);
            }
            $key = Server::sshPrivateKey((int) $s['id']);
            if (!$key) {
                return ['ok' => false, 'error' => 'нет SSH-ключа — сервер не управляется панелью'];
            }
            Provisioner::knockIfConfigured($s);
            $r = Ssh::runProvisionScript(
                $s['host'], (int) $s['ssh_port'], $s['ssh_user'], $key,
                Provisioner::scriptsDir() . '/net-boost.sh', ['action' => $action]
            );
            $text = (string) ($r['stdout'] ?? '') . "\n" . (string) ($r['stderr'] ?? '') . "\n" . (string) ($r['raw_error'] ?? '');
            return self::result((bool) ($r['ok'] ?? false), $text);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function local(string $action): array
    {
        if (!is_file(self::LOCAL_SCRIPT)) {
            return ['ok' => false, 'error' => 'на сервере панели нет ' . self::LOCAL_SCRIPT . ' — перезапустите deploy/bootstrap.sh'];
        }
        $p = proc_open(['sudo', '-n', self::LOCAL_SCRIPT, $action], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['ok' => false, 'error' => 'не удалось запустить ' . self::LOCAL_SCRIPT];
        }
        $text = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return self::result(proc_close($p) === 0, $text);
    }

    private static function result(bool $ok, string $text): array
    {
        $st = self::parseStatus($text);
        if ($st === null) {
            $last = trim((string) preg_replace('/\s+/', ' ', mb_substr(trim($text), -300)));
            return ['ok' => false, 'error' => $last !== '' ? $last : 'нет ответа'];
        }
        if (!$ok && preg_match('/BBR/u', $text)) {
            return ['ok' => false, 'error' => 'ядро ' . $st['kernel'] . ' не поддерживает BBR'] + $st;
        }
        return ['ok' => $ok] + $st;
    }
}
