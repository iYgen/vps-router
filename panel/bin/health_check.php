<?php
// Проверяет доступность каждого exit-сервера и пишет результат в БД —
// дашборд панели показывает это как online/offline.
//  - AmneziaWG: ping через его WG-интерфейс;
//  - нативные sing-box exit'ы (VLESS и т.п.): реальный запрос через туннель
//    (Clash API локального sing-box, см. App\ExitTunnelHealth) + письмо админу,
//    если туннель лёг или DPI начал резать часть портов.
// Запуск по cron раз в минуту: * * * * * php /var/www/panel/bin/health_check.php

require __DIR__ . '/../src/bootstrap.php';

use App\ExitTunnelHealth;
use App\Models\ExitServer;

foreach (ExitServer::all() as $es) {
    if ($es['status'] === 'disabled') {
        continue;
    }

    // Бесплатные exit'ы проверяет bin/free_pool_health.php.
    $params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
    if (($es['source'] ?? 'own') === 'free' || !empty($params['_raw'])) {
        continue;
    }
    if (($es['protocol'] ?? 'amneziawg') !== 'amneziawg') {
        $result = ExitTunnelHealth::check($es);
        ExitServer::setHealth((int) $es['id'], $result['ok'], $result['latency']);
        ExitTunnelHealth::record($es, $result);
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
