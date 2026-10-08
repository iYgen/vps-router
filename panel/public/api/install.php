<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Installer;
use App\Models\AuditLog;
use App\PanelBackup;

header('Content-Type: application/json; charset=utf-8');

// Мастер работает только до создания первого админа. После — 403 (и файлы уже удалены).
if (Installer::isInstalled()) {
    http_response_code(403);
    echo json_encode(['error' => 'Панель уже установлена']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
try {
    Auth::requireValidCsrf();
} catch (\Throwable $e) {
    http_response_code(419);
    echo json_encode(['error' => 'CSRF']);
    exit;
}

$action = $_GET['action'] ?? '';
try {
    if ($action === 'prep_components') {
        // Записываем параметры шага «компоненты» ДО запуска SSE-скрипта.
        $amnezia = ($_POST['amnezia'] ?? '1') !== '0';
        Installer::writeComponentsConf($amnezia);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'prep_cert') {
        // Записываем домен/e-mail для certbot. Пустой домен = пропустить TLS-шаг.
        $domain = trim($_POST['domain'] ?? '');
        if ($domain === '') {
            echo json_encode(['ok' => true, 'skip' => true]);
            exit;
        }
        Installer::writeCertConf($domain, trim($_POST['email'] ?? '') ?: null);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'configure') {
        $sni = trim($_POST['sni'] ?? '') ?: null;
        $notes = Installer::configureFresh($sni);
        echo json_encode(['ok' => true, 'notes' => $notes]);
        exit;
    }

    if ($action === 'import') {
        if (empty($_FILES['backup']['tmp_name']) || !is_uploaded_file($_FILES['backup']['tmp_name'])) {
            throw new \InvalidArgumentException('Файл копии не загружен');
        }
        $data = json_decode((string) file_get_contents($_FILES['backup']['tmp_name']), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Не удалось разобрать файл копии (ожидается JSON)');
        }
        $res = PanelBackup::import($data);
        echo json_encode(['ok' => true, 'restored' => array_sum($res['restored'])]);
        exit;
    }

    if ($action === 'create_admin') {
        // Создаём админа ПОСЛЕДНИМ шагом: сразу после этого мастер блокируется.
        Installer::createAdmin($_POST['username'] ?? '', $_POST['password'] ?? '');
        AuditLog::record('installer.finished', 'admin created via web installer');
        Installer::selfDestruct();
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Неизвестное действие']);
} catch (\Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
