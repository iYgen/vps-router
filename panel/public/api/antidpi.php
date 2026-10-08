<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\AuditLog;
use App\Models\Setting;

/**
 * Настройки обхода блокировок (анти-DPI) — те же глобальные Setting, что и на
 * странице «Настройки». Даёт редактировать их контекстно из «Инфраструктуры»
 * (инспектор узла), не дублируя хранилище: один источник правды.
 * Логин + CSRF (для POST) обеспечивает Http::guard.
 */
$method = Http::guard(['GET', 'POST']);

$FP = ['chrome', 'firefox', 'edge', 'safari', 'ios', 'android', 'random', 'randomized'];

if ($method === 'GET') {
    Http::json([
        'tls_fragment'    => Setting::get('antidpi_tls_fragment', '0') === '1',
        'record_fragment' => Setting::get('antidpi_record_fragment', '0') === '1',
        'fragment_delay'  => (string) Setting::get('antidpi_fragment_delay', ''),
        'utls_fingerprint' => (string) Setting::get('antidpi_utls_fingerprint', 'chrome'),
    ]);
}

// POST — сохранение.
$body = Http::jsonInput();
$delay = trim((string) ($body['fragment_delay'] ?? ''));
if ($delay !== '' && !preg_match('/^[0-9]{1,6}(ms|s)$/', $delay)) {
    Http::error('Задержка фрагментации: формат «500ms» или «1s»', 422);
}
$fp = (string) ($body['utls_fingerprint'] ?? 'chrome');
if (!in_array($fp, $FP, true)) {
    $fp = 'chrome';
}
Setting::set('antidpi_tls_fragment', !empty($body['tls_fragment']) ? '1' : '0');
Setting::set('antidpi_record_fragment', !empty($body['record_fragment']) ? '1' : '0');
Setting::set('antidpi_fragment_delay', $delay);
Setting::set('antidpi_utls_fingerprint', $fp);
AuditLog::record('antidpi.save', "fragment=" . (!empty($body['tls_fragment']) ? '1' : '0')
    . ' record=' . (!empty($body['record_fragment']) ? '1' : '0') . " fp=$fp from=infra");
Http::json(['ok' => true]);
