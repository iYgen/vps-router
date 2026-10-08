<?php

// Санитизированный список WG-пиров (App\Models\ExitServerPeer) для UI-селектов
// (выбор транспорта при регистрации Keenetic-устройства) — БЕЗ ключей.

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\ExitServerPeer;

$method = Http::guard(['GET']);

try {
    if ($method === 'GET') {
        $rows = array_map(static fn (array $p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'exit_server_id' => (int) $p['exit_server_id'],
            'exit_server_name' => $p['exit_server_name'],
            'tunnel_address' => $p['tunnel_address'],
            'revoked' => (bool) $p['revoked'],
        ], ExitServerPeer::allWithExitServer());
        Http::json($rows);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
