<?php
// Активная защита: замер доступности exit-узлов локально (вантедж панели) и
// извне (check-host) + подтягивание логов зондирований с тех узлов, где
// логирование включено пользователем. Пишет в probe_samples / probe_events,
// шлёт алерт при переходе в «похоже на блокировку Active Blocking System».
//
// Запуск по cron (внешние проверки check-host медленные — не чаще раза в 5 мин):
//   */5 * * * * php /var/www/panel/bin/probe_intel.php >/dev/null 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Server;
use App\Modules\ModuleManager;
use App\ProbeIntel;

if (!ModuleManager::featureActive('probe-intel')) {
    exit(0); // модуль «Активная защита» выключен
}

foreach (Server::all() as $server) {
    if (empty($server['enabled']) || !empty($server['is_self'])) {
        continue;
    }
    if (($server['role'] ?? '') !== 'exit') {
        continue; // активная защита — для выходных узлов
    }
    try {
        ProbeIntel::sample($server);
        $state = ProbeIntel::logState((int) $server['id']);
        if (!empty($state['enabled'])) {
            ProbeIntel::pullLog($server);
        }
    } catch (\Throwable $e) {
        error_log('probe_intel ' . ($server['id'] ?? '?') . ': ' . $e->getMessage());
    }
}
