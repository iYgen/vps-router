<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Installer;
use App\InstallRunner;

// Доступ только пока панель не установлена И совпадает токен «уникальной ссылки».
if (!Installer::accessAllowed($_GET['t'] ?? null)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$action = (string) ($_GET['action'] ?? '');
if (!InstallRunner::isAllowed($action)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unknown action';
    exit;
}

// Смещение для дочитывания после обрыва: из Last-Event-ID (SSE reconnect) или ?offset.
$offset = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $offset = max(0, (int) $_SERVER['HTTP_LAST_EVENT_ID']);
} elseif (isset($_GET['offset'])) {
    $offset = max(0, (int) $_GET['offset']);
}

@set_time_limit(0);
while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // не буферизовать на стороне nginx

echo "retry: 3000\n";
echo ": connected\n\n";
@flush();

// Запуск идемпотентен: если уже идёт/завершён — просто читаем лог.
try {
    InstallRunner::start($action);
} catch (\Throwable $e) {
    echo "event: error\ndata: " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n";
    @flush();
    exit;
}

$deadline = time() + 300; // защита от вечного цикла
while (!connection_aborted()) {
    $r = InstallRunner::readFrom($action, $offset);

    if ($r['chunk'] !== '') {
        foreach (explode("\n", rtrim($r['chunk'], "\n")) as $line) {
            echo 'data: ' . str_replace("\r", '', $line) . "\n";
        }
        $offset = $r['offset'];
        echo 'id: ' . $offset . "\n\n";
        @flush();
    }

    if ($r['done']) {
        echo "event: done\ndata: " . (int) $r['code'] . "\n\n";
        @flush();
        break;
    }
    if (time() > $deadline) {
        echo "event: timeout\ndata: 1\n\n";
        @flush();
        break;
    }
    usleep(400000); // 0.4с
}
