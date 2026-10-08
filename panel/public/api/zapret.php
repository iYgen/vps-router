<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Zapret;

// Логин + CSRF (для POST) обеспечивает Http::guard.
$method = Http::guard(['GET', 'POST']);

if ($method === 'GET') {
    Http::json(Zapret::status());
}

$body = Http::jsonInput();
$action = (string) ($body['action'] ?? ($_GET['action'] ?? ''));

try {
    if ($action === 'enable') {
        Http::json(Zapret::enable((string) ($body['strategy'] ?? 'fakesplit')));
    }
    if ($action === 'disable') {
        Http::json(Zapret::disable());
    }
    Http::error('unknown action', 400);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
