<?php

// Ускорение сети на серверах (BBR + fq).
//   GET                    → {"wanted": bool}  — без обращения к серверам
//   GET ?live=1            → {"wanted", "servers": [{id, name, ok, enabled, cc, qdisc, kernel, active, error?}]}
//   POST {"action":"on|off"} → то же, после применения

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Http;
use App\Models\AuditLog;
use App\NetBoost;

$method = Http::guard(['GET', 'POST']);
Auth::requireLoginJson();

try {
    @set_time_limit(300);
    if ($method === 'GET') {
        Http::json(!empty($_GET['live']) ? NetBoost::run('status') : ['wanted' => NetBoost::wanted()]);
    }
    Auth::requireValidCsrfJson();
    $action = (string) (Http::jsonInput()['action'] ?? '');
    if (!in_array($action, ['on', 'off'], true)) {
        Http::error('action: on | off', 422);
    }
    $res = NetBoost::run($action);
    AuditLog::record('net_boost.' . $action, implode(', ', array_map(
        fn ($s) => $s['name'] . '=' . (!empty($s['ok']) ? 'ok' : 'fail'),
        $res['servers']
    )));
    Http::json($res);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
