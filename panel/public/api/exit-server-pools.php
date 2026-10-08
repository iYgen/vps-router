<?php

// Список меток пулов балансировки (exit_servers.pool_label) для UI-селекта
// «Автоматически из пула» при регистрации нового Keenetic-устройства.
// См. App\ExitServerBalancer.

require __DIR__ . '/../../src/bootstrap.php';

use App\Database;
use App\Http;

$method = Http::guard(['GET']);

try {
    if ($method === 'GET') {
        $rows = Database::get()->query(
            "SELECT DISTINCT pool_label FROM exit_servers
             WHERE pool_label IS NOT NULL AND pool_label != '' AND status = 'active' AND protocol = 'wireguard'
             ORDER BY pool_label"
        )->fetchAll(\PDO::FETCH_COLUMN);
        Http::json(array_values($rows));
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
