<?php
// Сбор трафика устройств (App\TrafficCollector) — cron раз в минуту, работает ~55 с:
//   * * * * * php /var/www/panel/bin/collect_traffic.php >/dev/null 2>&1
// Пользователю, от которого идёт cron, нужен доступ к журналу sing-box
// (группа systemd-journal) — иначе устройства VLESS/SS/Trojan/Hy2 будут видны
// только по IP, без имени.

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Setting;
use App\TrafficCollector;

$lock = fopen(sys_get_temp_dir() . '/panel-collect-traffic.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // предыдущий запуск ещё идёт
}

$result = TrafficCollector::run(55, 2);
Setting::set('traffic_collector_last_run', gmdate('c') . ($result['errors'] ? ' ERR: ' . implode('; ', $result['errors']) : ''));
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";
