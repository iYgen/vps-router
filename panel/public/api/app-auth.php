<?php

// Вход Android-приложения без пароля (см. App\AppPairing). Сессия здесь не требуется.
//   POST ?action=pair     {"code": "...", "name": "Pixel 8"} → {"token", "username", "client_id"}
//   POST ?action=session  {"token": "..."}                    → сессия (cookie) + {"csrf", "username", "client_id"}
// Ошибки считаются как неудачные входы — общий лимит и блокировка по IP.

require __DIR__ . '/../../src/bootstrap.php';

use App\AppPairing;
use App\Auth;
use App\Http;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Http::error('method not allowed', 405);
}
if (Auth::clientIpBlocked()) {
    Http::error('blocked', 429);
}

$in = Http::jsonInput();
$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'pair') {
        $r = AppPairing::redeem((string) ($in['code'] ?? ''), (string) ($in['name'] ?? ''));
        if ($r === null) {
            Auth::appAuthFailed('app pairing code invalid/expired');
            Http::error('Код из QR недействителен или уже использован — покажите новый QR в панели', 401);
        }
        \App\Models\AuditLog::record('app.paired', 'Привязано приложение «' . ($in['name'] ?? 'Android') . '»');
        Http::json(['token' => $r['token'], 'username' => $r['username'], 'client_id' => $r['client_id']]);
    }

    if ($action === 'session') {
        $t = AppPairing::findToken((string) ($in['token'] ?? ''));
        if ($t === null || !Auth::loginAsApp($t['user_id'], 'app «' . $t['name'] . '»')) {
            Auth::appAuthFailed('app token invalid/revoked');
            Http::error('Доступ приложения отозван в панели — отсканируйте новый QR', 401);
        }
        if (array_key_exists('client_id', $in)) {
            AppPairing::setClient($t['id'], $in['client_id'] !== null ? (int) $in['client_id'] : null);
            $t['client_id'] = $in['client_id'] !== null ? (int) $in['client_id'] : null;
        }
        Http::json(['csrf' => Auth::csrfToken(), 'username' => Auth::username(), 'client_id' => $t['client_id']]);
    }

    Http::error('unknown action', 404);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка', 500);
}
