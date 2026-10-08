<?php

// Дополнительные WireGuard-пиры (домашние устройства вроде Keenetic) на
// exit-серверах с protocol=wireguard — см. App\Provisioner::addWireguardPeer().

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\AuditLog;
use App\Models\ExitServerPeer;
use App\Provisioner;

$method = Http::guard(['GET', 'POST', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

try {
    if ($method === 'GET') {
        $exitServerId = (int) ($_GET['exit_server_id'] ?? 0);
        if (!$exitServerId) {
            Http::error('exit_server_id обязателен');
        }
        Http::json(ExitServerPeer::forExitServer($exitServerId));
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $exitServerId = (int) ($body['exit_server_id'] ?? 0);
        $name = trim($body['name'] ?? '');
        if (!$exitServerId || $name === '') {
            Http::error('exit_server_id и name обязательны');
        }
        $result = Provisioner::addWireguardPeer($exitServerId, $name);
        AuditLog::record('wg_peer.add', "exit_server=$exitServerId name=$name");
        Http::json($result['peer'], 201);
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        Provisioner::removeWireguardPeer($id);
        AuditLog::record('wg_peer.remove', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
