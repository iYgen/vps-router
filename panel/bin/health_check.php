<?php
// Проверяет доступность каждого exit-сервера через его WG-интерфейс и
// пишет результат в БД — дашборд панели показывает это как online/offline.
// Запуск по cron раз в минуту: * * * * * php /var/www/panel/bin/health_check.php

require __DIR__ . '/../src/bootstrap.php';

use App\Models\ExitServer;

foreach (ExitServer::all() as $es) {
    if ($es['status'] === 'disabled') {
        continue;
    }

    $iface = escapeshellarg($es['interface_name']);
    // 1 пакет, таймаут 2с, отправляем именно через интерфейс туннеля.
    $out = [];
    exec("ping -I $iface -c 1 -W 2 1.1.1.1 2>/dev/null", $out, $code);

    // Достаём задержку из строки "time=12.3 ms".
    $latency = null;
    if ($code === 0 && preg_match('/time[=<]\s*([\d.]+)\s*ms/i', implode("\n", $out), $m)) {
        $latency = (int) round((float) $m[1]);
    }

    ExitServer::setHealth((int) $es['id'], $code === 0, $latency);
}
