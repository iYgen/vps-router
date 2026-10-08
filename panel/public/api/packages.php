<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\AuditLog;
use App\PackageManager;

// Логин + CSRF (для POST) обеспечивает Http::guard.
$method = Http::guard(['GET', 'POST']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Http::error('id обязателен', 400);
}

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'status';
        if ($action === 'log') {
            Http::json(PackageManager::log($id));
        }
        Http::json(PackageManager::status($id));
    }

    // POST — запуск обновления (фоновое).
    $res = PackageManager::upgrade($id);
    AuditLog::record('server.pkg_upgrade', 'id=' . $id . ' ' . ($res['status'] ?? ($res['error'] ?? '?')));
    Http::json($res);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
