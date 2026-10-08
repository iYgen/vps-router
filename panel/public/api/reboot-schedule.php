<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Http;
use App\Models\AuditLog;
use App\Models\Server;
use App\ScheduledReboots;

$method = Http::guard(['GET', 'POST']);
Auth::requireLoginJson();

try {
    $serverId = (int) ($_GET['server_id'] ?? 0);
    if ($method === 'GET') {
        if (!$serverId || !Server::find($serverId)) {
            Http::error('Сервер не найден', 404);
        }
        Http::json(ScheduledReboots::forServer($serverId));
    }

    Auth::requireValidCsrfJson();
    $body = Http::jsonInput();
    $serverId = (int) ($body['server_id'] ?? 0);
    $server = $serverId ? Server::find($serverId) : null;
    if (!$server) {
        Http::error('Сервер не найден', 404);
    }
    ScheduledReboots::save(
        $serverId,
        !empty($body['enabled']),
        (string) ($body['freq'] ?? 'weekly'),
        (string) ($body['time_utc'] ?? '04:00'),
        (int) ($body['dow'] ?? 0)
    );
    AuditLog::record('server.reboot_schedule', $server['name'] . ' enabled=' . (!empty($body['enabled']) ? '1' : '0'));
    Http::json(['ok' => true, 'schedule' => ScheduledReboots::forServer($serverId)]);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
