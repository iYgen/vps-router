<?php

namespace App;

use App\Models\Setting;

/**
 * Учёт трафика устройств на входной VPS.
 *
 * Источники данных (оба — штатные у sing-box, ничего не патчим):
 *  - Clash API /connections (experimental.clash_api, только 127.0.0.1): живые
 *    соединения с байтами up/down, адресом источника, хостом и цепочкой
 *    outbound'ов (куда ушло: direct-rf / exit-N);
 *  - журнал sing-box (journalctl): по каждому соединению пишет
 *    "inbound connection from IP:port" и "[c<id>] inbound connection to …" —
 *    из них строим соответствие IP:port -> устройство. Clash API имени
 *    пользователя не отдаёт, поэтому склеиваем по адресу источника.
 * WireGuard/AmneziaWG-устройства опознаются без журнала — по адресу в туннеле.
 *
 * Сбор — дельтами счётчиков между опросами; соединение, закрывшееся между
 * двумя опросами, теряет только последние ~2 с трафика.
 */
class TrafficCollector
{
    public const CLASH_LISTEN = '127.0.0.1:19090';
    private const RETENTION_SECONDS = 172800; // 48 ч поминутной детализации
    private const HOSTS_RETENTION_DAYS = 60;  // сайты по дням; итоги по устройствам — без ограничения

    /** Секрет Clash API (создаётся один раз). */
    public static function secret(): string
    {
        $secret = Setting::get('clash_api_secret');
        if (!$secret) {
            $secret = bin2hex(random_bytes(16));
            Setting::set('clash_api_secret', $secret);
        }
        return $secret;
    }

    /** Блок experimental для sing-box: Clash API только на localhost. */
    public static function singboxExperimental(): array
    {
        return ['clash_api' => ['external_controller' => self::CLASH_LISTEN, 'secret' => self::secret()]];
    }

    /**
     * Разбирает строки журнала sing-box в соответствие "IP:port" -> "c<id>".
     *
     * @param string[] $lines
     * @return array<string,string>
     */
    public static function parseLogUsers(array $lines): array
    {
        $fromById = [];
        $userById = [];
        foreach ($lines as $line) {
            $line = preg_replace('/\e\[[0-9;]*m/', '', $line);
            if (!preg_match('/\[(\d+) [^\]]*\] inbound\/[^:]+: (.*)$/', $line, $m)) {
                continue;
            }
            [$all, $id, $rest] = $m;
            if (preg_match('/^inbound (?:packet )?connection from (.+):(\d+)$/', $rest, $f)) {
                $fromById[$id] = trim($f[1], '[]') . ':' . $f[2];
            } elseif (preg_match('/^\[([^\]]+)\] inbound (?:packet )?connection to /', $rest, $u)) {
                $userById[$id] = $u[1];
            }
        }
        $map = [];
        foreach ($userById as $id => $user) {
            if (isset($fromById[$id])) {
                $map[$fromById[$id]] = $user;
            }
        }
        return $map;
    }

    /**
     * Какому устройству принадлежит соединение из Clash API.
     *
     * @param array<string,string> $addrUsers "IP:port" -> "c<id>" из журнала
     * @param array<string,string> $ipUsers   "IP" -> "c<id>" (последний известный пользователь с этого IP)
     */
    public static function clientKey(array $meta, array $addrUsers, array $ipUsers): string
    {
        // Самый надёжный источник: sing-box сам сообщает аутентифицированного
        // пользователя инбаунда в metadata.user (мы называем их 'c<id>'). Это не
        // зависит от IP устройства и от гонок в журнале — поэтому проверяем первым.
        // Так устройство узнаётся, даже если подключается из другой сети/страны.
        $user = trim((string) ($meta['user'] ?? ''));
        if (preg_match('/^c\d+$/', $user)) {
            return $user;
        }

        $ip = (string) ($meta['sourceIP'] ?? '');
        $port = (string) ($meta['sourcePort'] ?? '');
        foreach ([DeviceInbounds::WG_SUBNET_BASE, DeviceInbounds::AWG_SUBNET_BASE] as $base) {
            $long = ip2long($ip);
            $baseLong = ip2long($base);
            if ($long !== false && ($long & 0xFFFF0000) === $baseLong) {
                $id = $long - $baseLong - 1;
                if ($id > 0) {
                    return 'c' . $id;
                }
            }
        }
        if (isset($addrUsers["$ip:$port"])) {
            return $addrUsers["$ip:$port"];
        }
        if (isset($ipUsers[$ip])) {
            return $ipUsers[$ip];
        }
        return 'ip:' . ($ip ?: '?');
    }

