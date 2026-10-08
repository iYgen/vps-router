<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Modules\ModuleManager;
use App\TgProxy;

// Логин + CSRF (для POST) обеспечивает Http::guard.
$method = Http::guard(['GET', 'POST']);

if (!ModuleManager::featureActive('tg-proxy')) {
    Http::error('module disabled', 404);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Http::error('id обязателен', 400);
}

try {
    if ($method === 'GET') {
        Http::json(TgProxy::status($id));
    }

    $body = Http::jsonInput();
    $action = (string) ($body['action'] ?? ($_GET['action'] ?? ''));
    switch ($action) {
        case 'mtg_enable':
            Http::json(TgProxy::enableMtg($id, (int) ($body['port'] ?? 8443), trim((string) ($body['domain'] ?? ''))));
            // no break (Http::json exits)
        case 'mtg_disable':
            Http::json(TgProxy::disableMtg($id));
        case 'regen':
            Http::json(TgProxy::regenSecret($id));
        case 'socks_enable':
            Http::json(TgProxy::enableSocks($id, (int) ($body['port'] ?? 1080), (string) ($body['user'] ?? 'tg'), (string) ($body['pass'] ?? '')));
        case 'socks_disable':
            Http::json(TgProxy::disableSocks($id));
        default:
            Http::error('unknown action', 400);
    }
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
