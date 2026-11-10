<?php

namespace App;

use App\Models\Setting;

/**
 * Handshake watch — ловит «волны» неудачных рукопожатий клиентов на входном узле.
 *
 * Каждую минуту (bin/handshake_watch.php) читает журнал sing-box ПО КУРСОРУ (без
 * повторов и пропусков), считает по адресу клиента попытки подключения и
 * неудачные рукопожатия (REALITY «processed invalid connection» / ошибки TLS
 * handshake) и пишет поминутные счётчики в hs_minutes.
 *
 * Волна по адресу открывается, когда OPEN_STREAK минут подряд «плохие»
 * (failed ≥ hs_min_failed и доля ≥ hs_min_ratio), и закрывается после
 * hs_quiet_minutes тихих минут. При открытии — whois провайдера, клиенты панели
 * с этого адреса, опц. письмо и короткий захват трафика этого адреса (root-хелпер
 * vpsrouter-hs-capture.sh по sudo); дамп потом разбирается здесь же: дошло ли
 * ClientHello целиком, ответил ли сервер, кто и когда оборвал.
 *
 * Типичная причина таких волн — DPI на пути клиента (особенно мобильные сети):
 * часть рукопожатий пропускается, часть обрывается.
 */
class HandshakeWatch
{
    public const OPEN_STREAK = 2;
    private const CAPTURE_DIR = '/var/lib/panel/captures';
    private const HELPER = '/usr/local/sbin/vpsrouter-hs-capture.sh';
    private const KEEP_MINUTES_DAYS = 14;
    private const KEEP_WAVES = 500;
    private const KEEP_CAPTURES = 20;

    private const RE_CONN = '/\[(\d+) [^\]]*\] inbound\/[\w-]+\[[^\]]*\]: inbound connection from (\[[0-9a-fA-F:.]+\]|[0-9.]+):\d+/';
    private const RE_FAIL = '/ERROR \[(\d+) [^\]]*\] inbound\/[\w-]+\[[^\]]*\]: process connection from (\[[0-9a-fA-F:.]+\]|[0-9.]+):\d+: (.*)$/';
    private const RE_USER = '/\[(\d+) [^\]]*\] inbound\/[\w-]+\[[^\]]*\]: \[([^\]]+)\] inbound /';

    // ------------------------------------------------------------------ config

    /** @return array{min_failed:int,min_ratio:float,quiet_minutes:int,capture:bool,capture_seconds:int,alert_email:bool} */
    public static function config(): array
    {
        return [
            'min_failed'      => max(1, (int) Setting::get('hs_min_failed', '15')),
            'min_ratio'       => min(1.0, max(0.0, (float) Setting::get('hs_min_ratio', '0.15'))),
            'quiet_minutes'   => max(1, (int) Setting::get('hs_quiet_minutes', '5')),
            'capture'         => Setting::get('hs_capture', '1') === '1',
            'capture_seconds' => min(300, max(10, (int) Setting::get('hs_capture_seconds', '60'))),
            'alert_email'     => Setting::get('hs_alert_email', '0') === '1',
        ];
    }

    public static function saveConfig(array $in): array
    {
        $map = [
            'min_failed'      => fn($v) => (string) max(1, min(100000, (int) $v)),
            'min_ratio'       => fn($v) => (string) min(1.0, max(0.0, (float) $v)),
            'quiet_minutes'   => fn($v) => (string) max(1, min(120, (int) $v)),
            'capture'         => fn($v) => $v ? '1' : '0',
            'capture_seconds' => fn($v) => (string) max(10, min(300, (int) $v)),
            'alert_email'     => fn($v) => $v ? '1' : '0',
        ];
        foreach ($map as $k => $fn) {
            if (array_key_exists($k, $in)) {
                Setting::set('hs_' . $k, $fn($in[$k]));
            }
        }
        return self::config();
    }

    // ------------------------------------------------------------------ parsing