    /**
     * Один проход сборщика: ~$seconds секунд опрашивает Clash API и журнал,
     * пишет дельты в traffic_minute. Возвращает краткую сводку для лога.
     */
    public static function run(int $seconds = 55, int $interval = 2): array
    {
        $pdo = Database::get();
        $stateStmt = $pdo->query('SELECT conn_id, up, down FROM traffic_conn_state');
        $prev = [];
        foreach ($stateStmt as $row) {
            $prev[$row['conn_id']] = [(int) $row['up'], (int) $row['down']];
        }

        $addrUsers = [];
        $ipUsers = self::loadIpUsers();
        $since = time() - 120;
        $deadline = time() + $seconds;
        $polls = 0;
        $bytes = 0;
        $errors = [];

        do {
            // Журнал — инкрементально, с запасом в пару секунд на гонку.
            $lines = self::journalSince($since - 2);
            $since = time();
            $fresh = self::parseLogUsers($lines);
            $addrUsers = $fresh + $addrUsers;
            foreach ($fresh as $addr => $user) {
                $ipUsers[substr($addr, 0, strrpos($addr, ':'))] = $user;
            }

            $data = self::fetchConnections();
            if ($data === null) {
                $errors[] = 'Clash API недоступен на ' . self::CLASH_LISTEN;
                break;
            }
            $polls++;
            $minute = gmdate('Y-m-d H:i');
            $now = time();
            $seen = [];
            $pdo->beginTransaction();
            foreach ($data['connections'] ?? [] as $c) {
                $id = (string) ($c['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $seen[$id] = true;
                $up = (int) ($c['upload'] ?? 0);
                $down = (int) ($c['download'] ?? 0);
                [$pUp, $pDown] = $prev[$id] ?? [0, 0];
                $dUp = max(0, $up - $pUp);
                $dDown = max(0, $down - $pDown);
                $prev[$id] = [$up, $down];

                $meta = $c['metadata'] ?? [];
                $key = self::clientKey($meta, $addrUsers, $ipUsers);
                $chains = $c['chains'] ?? [];
                $outbound = (string) ($chains[0] ?? 'direct-rf');
                $host = (string) (($meta['host'] ?? '') ?: ($meta['destinationIP'] ?? '?'));
                $inbound = (string) ($meta['type'] ?? '');

                // Присутствие пишем только для реальных устройств (c<id>).
                // Неатрибутированные ip:* (сканеры/pre-auth) в список устройств не попадают.
                if (preg_match('/^c\d+$/', $key)) {
                    // Без ON CONFLICT…DO UPDATE: на CentOS 7 SQLite 3.7 его не понимает.
                    $pdo->prepare('INSERT OR REPLACE INTO device_presence (client_key, source_ip, inbound, last_seen) VALUES (?, ?, ?, ?)')
                        ->execute([$key, $meta['sourceIP'] ?? null, $inbound, $now]);
                }

                if ($dUp + $dDown === 0) {
                    continue;
                }
                $bytes += $dUp + $dDown;
                $host = mb_substr($host, 0, 255);
                $upd = $pdo->prepare('UPDATE traffic_minute SET up = up + ?, down = down + ? WHERE minute = ? AND client_key = ? AND outbound = ? AND host = ?');
                $upd->execute([$dUp, $dDown, $minute, $key, $outbound, $host]);
                if ($upd->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO traffic_minute (minute, client_key, outbound, host, inbound, up, down) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$minute, $key, $outbound, $host, $inbound, $dUp, $dDown]);
                }
                // Долгосрочные итоги: сутки на устройство (навсегда) и сайты за сутки.
                $day = substr($minute, 0, 10);
                self::addTo($pdo, 'traffic_daily', ['day' => $day, 'client_key' => $key], $dUp, $dDown);
                self::addTo($pdo, 'traffic_hosts_daily', ['day' => $day, 'client_key' => $key, 'host' => $host, 'outbound' => $outbound], $dUp, $dDown);
            }
            // Закрытые соединения больше не отслеживаем.
            $prev = array_intersect_key($prev, $seen);
            $pdo->commit();

            if (time() + $interval > $deadline) {
                break;
            }
            sleep($interval);
        } while (time() < $deadline);

        // Состояние для следующего запуска (соединение может жить часами).
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM traffic_conn_state');
        $ins = $pdo->prepare('INSERT INTO traffic_conn_state (conn_id, up, down, seen_at) VALUES (?, ?, ?, ?)');
        foreach ($prev as $id => [$up, $down]) {
            $ins->execute([$id, $up, $down, time()]);
        }
        $pdo->prepare('DELETE FROM traffic_minute WHERE minute < ?')->execute([gmdate('Y-m-d H:i', time() - self::RETENTION_SECONDS)]);
        $pdo->prepare('DELETE FROM traffic_hosts_daily WHERE day < ?')->execute([gmdate('Y-m-d', time() - self::HOSTS_RETENTION_DAYS * 86400)]);
        $pdo->commit();

        return ['polls' => $polls, 'bytes' => $bytes, 'errors' => $errors];
    }

