<?php
// Handshake watch: поминутный разбор журнала sing-box входного узла — счётчики
// попыток / неудачных рукопожатий по адресам клиентов, детекция «волн» обрывов
// и автоматический захват трафика при начале волны (см. App\HandshakeWatch).
//
// Запуск по cron раз в минуту (пользователь панели должен быть в группе
// systemd-journal):
//   * * * * * php /var/www/panel/bin/handshake_watch.php >/dev/null 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\HandshakeWatch;
use App\Modules\ModuleManager;

if (!ModuleManager::featureActive('handshake-watch')) {
    exit(0);
}

$stat = HandshakeWatch::run();
if (PHP_SAPI === 'cli' && in_array('-v', $argv ?? [], true)) {
    echo json_encode($stat), "\n";
}