    /**
     * Разбор строк `journalctl -o short-iso` юнита sing-box.
     *
     * @param string[] $lines
     * @param array<string,string> $idIp карта id соединения → IP (переживает пачки)
     * @return array{minutes: array<string, array<string, array{total:int, failed:int}>>, clients: array<string, array<string,int>>, idIp: array<string,string>}
     */
    public static function parseLines(array $lines, array $idIp = []): array
    {
        $minutes = [];
        $clients = [];
        foreach ($lines as $line) {
            $minute = self::lineMinute($line);
            if ($minute === null) {
                continue;
            }
            if (preg_match(self::RE_FAIL, $line, $m)) {
                $ip = trim($m[2], '[]');
                if (self::isHandshakeFailure($m[3])) {
                    $minutes[$minute][$ip]['failed'] = ($minutes[$minute][$ip]['failed'] ?? 0) + 1;
                    $minutes[$minute][$ip]['total'] = $minutes[$minute][$ip]['total'] ?? 0;
                }
                continue;
            }
            if (preg_match(self::RE_CONN, $line, $m)) {
                $ip = trim($m[2], '[]');
                $idIp[$m[1]] = $ip;
                $minutes[$minute][$ip]['total'] = ($minutes[$minute][$ip]['total'] ?? 0) + 1;
                $minutes[$minute][$ip]['failed'] = $minutes[$minute][$ip]['failed'] ?? 0;
                continue;
            }
            if (preg_match(self::RE_USER, $line, $m) && isset($idIp[$m[1]])) {
                $ip = $idIp[$m[1]];
                $clients[$ip][$m[2]] = ($clients[$ip][$m[2]] ?? 0) + 1;
                unset($idIp[$m[1]]); // одна строка-пользователь на соединение достаточно
            }
        }
        // не даём карте расти бесконечно (id без пользовательской строки = неудачные)
        if (count($idIp) > 20000) {
            $idIp = array_slice($idIp, -5000, null, true);
        }
        return ['minutes' => $minutes, 'clients' => $clients, 'idIp' => $idIp];
    }

    public static function isHandshakeFailure(string $msg): bool
    {
        return str_contains($msg, 'REALITY: processed invalid connection')
            || str_starts_with($msg, 'TLS handshake:');
    }