    /**
     * Сводка для графа/API за последние $minutes минут: по устройствам,
     * по выходам (outbound) и топ хостов на устройство.
     */
    public static function summary(int $minutes = 15, int $topHosts = 12): array
    {
        $pdo = Database::get();
        $from = gmdate('Y-m-d H:i', time() - $minutes * 60);

        $clients = [];
        foreach (Models\Client::all() as $c) {
            $clients['c' . $c['id']] = $c;
        }

        $devices = [];
        $stmt = $pdo->prepare('SELECT client_key, outbound, SUM(up) AS t_up, SUM(down) AS t_down FROM traffic_minute WHERE minute >= ? GROUP BY client_key, outbound');
        $stmt->execute([$from]);
        foreach ($stmt as $r) {
            $k = $r['client_key'];
            $devices[$k] ??= ['key' => $k, 'up' => 0, 'down' => 0, 'by_outbound' => [], 'hosts' => []];
            $devices[$k]['up'] += (int) $r['t_up'];
            $devices[$k]['down'] += (int) $r['t_down'];
            $devices[$k]['by_outbound'][$r['outbound']] = ['up' => (int) $r['t_up'], 'down' => (int) $r['t_down']];
        }

        $presence = [];
        foreach ($pdo->query('SELECT * FROM device_presence') as $p) {
            $presence[$p['client_key']] = $p;
        }
        // Устройство без трафика, но с соединением за окно — тоже показываем.
        foreach ($presence as $k => $p) {
            if ((int) $p['last_seen'] >= time() - $minutes * 60) {
                $devices[$k] ??= ['key' => $k, 'up' => 0, 'down' => 0, 'by_outbound' => [], 'hosts' => []];
            }
        }

        // Показываем только реальные устройства (c<id>). Неатрибутированные ip:*
        // соединения — это сканеры/пробы/pre-auth, а не устройства пользователя;
        // после сопоставления по metadata.user настоящие устройства всегда c<id>.
        $devices = array_intersect_key($devices, $clients);

        $hostStmt = $pdo->prepare(
            'SELECT host, outbound, SUM(up) AS t_up, SUM(down) AS t_down FROM traffic_minute
             WHERE minute >= ? AND client_key = ? GROUP BY host, outbound ORDER BY t_up + t_down DESC LIMIT ' . (int) $topHosts
        );
        $totalsAll = self::deviceTotals();
        foreach ($devices as $k => &$d) {
            $d['totals'] = $totalsAll[$k] ?? null;
            $hostStmt->execute([$from, $k]);
            $d['hosts'] = array_map(fn($h) => ['host' => $h['host'], 'outbound' => $h['outbound'], 'up' => (int) $h['t_up'], 'down' => (int) $h['t_down']], $hostStmt->fetchAll());
            $client = $clients[$k] ?? null;
            $d['client_id'] = $client ? (int) $client['id'] : null;
            $d['name'] = $client ? $client['name'] : (str_starts_with($k, 'ip:') ? 'Неизвестное (' . substr($k, 3) . ')' : $k);
            $d['last_seen'] = isset($presence[$k]) ? (int) $presence[$k]['last_seen'] : null;
            $d['source_ip'] = $presence[$k]['source_ip'] ?? null;
            $d['inbound'] = $presence[$k]['inbound'] ?? null;
            $d['online'] = isset($presence[$k]) && (int) $presence[$k]['last_seen'] >= time() - 120;
        }
        unset($d);

        $byOutbound = [];
        foreach ($devices as $d) {
            foreach ($d['by_outbound'] as $ob => $t) {
                $byOutbound[$ob] ??= ['up' => 0, 'down' => 0];
                $byOutbound[$ob]['up'] += $t['up'];
                $byOutbound[$ob]['down'] += $t['down'];
            }
        }

        usort($devices, fn($a, $b) => ($b['up'] + $b['down']) <=> ($a['up'] + $a['down']));
        return [
            'minutes' => $minutes,
            'devices' => array_values($devices),
            'by_outbound' => $byOutbound,
            'collector_last_run' => Setting::get('traffic_collector_last_run'),
        ];
    }

