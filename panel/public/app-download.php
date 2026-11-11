<?php

// Скачивание APK по временной подписанной ссылке (QR со страницы «Приложение»).
// Без входа в панель: телефон открывает ссылку в браузере. Ссылка живёт
// AppRelease::LINK_TTL секунд и подписана app_secret этой панели.

require __DIR__ . '/../src/bootstrap.php';

use App\AppRelease;

$e = (string) ($_GET['e'] ?? '');
$s = (string) ($_GET['s'] ?? '');

if (!AppRelease::verify($e, $s)) {
    // Тот же ответ, что и на любой неизвестный адрес: не подтверждаем, что тут что-то есть.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

AppRelease::stream();
