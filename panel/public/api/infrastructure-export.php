<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\InfrastructureExport;

$method = Http::guard(['GET', 'POST']);

try {
    if ($method === 'GET') {
        $data = InfrastructureExport::export();
        header('Content-Disposition: attachment; filename="vps-router-infrastructure-' . date('Y-m-d') . '.json"');
        Http::json($data);
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $result = InfrastructureExport::import($body);
        Http::json($result);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 400);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
