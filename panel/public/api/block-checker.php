<?php

// Проверяет, выглядит ли домен заблокированным локально, прямо с этой входной VPS —
// см. App\BlockChecker. Помогает решить, нужно ли вообще проксировать
// конкретный маршрут.

require __DIR__ . '/../../src/bootstrap.php';

use App\BlockChecker;
use App\Http;
use App\Models\AuditLog;

$method = Http::guard(['POST']);

try {
    if ($method === 'POST') {
        $body = Http::jsonInput();
        $domains = array_filter(array_map('trim', (array) ($body['domains'] ?? [])));
        if (!$domains) {
            Http::error('Укажите хотя бы один домен');
        }
        $results = BlockChecker::checkDomains($domains);
        AuditLog::record('block_checker.check', implode(',', array_column($results, 'domain')));
        Http::json($results);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
