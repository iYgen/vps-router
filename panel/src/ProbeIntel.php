<?php

namespace App;

use App\Models\Server;

/**
 * «Активная защита» / Probe Intelligence.
 *
 * Панель САМА (pull-модель, по исходящему SSH / своим исходящим проверкам)
 * наблюдает доступность exit-узлов и собирает, кто к ним стучится:
 *
 *  1) Доступность во времени: с вантеджа панели (она локально) меряем TCP+TLS до
 *     Reality-порта узла и сравниваем с доступностью извне (check-host).
 *     Расхождение «локально не идёт, а извне идёт» — ранний признак Active Blocking System.
 *  2) Входящие зондирования (опционально, по кнопке): на exit включается
 *     ТОЛЬКО логирование (LOG-only nftables в отдельной изолированной таблице,
 *     rate-limited — не трогает боевой firewall и не может положить сервер),
 *     а панель вычитывает логи по SSH и агрегирует «кто стучался».
 *
 * Безопасность: никакого приёмника на exit и новых входящих эндпоинтов у
 * панели — только исходящие проверки и SSH-pull существующим ключом.
 */
class ProbeIntel
{
    private const DEFAULT_PORT = 443;        // Reality обычно на 443
    private const LOCAL_TIMEOUT = 5;            // сек на проверку с вантеджа панели
    private const KEEP_SAMPLES = 500;        // храним последние N замеров на узел
    private const KEEP_EVENTS = 1000;

    // ---- доступность (reachability) ------------------------------------

