<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\ExitServerFactory;
use App\Http;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Server;
use App\Provisioner;

$method = Http::guard(['GET', 'POST', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && $action === 'camouflage-presets') {
        Http::json(\App\CamouflageFront::presets());
    }

    // Предпроверка мастера маскировки: резолвим A-записи домена и сверяем с IP
    // exit-сервера (A-запись должна указывать на него, иначе ACME/сертификат не
    // выпустится и зонды не увидят сайт).
    if ($method === 'GET' && $action === 'camouflage-precheck') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $conn = Connection::find($id) ?? Http::error('Соединение не найдено', 404);
        $domain = strtolower(trim((string) ($_GET['domain'] ?? '')));
        if ($domain === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
            Http::error('Некорректный домен', 422);
        }
        $target = Server::find((int) $conn['target_server_id']);
        $expected = (string) ($target['host'] ?? '');
        $resolved = [];
        foreach (@dns_get_record($domain, DNS_A) ?: [] as $rec) {
            if (!empty($rec['ip'])) {
                $resolved[] = $rec['ip'];
            }
        }
        Http::json([
            'domain' => $domain,
            'expected_ip' => $expected,
            'resolved' => $resolved,
            'match' => $expected !== '' && in_array($expected, $resolved, true),
        ]);
    }

    if ($method === 'GET') {
        $serverId = isset($_GET['server_id']) ? (int) $_GET['server_id'] : null;
        Http::json($serverId ? Connection::forServer($serverId) : Connection::all());
    }

    if ($method === 'POST' && $action === 'provision') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $conn = Connection::find($id) ?? Http::error('Соединение не найдено', 404);
        $result = Provisioner::provisionConnection($id);
        AuditLog::record('connection.provision', "id=$id type={$conn['type']}: " . ($result['ok'] ? 'ok' : 'failed'));
        Http::json($result);
    }

    // Маскировка Reality: сменить Camouflage-домен и/или поднять сайт-прикрытие на exit-сервере.
    if ($method === 'POST' && $action === 'camouflage') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $conn = Connection::find($id) ?? Http::error('Соединение не найдено', 404);
        $body = Http::jsonInput();
        $preset = trim((string) ($body['preset'] ?? ''));
        $result = Provisioner::setExitCamouflage(
            $id,
            (string) ($body['domain'] ?? ''),
            $preset !== '' ? $preset : null,
            (string) ($body['brand'] ?? 'Service'),
            (string) ($body['custom_html'] ?? '')
        );
        AuditLog::record('connection.camouflage', "id=$id domain=" . ($body['domain'] ?? '') . ($preset ? " front=$preset" : ''));
        Http::json($result);
    }

    if ($method === 'POST' && $action === null) {
        $body = Http::jsonInput();
        $sourceId = (int) ($body['source_server_id'] ?? 0);
        $targetId = (int) ($body['target_server_id'] ?? 0);
        $type = $body['type'] ?? '';

        if (!Server::find($sourceId) || !Server::find($targetId)) {
            Http::error('Исходный или целевой сервер не найден', 422);
        }
        if (!in_array($type, Connection::TYPES, true)) {
            Http::error('Неизвестный тип соединения', 422);
        }

        $exitServerId = null;
        if (in_array($type, Connection::EXECUTABLE_TYPES, true)) {
            $target = Server::find($targetId);
            $exitServerId = ExitServerFactory::fromRequest($type, $body, $target);
        }

        $config = in_array($type, ['ssh', 'tcp', 'http', 'socks', 'generic'], true)
            ? ($body['config'] ?? null)
            : null;

        $newId = Connection::create($sourceId, $targetId, $type, $exitServerId, $config, $body['label'] ?? null);
        AuditLog::record('connection.create', "type=$type $sourceId->$targetId");
        Http::json(Connection::find($newId), 201);
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        Connection::delete($id);
        AuditLog::record('connection.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
