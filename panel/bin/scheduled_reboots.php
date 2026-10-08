<?php
// Плановая перезагрузка серверов по расписанию (App\ScheduledReboots).
// Запускать по cron каждые ~10 минут (окно isDue по умолчанию 10 мин):
//   */10 * * * *  php /var/www/panel/bin/scheduled_reboots.php >/dev/null 2>&1
//
// Перезагружает только УДАЛЁННЫЕ узлы по SSH (у self/панели нет root из крона).
// Команда отсоединённая (nohup ... &), чтобы SSH-сессия не висла на выключении.

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Server;
use App\ScheduledReboots;
use App\ServerAlerts;
use App\Ssh;

$now = time();
$windowMin = 10;

foreach (ScheduledReboots::allEnabled() as $sched) {
    if (!ScheduledReboots::isDue($sched, $now, $windowMin)) {
        continue;
    }
    $server = Server::find((int) $sched['server_id']);
    if (!$server || !$server['enabled']) {
        continue;
    }
    // Самого себя (узел панели) из крона не перезагружаем: нет root и это снесёт панель.
    if ((int) ($server['is_self'] ?? 0) === 1) {
        echo "skip self node #{$server['id']} ({$server['name']})\n";
        ScheduledReboots::markRun((int) $server['id'], $now); // чтобы не долбить каждое окно
        continue;
    }
    if (empty($server['has_ssh_key'])) {
        echo "skip #{$server['id']} ({$server['name']}): нет SSH-ключа\n";
        continue;
    }
    $key = Server::sshPrivateKey((int) $server['id']);
    if (!$key) {
        continue;
    }

    // Отсоединённый перезапуск: команда вернётся раньше, чем узел уйдёт в ребут.
    $cmd = 'nohup sh -c "sleep 3; systemctl reboot || reboot" >/dev/null 2>&1 &';
    $r = Ssh::runCommand($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $key, $cmd);
    ScheduledReboots::markRun((int) $server['id'], $now);

    $okTxt = ($r['ok'] ?? false) ? 'ok' : ('ошибка: ' . ($r['error'] ?? '?'));
    echo "reboot #{$server['id']} ({$server['name']}): {$okTxt}\n";
    try {
        \App\Models\AuditLog::record('server.scheduled_reboot', $server['name'] . ' (' . $sched['freq'] . ' ' . $sched['time_utc'] . ' UTC)');
    } catch (\Throwable $e) {
    }
    // Короткое уведомление админу (если включены алерты) — чтобы ребут не выглядел инцидентом.
    if (ServerAlerts::enabled() && ($to = ServerAlerts::recipient())) {
        \App\Mailer::send($to, '[VPS Router] Плановая перезагрузка: ' . $server['name'],
            "Узел «{$server['name']}» ({$server['host']}) перезагружен по расписанию ({$sched['freq']} {$sched['time_utc']} UTC).\nВремя: " . gmdate('Y-m-d H:i') . " UTC\n");
    }
}
