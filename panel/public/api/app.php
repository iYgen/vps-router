<?php

// API для Android-приложения VPS Router (сессия панели, как у остальных /api/*):
//   GET                        → версия опубликованного APK, версия панели, хэши скриптов
//   GET ?action=download       → APK
//   GET ?action=script&name=…  → скрипт установки из deploy/provision (только из белого списка)
//   GET ?action=link           → временная ссылка для скачивания APK (для QR)

require __DIR__ . '/../../src/bootstrap.php';

use App\AppPairing;
use App\AppRelease;
use App\Auth;
use App\Http;
use App\Version;

$method = Http::guard(['GET', 'POST']);
Auth::requireLoginJson();

$action = (string) ($_GET['action'] ?? '');

if ($method === 'POST') {
    Auth::requireValidCsrfJson();
    if ($action === 'pair') {
        // Одноразовый код для QR: телефон войдёт без пароля (AppPairing).
        $in = Http::jsonInput();
        $clientId = isset($in['client_id']) && $in['client_id'] !== '' && $in['client_id'] !== null ? (int) $in['client_id'] : null;
        $code = AppPairing::createCode((int) $_SESSION['user_id'], $clientId);
        $q = 'url=' . rawurlencode(AppRelease::baseUrl()) . '&user=' . rawurlencode((string) Auth::username()) . '&code=' . rawurlencode($code);
        foreach ((array) ($in['alt'] ?? []) as $alt) {
            if (is_string($alt) && preg_match('#^https?://\S+$#i', trim($alt))) {
                $q .= '&alt=' . rawurlencode(trim($alt));
            }
        }
        if (!empty($in['insecure'])) {
            $q .= '&insecure=1';
        }
        if ($clientId !== null) {
            $q .= '&device=' . $clientId;
        }
        Http::json(['link' => 'vpsrouter://panel?' . $q, 'ttl' => AppPairing::CODE_TTL]);
    }
    Http::error('unknown action', 404);
}

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

if ($action === 'traffic') {
    // Куда уходит трафик одного устройства (для вкладки «Связи» в приложении):
    // по выходам (напрямую / exit-сервер) и топ сайтов, с готовыми подписями.
    $clientId = (int) ($_GET['client_id'] ?? 0);
    $minutes = max(5, min(2880, (int) ($_GET['minutes'] ?? 1440)));
    $sum = \App\TrafficCollector::summary($minutes, 15);
    $dev = null;
    foreach ($sum['devices'] as $d) {
        if (($d['client_id'] ?? null) === $clientId) {
            $dev = $d;
            break;
        }
    }
    $labels = [];
    $label = function (string $tag) use (&$labels): string {
        if (isset($labels[$tag])) {
            return $labels[$tag];
        }
        $name = $tag;
        if ($tag === 'direct-rf' || $tag === 'direct') {
            $name = t('app.traffic.direct');
        } elseif (preg_match('/^exit-(\d+)$/', $tag, $m)) {
            $es = \App\Models\ExitServer::find((int) $m[1]);
            $name = $es ? (string) $es['name'] : ('exit #' . $m[1]);
        }
        return $labels[$tag] = $name;
    };
    $byOutbound = [];
    foreach (($dev['by_outbound'] ?? []) as $tag => $t) {
        $byOutbound[] = ['tag' => (string) $tag, 'label' => $label((string) $tag), 'up' => $t['up'], 'down' => $t['down']];
    }
    usort($byOutbound, fn($a, $b) => ($b['up'] + $b['down']) <=> ($a['up'] + $a['down']));
    $hosts = array_map(fn($h) => ['host' => $h['host'], 'tag' => (string) $h['outbound'], 'label' => $label((string) $h['outbound']), 'up' => $h['up'], 'down' => $h['down']], $dev['hosts'] ?? []);
    Http::json([
        'minutes' => $minutes,
        'found' => $dev !== null,
        'online' => (bool) ($dev['online'] ?? false),
        'up' => (int) ($dev['up'] ?? 0),
        'down' => (int) ($dev['down'] ?? 0),
        'totals' => $dev['totals'] ?? null,
        'by_outbound' => $byOutbound,
        'hosts' => $hosts,
    ]);
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
