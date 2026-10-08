<?php

namespace App;

/**
 * Трафик серверов для контроля лимита тарифа (у Hostkey и многих хостеров —
 * N ТБ в месяц). Считаем по счётчикам ОСНОВНОГО сетевого интерфейса (того,
 * через который идёт маршрут по умолчанию) — это ровно то, что видит и
 * тарифицирует хостер; туннельные интерфейсы не суммируем, иначе трафик
 * посчитался бы дважды.
 *
 * Счётчики ядра обнуляются при перезагрузке — храним последнее значение и
 * копим дельты по суткам (server_traffic_daily). Если счётчик стал меньше
 * прежнего — была перезагрузка, дельта = новое значение целиком.
 *
 * Цифры хостера могут немного отличаться (другое время отсечки, служебный
 * трафик) — для этого есть ручная сверка «израсходовано по данным хостера».
 */
class ServerTraffic
{
    public const MODES = [
        'sum' => 'входящий + исходящий',
        'out' => 'только исходящий',
        'max' => 'больший из входящего и исходящего',
    ];
    public const WARN_PERCENT = 85;
    public const DANGER_PERCENT = 95;

    /** Команда для удалённого сервера: "<iface> <rx_bytes> <tx_bytes>". */
    public const REMOTE_COMMAND = 'DEV=$(ip -4 route show default 2>/dev/null | awk \'{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}\'); '
        . '[ -n "$DEV" ] && awk -v d="$DEV:" \'$1==d {print substr(d,1,length(d)-1), $2, $10}\' /proc/net/dev';

