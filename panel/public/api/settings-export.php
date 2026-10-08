<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Models\AuditLog;
use App\PanelBackup;

Auth::requireLogin();

$json = PanelBackup::exportJson();
$name = 'vpsrouter-settings-' . date('Ymd-His') . '.json';
AuditLog::record('settings.export', $name);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($json));
header('X-Content-Type-Options: nosniff');
echo $json;
