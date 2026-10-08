<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\ExitServerBalancer;
use App\Http;
use App\KeeneticSyncService;
use App\Models\AuditLog;
use App\Models\PolicyDevice;
use App\Provisioner;

/** Белый список полей наружу — БЕЗ rci_password_enc/расшифрованного пароля, как api/exit-servers.php. */
function sanitizePolicyDevice(array $d): array
{
    return [
        'id' => (int) $d['id'],
        'name' => $d['name'],
        'adapter_type' => $d['adapter_type'],
        'profile_id' => $d['profile_id'] !== null ? (int) $d['profile_id'] : null,
        'profile_name' => $d['profile_name'] ?? null,
        'exit_server_peer_id' => $d['exit_server_peer_id'] !== null ? (int) $d['exit_server_peer_id'] : null,
        'wg_interface_name' => $d['wg_interface_name'],
        'rci_host' => $d['rci_host'],
        'rci_port' => (int) $d['rci_port'],
        'rci_scheme' => $d['rci_scheme'],
        'rci_username' => $d['rci_username'],
        'has_rci_password' => !empty($d['rci_password_enc']),
        'applied_policy_version' => $d['applied_policy_version'] !== null ? (int) $d['applied_policy_version'] : null,
        'sync_status' => $d['sync_status'],
        'last_sync_at' => $d['last_sync_at'],
        'last_sync_log' => $d['last_sync_log'],
        'enabled' => (bool) $d['enabled'],
        'created_at' => $d['created_at'],
    ];
}

$method = Http::guard(['GET', 'POST', 'PUT', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET') {
        if ($id) {
            $device = PolicyDevice::find($id);
            Http::json($device ? sanitizePolicyDevice($device) : Http::error('Не найдено', 404));
        }
        Http::json(array_map('sanitizePolicyDevice', PolicyDevice::all()));
    }

    if ($method === 'POST' && $id && $action === 'sync') {
        // sync() уже пишет sync_status/last_sync_log в БД при любом исходе
        // (включая исключение) — отдаём актуальное состояние устройства, а
        // не общую HTTP-ошибку, чтобы UI мог показать реальный лог синхронизации.
        try {
            (new KeeneticSyncService())->sync($id);
            AuditLog::record('policy_device.sync', "id=$id ok=1");
        } catch (\Throwable $e) {
            AuditLog::record('policy_device.sync', "id=$id ok=0 error=" . $e->getMessage());
        }
        Http::json(sanitizePolicyDevice(PolicyDevice::find($id)));
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();

        // Автовыбор exit-сервера из пула балансировки — только для НОВОГО
        // устройства (см. App\ExitServerBalancer), уже существующие пиры не
        // трогает. Прямая передача exit_server_peer_id (текущее поведение)
        // продолжает работать как раньше — pool_label полностью опционален.
        if (!empty($body['pool_label']) && empty($body['exit_server_peer_id'])) {
            $exitServer = ExitServerBalancer::pickLeastLoaded($body['pool_label']);
            if (!$exitServer) {
                Http::error("Пул «{$body['pool_label']}» пуст или не настроен", 400);
            }
            $peer = Provisioner::addWireguardPeer((int) $exitServer['id'], $body['name'] ?? 'device');
            $body['exit_server_peer_id'] = $peer['peer']['id'];
            AuditLog::record(
                'policy_device.auto_peer',
                "pool={$body['pool_label']} exit_server={$exitServer['name']} peer_id={$peer['peer']['id']}"
            );
        }

        $newId = PolicyDevice::create($body);
        AuditLog::record('policy_device.create', $body['name'] ?? "id=$newId");
        Http::json(sanitizePolicyDevice(PolicyDevice::find($newId)), 201);
    }

    if ($method === 'PUT') {
        if (!$id) {
            Http::error('id обязателен');
        }
        PolicyDevice::update($id, Http::jsonInput());
        AuditLog::record('policy_device.update', "id=$id");
        Http::json(sanitizePolicyDevice(PolicyDevice::find($id)));
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        PolicyDevice::delete($id);
        AuditLog::record('policy_device.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
