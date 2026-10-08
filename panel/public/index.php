<?php

require __DIR__ . '/../src/bootstrap.php';

// Свежая установка (нет админа) → визуальный мастер установки.
if (!\App\Installer::isInstalled() && is_file(__DIR__ . '/install.php')) {
    header('Location: /install.php');
    exit;
}

// Роутинг по домену: сайт-визитка на site_public_host (напр. domain.ru), панель —
// на своём хосте (panel.domain.ru). Один docroot, nginx направляет оба имени сюда.
$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$siteHost = strtolower(trim((string) \App\Models\Setting::get('site_public_host', '')));
if ($siteHost !== '' && $host === $siteHost && \App\Modules\ModuleManager::featureActive('site-builder')) {
    $row = \App\Models\SiteDesign::published() ?: ['template' => 'modern-saas', 'config_json' => '{}'];
    header('Content-Type: text/html; charset=utf-8');
    echo \App\Site\Site::website($row, false);
    exit;
}

header('Location: ' . (\App\Auth::check() ? '/dashboard.php' : '/login.php'));
