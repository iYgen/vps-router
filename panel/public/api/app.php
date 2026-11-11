<?php

// API для Android-приложения VPS Router (сессия панели, как у остальных /api/*):
//   GET                        → версия опубликованного APK, версия панели, хэши скриптов
//   GET ?action=download       → APK
//   GET ?action=script&name=…  → скрипт установки из deploy/provision (только из белого списка)
//   GET ?action=link           → временная ссылка для скачивания APK (для QR)

require __DIR__ . '/../../src/bootstrap.php';

use App\AppRelease;
use App\Auth;
use App\Http;
use App\Version;

Http::guard(['GET']);
Auth::requireLoginJson();

$action = (string) ($_GET['action'] ?? '');

if ($action === 'download') {
    AppRelease::stream();
    exit;
}

if ($action === 'script') {
    $path = AppRelease::scriptPath((string) ($_GET['name'] ?? ''));
    if ($path === null) {
        Http::error('unknown script', 404);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Sha256: ' . hash_file('sha256', $path));
    readfile($path);
    exit;
}

if ($action === 'link') {
    if (AppRelease::info() === null) {
        Http::error('APK not published', 404);
    }
    Http::json(['url' => AppRelease::baseUrl() . AppRelease::signedPath(), 'ttl' => AppRelease::LINK_TTL]);
}

Http::json([
    'app' => AppRelease::info(),
    'panelVersion' => Version::current(),
    'scripts' => AppRelease::scriptHashes(),
]);
