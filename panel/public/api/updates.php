<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Database;
use App\Http;
use App\Models\AuditLog;
use App\SelfUpdater;
use App\UpdateChecker;
use App\Version;

$method = Http::guard(['GET', 'POST']);
Auth::requireLoginJson();

try {
    if ($method === 'GET') {
        $s = UpdateChecker::status();
        $s['pending_migrations'] = Version::pendingMigrations();
        $s['self_update_available'] = SelfUpdater::available();
        Http::json($s);
    }

    // POST — мутации: проверяем CSRF.
    Auth::requireValidCsrfJson();
    $action = $_GET['action'] ?? '';

    if ($action === 'check') {
        $s = UpdateChecker::check();
        AuditLog::record('update.check', 'current=' . $s['current'] . ' latest=' . ($s['latest'] ?? '?'));
        $s['pending_migrations'] = Version::pendingMigrations();
        Http::json($s);
    }

    if ($action === 'migrate') {
        // Накатывает невыполненные миграции БД (идемпотентно; обычно они уже
        // накатаны автоматически при подключении). Кода не трогает — только схему.
        $before = Version::pendingMigrations();
        Database::migrate();
        $after = Version::pendingMigrations();
        $applied = array_values(array_diff($before, $after));
        AuditLog::record('update.migrate', $applied ? implode(',', $applied) : 'none');
        Http::json(['ok' => true, 'applied' => $applied, 'pending_migrations' => $after]);
    }

    if ($action === 'self_update') {
        // Самообновление кода из репозитория: скачать архив, бэкап, наложить,
        // мигрировать, при ошибке — откат. Долгая операция.
        @set_time_limit(180);
        if (!SelfUpdater::available()) {
            Http::error('Самообновление недоступно (не настроен репозиторий/архив или нет прав на запись в каталог панели).', 400);
        }
        $r = SelfUpdater::run();
        AuditLog::record('update.self', ($r['ok'] ? 'ok ' : 'fail ') . $r['from'] . '→' . ($r['to'] ?? '?')
            . ($r['error'] ? (' err=' . $r['error']) : ''));
        Http::json($r);
    }

    Http::error('Неизвестное действие', 404);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
