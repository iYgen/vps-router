<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\AuditLog;
use App\Models\ServerSet;

$method = Http::guard(['GET', 'POST', 'PUT', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET') {
        Http::json($id ? (ServerSet::find($id) ?? Http::error('Не найдено', 404)) : ServerSet::all());
    }

    if ($method === 'POST' && $action === 'members') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $body = Http::jsonInput();
        $serverId = (int) ($body['server_id'] ?? 0);
        $op = $body['op'] ?? 'add';
        if ($op === 'remove') {
            ServerSet::removeMember($id, $serverId);
        } else {
            ServerSet::addMember($id, $serverId, (int) ($body['priority'] ?? 0));
        }
        AuditLog::record('server_set.members', "set=$id server=$serverId op=$op");
        Http::json(ServerSet::find($id));
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $newId = ServerSet::create($body);
        AuditLog::record('server_set.create', $body['name'] ?? "id=$newId");
        Http::json(ServerSet::find($newId), 201);
    }

    if ($method === 'PUT') {
        if (!$id) {
            Http::error('id обязателен');
        }
        ServerSet::update($id, Http::jsonInput());
        AuditLog::record('server_set.update', "id=$id");
        Http::json(ServerSet::find($id));
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        // ?cascade=1 — удалить и серверы-участники (с их exit-туннелями), а не
        // только сам набор-группу. По умолчанию (без флага) — только группа.
        if (!empty($_GET['cascade'])) {
            $deleted = ServerSet::deleteCascade($id);
            AuditLog::record('server_set.delete', "id=$id cascade servers=$deleted");
            Http::json(['ok' => true, 'deleted_servers' => $deleted]);
        }
        ServerSet::delete($id);
        AuditLog::record('server_set.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