    /** Полный замер доступности узла + запись + алерт при переходе. */
    public static function sample(array $server): ?array
    {
        $id = (int) ($server['id'] ?? 0);
        $host = (string) ($server['host'] ?? '');
        if ($id <= 0 || $host === '' || !empty($server['is_self'])) {
            return null;
        }
        $port = self::port($id);
        $rf = self::rfProbe($host, $port);
        $abroad = self::abroadProbe($host, $port);
        $verdict = self::verdict($rf['tcp'], $rf['tls'], $abroad['ok']);
        // Зонд меряет СЫРОЙ TLS-хендшейк (не через sing-box), поэтому при включённой
        // фрагментации его «деградация» не отражает реальный трафик (он фрагментируется
        // и, как правило, проходит). Чтобы не мигать ok↔yellow и не спамить — при
        // включённой фрагментации считаем rf_degraded за норму (зелёное).
        if ($verdict === 'rf_degraded' && \App\Models\Setting::get('antidpi_tls_fragment', '0') === '1') {
            $verdict = 'ok';
        }

        try {
            $st = Database::get()->prepare(
                'INSERT INTO probe_samples
                 (server_id, port, rf_tcp_ok, rf_tls_ok, rf_latency_ms, abroad_ok, abroad_nodes_ok, abroad_nodes_total, verdict)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $id, $port, $rf['tcp'] ? 1 : 0, $rf['tls'] ? 1 : 0, $rf['latency'],
                $abroad['ok'] === null ? null : ($abroad['ok'] ? 1 : 0),
                $abroad['nodes_ok'], $abroad['nodes_total'], $verdict,
            ]);
            self::prevVerdictAlert($server, $verdict);
            self::prune('probe_samples', $id, self::KEEP_SAMPLES);
            self::autoRotate($server, $verdict);
        } catch (\Throwable $e) {
            error_log('ProbeIntel::sample ' . $e->getMessage());
        }
        return ['verdict' => $verdict, 'rf' => $rf, 'abroad' => $abroad, 'port' => $port];
    }

    /** TCP + TLS с вантеджа панели (вход). TLS-хендшейк ловит DPI-подрезку (TCP есть, handshake рвётся). */
    private static function rfProbe(string $host, int $port): array
    {
        $t = microtime(true);
        $tcp = @fsockopen($host, $port, $errno, $errstr, 4);
        $latency = (int) round((microtime(true) - $t) * 1000);
        $tcpOk = $tcp !== false;
        if ($tcp) {
            fclose($tcp);
        }
        $tlsOk = false;
        if ($tcpOk) {
            $ctx = stream_context_create(['ssl' => [
                'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true,
                'peer_name' => $host, 'capture_peer_cert' => false,
            ]]);
            $c = @stream_socket_client("ssl://$host:$port", $e2, $es2, self::LOCAL_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
            $tlsOk = $c !== false;
            if ($c) {
                fclose($c);
            }
        }
        return ['tcp' => $tcpOk, 'tls' => $tlsOk, 'latency' => $tcpOk ? $latency : null];
    }

    /** Доступность порта извне (check-host.net). null = внешняя проверка недоступна/выключена. */
    private static function abroadProbe(string $host, int $port): array
    {
        try {
            $r = Diagnostics::checkPortReachability($host, $port);
        } catch (\Throwable $e) {
            return ['ok' => null, 'nodes_ok' => null, 'nodes_total' => null];
        }
        if (empty($r['available'])) {
            return ['ok' => null, 'nodes_ok' => null, 'nodes_total' => null];
        }
        $ok = 0; $tot = 0;
        foreach ($r['nodes'] ?? [] as $n) {
            // Считаем только внешние узлы (локально check-host тоже может резаться Active Blocking System).
            $isRu = stripos((string) ($n['country'] ?? ''), 'russia') !== false || str_starts_with((string) ($n['node'] ?? ''), 'ru');
            if ($isRu) {
                continue;
            }
            $tot++;
            $ok += !empty($n['ok']) ? 1 : 0;
        }
        // «Доступен извне» — если открыт заметной доле узлов (единичные — шум).
        $reach = $tot > 0 && $ok >= max(2, (int) ceil($tot * 0.3));
        return ['ok' => $reach, 'nodes_ok' => $ok, 'nodes_total' => $tot];
    }

    private static function verdict(bool $rfTcp, bool $rfTls, ?bool $abroad): string
    {
        if ($abroad === true) {
            if ($rfTcp && $rfTls) {
                return 'ok';
            }
            if ($rfTcp && !$rfTls) {
                return 'rf_degraded'; // TCP идёт, TLS-хендшейк рвётся — почерк DPI/Active Blocking System
            }
            return 'rf_blocked';      // локально не идёт совсем, а извне идёт
        }
        if ($abroad === false) {
            return $rfTcp ? 'ok' : 'down';
        }
        // abroad неизвестен (внешняя проверка выключена/недоступна) — без сравнения не судим.
        return ($rfTcp && $rfTls) ? 'ok' : 'unknown';
    }

    private const ALERT_COOLDOWN = 604800; // 7 дней между письмами на один узел — чтобы не спамить

    /**
     * Письмо только на ОДНОЗНАЧНОЙ блокировке (rf_blocked). rf_degraded — шумный
     * (возможен ложный из-за SNI), поэтому писем по нему НЕ шлём: он виден только
     * в UI (лента/вердикт). Плюс кулдаун 6ч на узел — даже при флапе придёт максимум
     * одно письмо в 6 часов.
     */
    private static function prevVerdictAlert(array $server, string $verdict): void
    {
        if ($verdict !== 'rf_blocked' || !ServerAlerts::enabled()) {
            return;
        }
        $id = (int) $server['id'];
        $key = 'probe_alert_at_' . $id;
        try {
            $last = (int) \App\Models\Setting::get($key, '0');
            if (time() - $last < self::ALERT_COOLDOWN) {
                return; // уже слали недавно — не дублируем
            }
            $to = ServerAlerts::recipient();
            if (!$to) {
                return;
            }
            $name = (string) ($server['name'] ?? ('#' . $server['id']));
            $host = (string) ($server['host'] ?? '');
            $subject = "[VPS Router] Активная защита: {$name} — возможна блокировка Active Blocking System";
            $body = "Узел «{$name}»" . ($host ? " ({$host})" : '') . " недоступен локально (rf_blocked).\n"
                . "Локально до Reality-порта не достучаться ни по TCP, а извне порт открыт — похоже на блокировку IP/порта Active Blocking System.\n"
                . "Время: " . gmdate('Y-m-d H:i') . " UTC\n"
                . "Следующее письмо по этому узлу — не раньше чем через неделю (проверки продолжаются, видны в панели).\n"
                . "Рекомендация: включить анти-DPI (фрагментацию), сменить IP/порт у хостера или увести маршруты на резервный exit.\n";
            Mailer::send($to, $subject, $body);
            \App\Models\Setting::set($key, (string) time());
            \App\Models\AuditLog::record('probe.alert', "{$name}: {$verdict}");
        } catch (\Throwable $e) {
            error_log('ProbeIntel alert ' . $e->getMessage());
        }
    }

    // ---- фаза 3: авто-переключение маршрутов при блокировке ------------

    /**
     * При устойчивой блокировке локально (rf_blocked два замера подряд) и включённом
     * Setting 'probe_auto_rotate' — переключает маршруты (rule_groups.exit_server_id),
     * указывающие на заблокированный exit, на другой ПРОВЕРЕННО здоровый exit и
     * применяет конфиг. Только rf_blocked (однозначно); rf_degraded не триггерит
     * (возможен ложный из-за SNI). Обратно сам не переключает (чтобы не флапать).
     */
    private static function autoRotate(array $server, string $verdict): void
    {
        if ($verdict !== 'rf_blocked' || \App\Models\Setting::get('probe_auto_rotate', '0') !== '1') {
            return;
        }
        $id = (int) $server['id'];
        try {
            $q = Database::get()->prepare('SELECT verdict FROM probe_samples WHERE server_id = ? ORDER BY id DESC LIMIT 2');
            $q->execute([$id]);
            $vs = $q->fetchAll(\PDO::FETCH_COLUMN);
            if (count($vs) < 2 || $vs[0] !== 'rf_blocked' || $vs[1] !== 'rf_blocked') {
                return; // нужен устойчивый сигнал (2 подряд)
            }
            $bq = Database::get()->prepare('SELECT exit_server_id FROM connections WHERE target_server_id = ? AND exit_server_id IS NOT NULL ORDER BY id LIMIT 1');
            $bq->execute([$id]);
            $blocked = (int) ($bq->fetchColumn() ?: 0);
            if ($blocked <= 0) {
                return;
            }
            $cq = Database::get()->prepare('SELECT COUNT(*) FROM rule_groups WHERE exit_server_id = ?');
            $cq->execute([$blocked]);
            if ((int) $cq->fetchColumn() === 0) {
                return; // маршрутов на этот exit нет (или уже переключили)
            }
            $target = self::healthyAlternativeExit($id);
            if (!$target) {
                return; // нет проверенно здорового запасного exit — не трогаем
            }
            $upd = Database::get()->prepare('UPDATE rule_groups SET exit_server_id = ? WHERE exit_server_id = ?');
            $upd->execute([$target['exit_server_id'], $blocked]);
            $moved = $upd->rowCount();
            if ($moved <= 0) {
                return;
            }
            \App\Models\AuditLog::record('probe.rotate', "server #$id blocked -> exit_server {$target['exit_server_id']} ({$moved} groups)");
            $applied = false;
            try {
                (new \App\Applier())->apply('probe-auto', "Active defense: exit #$id rf_blocked -> {$target['name']}", \App\RouterContext::currentId());
                $applied = true;
            } catch (\Throwable $e) {
                error_log('ProbeIntel::autoRotate apply ' . $e->getMessage());
            }
            $to = ServerAlerts::recipient();
            if ($to && ServerAlerts::enabled()) {
                Mailer::send(
                    $to,
                    "[VPS Router] Авто-переключение: маршруты уведены с «{$server['name']}»",
                    "Узел «{$server['name']}» заблокирован локально (rf_blocked). Маршруты ($moved групп) автоматически переключены на «{$target['name']}».\n"
                    . ($applied ? "Конфиг применён.\n" : "ВНИМАНИЕ: не удалось применить конфиг автоматически — нажмите Review & Apply в панели.\n")
                    . "Время: " . gmdate('Y-m-d H:i') . " UTC\n"
                );
            }
        } catch (\Throwable $e) {
            error_log('ProbeIntel::autoRotate ' . $e->getMessage());
        }
    }

    /** Другой exit-узел с последним вердиктом ok и привязанным exit_server_id. */
    private static function healthyAlternativeExit(int $excludeServerId): ?array
    {
        $st = Database::get()->prepare(
            "SELECT s.id, s.name, c.exit_server_id
             FROM servers s
             JOIN connections c ON c.target_server_id = s.id AND c.exit_server_id IS NOT NULL
             WHERE s.role = 'exit' AND s.enabled = 1 AND s.is_self = 0 AND s.id != ?
             ORDER BY s.id"
        );
        $st->execute([$excludeServerId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $cur = self::current((int) $row['id']);
            if ($cur && ($cur['verdict'] ?? '') === 'ok') {
                return ['server_id' => (int) $row['id'], 'name' => (string) $row['name'], 'exit_server_id' => (int) $row['exit_server_id']];
            }
        }
        return null;
    }

    /** Reality-порт узла: пока 443 по умолчанию (переопределение — Setting probe_port_<id>). */
    private static function port(int $serverId): int
    {
        $v = (int) \App\Models\Setting::get('probe_port_' . $serverId, (string) self::DEFAULT_PORT);
        return ($v >= 1 && $v <= 65535) ? $v : self::DEFAULT_PORT;
    }

    private static function prune(string $table, int $serverId, int $keep): void
    {
        // Только из белого списка таблиц (имя не из пользовательского ввода).
        if (!in_array($table, ['probe_samples', 'probe_events'], true)) {
            return;
        }
        $st = Database::get()->prepare(
            "DELETE FROM {$table} WHERE server_id = ? AND id NOT IN
             (SELECT id FROM {$table} WHERE server_id = ? ORDER BY id DESC LIMIT ?)"
        );
        $st->bindValue(1, $serverId, \PDO::PARAM_INT);
        $st->bindValue(2, $serverId, \PDO::PARAM_INT);
        $st->bindValue(3, $keep, \PDO::PARAM_INT);
        $st->execute();
    }

    // ---- чтение для UI --------------------------------------------------

    public static function timeline(int $serverId, int $limit = 200): array
    {
        $st = Database::get()->prepare(
            'SELECT checked_at, rf_tcp_ok, rf_tls_ok, rf_latency_ms, abroad_ok, abroad_nodes_ok, abroad_nodes_total, verdict
             FROM probe_samples WHERE server_id = ? ORDER BY id DESC LIMIT ?'
        );
        $st->bindValue(1, $serverId, \PDO::PARAM_INT);
        $st->bindValue(2, max(1, min(1000, $limit)), \PDO::PARAM_INT);
        $st->execute();
        return array_reverse($st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function current(int $serverId): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM probe_samples WHERE server_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$serverId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Агрегат по IP: суммарно обращений, за сколько проверок замечен, когда
     * в последний раз, заблокирован ли. Пагинация limit/offset («Показать ещё»).
     */
    public static function events(int $serverId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $st = Database::get()->prepare(
            "SELECT e.src_ip,
                    SUM(e.hits) AS total,
                    COUNT(*) AS seen_times,
                    MAX(e.observed_at) AS last_seen,
                    MAX(e.dst_port) AS dst_port,
                    MAX(e.src_country) AS src_country,
                    CASE WHEN b.ip IS NOT NULL THEN 1 ELSE 0 END AS blocked
             FROM probe_events e
             LEFT JOIN probe_blocks b ON b.server_id = e.server_id AND b.ip = e.src_ip
             WHERE e.server_id = ?
             GROUP BY e.src_ip
             ORDER BY total DESC, last_seen DESC
             LIMIT ? OFFSET ?"
        );
        $st->bindValue(1, $serverId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->bindValue(3, $offset, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Сколько всего уникальных IP в статистике узла (для кнопки «Показать ещё»). */
    public static function eventsCount(int $serverId): int
    {
        $st = Database::get()->prepare('SELECT COUNT(DISTINCT src_ip) FROM probe_events WHERE server_id = ?');
        $st->execute([$serverId]);
        return (int) $st->fetchColumn();
    }

    public static function logState(int $serverId): array
    {
        $st = Database::get()->prepare('SELECT enabled, installed_at, last_pull_at FROM probe_log_state WHERE server_id = ?');
        $st->execute([$serverId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: ['enabled' => 0, 'installed_at' => null, 'last_pull_at' => null];
    }

    // ---- опциональное логирование зондирований на exit (по кнопке) ------

    private static function provisionScript(): string
    {
        $dir = (string) (\App\App::config()['provision_scripts_dir'] ?? (__DIR__ . '/../../deploy/provision'));
        return rtrim($dir, '/') . '/exit-probe-log.sh';
    }

    /** Ставит/снимает правило на exit по SSH. $enable — true=install(LOG+блоки), false=remove. */
    public static function setLogging(array $server, bool $enable): array
    {
        $id = (int) ($server['id'] ?? 0);
        if ($id <= 0 || !empty($server['is_self'])) {
            return ['ok' => false, 'error' => 'not applicable'];
        }
        if (!$enable) {
            $res = self::runScript($server, ['action' => 'remove', 'port' => self::port($id)]);
            if (!empty($res['ok'])) {
                self::setLogState($id, 0);
            }
            return $res;
        }
        $res = self::reprovision($server);
        if (!empty($res['ok'])) {
            self::setLogState($id, 1);
        }
        return $res;
    }

    /** Переустановка изолированной таблицы на exit с текущим портом и блок-листом. */
    private static function reprovision(array $server): array
    {
        $id = (int) $server['id'];
        return self::runScript($server, [
            'action' => 'install',
            'port' => self::port($id),
            'blocked' => self::blockedIps($id),
        ]);
    }

    private static function runScript(array $server, array $params): array
    {
        $key = Server::sshPrivateKey((int) $server['id']);
        if (!$key) {
            return ['ok' => false, 'error' => 'no ssh key'];
        }
        $res = Ssh::runProvisionScript(
            (string) $server['host'], (int) $server['ssh_port'], (string) $server['ssh_user'], $key,
            self::provisionScript(), $params
        );
        return ['ok' => !empty($res['ok']), 'output' => (string) ($res['stdout'] ?? ''), 'error' => (string) ($res['raw_error'] ?? $res['stderr'] ?? '')];
    }

    private static function setLogState(int $id, int $enabled): void
    {
        $pdo = Database::get();
        $ex = $pdo->prepare('SELECT 1 FROM probe_log_state WHERE server_id = ?');
        $ex->execute([$id]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE probe_log_state SET enabled = ?, installed_at = datetime('now') WHERE server_id = ?")->execute([$enabled, $id]);
        } else {
            $pdo->prepare("INSERT INTO probe_log_state (server_id, enabled, installed_at) VALUES (?, ?, datetime('now'))")->execute([$id, $enabled]);
        }
    }

    // ---- блокировка по IP (DROP на Reality-порт) -----------------------

    /** @return string[] заблокированные IP узла */
    public static function blockedIps(int $serverId): array
    {
        $st = Database::get()->prepare('SELECT ip FROM probe_blocks WHERE server_id = ? ORDER BY id DESC');
        $st->execute([$serverId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** IP, которые НИКОГДА не блокируем: сам exit и публичный IP панели (вход) — защита от само-отреза. */
    private static function neverBlock(array $server): array
    {
        $ips = [];
        $host = (string) ($server['host'] ?? '');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        }
        foreach (['reality_listen_ip', 'reality_public_host'] as $k) {
            $v = trim((string) \App\Models\Setting::get($k, ''));
            if (filter_var($v, FILTER_VALIDATE_IP)) {
                $ips[] = $v;
            }
        }
        $self = \App\NetworkInfo::detectPublicIp();
        if ($self && filter_var($self, FILTER_VALIDATE_IP)) {
            $ips[] = $self;
        }
        return array_values(array_unique($ips));
    }

    /** Блокирует IP на узле: валидация + защита от само-отреза + переустановка правил. */
    public static function blockIp(array $server, string $ip): array
    {
        $id = (int) ($server['id'] ?? 0);
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'error' => 'Некорректный IPv4'];
        }
        if (in_array($ip, self::neverBlock($server), true)) {
            return ['ok' => false, 'error' => 'Этот IP нельзя блокировать (это ваш сервер/панель) — риск отрезать себя.'];
        }
        // upsert (portable)
        $pdo = Database::get();
        $ex = $pdo->prepare('SELECT 1 FROM probe_blocks WHERE server_id = ? AND ip = ?');
        $ex->execute([$id, $ip]);
        if (!$ex->fetchColumn()) {
            $pdo->prepare('INSERT INTO probe_blocks (server_id, ip) VALUES (?, ?)')->execute([$id, $ip]);
        }
        $res = self::reprovision($server);
        if (!empty($res['ok'])) {
            self::setLogState($id, 1);
            \App\Models\AuditLog::record('probe.block', "server #$id block $ip");
        }
        return $res;
    }

    /** Снимает блок с IP и переустанавливает правила. */
    public static function unblockIp(array $server, string $ip): array
    {
        $id = (int) ($server['id'] ?? 0);
        Database::get()->prepare('DELETE FROM probe_blocks WHERE server_id = ? AND ip = ?')->execute([$id, trim($ip)]);
        \App\Models\AuditLog::record('probe.unblock', "server #$id unblock " . trim($ip));
        return self::reprovision($server);
    }

    /** Вычитывает по SSH лог зондирований (VPSR_PROBE) и агрегирует по src_ip. */
    public static function pullLog(array $server): array
    {
        $id = (int) ($server['id'] ?? 0);
        $state = self::logState($id);
        if (empty($state['enabled'])) {
            return ['ok' => false, 'error' => 'logging disabled'];
        }
        $key = Server::sshPrivateKey($id);
        if (!$key) {
            return ['ok' => false, 'error' => 'no ssh key'];
        }
        // Берём только последние 30 минут, чтобы не вычитывать весь журнал.
        $cmd = 'journalctl -k --since "30 min ago" --no-pager 2>/dev/null | grep "VPSR_PROBE" | '
            . 'grep -oE "SRC=[0-9.]+ .*DPT=[0-9]+" | grep -oE "SRC=[0-9.]+|DPT=[0-9]+" | paste - - | '
            . 'awk "{gsub(/SRC=|DPT=/,\"\"); print \$1\" \"\$2}" | sort | uniq -c | sort -rn | head -200';
        $r = Ssh::runCommand((string) $server['host'], (int) $server['ssh_port'], (string) $server['ssh_user'], $key, $cmd);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'ssh error')];
        }
        $added = 0;
        foreach (explode("\n", (string) ($r['output'] ?? '')) as $line) {
            // формат: "<count> <ip> <dport>"
            if (!preg_match('/^\s*(\d+)\s+(\d{1,3}(?:\.\d{1,3}){3})\s+(\d+)/', $line, $m)) {
                continue;
            }
            $ip = $m[2];
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $ins = Database::get()->prepare(
                'INSERT INTO probe_events (server_id, src_ip, dst_port, hits) VALUES (?,?,?,?)'
            );
            $ins->execute([$id, $ip, (int) $m[3], (int) $m[1]]);
            $added++;
        }
        self::prune('probe_events', $id, self::KEEP_EVENTS);
        Database::get()->prepare('UPDATE probe_log_state SET last_pull_at=datetime(\'now\') WHERE server_id=?')->execute([$id]);
        return ['ok' => true, 'added' => $added];
    }
}
