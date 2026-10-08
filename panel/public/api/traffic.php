<?php

// Трафик устройств за последние N минут (App\TrafficCollector::summary) —
// для узлов-устройств и подписей трафика на графе инфраструктуры.

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\TrafficCollector;

Http::guard(['GET']);

try {
    $minutes = max(1, min(2880, (int) ($_GET['minutes'] ?? 15)));
    Http::json(TrafficCollector::summary($minutes));
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
