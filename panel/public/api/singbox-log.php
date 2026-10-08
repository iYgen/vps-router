<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\SingboxLog;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$lines = (int) ($_GET['lines'] ?? 200);
$grep = trim((string) ($_GET['grep'] ?? ''));

echo json_encode(SingboxLog::tail($lines, $grep), JSON_UNESCAPED_UNICODE);
