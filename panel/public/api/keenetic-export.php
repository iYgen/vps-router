<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HydraRouteExport;
use App\KeeneticExport;
use App\Models\AuditLog;
use App\RouterContext;
use App\RouterExport;

Auth::requireLogin();

$routerId = RouterContext::currentId();
$includeNull = RouterContext::includeNull($routerId);
$fmtIn = $_GET['format'] ?? 'txt';
$format = in_array($fmtIn, ['bat', 'hydra', 'mikrotik', 'openwrt'], true) ? $fmtIn : 'txt';
$iface = (string) ($_GET['iface'] ?? '');

// Формат доступен, только если его предоставляет активный router-модуль
// (модульная система: удалить/выключить модуль → экспорт этого формата пропадает).
if (!\App\Modules\ModuleManager::isExportFormatActive($format)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Формат экспорта недоступен: соответствующий модуль роутера не установлен/не активен.';
    exit;
}

if ($format === 'mikrotik') {
    $body = RouterExport::mikrotik($routerId, $includeNull, $iface !== '' ? $iface : 'wg-vpn');
    $name = 'mikrotik-routes-' . date('Ymd-His') . '.rsc';
    $type = 'text/plain; charset=utf-8';
} elseif ($format === 'openwrt') {
    $body = RouterExport::openwrt($routerId, $includeNull, $iface !== '' ? $iface : 'wg-vpn');
    $name = 'openwrt-routes-' . date('Ymd-His') . '.sh';
    $type = 'text/plain; charset=utf-8';
} elseif ($format === 'hydra') {
    // Формат HydraRoute Neo: domain.conf + ip.list в ZIP.
    $ts = date('Ymd-His');
    $body = KeeneticExport::zip([
        'domain.conf' => HydraRouteExport::domainConf($routerId, $includeNull),
        'ip.list' => HydraRouteExport::ipList($routerId, $includeNull),
    ]);
    $name = 'hydraroute-' . $ts . '.zip';
    $type = 'application/zip';
} elseif ($format === 'bat') {
    // Keenetic ограничивает статические маршруты (~1024 на файл), поэтому режем
    // по 1000 строк. Одна часть — отдаём .bat, несколько — ZIP с файлами.
    $files = KeeneticExport::asBatChunks($routerId, $includeNull, 1000);
    if (count($files) === 1) {
        $name = array_key_first($files);
        $body = $files[$name];
        $type = 'text/plain; charset=utf-8';
    } else {
        $name = 'keenetic-routes-' . date('Ymd-His') . '.zip';
        $body = KeeneticExport::zip($files);
        $type = 'application/zip';
    }
} else {
    $body = KeeneticExport::asText($routerId, $includeNull);
    $name = 'keenetic-routes-' . date('Ymd-His') . '.txt';
    $type = 'text/plain; charset=utf-8';
}

// Экспорт — чтение; блокировка/сбой журнала аудита не должны ломать выгрузку.
try {
    AuditLog::record('routes.keenetic_export', $name);
} catch (\Throwable $e) {
    error_log('keenetic-export audit skipped: ' . $e->getMessage());
}

header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($body));
header('X-Content-Type-Options: nosniff');
echo $body;
