<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\SiteDesign;
use App\Modules\ModuleManager;
use App\Site\Site;
use App\Site\TemplateRegistry;

// Платный модуль «Конструктор сайта и кабинета». Без него публичного сайта нет.
if (!ModuleManager::featureActive('site-builder')) {
    header('Location: /login.php');
    exit;
}

/**
 * Публичный сайт-визитка. Отдаёт ОПУБЛИКОВАННЫЙ дизайн на реальных тарифах.
 * Админ-предпросмотр: ?preview=1 (черновик) или ?preview=1&template=<key> для
 * сравнения шаблонов; demo-тарифы подставляются только в preview и помечаются.
 */

$isAdmin = Auth::check();
$preview = $isAdmin && isset($_GET['preview']);
$device = in_array($_GET['device'] ?? '', ['desktop', 'tablet', 'mobile'], true) ? $_GET['device'] : 'desktop';

if ($preview) {
    // В предпросмотре можно форсировать шаблон (для скриншот-сравнения 5 штук).
    $row = SiteDesign::draft() ?: ['template' => 'modern-saas', 'config_json' => '{}'];
    if (isset($_GET['template']) && TemplateRegistry::get((string) $_GET['template'])) {
        $row = ['template' => (string) $_GET['template'], 'config_json' => $row['config_json'] ?? '{}'];
    }
} else {
    $row = SiteDesign::published();
    if (!$row) {
        // Нет опубликованного дизайна — ведём на вход панели.
        header('Location: /login.php');
        exit;
    }
}

// Эту страницу встраивает конструктор в iframe (тот же origin). Глобальный
// X-Frame-Options: DENY (bootstrap) это ломает — разрешаем SAMEORIGIN (кадрировать
// может только тот же origin, для сайта-визитки это безопасно).
header('X-Frame-Options: SAMEORIGIN');

header('Content-Type: text/html; charset=utf-8');

// Предпросмотр кабинета (админ): ?surface=cabinet — рендер с demo-данными.
if ($preview && ($_GET['surface'] ?? '') === 'cabinet') {
    echo Site::cabinet($row, ['demo' => true], $device);
    return;
}

echo Site::website($row, $preview, $device);
