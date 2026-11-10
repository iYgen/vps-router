<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\NodeServices;

// Только чтение: Docker-контейнеры (Amnezia и пр.) и ключевые службы узла.
Http::guard(['GET']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Http::error('id обязателен', 400);
}
try {
    Http::json(NodeServices::fetch($id));
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
