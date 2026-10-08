<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\LocalSystem;
use App\Models\ExitServer;
use App\TrafficCollector;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Живой снимок из Clash API (кумулятивные счётчики); скорость считает клиент по дельте.
$live = TrafficCollector::liveSnapshot();

$totals = TrafficCollector::deviceTotals();
$online = 0;
$transferTotal = 0;
foreach ($totals as $v) {
    if (!empty($v['online'])) {
        $online++;
    }
    $transferTotal += ($v['total_up'] ?? 0) + ($v['total_down'] ?? 0);
}

// Аптайм сервера (self) — из /proc/uptime, если доступно.
$uptime = null;
if (@is_readable('/proc/uptime')) {
    $u = @file_get_contents('/proc/uptime');
    if ($u !== false) {
        $uptime = (int) floatval(strtok($u, " \t"));
    }
}

// Доступность exit-серверов: healthy = свежий last_health_ok_at (< 5 мин).
$exits = ExitServer::all();
$exitsHealthy = 0;
$latencies = [];
foreach ($exits as $es) {
    $ok = $es['last_health_ok_at'] ?? null;
    if ($ok) {
        $ts = strtotime($ok . ' UTC');
        if ($ts !== false && (time() - $ts) < 300) {
            $exitsHealthy++;
            if (isset($es['latency_ms']) && $es['latency_ms'] !== null) {
                $latencies[] = (int) $es['latency_ms'];
            }
        }
    }
}
$latencyAvg = $latencies ? (int) round(array_sum($latencies) / count($latencies)) : null;

echo json_encode([
    'ts' => (int) round(microtime(true) * 1000),
    'clash_up' => $live !== null,
    'download_total' => $live['download_total'] ?? null,
    'upload_total' => $live['upload_total'] ?? null,
    'connections' => $live['connections'] ?? null,
    'devices_online' => $online,
    'devices_total' => count($totals),
    'transfer_total' => $transferTotal,
    'uptime' => $uptime,
    'exits_total' => count($exits),
    'exits_healthy' => $exitsHealthy,
    'exits_latency' => $latencyAvg,
    'singbox_active' => \App\LocalSystem::singboxActive(),
], JSON_UNESCAPED_UNICODE);
