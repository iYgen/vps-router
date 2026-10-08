<?php

namespace App;

/**
 * Монитор «жив ли WG-вход» на входной роутере: читает пиров WireGuard/AmneziaWG
 * (Keenetic и прочие клиенты) и их last-handshake. Если пир давно не делал
 * handshake — вероятно, WG-вход режется Active Blocking System (plain WG блокируется быстро) или
 * клиент отключён. Это другой сигнал, чем TLS-зонд exit'ов (ProbeIntel):
 * здесь — состояние ВХОДА под Keenetic, который штатно умеет только WireGuard.
 *
 * Данные берём локально по sudo из read-only хелпера (панель крутится на входном узле).
 */
class WgMonitor
{
    private const FRESH = 180; // ≤3 мин с последнего handshake — «живой»

    private static function script(): string
    {
        return (string) (App::config()['wg_status_script'] ?? '/usr/local/sbin/vpsrouter-wg-status.sh');
    }

    /** @return array<int,array{iface:string,pubkey:string,endpoint:string,last_handshake:int,ago:?int,rx:int,tx:int,status:string}> */
    public static function peers(): array
    {
        $out = [];
        $code = 1;
        @exec('sudo -n ' . escapeshellarg(self::script()) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return [];
        }
        $now = time();
        $peers = [];
        foreach ($out as $line) {
            $c = explode("\t", rtrim($line, "\r\n"));
            // Пир = 9 колонок; строка интерфейса = 5 — пропускаем.
            if (count($c) < 8) {
                continue;
            }
            $last = (int) $c[5];
            $ago = $last > 0 ? max(0, $now - $last) : null;
            $status = $last === 0 ? 'never' : ($ago <= self::FRESH ? 'fresh' : 'stale');
            $peers[] = [
                'iface'          => $c[0],
                'pubkey'         => $c[1],
                'endpoint'       => $c[3] === '(none)' ? '' : $c[3],
                'last_handshake' => $last,
                'ago'            => $ago,
                'rx'             => (int) ($c[6] ?? 0),
                'tx'             => (int) ($c[7] ?? 0),
                'status'         => $status,
            ];
        }
        // Свежие сверху, затем по давности.
        usort($peers, fn($a, $b) => ($b['last_handshake'] <=> $a['last_handshake']));
        return $peers;
    }

    /** Короткая сводка: есть ли хоть один живой пир. */
    public static function summary(): array
    {
        $peers = self::peers();
        $fresh = 0;
        foreach ($peers as $p) {
            if ($p['status'] === 'fresh') {
                $fresh++;
            }
        }
        return ['total' => count($peers), 'fresh' => $fresh, 'peers' => $peers];
    }
}
