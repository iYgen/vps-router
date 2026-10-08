<?php

namespace App;

/**
 * Агрегация трафика во временные ряды для графиков на странице «Трафик»:
 *   • по серверам, по периодам — входящий (rx) и исходящий (tx) из server_traffic_daily;
 *   • по ресурсам (хостам) за период — из traffic_hosts_daily.
 *
 * Для длинных диапазонов (год, два и больше) точки агрегируются: день → неделя →
 * месяц, иначе на графике были бы сотни точек. Гранулярность выбирается по длине
 * периода. Только чтение; данные собирает крон (ServerTraffic::record).
 */
class TrafficSeries
{
    public const MAX_DAYS = 1100; // ~3 года — защита от гигантских выборок

    /** Диапазон по числу дней назад от сегодня (UTC), включая сегодня. */
    public static function rangeForDays(int $days): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        return [gmdate('Y-m-d', time() - ($days - 1) * 86400), gmdate('Y-m-d')];
    }

    /** Самый ранний день с данными (для режима «Всё»), либо сегодня. */
    public static function earliestDay(): string
    {
        try {
            $d = Database::get()->query('SELECT MIN(day) FROM server_traffic_daily')->fetchColumn();
        } catch (\Throwable $e) {
            $d = null;
        }
        return $d ?: gmdate('Y-m-d');
    }

    public const GRANULARITIES = ['day', 'week', 'month', 'year'];

    /** Авто-гранулярность по числу дней (когда пользователь не выбрал явно). */
    public static function granularityFor(int $days): string
    {
        if ($days <= 92) {
            return 'day';
        }
        if ($days <= 800) {
            return 'week';
        }
        if ($days <= 1500) {
            return 'month';
        }
        return 'year';
    }

    /** Ключ/метка корзины для даты при заданной гранулярности. */
    private static function bucketKey(string $day, string $gran): string
    {
        if ($gran === 'year') {
            return substr($day, 0, 4); // YYYY
        }
        if ($gran === 'month') {
            return substr($day, 0, 7); // YYYY-MM
        }
        if ($gran === 'week') {
            // Начало недели (понедельник) — стабильная метка YYYY-MM-DD.
            $ts = strtotime($day . ' UTC');
            $dow = (int) gmdate('N', $ts); // 1..7
            return gmdate('Y-m-d', $ts - ($dow - 1) * 86400);
        }
        return $day;
    }

    /** Упорядоченный список меток корзин от $from до $to при данной гранулярности. */
    private static function bucketLabels(string $from, string $to, string $gran): array
    {
        $labels = [];
        $seen = [];
        $cur = strtotime($from . ' UTC');
        $end = strtotime($to . ' UTC');
        if ($cur === false || $end === false || $cur > $end) {
            return [];
        }
        $guard = 0;
        while ($cur <= $end && $guard++ < self::MAX_DAYS + 5) {
            $k = self::bucketKey(gmdate('Y-m-d', $cur), $gran);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $labels[] = $k;
            }
            $cur += 86400;
        }
        return $labels;
    }

    /**
     * Per-server ряды rx/tx по корзинам + суммарные ряды.
     *
     * @return array{labels:string[],granularity:string,servers:array<int,array{id:int,name:string,rx:int[],tx:int[]}>,totals:array{rx:int[],tx:int[]}}
     */
    public static function serverSeries(string $from, string $to, string $gran): array
    {
        $labels = self::bucketLabels($from, $to, $gran);
        $idx = array_flip($labels);
        $n = count($labels);

        $stmt = Database::get()->prepare(
            'SELECT d.server_id, d.day, d.rx, d.tx, s.name
             FROM server_traffic_daily d JOIN servers s ON s.id = d.server_id
             WHERE d.day >= ? AND d.day <= ?
             ORDER BY s.name'
        );
        $stmt->execute([$from, $to]);

        $servers = [];
        $totRx = array_fill(0, $n, 0);
        $totTx = array_fill(0, $n, 0);
        foreach ($stmt->fetchAll() as $row) {
            $k = self::bucketKey((string) $row['day'], $gran);
            if (!isset($idx[$k])) {
                continue;
            }
            $p = $idx[$k];
            $sid = (int) $row['server_id'];
            if (!isset($servers[$sid])) {
                $servers[$sid] = ['id' => $sid, 'name' => $row['name'], 'rx' => array_fill(0, $n, 0), 'tx' => array_fill(0, $n, 0)];
            }
            $rx = (int) $row['rx'];
            $tx = (int) $row['tx'];
            $servers[$sid]['rx'][$p] += $rx;
            $servers[$sid]['tx'][$p] += $tx;
            $totRx[$p] += $rx;
            $totTx[$p] += $tx;
        }

        return ['labels' => $labels, 'granularity' => $gran, 'servers' => array_values($servers), 'totals' => ['rx' => $totRx, 'tx' => $totTx]];
    }

    /**
     * Топ ресурсов (хостов) по трафику за период.
     * @return array<int,array{host:string,up:int,down:int,total:int}>
     */
    public static function topHosts(string $from, string $to, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        // ВАЖНО (старый SQLite на EL7): НЕ алиасить агрегат именем столбца (AS up/down) —
        // иначе ORDER BY ловит «misuse of aliased aggregate». Алиасы up_sum/down_sum,
        // ORDER BY по самим SUM(...). Строковые литералы — в одинарных кавычках.
        $sql = <<<SQL
            SELECT host, COALESCE(SUM(up),0) AS up_sum, COALESCE(SUM(down),0) AS down_sum
            FROM traffic_hosts_daily
            WHERE day >= ? AND day <= ? AND host != '' AND host != '?'
            GROUP BY host
            ORDER BY (SUM(up) + SUM(down)) DESC
            LIMIT ?
            SQL;
        $stmt = Database::get()->prepare($sql);
        $stmt->execute([$from, $to, $limit]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $up = (int) $r['up_sum'];
            $down = (int) $r['down_sum'];
            $out[] = ['host' => (string) $r['host'], 'up' => $up, 'down' => $down, 'total' => $up + $down];
        }
        return $out;
    }
}