    /** @return array{iface:string,rx:int,tx:int}|null */
    public static function parseCounters(string $output): ?array
    {
        if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s*$/m', trim($output), $m)) {
            return null;
        }
        return ['iface' => $m[1], 'rx' => (int) $m[2], 'tx' => (int) $m[3]];
    }

    /** Счётчики основного интерфейса этого (локального) сервера. */
    public static function localCounters(): ?array
    {
        $routes = @file_get_contents('/proc/net/route');
        $dev = null;
        if ($routes !== false) {
            foreach (array_slice(explode("\n", trim($routes)), 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) > 2 && $cols[1] === '00000000') { // маршрут по умолчанию
                    $dev = $cols[0];
                    break;
                }
            }
        }
        $netdev = @file_get_contents('/proc/net/dev');
        if (!$dev || $netdev === false) {
            return null;
        }
        foreach (explode("\n", $netdev) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$iface, $rest] = explode(':', $line, 2);
            if (trim($iface) === $dev) {
                $cols = preg_split('/\s+/', trim($rest));
                return ['iface' => $dev, 'rx' => (int) $cols[0], 'tx' => (int) $cols[8]];
            }
        }
        return null;
    }

    /** Учитывает новый замер счётчиков сервера. */
    public static function record(int $serverId, array $counters, ?int $now = null): void
    {
        $now ??= time();
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM server_traffic_state WHERE server_id = ?');
        $stmt->execute([$serverId]);
        $state = $stmt->fetch();

        $dRx = 0;
        $dTx = 0;
        if ($state && $state['iface'] === $counters['iface']) {
            $dRx = $counters['rx'] >= (int) $state['last_rx'] ? $counters['rx'] - (int) $state['last_rx'] : $counters['rx'];
            $dTx = $counters['tx'] >= (int) $state['last_tx'] ? $counters['tx'] - (int) $state['last_tx'] : $counters['tx'];
        }
        // Первый замер (или сменился интерфейс) — только запоминаем точку отсчёта.

        $pdo->prepare('INSERT OR REPLACE INTO server_traffic_state (server_id, iface, last_rx, last_tx, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$serverId, $counters['iface'], $counters['rx'], $counters['tx'], $now]);

        if ($dRx + $dTx === 0) {
            return;
        }
        $day = gmdate('Y-m-d', $now);
        $upd = $pdo->prepare('UPDATE server_traffic_daily SET rx = rx + ?, tx = tx + ? WHERE server_id = ? AND day = ?');
        $upd->execute([$dRx, $dTx, $serverId, $day]);
        if ($upd->rowCount() === 0) {
            $pdo->prepare('INSERT INTO server_traffic_daily (server_id, day, rx, tx) VALUES (?, ?, ?, ?)')->execute([$serverId, $day, $dRx, $dTx]);
        }
    }

    /** Начало текущего расчётного периода (UTC-дата) для дня сброса $resetDay. */
    public static function periodStart(int $resetDay, ?int $now = null): string
    {
        $now ??= time();
        $resetDay = max(1, min(28, $resetDay));
        $y = (int) gmdate('Y', $now);
        $m = (int) gmdate('n', $now);
        if ((int) gmdate('j', $now) < $resetDay) {
            $m--;
            if ($m < 1) {
                $m = 12;
                $y--;
            }
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $resetDay);
    }

    private static function countBytes(int $rx, int $tx, string $mode): int
    {
        return match ($mode) {
            'out' => $tx,
            'max' => max($rx, $tx),
            default => $rx + $tx,
        };
    }

    /**
     * Израсходовано за текущий период по данным панели (+ сверка с хостером).
     *
     * @return array{period_start:string,rx:int,tx:int,measured_bytes:int,used_bytes:int,limit_bytes:?int,percent:?float,level:string,mode:string,synced:bool,since:?string}
     */
    public static function usage(array $server, ?int $now = null): array
    {
        $now ??= time();
        $mode = isset(self::MODES[$server['traffic_count_mode'] ?? '']) ? $server['traffic_count_mode'] : 'sum';
        $start = self::periodStart((int) ($server['traffic_reset_day'] ?? 1), $now);

        $stmt = Database::get()->prepare('SELECT COALESCE(SUM(rx),0) AS s_rx, COALESCE(SUM(tx),0) AS s_tx, MIN(day) AS first_day FROM server_traffic_daily WHERE server_id = ? AND day >= ?');
        $stmt->execute([(int) $server['id'], $start]);
        $row = $stmt->fetch();
        $rx = (int) $row['s_rx'];
        $tx = (int) $row['s_tx'];
        $measured = self::countBytes($rx, $tx, $mode);

        // Сверка с цифрой хостера действует внутри того периода, в котором её ввели:
        // «хостер показывал X, панель тогда насчитала Y» -> поправка X - Y.
        $used = $measured;
        $synced = false;
        if (!empty($server['traffic_sync_at']) && $server['traffic_sync_gb'] !== null
            && substr((string) $server['traffic_sync_at'], 0, 10) >= $start) {
            $offset = ((float) $server['traffic_sync_gb'] - (float) ($server['traffic_sync_measured_gb'] ?? 0)) * 1e9;
            $used = max(0, (int) round($measured + $offset));
            $synced = true;
        }

        $limit = !empty($server['traffic_limit_gb']) ? (int) round((float) $server['traffic_limit_gb'] * 1e9) : null;
        $percent = $limit ? round($used / $limit * 100, 1) : null;
        $level = $percent === null ? 'none' : ($percent >= self::DANGER_PERCENT ? 'danger' : ($percent >= self::WARN_PERCENT ? 'warn' : 'ok'));

        return [
            'period_start' => $start,
            'rx' => $rx,
            'tx' => $tx,
            'measured_bytes' => $measured,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'percent' => $percent,
            'level' => $level,
            'mode' => $mode,
            'synced' => $synced,
            'since' => $row['first_day'] ?: null,
        ];
    }

    /** Запомнить сверку: «сейчас хостер показывает $hosterGb ГБ». */
    public static function sync(int $serverId, float $hosterGb): void
    {
        $server = Models\Server::find($serverId);
        if (!$server) {
            throw new \InvalidArgumentException('Сервер не найден');
        }
        // Поправку считаем относительно чистого замера панели, без старой сверки.
        $plain = self::usage(['traffic_sync_at' => null, 'traffic_sync_gb' => null] + $server);
        Database::get()->prepare('UPDATE servers SET traffic_sync_gb = ?, traffic_sync_measured_gb = ?, traffic_sync_at = ? WHERE id = ?')
            ->execute([$hosterGb, $plain['measured_bytes'] / 1e9, gmdate('Y-m-d H:i:s'), $serverId]);
    }
}