    /** 'Y-m-d H:i' UTC из префикса short-iso (2026-11-10T19:18:01+03:00 …). */
    private static function lineMinute(string $line): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z))/', $line, $m)) {
            return null;
        }
        $ts = strtotime($m[1]);
        return $ts === false ? null : gmdate('Y-m-d H:i', $ts);
    }

    // ------------------------------------------------------------------ cron entry

    /** Один проход крона. Возвращает краткую статистику (для CLI/тестов). */
    public static function run(): array
    {
        $lock = @fopen(sys_get_temp_dir() . '/vpsrouter-hs-watch.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'locked'];
        }
        $stat = ['lines' => 0, 'opened' => 0, 'closed' => 0];
        [$lines, $cursor] = self::readJournal((string) Setting::get('hs_cursor', ''));
        $stat['lines'] = count($lines);
        $idIp = json_decode((string) Setting::get('hs_id_ip', ''), true) ?: [];
        $parsed = self::parseLines($lines, $idIp);
        self::storeMinutes($parsed['minutes']);
        if ($cursor !== '') {
            Setting::set('hs_cursor', $cursor);
        }
        Setting::set('hs_id_ip', json_encode(array_slice($parsed['idIp'], -3000, null, true)));

        $res = self::detect(time(), $parsed['clients']);
        $stat['opened'] = $res['opened'];
        $stat['closed'] = $res['closed'];
        self::processCaptures();
        if ((int) gmdate('i') === 7) {
            self::prune();
        }
        return $stat;
    }

    /** @return array{0:string[],1:string} строки и новый курсор */
    private static function readJournal(string $cursor): array
    {
        $cmd = ['journalctl', '-u', 'sing-box', '--no-pager', '-q', '-o', 'short-iso', '--show-cursor'];
        if ($cursor !== '') {
            $cmd[] = '--after-cursor=' . $cursor;
        } else {
            $cmd[] = '--since=-3min';
        }
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [[], ''];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);

        $lines = explode("\n", $out);
        $newCursor = '';
        foreach ($lines as $i => $l) {
            if (str_starts_with($l, '-- cursor: ')) {
                $newCursor = trim(substr($l, 11));
                unset($lines[$i]);
            } elseif (trim($l) === '') {
                unset($lines[$i]);
            }
        }
        return [array_values($lines), $newCursor];
    }

    /** @param array<string, array<string, array{total:int, failed:int}>> $minutes */
    public static function storeMinutes(array $minutes): void
    {
        if (!$minutes) {
            return;
        }
        Database::transaction(function (\PDO $db) use ($minutes) {
            $st = $db->prepare(
                'INSERT INTO hs_minutes (minute, src_ip, total, failed) VALUES (?, ?, ?, ?)
                 ON CONFLICT(minute, src_ip) DO UPDATE SET total = total + excluded.total, failed = failed + excluded.failed'
            );
            foreach ($minutes as $minute => $ips) {
                foreach ($ips as $ip => $c) {
                    $st->execute([$minute, $ip, (int) ($c['total'] ?? 0), (int) ($c['failed'] ?? 0)]);
                }
            }
        });
    }

    // ------------------------------------------------------------------ detection

    public static function isBad(int $total, int $failed, array $cfg): bool
    {
        $total = max($total, $failed);
        return $failed >= $cfg['min_failed'] && $total > 0 && ($failed / $total) >= $cfg['min_ratio'];
    }

    /**
     * Открывает/обновляет/закрывает волны по данным hs_minutes.
     *
     * @param array<string, array<string,int>> $clients клиенты, увиденные в этом проходе (ip → [cN → n])
     * @return array{opened:int, closed:int}
     */
    public static function detect(int $now, array $clients = []): array
    {
        $cfg = self::config();
        $db = Database::get();
        $opened = 0;
        $closed = 0;
        $windowStart = gmdate('Y-m-d H:i', $now - 15 * 60);

        // Кандидаты: адреса с неудачами за последние 15 минут + адреса с открытой волной.
        $ips = $db->prepare('SELECT DISTINCT src_ip FROM hs_minutes WHERE minute >= ? AND failed > 0');
        $ips->execute([$windowStart]);
        $candidates = array_flip($ips->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($db->query('SELECT src_ip FROM hs_waves WHERE ended_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN) as $ip) {
            $candidates[$ip] = true;
        }

        foreach (array_keys($candidates) as $ip) {
            $st = $db->prepare('SELECT minute, total, failed FROM hs_minutes WHERE src_ip = ? AND minute >= ? ORDER BY minute');
            $st->execute([$ip, $windowStart]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            $badMinutes = [];
            foreach ($rows as $r) {
                if (self::isBad((int) $r['total'], (int) $r['failed'], $cfg)) {
                    $badMinutes[] = $r['minute'];
                }
            }

            $wq = $db->prepare('SELECT * FROM hs_waves WHERE src_ip = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
            $wq->execute([$ip]);
            $wave = $wq->fetch(\PDO::FETCH_ASSOC) ?: null;
            $wq->closeCursor();

            if (!$wave) {
                // Минуты уже закрытой волны не должны тут же открыть новую.
                $lq = $db->prepare('SELECT MAX(ended_at) FROM hs_waves WHERE src_ip = ? AND ended_at IS NOT NULL');
                $lq->execute([$ip]);
                $lastEnd = (string) $lq->fetchColumn();
                $lq->closeCursor();
                if ($lastEnd !== '') {
                    $badMinutes = array_values(array_filter($badMinutes, fn($m) => $m >= $lastEnd));
                }
                $start = self::streakStart($badMinutes, self::OPEN_STREAK);
                if ($start === null) {
                    continue;
                }
                $id = self::openWave($ip, $start, $cfg);
                $wave = ['id' => $id, 'started_at' => $start, 'last_bad_at' => $start, 'clients' => null];
                $opened++;
            }

            $lastBad = $badMinutes ? max(end($badMinutes), (string) $wave['last_bad_at']) : (string) $wave['last_bad_at'];
            $agg = $db->prepare('SELECT COALESCE(SUM(failed),0) f, COALESCE(SUM(total),0) t, COALESCE(MAX(failed),0) p FROM hs_minutes WHERE src_ip = ? AND minute >= ? AND minute <= ?');
            $agg->execute([$ip, $wave['started_at'], $lastBad]);
            $a = $agg->fetch(\PDO::FETCH_ASSOC);
            $agg->closeCursor();

            $cl = json_decode((string) ($wave['clients'] ?? ''), true) ?: [];
            foreach ($clients[$ip] ?? [] as $c => $n) {
                $cl[$c] = ($cl[$c] ?? 0) + $n;
            }

            $quietFor = (int) floor(($now - strtotime($lastBad . ':00 UTC')) / 60);
            $endedAt = $quietFor >= $cfg['quiet_minutes'] + 1
                ? gmdate('Y-m-d H:i', strtotime($lastBad . ':00 UTC') + 60)
                : null;

            Database::transaction(function (\PDO $db) use ($wave, $lastBad, $a, $cl, $endedAt) {
                $db->prepare('UPDATE hs_waves SET last_bad_at = ?, failed_total = ?, attempts_total = ?, peak_per_min = ?, clients = ?, ended_at = ? WHERE id = ?')
                    ->execute([$lastBad, (int) $a['f'], max((int) $a['t'], (int) $a['f']), (int) $a['p'], $cl ? json_encode($cl) : null, $endedAt, (int) $wave['id']]);
            });
            if ($endedAt !== null) {
                $closed++;
            }
        }
        return ['opened' => $opened, 'closed' => $closed];
    }

    /** Начало первой серии из $len подряд идущих минут (минуты отсортированы). */
    public static function streakStart(array $minutes, int $len): ?string
    {
        $run = 0;
        $prev = null;
        $start = null;
        foreach ($minutes as $m) {
            $t = strtotime($m . ':00 UTC');
            if ($prev !== null && $t - $prev === 60) {
                $run++;
            } else {
                $run = 1;
                $start = $m;
            }
            if ($run >= $len) {
                return $start;
            }
            $prev = $t;
        }
        return null;
    }

    private static function openWave(string $ip, string $start, array $cfg): int
    {
        [$isp, $country] = self::ipInfo($ip);
        $id = Database::transaction(function (\PDO $db) use ($ip, $start, $isp, $country) {
            $db->prepare('INSERT INTO hs_waves (src_ip, isp, country, started_at, last_bad_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ip, $isp, $country, $start, $start]);
            return (int) $db->lastInsertId();
        });
        Models\AuditLog::record('hs.wave_open', "{$ip}" . ($isp ? " ({$isp})" : '') . " since {$start} UTC");

        if ($cfg['capture']) {
            self::requestCapture($id, $ip, $cfg['capture_seconds']);
        } else {
            self::setCapture($id, null, 'skipped');
        }
        if ($cfg['alert_email'] && ServerAlerts::enabled() && ($to = ServerAlerts::recipient())) {
            try {
                Mailer::send(
                    $to,
                    "[VPS Router] Волна обрывов рукопожатий: {$ip}" . ($isp ? " ({$isp})" : ''),
                    "С адреса {$ip}" . ($isp ? " ({$isp})" : '') . " массово не проходят рукопожатия с {$start} UTC.\n"
                    . "Подробности и дамп трафика — в панели, вкладка «Волны обрывов» входного узла.\n"
                );
            } catch (\Throwable $e) {
                error_log('HandshakeWatch mail: ' . $e->getMessage());
            }
        }
        return $id;
    }

    // ------------------------------------------------------------------ ip info

    /** @return array{0:?string,1:?string} [isp, country], с кешем в hs_ip_info */
    public static function ipInfo(string $ip): array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT isp, country FROM hs_ip_info WHERE ip = ? AND fetched_at >= datetime('now','-30 days')");
        $st->execute([$ip]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row) {
            return [$row['isp'], $row['country']];
        }
        $isp = null;
        $country = GeoIp::available() ? GeoIp::country($ip) : null;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $txt = self::runCapture(['timeout', '6', 'whois', $ip]);
            [$isp, $c2] = self::parseWhois($txt);
            $country = $country ?: $c2;
        } else {
            $isp = 'private';
        }
        Database::transaction(function (\PDO $db) use ($ip, $isp, $country) {
            $db->prepare("INSERT OR REPLACE INTO hs_ip_info (ip, isp, country, fetched_at) VALUES (?, ?, ?, datetime('now'))")
                ->execute([$ip, $isp, $country]);
        });
        return [$isp, $country];
    }

    /** @return array{0:?string,1:?string} */
    public static function parseWhois(string $txt): array
    {
        $get = function (string $key) use ($txt): ?string {
            return preg_match('/^' . $key . ':\s*(.+)$/mi', $txt, $m) ? trim($m[1]) : null;
        };
        $name = $get('org-name') ?: $get('OrgName') ?: $get('netname') ?: $get('NetName');
        $descr = $get('descr');
        $isp = $name;
        if ($descr && $name && stripos($name, $descr) === false && strlen($descr) < 60) {
            $isp = "{$name} — {$descr}";
        }
        $country = $get('country');
        return [$isp ? mb_substr($isp, 0, 120) : null, $country ? strtoupper(substr($country, 0, 2)) : null];
    }

    // ------------------------------------------------------------------ capture

    private static function requestCapture(int $id, string $ip, int $seconds): void
    {
        if (!is_file(self::HELPER)) {
            self::setCapture($id, null, 'unavailable');
            return;
        }
        $out = self::runCapture(['sudo', '-n', self::HELPER, 'start', (string) $id, $ip, (string) $seconds], $code);
        if ($code === 0) {
            self::setCapture($id, "wave-{$id}.pcap", 'requested');
        } else {
            self::setCapture($id, null, 'failed', ['error' => mb_substr(trim($out), 0, 300)]);
        }
    }

    private static function setCapture(int $id, ?string $file, string $status, ?array $summary = null): void
    {
        Database::transaction(function (\PDO $db) use ($id, $file, $status, $summary) {
            $db->prepare('UPDATE hs_waves SET capture_file = COALESCE(?, capture_file), capture_status = ?, capture_summary = COALESCE(?, capture_summary) WHERE id = ?')
                ->execute([$file, $status, $summary !== null ? json_encode($summary, JSON_UNESCAPED_UNICODE) : null, $id]);
        });
    }

    /** Разобрать готовые дампы (помечены .done хелпером). */
    public static function processCaptures(): void
    {
        $db = Database::get();
        $rows = $db->query("SELECT id, src_ip, capture_file, started_at FROM hs_waves WHERE capture_status = 'requested'")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $w) {
            $file = self::capturePath((string) $w['capture_file']);
            if ($file && is_file($file . '.done')) {
                $text = self::runCapture(['tcpdump', '-r', $file, '-nn', '-tt', '-S']);
                $summary = self::analyzeCapture($text, (string) $w['src_ip']);
                $summary['bytes'] = (int) @filesize($file);
                self::setCapture((int) $w['id'], null, 'done', $summary);
                @unlink($file . '.done');
            } elseif (strtotime($w['started_at'] . ':00 UTC') < time() - 20 * 60) {
                self::setCapture((int) $w['id'], null, 'failed', ['error' => 'capture did not finish']);
            }
        }
    }

    /** Абсолютный путь дампа по имени из БД (только wave-N.pcap внутри каталога). */
    public static function capturePath(string $name): ?string
    {
        return preg_match('/^wave-\d+\.pcap$/', $name) ? self::CAPTURE_DIR . '/' . $name : null;
    }

    /**
     * Сводка по дампу (`tcpdump -r -nn -tt -S`): по каждому новому TCP-соединению
     * клиента — пришёл ли ответ сервера, размеры первых сегментов клиента, кто и
     * как закрыл. Главное: «hello_split_lost» — клиент прислал полный первый
     * сегмент (≈MSS), а сервер так и не ответил: продолжение ClientHello потерялось
     * по дороге (характерный почерк DPI).
     */
    public static function analyzeCapture(string $text, string $clientIp): array
    {
        $flows = [];
        $re = '/^(\d+\.\d+) .*?IP6? (\S+)\.(\d+) > (\S+)\.(\d+): Flags \[([^\]]+)\].*?length (\d+)/';
        foreach (explode("\n", $text) as $line) {
            if (!preg_match($re, $line, $m)) {
                continue;
            }
            [, $t, $sa, $sp, $da, $dp, $fl, $len] = $m;
            $t = (float) $t;
            $len = (int) $len;
            $fromClient = $sa === $clientIp;
            if (!$fromClient && $da !== $clientIp) {
                continue;
            }
            $key = $fromClient ? $sp : $dp;
            if (!isset($flows[$key])) {
                $flows[$key] = ['t0' => $t, 'syn' => false, 'cli' => [], 'srv_first' => null, 'srv_bytes' => 0, 'end' => null, 'last' => $t];
            }
            $f = &$flows[$key];
            $f['last'] = $t;
            if ($fromClient && str_contains($fl, 'S') && !str_contains($fl, '.')) {
                $f['syn'] = true;
            }
            if ($len > 0) {
                if ($fromClient) {
                    if (count($f['cli']) < 4) {
                        $f['cli'][] = $len;
                    }
                } else {
                    $f['srv_bytes'] += $len;
                    if ($f['srv_first'] === null) {
                        $f['srv_first'] = round($t - $f['t0'], 3);
                    }
                }
            }
            if ($f['end'] === null && (str_contains($fl, 'R') || str_contains($fl, 'F'))) {
                $f['end'] = ($fromClient ? 'client' : 'server') . (str_contains($fl, 'R') ? '_rst' : '_fin');
            }
            unset($f);
        }

        $new = array_filter($flows, fn($f) => $f['syn']);
        $sum = [
            'flows' => count($new), 'answered' => 0, 'unanswered' => 0, 'no_data' => 0,
            'hello_split_lost' => 0, 'first_seg' => [], 'ends' => [], 'answer_ms_median' => null,
        ];
        $lat = [];
        foreach ($new as $f) {
            if (!$f['cli']) {
                $sum['no_data']++;
            } else {
                $b = (string) $f['cli'][0];
                $sum['first_seg'][$b] = ($sum['first_seg'][$b] ?? 0) + 1;
            }
            if ($f['srv_first'] !== null) {
                $sum['answered']++;
                $lat[] = (int) round($f['srv_first'] * 1000);
            } elseif ($f['cli']) {
                $sum['unanswered']++;
                if ($f['cli'][0] >= 1200 && count($f['cli']) === 1) {
                    $sum['hello_split_lost']++;
                }
            }
            $e = $f['end'] ?? 'open';
            $sum['ends'][$e] = ($sum['ends'][$e] ?? 0) + 1;
        }
        if ($lat) {
            sort($lat);
            $sum['answer_ms_median'] = $lat[intdiv(count($lat), 2)];
        }
        arsort($sum['first_seg']);
        $sum['first_seg'] = array_slice($sum['first_seg'], 0, 5, true);
        $sum['verdict'] = self::captureVerdict($sum);
        return $sum;
    }

    public static function captureVerdict(array $s): string
    {
        if ($s['flows'] === 0) {
            return 'no_traffic';
        }
        if ($s['hello_split_lost'] > 0 && $s['hello_split_lost'] >= $s['unanswered'] / 2) {
            return 'hello_cut';          // хвост ClientHello не доходит
        }
        if ($s['unanswered'] > 0 && $s['unanswered'] / $s['flows'] >= 0.2) {
            return 'handshake_dropped';  // ClientHello целиком, но ответа нет / до сервера не дошло
        }
        $rst = ($s['ends']['server_rst'] ?? 0) + ($s['ends']['client_rst'] ?? 0);
        if ($rst / $s['flows'] >= 0.3) {
            return 'resets';             // соединения рвутся RST
        }
        return 'looks_ok';               // в окне захвата сбоев не видно
    }

    // ------------------------------------------------------------------ queries for UI

    /** Сводка за 24 ч по 10-минутным корзинам (все адреса). */
    public static function series24h(int $now): array
    {
        $since = gmdate('Y-m-d H:i', $now - 86400);
        $st = Database::get()->prepare(
            "SELECT substr(minute,1,15) || '0' AS bucket, SUM(total) total, SUM(failed) failed
             FROM hs_minutes WHERE minute >= ? GROUP BY bucket ORDER BY bucket"
        );
        $st->execute([$since]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function waves(int $limit = 30, int $offset = 0): array
    {
        $st = Database::get()->prepare('SELECT * FROM hs_waves ORDER BY id DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->bindValue(2, $offset, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $names = [];
        foreach (Database::get()->query('SELECT id, name FROM clients') as $c) {
            $names['c' . $c['id']] = $c['name'];
        }
        foreach ($rows as &$r) {
            $cl = json_decode((string) $r['clients'], true) ?: [];
            arsort($cl);
            $r['clients'] = array_map(fn($k, $n) => ['tag' => $k, 'name' => $names[$k] ?? $k, 'n' => $n], array_keys($cl), $cl);
            $r['capture_summary'] = json_decode((string) $r['capture_summary'], true);
            $r['capture_available'] = $r['capture_file'] && ($p = self::capturePath($r['capture_file'])) && is_file($p);
        }
        return $rows;
    }

    public static function wavesCount(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) FROM hs_waves')->fetchColumn();
    }

    public static function waveCapture(int $id): ?string
    {
        $st = Database::get()->prepare('SELECT capture_file FROM hs_waves WHERE id = ?');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        $st->closeCursor();
        $p = $name ? self::capturePath((string) $name) : null;
        return ($p && is_file($p)) ? $p : null;
    }

    // ------------------------------------------------------------------ housekeeping

    public static function prune(): void
    {
        Database::transaction(function (\PDO $db) {
            $db->prepare('DELETE FROM hs_minutes WHERE minute < ?')->execute([gmdate('Y-m-d H:i', time() - self::KEEP_MINUTES_DAYS * 86400)]);
            $db->exec('DELETE FROM hs_waves WHERE id NOT IN (SELECT id FROM hs_waves ORDER BY id DESC LIMIT ' . self::KEEP_WAVES . ')');
        });
        $files = glob(self::CAPTURE_DIR . '/wave-*.pcap') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::KEEP_CAPTURES) as $f) {
            @unlink($f);
        }
    }

    /** proc_open без шелла; возвращает stdout+stderr. */
    private static function runCapture(array $cmd, ?int &$code = null): string
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $code = 127;
            return '';
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $out . ($err !== '' ? "\n" . $err : '');
    }
}