    /** UPDATE … up = up + ?, а если строки нет — INSERT (SQLite 3.7 без UPSERT). */
    private static function addTo(\PDO $pdo, string $table, array $keys, int $up, int $down): void
    {
        $where = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($keys)));
        $upd = $pdo->prepare("UPDATE $table SET up = up + ?, down = down + ? WHERE $where");
        $upd->execute(array_merge([$up, $down], array_values($keys)));
        if ($upd->rowCount() === 0) {
            $cols = implode(', ', array_keys($keys));
            $marks = implode(', ', array_fill(0, count($keys) + 2, '?'));
            $pdo->prepare("INSERT INTO $table ($cols, up, down) VALUES ($marks)")->execute(array_merge(array_values($keys), [$up, $down]));
        }
    }

    /**
     * Итоги по устройствам: сегодня, 30 дней, всего (с первого дня учёта).
     *
     * @return array<string,array{today_up:int,today_down:int,d30_up:int,d30_down:int,total_up:int,total_down:int,since:?string,last_seen:?int,source_ip:?string,online:bool}>
     */
    public static function deviceTotals(): array
    {
        $pdo = Database::get();
        $today = gmdate('Y-m-d');
        $d30 = gmdate('Y-m-d', time() - 29 * 86400);
        $stmt = $pdo->prepare(
            'SELECT client_key,
                    SUM(CASE WHEN day = ? THEN up ELSE 0 END) AS today_up, SUM(CASE WHEN day = ? THEN down ELSE 0 END) AS today_down,
                    SUM(CASE WHEN day >= ? THEN up ELSE 0 END) AS d30_up, SUM(CASE WHEN day >= ? THEN down ELSE 0 END) AS d30_down,
                    SUM(up) AS total_up, SUM(down) AS total_down, MIN(day) AS since
             FROM traffic_daily GROUP BY client_key'
        );
        $stmt->execute([$today, $today, $d30, $d30]);
        $out = [];
        foreach ($stmt as $r) {
            $out[$r['client_key']] = [
                'today_up' => (int) $r['today_up'], 'today_down' => (int) $r['today_down'],
                'd30_up' => (int) $r['d30_up'], 'd30_down' => (int) $r['d30_down'],
                'total_up' => (int) $r['total_up'], 'total_down' => (int) $r['total_down'],
                'since' => $r['since'], 'last_seen' => null, 'source_ip' => null, 'online' => false,
            ];
        }
        foreach ($pdo->query('SELECT client_key, source_ip, last_seen FROM device_presence') as $p) {
            $k = $p['client_key'];
            $out[$k] ??= ['today_up' => 0, 'today_down' => 0, 'd30_up' => 0, 'd30_down' => 0, 'total_up' => 0, 'total_down' => 0, 'since' => null];
            $out[$k]['last_seen'] = (int) $p['last_seen'];
            $out[$k]['source_ip'] = $p['source_ip'];
            $out[$k]['online'] = (int) $p['last_seen'] >= time() - 120;
        }
        return $out;
    }

    /** Сайты устройства за последние $days дней (по суточным итогам). */
    public static function deviceHosts(string $clientKey, int $days = 30, int $limit = 50): array
    {
        $stmt = Database::get()->prepare(
            'SELECT host, outbound, SUM(up) AS t_up, SUM(down) AS t_down FROM traffic_hosts_daily
             WHERE client_key = ? AND day >= ? GROUP BY host, outbound ORDER BY t_up + t_down DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$clientKey, gmdate('Y-m-d', time() - ($days - 1) * 86400)]);
        return array_map(fn($h) => ['host' => $h['host'], 'outbound' => $h['outbound'], 'up' => (int) $h['t_up'], 'down' => (int) $h['t_down']], $stmt->fetchAll());
    }

    private static function loadIpUsers(): array
    {
        $map = [];
        foreach (Database::get()->query("SELECT client_key, source_ip FROM device_presence WHERE client_key LIKE 'c%' ORDER BY last_seen") as $r) {
            if ($r['source_ip']) {
                $map[$r['source_ip']] = $r['client_key'];
            }
        }
        return $map;
    }

    /**
     * Живой снимок из Clash API: суммарно скачано/отдано (с момента старта
     * sing-box) и число активных соединений. null, если Clash API недоступен.
     * Скорость (rate) считает клиент по разнице снимков.
     *
     * @return array{download_total:int,upload_total:int,connections:int}|null
     */
    public static function liveSnapshot(): ?array
    {
        $data = self::fetchConnections();
        if ($data === null) {
            return null;
        }
        return [
            'download_total' => (int) ($data['downloadTotal'] ?? 0),
            'upload_total' => (int) ($data['uploadTotal'] ?? 0),
            'connections' => is_array($data['connections'] ?? null) ? count($data['connections']) : 0,
        ];
    }

    /**
     * Живые соединения из Clash API для страницы «Соединения»: назначение,
     * outbound (последнее звено chains), трафик, старт, страна назначения (офлайн
     * GeoIP, если доступен). Отсортированы по объёму, обрезаны до $limit.
     *
     * @return array<int,array{host:string,dest_ip:string,dest_port:mixed,network:string,outbound:string,up:int,down:int,start:?string,cc:?string}>
     */
    public static function liveConnections(int $limit = 200): array
    {
        $data = self::fetchConnections();
        if ($data === null || empty($data['connections']) || !is_array($data['connections'])) {
            return [];
        }
        $rows = [];
        foreach ($data['connections'] as $c) {
            $m = $c['metadata'] ?? [];
            $chains = $c['chains'] ?? [];
            // В Clash chains перечисляет прокси; фактический outbound — последнее звено.
            $outbound = (is_array($chains) && $chains) ? (string) end($chains) : '';
            $destIp = (string) ($m['destinationIP'] ?? '');
            $host = (string) ($m['host'] ?? '');
            $rows[] = [
                'host' => $host !== '' ? $host : $destIp,
                'dest_ip' => $destIp,
                'dest_port' => $m['destinationPort'] ?? '',
                'network' => (string) ($m['network'] ?? ''),
                'outbound' => $outbound,
                'up' => (int) ($c['upload'] ?? 0),
                'down' => (int) ($c['download'] ?? 0),
                'start' => $c['start'] ?? null,
                'cc' => GeoIp::country($destIp),
            ];
        }
        usort($rows, fn($a, $b) => ($b['up'] + $b['down']) <=> ($a['up'] + $a['down']));
        return array_slice($rows, 0, max(1, $limit));
    }

    private static function fetchConnections(): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . self::secret() . "\r\n",
            'timeout' => 3,
        ]]);
        $raw = @file_get_contents('http://' . self::CLASH_LISTEN . '/connections', false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @return string[] */
    private static function journalSince(int $ts): array
    {
        if (!function_exists('proc_open')) {
            return [];
        }
        $proc = @proc_open(
            ['journalctl', '-u', 'sing-box', '--since', '@' . $ts, '-o', 'cat', '--no-pager', '-q'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return [];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out ? explode("\n", $out) : [];
    }
}
