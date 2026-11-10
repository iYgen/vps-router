<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\HandshakeWatch;
use App\Http;
use App\Modules\ModuleManager;

// Логин + CSRF (для POST) обеспечивает Http::guard централизованно.
$method = Http::guard(['GET', 'POST']);

if (!ModuleManager::featureActive('handshake-watch')) {
    Http::error('module disabled', 404);
}

$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && $action === 'overview') {
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        Http::json([
            'config' => HandshakeWatch::config(),
            'series' => HandshakeWatch::series24h(time()),
            'waves'  => HandshakeWatch::waves(30, $offset),
            'total'  => HandshakeWatch::wavesCount(),
            'offset' => $offset,
        ]);
    }
    if ($method === 'GET' && $action === 'capture') {
        $path = HandshakeWatch::waveCapture((int) ($_GET['id'] ?? 0));
        if (!$path) {
            Http::error('Не найдено', 404);
        }
        \App\Models\AuditLog::record('hs.capture_download', basename($path));
        header('Content-Type: application/vnd.tcpdump.pcap');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($method === 'POST' && $action === 'config') {
        $cfg = HandshakeWatch::saveConfig(Http::jsonInput());
        \App\Models\AuditLog::record('hs.config', json_encode($cfg));
        Http::json(['ok' => true, 'config' => $cfg]);
    }
    Http::error('unknown action', 400);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
