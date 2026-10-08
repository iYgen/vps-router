<?php

// Санитизированный список exit_servers для UI-селектов (Routes tree inspector
// и т.п.) — БЕЗ секретов (wg_local_privkey/wg_peer_psk/protocol_params могут
// содержать приватные ключи). Для полноценного CRUD exit-серверов
// используется отдельный флоу через connections/servers, не этот endpoint.

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\ExitServer;

$method = Http::guard(['GET']);

try {
    if ($method === 'GET') {
        $rows = array_map(static fn (array $es) => [
            'id' => (int) $es['id'],
            'name' => $es['name'],
            'protocol' => $es['protocol'] ?? 'amneziawg',
            'status' => $es['status'],
            'provision_status' => $es['provision_status'] ?? null,
        ], ExitServer::all());
        Http::json($rows);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
