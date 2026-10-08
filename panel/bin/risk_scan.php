<?php
// Автоматический периодический прогон App\RiskScanner по всем серверам
// графа инфраструктуры (у которых задан host) — раньше проверка была
// доступна только по кнопке в карточке сервера, и находки нигде не
// сохранялись между прогонами. Не меняет ничего на сканируемых серверах —
// только TCP-подключения теми же средствами, что доступны внешнему
// наблюдателю (см. App\RiskScanner).
//
// Запуск по cron (например раз в сутки):
//   0 6 * * * php /var/www/panel/bin/risk_scan.php >/dev/null 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\Models\RiskScanResult;
use App\Models\Server;
use App\RiskScanner;

foreach (Server::all() as $server) {
    if (empty($server['host'])) {
        continue;
    }
    $result = RiskScanner::scan($server['host'], $server['name'], RiskScanner::camouflageFor($server));
    RiskScanResult::record((int) $server['id'], $server['host'], $result['score'], $result['findings'], 'cron');
    echo "{$server['name']} ({$server['host']}): score={$result['score']}\n";
}
