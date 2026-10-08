<?php

namespace App;

/**
 * Журнал недоступности серверов. Крон-проверка здоровья
 * (bin/health_check_servers.php) при смене статуса узла вызывает handle():
 *   online → offline/warning  — открывает инцидент (фиксирует down_at);
 *   offline/warning → online   — закрывает инцидент (up_at) и, если есть SSH-доступ,
 *                                снимает логи за окно простоя (journalctl, ядро,
 *                                перезагрузки, ключевые службы) — чтобы постфактум
 *                                понять, почему узел «падал».
 * Логи показываются в инспекторе сервера (вкладка «Журнал простоев»).
 */
class ServerIncidents
{
    private const DOWN = ['offline', 'warning'];
    private const MAX_LOG = 60000; // символов — обрезаем, чтобы не раздувать БД

    /**
     * @param array      $server строка servers (нужны id)
     * @param array|null $ssh    ['host'=>,'port'=>,'user'=>,'key'=>] для снятия логов при восстановлении, иначе null
     */
    public static function handle(array $server, string $old, string $new, string $detail = '', ?array $ssh = null): void
    {
        $id = (int) ($server['id'] ?? 0);
        if ($id <= 0 || $old === $new) {
            return;
        }
        if ($old === 'online' && in_array($new, self::DOWN, true)) {
            self::open($id, $detail);
        } elseif (in_array($old, self::DOWN, true) && $new === 'online') {
            self::close($id, $ssh);
        }
    }

    private static function open(int $serverId, string $detail): void
    {
        try {
            $db = Database::get();
            $q = $db->prepare('SELECT 1 FROM server_incidents WHERE server_id = ? AND up_at IS NULL LIMIT 1');
            $q->execute([$serverId]);
            if ($q->fetchColumn()) {
                return; // уже есть открытый инцидент — не плодим
            }
            $ins = $db->prepare('INSERT INTO server_incidents (server_id, down_at, down_detail) VALUES (?, ?, ?)');
            $ins->execute([$serverId, gmdate('Y-m-d H:i:s'), mb_substr($detail, 0, 500)]);
        } catch (\Throwable $e) {
            error_log('ServerIncidents::open ' . $e->getMessage());
        }
    }

    private static function close(int $serverId, ?array $ssh): void
    {
        try {
            $db = Database::get();
            $sel = $db->prepare('SELECT id, down_at FROM server_incidents WHERE server_id = ? AND up_at IS NULL ORDER BY id DESC LIMIT 1');
            $sel->execute([$serverId]);
            $inc = $sel->fetch(\PDO::FETCH_ASSOC);
            if (!$inc) {
                return; // открытого инцидента не было (напр. первый запуск после рестарта)
            }
            $logs = ($ssh && !empty($ssh['key'])) ? self::collectLogs($ssh, (string) $inc['down_at']) : null;
            $upd = $db->prepare('UPDATE server_incidents SET up_at = ?, logs = ? WHERE id = ?');
            $upd->execute([gmdate('Y-m-d H:i:s'), $logs, (int) $inc['id']]);
        } catch (\Throwable $e) {
            error_log('ServerIncidents::close ' . $e->getMessage());
        }
    }

    /** Снимает логи за окно простоя по SSH. Относительный --since надёжнее абсолютного (TZ сервера). */
    private static function collectLogs(array $ssh, string $downAtUtc): ?string
    {
        $downTs = strtotime($downAtUtc . ' UTC') ?: time();
        $mins = max(2, (int) ceil((time() - $downTs) / 60) + 2);
        try {
            $r = Ssh::runCommand(
                (string) $ssh['host'],
                (int) $ssh['port'],
                (string) $ssh['user'],
                (string) $ssh['key'],
                self::logScript($mins . ' min ago')
            );
        } catch (\Throwable $e) {
            return '[collect error] ' . $e->getMessage();
        }
        $out = trim((string) ($r['output'] ?? ''));
        if ($out === '' && !empty($r['error'])) {
            $out = '[collect error] ' . $r['error'];
        }
        return $out === '' ? null : mb_substr($out, 0, self::MAX_LOG);
    }

    private static function logScript(string $since): string
    {
        $s = escapeshellarg($since);
        return
            'echo "== uptime =="; uptime; '
            . 'echo; echo "== last reboots =="; last -x reboot 2>/dev/null | head -5; '
            . 'echo; echo "== kernel tail (OOM/network) =="; (dmesg -T 2>/dev/null || journalctl -k --no-pager 2>/dev/null) | tail -40; '
            . 'echo; echo "== services since down =="; journalctl --since ' . $s
                . ' -u sing-box -u caddy -u "amneziawg*" -u "awg-quick*" -u "wg-quick*" -u sshd -u ssh --no-pager 2>/dev/null | tail -300; '
            . 'echo; echo "== journal since down (tail) =="; journalctl --since ' . $s . ' --no-pager 2>/dev/null | tail -300';
    }

    /** Список инцидентов сервера для UI (без тяжёлого поля logs). */
    public static function listFor(int $serverId, int $limit = 20): array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT id, down_at, up_at, down_detail,
                    CASE WHEN logs IS NOT NULL AND logs != '' THEN 1 ELSE 0 END AS has_log
             FROM server_incidents WHERE server_id = ? ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $serverId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Логи одного инцидента. */
    public static function logFor(int $incidentId): ?string
    {
        $st = Database::get()->prepare('SELECT logs FROM server_incidents WHERE id = ?');
        $st->execute([$incidentId]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (string) $v;
    }
}
