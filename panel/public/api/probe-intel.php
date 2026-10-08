<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\Server;
use App\Modules\ModuleManager;
use App\ProbeIntel;

// Логин + CSRF (для POST) обеспечивает Http::guard централизованно.
$method = Http::guard(['GET', 'POST']);

if (!ModuleManager::featureActive('probe-intel')) {
    Http::error('module disabled', 404);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = $_GET['action'] ?? null;
$server = $id ? Server::find($id) : null;
if ($id && !$server) {
    Http::error('Не найдено', 404);
}

try {
    if ($method === 'GET' && $action === 'timeline' && $server) {
        Http::json([
            'current'         => ProbeIntel::current($id),
            'timeline'        => ProbeIntel::timeline($id),
            'log'             => ProbeIntel::logState($id),
            'logging_allowed' => \App\Models\Setting::get('probe_logging_allowed', '0') === '1',
        ]);
    }
    if ($method === 'GET' && $action === 'events' && $server) {
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $rows = ProbeIntel::events($id, 20, $offset);
        Http::json([
            'rows'    => $rows,
            'offset'  => $offset,
            'total'   => ProbeIntel::eventsCount($id),
            'blocked' => ProbeIntel::blockedIps($id),
        ]);
    }
    if ($method === 'POST' && $action === 'block-ip' && $server) {
        Http::json(ProbeIntel::blockIp($server, (string) (Http::jsonInput()['ip'] ?? '')));
    }
    if ($method === 'POST' && $action === 'unblock-ip' && $server) {
        Http::json(ProbeIntel::unblockIp($server, (string) (Http::jsonInput()['ip'] ?? '')));
    }
    // Опциональное логирование зондирований на самом exit (LOG-only, по кнопке).
    if ($method === 'POST' && $action === 'enable-log' && $server) {
        Http::json(ProbeIntel::setLogging($server, true));
    }
    if ($method === 'POST' && $action === 'disable-log' && $server) {
        Http::json(ProbeIntel::setLogging($server, false));
    }
    if ($method === 'POST' && $action === 'pull-log' && $server) {
        Http::json(ProbeIntel::pullLog($server));
    }
    Http::error('unknown action', 400);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
