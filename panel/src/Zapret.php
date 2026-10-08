<?php

namespace App;

use App\Models\Setting;

/**
 * Управление пакетным DPI-десинком (nfqws/zapret) на входной роутере (self).
 * Панель пишет параметры в <db_dir>/zapret.conf и запускает привилегированный
 * whitelisted-скрипт через sudo (как Applier/apply-router.sh). Десинк применяется
 * ТОЛЬКО к трафику вход→exit (IP exit-серверов на 443), с --queue-bypass —
 * без демона трафик проходит, egress не ломается. По умолчанию выключено.
 */
class Zapret
{
    private const STRATEGIES = ['fake', 'disorder', 'split', 'fakesplit'];

    private static function confPath(): string
    {
        $dir = \dirname((string) (App::config()['db_path'] ?? '/var/lib/panel/panel.db'));
        return $dir . '/zapret.conf';
    }

    private static function statePath(): string
    {
        $dir = \dirname((string) (App::config()['db_path'] ?? '/var/lib/panel/panel.db'));
        return $dir . '/zapret.state';
    }

    private static function script(): string
    {
        return (string) (App::config()['zapret_script'] ?? '/usr/local/sbin/vpsrouter-zapret.sh');
    }

    /** IP активных exit-серверов (цели десинка). */
    private static function exitTargets(): array
    {
        $ips = [];
        try {
            $rows = Database::get()->query("SELECT host FROM servers WHERE role = 'exit' AND enabled = 1")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $h) {
                if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $h;
                }
            }
        } catch (\Throwable $e) {
            // таблицы ещё нет — пусто
        }
        return array_values(array_unique($ips));
    }

    public static function status(): array
    {
        $enabled = Setting::get('zapret_enabled', '0') === '1';
        $strategy = (string) Setting::get('zapret_strategy', 'fakesplit');
        $state = @file_get_contents(self::statePath());
        return [
            'enabled'   => $enabled,
            'strategy'  => in_array($strategy, self::STRATEGIES, true) ? $strategy : 'fakesplit',
            'strategies' => self::STRATEGIES,
            'targets'   => count(self::exitTargets()),
            'state_raw' => $state !== false ? trim((string) $state) : null,
        ];
    }

    /**
     * Включить десинк. По умолчанию ($verify=true) после применения проверяет,
     * что путь вход→exit:443 не стал ХУЖE: если стратегия сломала коннект, который
     * до включения работал, — автоматически откатывается (disable) и возвращает
     * ok=false с пояснением. Так неудачная стратегия не рушит тихо все маршруты
     * через exit (весь exit-трафик идёт в одну трубу на exit:443).
     * $verify=false — для refresh() (повторное применение уже принятой стратегии
     * при Apply/смене exit), чтобы не делать проверку и откат внутри Apply.
     */
    public static function enable(string $strategy, bool $verify = true): array
    {
        if (!in_array($strategy, self::STRATEGIES, true)) {
            $strategy = 'fakesplit';
        }
        $targets = self::exitTargets();
        // Снимок доступности ДО включения (через ту же трубу вход→exit:443).
        $before = ($verify && $targets) ? self::probeTargets($targets) : [];

        $conf = [
            'action'   => 'install',
            'strategy' => $strategy,
            'qnum'     => 200,
            'port'     => 443,
            'targets'  => $targets,
        ];
        $res = self::writeAndRun($conf);
        if (!$res['ok']) {
            return $res;
        }

        if ($verify && $targets) {
            // Дать nfqws подняться и правилу примениться, затем проверить ПОСЛЕ.
            sleep(3);
            $after = self::probeTargets($targets);
            $broke = [];
            foreach ($targets as $ip) {
                if (($before[$ip] ?? false) && !($after[$ip] ?? false)) {
                    $broke[] = $ip;
                }
            }
            if ($broke) {
                // Десинк сломал ранее рабочий путь — откатываемся.
                self::writeAndRun(['action' => 'remove']);
                Setting::set('zapret_enabled', '0');
                \App\Models\AuditLog::record('zapret.auto_rollback', "strategy=$strategy broke=" . implode(',', $broke));
                return [
                    'ok'          => false,
                    'rolled_back' => true,
                    'broke'       => $broke,
                    'before'      => $before,
                    'after'       => $after,
                    'strategy'    => $strategy,
                    'output'      => I18n::t('zapret.verify_rolled_back', $strategy, implode(', ', $broke)),
                ];
            }
        }

        Setting::set('zapret_enabled', '1');
        Setting::set('zapret_strategy', $strategy);
        \App\Models\AuditLog::record('zapret.enable', "strategy=$strategy targets=" . count($targets));
        if ($verify && $targets) {
            $res['verified'] = true;
            $res['output'] = ($res['output'] ?? '') . "\n" . I18n::t('zapret.verify_ok', $strategy);
        }
        return $res;
    }

    /**
     * Доступность каждого target на УРОВНЕ TLS (несколько попыток — против флапа).
     * Важно: проверяем именно TLS, а не TCP-коннект. fakesplit и подобные десинки
     * ломают TLS-рукопожатие (ClientHello), но TCP-handshake при этом проходит —
     * поэтому простой fsockopen дал бы ложно-положительный результат. Проба идёт
     * через правило NFQUEUE вход→exit:443, поэтому отражает реальный эффект десинка.
     * @param string[] $targets
     * @return array<string,bool> ip => жив ли TLS-транспорт
     */
    private static function probeTargets(array $targets, int $attempts = 2, float $timeout = 4.0): array
    {
        $sni = (string) (Setting::get('reality_server_name') ?: 'www.microsoft.com');
        $res = [];
        foreach ($targets as $ip) {
            $ok = false;
            for ($i = 0; $i < $attempts && !$ok; $i++) {
                if (self::tlsReachable($ip, 443, $sni, $timeout)) {
                    $ok = true;
                }
            }
            $res[$ip] = $ok;
        }
        return $res;
    }

    /**
     * true — если TLS-транспорт до $ip:$port жив: либо рукопожатие прошло, либо
     * сервер ответил TLS-алертом/закрытием быстрее таймаута (Reality так и делает
     * на чужой SNI — это нормальный ОТВЕТ, значит путь рабочий). false — если
     * чтение ушло в таймаут (ответа нет = путь убит десинком) или TCP не встал.
     */
    private static function tlsReachable(string $ip, int $port, string $sni, float $timeout): bool
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'peer_name'        => $sni,
            'SNI_enabled'      => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return false; // даже TCP не установился
        }
        stream_set_timeout($fp, (int) ceil($timeout));
        $t0 = microtime(true);
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
        $elapsed = microtime(true) - $t0;
        $meta = @stream_get_meta_data($fp);
        @fclose($fp);
        if ($ok === true) {
            return true; // полное TLS-рукопожатие — транспорт точно жив
        }
        if (!empty($meta['timed_out'])) {
            return false; // ответа не дождались — путь убит
        }
        return $elapsed < ($timeout * 0.85); // сервер ответил (алерт) раньше таймаута — путь жив
    }

    public static function disable(): array
    {
        $res = self::writeAndRun(['action' => 'remove']);
        if ($res['ok']) {
            Setting::set('zapret_enabled', '0');
            \App\Models\AuditLog::record('zapret.disable', '');
        }
        return $res;
    }

    /** Переустановить правила с актуальным списком exit-IP (вызывать после Apply/смены exit). */
    public static function refresh(): array
    {
        if (Setting::get('zapret_enabled', '0') !== '1') {
            return ['ok' => true, 'output' => 'disabled'];
        }
        return self::enable((string) Setting::get('zapret_strategy', 'fakesplit'), false);
    }

    private static function writeAndRun(array $conf): array
    {
        // Простой key=value (скрипт парсит без jq). targets — IP через пробел.
        $lines = ['action=' . ($conf['action'] ?? 'status')];
        if (isset($conf['strategy'])) {
            $lines[] = 'strategy=' . $conf['strategy'];
        }
        if (isset($conf['qnum'])) {
            $lines[] = 'qnum=' . (int) $conf['qnum'];
        }
        if (isset($conf['port'])) {
            $lines[] = 'port=' . (int) $conf['port'];
        }
        if (isset($conf['targets'])) {
            $lines[] = 'targets=' . implode(' ', (array) $conf['targets']);
        }
        if (@file_put_contents(self::confPath(), implode("\n", $lines) . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write zapret.conf'];
        }
        @chmod(self::confPath(), 0600);
        $out = [];
        $code = 1;
        @exec('sudo -n ' . escapeshellarg(self::script()) . ' 2>&1', $out, $code);
        $output = implode("\n", $out);
        return ['ok' => $code === 0, 'code' => $code, 'output' => $output];
    }
}
