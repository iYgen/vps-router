<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Http;
use App\Models\AuditLog;
use App\Modules\ModuleManager;

$method = Http::guard(['GET', 'POST']);
Auth::requireLoginJson();

try {
    if ($method === 'GET') {
        Http::json([
            'installed' => ModuleManager::all(),
            'available' => ModuleManager::availableArchives(),
        ]);
    }

    // POST — мутации: CSRF обязателен.
    Auth::requireValidCsrfJson();
    $action = $_GET['action'] ?? '';

    // Загрузка стороннего архива .vmod.
    if ($action === 'upload') {
        if (empty($_FILES['archive']['tmp_name']) || !is_uploaded_file($_FILES['archive']['tmp_name'])) {
            Http::error('Файл архива не загружен', 422);
        }
        $name = ModuleManager::acceptUpload($_FILES['archive']['tmp_name'], (string) ($_FILES['archive']['name'] ?? ''));
        AuditLog::record('module.upload', $name);
        Http::json(['ok' => true, 'archive' => $name]);
    }

    $body = Http::jsonInput();

    if ($action === 'install') {
        $mod = ModuleManager::install((string) ($body['archive'] ?? ''));
        AuditLog::record('module.install', $mod['id']);
        Http::json(['ok' => true, 'module' => $mod]);
    }

    $id = (string) ($body['id'] ?? '');
    if ($id === '') {
        Http::error('id обязателен', 422);
    }

    if ($action === 'activate') {
        ModuleManager::activate($id);
        AuditLog::record('module.activate', $id);
        Http::json(['ok' => true]);
    }
    if ($action === 'deactivate') {
        ModuleManager::deactivate($id);
        AuditLog::record('module.deactivate', $id);
        Http::json(['ok' => true]);
    }
    if ($action === 'remove') {
        $keep = !empty($body['keep_settings']);
        ModuleManager::remove($id, $keep);
        AuditLog::record('module.remove', $id . ($keep ? ' (settings kept)' : ' (settings deleted)'));
        Http::json(['ok' => true]);
    }

    Http::error('Неизвестное действие', 404);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}
