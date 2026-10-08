<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Models\ExitServer;
use App\TrafficCollector;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conns = TrafficCollector::liveConnections(300);

// Карта outbound-тег -> человекочитаемое имя exit.
$exitNames = ['direct-rf' => t('traffic.direct'), 'block' => 'block'];
foreach (ExitServer::all() as $es) {
    $exitNames['exit-' . $es['id']] = $es['name'];
}

echo json_encode([
    'ts' => (int) round(microtime(true) * 1000),
    'clash_up' => TrafficCollector::liveSnapshot() !== null,
    'geoip' => \App\GeoIp::available(),
    'connections' => $conns,
    'exit_names' => $exitNames,
], JSON_UNESCAPED_UNICODE);
