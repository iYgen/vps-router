<?php

namespace App;

/**
 * Design-система панели ("Infrastructure Control Center" redesign).
 * Один общий <style> на все страницы — держим все классы стабильными
 * (button/.secondary/.danger/.card/.badge/.modal/.toast/.field-row/…),
 * чтобы существующий infrastructure.js и остальные public/*.php страницы
 * продолжали работать без изменений разметки/JS-шаблонов. Новые классы
 * (.app-shell/.sidebar/.topbar/.kpi-*) добавлены только для нового shell,
 * ничего из старого не переименовано.
 */
class View
{
    /** Группы навигации: ключ i18n секции => [файл => [i18n-ключ метки, иконка]]. */
    private static array $navGroups = [
        'nav.section.main' => [
            'dashboard.php' => ['label' => 'nav.infra', 'icon' => 'grid'],
            'groups.php' => ['label' => 'nav.routes', 'icon' => 'route'],
            'servers.php' => ['label' => 'nav.exits', 'icon' => 'server'],
            'devices.php' => ['label' => 'nav.devices', 'icon' => 'device'],
            'traffic.php' => ['label' => 'nav.traffic', 'icon' => 'chart', 'feature' => 'traffic-stats'],
            'connections.php' => ['label' => 'nav.connections', 'icon' => 'globe'],
            'billing.php' => ['label' => 'nav.billing', 'icon' => 'billing', 'feature' => 'billing'],
            'site-builder.php' => ['label' => 'nav.site', 'icon' => 'layout', 'feature' => 'site-builder'],
            'client-policy.php' => ['label' => 'nav.keenetic', 'icon' => 'router', 'feature' => 'keenetic-page'],
        ],
        'nav.section.ops' => [
            'logs.php' => ['label' => 'nav.logs', 'icon' => 'terminal'],
            'audit.php' => ['label' => 'nav.audit', 'icon' => 'activity'],
        ],
        'nav.section.system' => [
            'modules.php' => ['label' => 'nav.modules', 'icon' => 'puzzle'],
            'settings.php' => ['label' => 'nav.settings', 'icon' => 'settings'],
        ],
    ];

    private const ICONS = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'route' => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 7.4C11 10 13 14 15.8 16.6"/>',
        'server' => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><circle cx="7" cy="7" r="1"/><circle cx="7" cy="17" r="1"/>',
        'device' => '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>',
        'router' => '<circle cx="12" cy="19" r="1"/><path d="M8 15a5 5 0 0 1 8 0"/><path d="M4.5 11.5a10 10 0 0 1 15 0"/>',
        'activity' => '<path d="M3 12h4l2 7 4-14 2 7h6"/>',
        'chart' => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="7" rx="0.5"/><rect x="12" y="7" width="3" height="11" rx="0.5"/><rect x="17" y="4" width="3" height="14" rx="0.5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/>',
        'billing' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M13 15h4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.96 19a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.14.6.55 1.1 1.11 1.36l.13.05a2 2 0 1 1 0 3.9c-.6.14-1.1.55-1.36 1.11l-.05.13"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'chevron' => '<path d="M9 18l6-6-6-6"/>',
        'puzzle' => '<path d="M10 3h4a1 1 0 0 1 1 1v2a2 2 0 1 0 4 0 1 1 0 0 1 1 1v4h-2a2 2 0 1 0 0 4h2v4a1 1 0 0 1-1 1h-4v-2a2 2 0 1 0-4 0v2H7a1 1 0 0 1-1-1v-4H4a2 2 0 1 0 0-4h2V5a1 1 0 0 1 1-1h3z"/>',
    ];

    private static function icon(string $name, int $size = 18): string
    {
        $paths = self::ICONS[$name] ?? '';
        return "<svg class=\"icon\" width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\">$paths</svg>";
    }

    public static function header(string $title, string $subtitle = '', bool $embed = false): void
    {
        $current = basename($_SERVER['SCRIPT_NAME']);
        ?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($title) ?> — VPS Router</title>
<link href="/assets/fonts/inter.css" rel="stylesheet">
<style>
/* ============================== DESIGN TOKENS ============================== */
:root {
  color-scheme: dark;
  --bg: #17171a;
  --sidebar-bg: #151519;
  --surface: #1d1d22;
  --surface-elevated: #222228;
  --border: rgba(255,255,255,.07);
  --border-hover: rgba(255,255,255,.12);

  --text: #f5f5f7;
  --text-secondary: #a1a1aa;
  --text-muted: #71717a;
  --text-disabled: #52525b;

  --accent: #ff4f87;
  --accent-hover: #ff689a;
  --accent-active: #e8447a;
  --accent-purple: #8b7cff;
  --accent-glow: rgba(255,79,135,.10);

  --success: #35d07f;
  --warning: #f5b942;
  --danger: #ff5c5c;
  --danger-hover: #ff7373;
  --info: #5b9cff;

  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;

  --shadow-modal: 0 24px 80px rgba(0,0,0,.45);
  --shadow-card: 0 4px 20px rgba(0,0,0,.15);
  --shadow-dropdown: 0 8px 24px rgba(0,0,0,.35);

  --font: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;

  --sidebar-w: 254px;
  --sidebar-w-collapsed: 72px;
  --topbar-h: 68px;

  /* --- обратная совместимость со старыми именами, использованными в JS/других страницах --- */
  --fg: var(--text);
  --card: var(--surface);
  --ok: var(--success);
  --muted: var(--text-muted);
}

* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0;
  font-family: var(--font);
  font-size: 14px;
  line-height: 1.5;
  background: var(--bg);
  color: var(--text);
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; }
::selection { background: var(--accent); color: #fff; }
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 8px; border: 2px solid transparent; background-clip: padding-box; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.2); background-clip: padding-box; }

:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }

.icon { flex-shrink: 0; }

/* ============================== APP SHELL ============================== */
.app-shell { display: flex; height: 100vh; overflow: hidden; }

.sidebar {
  width: var(--sidebar-w);
  flex-shrink: 0;
  background: var(--sidebar-bg);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  transition: width 180ms ease;
  overflow: hidden;
}
.sidebar.collapsed { width: var(--sidebar-w-collapsed); }
.sidebar.collapsed .sidebar-label,
.sidebar.collapsed .sidebar-group-title,
.sidebar.collapsed .sidebar-user-info { display: none; }
.sidebar.collapsed .sidebar-item { justify-content: center; }
/* Свёрнутый сайдбар: полный логотип прячем, показываем иконку без текста. */
.sidebar.collapsed .sidebar-brand-logo { display: none; }
.sidebar.collapsed .sidebar-brand-mark-img { display: block; }
.sidebar.collapsed .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }

.sidebar-brand { display: flex; align-items: center; padding: 18px 16px 14px; }
.sidebar-brand-link { display: flex; align-items: center; justify-content: center; text-decoration: none; width: 100%; }
/* Логотип на всю ширину блока шапки. */
.sidebar-brand-logo { width: 100%; height: auto; display: block; }
.sidebar-brand-mark-img { display: none; width: 30px; height: 30px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 12px; }
.sidebar-group { margin-bottom: 18px; }
.sidebar-group-title { font-size: 10.5px; font-weight: 600; letter-spacing: .08em; color: var(--text-disabled); padding: 0 10px; margin-bottom: 6px; white-space: nowrap; }
.sidebar-item {
  display: flex; align-items: center; gap: 11px;
  padding: 8px 10px; margin-bottom: 2px;
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  text-decoration: none; font-size: 13.5px; font-weight: 500;
  position: relative; white-space: nowrap;
  transition: background 120ms ease, color 120ms ease;
}
.sidebar-item .icon { color: var(--text-muted); transition: color 120ms ease; }
.sidebar-item:hover { background: rgba(255,255,255,.04); color: var(--text); }
.sidebar-item:hover .icon { color: var(--text-secondary); }
.sidebar-item.active { background: rgba(255,255,255,.05); color: var(--text); }
.sidebar-item.active .icon { color: var(--accent); }
.sidebar-item.active::before {
  content: ''; position: absolute; left: -12px; top: 50%; transform: translateY(-50%);
  width: 3px; height: 16px; border-radius: 0 3px 3px 0; background: var(--accent);
}

.sidebar-footer { border-top: 1px solid var(--border); padding: 12px; }
.sidebar-copyright { font-size: 10.5px; color: var(--text-muted); text-align: center; padding: 8px 4px 2px; opacity: .7; letter-spacing: .3px; }
.sidebar-donate { display: block; text-align: center; font-size: 11.5px; margin: 2px 8px 0; padding: 6px 8px; border-radius: 8px; color: var(--text-muted); text-decoration: none; border: 1px solid var(--border); transition: color .15s, border-color .15s; }
.sidebar-donate:hover { color: var(--accent); border-color: var(--accent); }
.sidebar-status { display: flex; align-items: center; gap: 8px; padding: 6px 10px; font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.sidebar-status .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--success); box-shadow: 0 0 0 3px rgba(53,208,127,.15); }
.sidebar-user { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-radius: var(--radius-sm); }
.sidebar-user-link { display: flex; align-items: center; gap: 10px; flex: 1; padding: 4px; border-radius: var(--radius-sm); text-decoration: none; color: inherit; min-width: 0; }
.sidebar-user-link:hover { background: rgba(255,255,255,.05); }
.sidebar-user-info span.muted { font-size: 11px; color: var(--text-muted); }
.sidebar-logout { display: flex; align-items: center; justify-content: center; color: var(--text-muted); text-decoration: none; padding: 6px; border-radius: 6px; flex-shrink: 0; margin-left: 4px; }
.sidebar-logout:hover { background: rgba(255,92,92,.12); color: var(--danger); }
.sidebar.collapsed .sidebar-logout { display: none; }
.sidebar.collapsed .sidebar-lang { display: none; }
.lang-opt { font-size: 11px; font-weight: 600; letter-spacing: .03em; color: var(--text-muted); text-decoration: none; padding: 2px 7px; border-radius: 6px; border: 1px solid transparent; }
.lang-opt:hover { color: var(--text); background: rgba(255,255,255,.05); }
.lang-opt.active { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 40%, transparent); }
.sidebar-avatar {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  background: var(--surface-elevated); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600; color: var(--text-secondary);
}
.sidebar-user-info { overflow: hidden; white-space: nowrap; flex: 1; }
.sidebar-user-info strong { display: block; font-size: 12.5px; }
.sidebar-user-info a { font-size: 11.5px; color: var(--text-muted); text-decoration: none; }
.sidebar-user-info a:hover { color: var(--danger); }
.sidebar-toggle { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; flex-shrink: 0; }
.sidebar-toggle:hover { background: rgba(255,255,255,.06); color: var(--text); }

.main-column { flex: 1; min-width: 0; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

.topbar {
  height: var(--topbar-h); flex-shrink: 0;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 24px; border-bottom: 1px solid var(--border);
  background: var(--bg);
}
.topbar-title { font-size: 16px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.topbar-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; font-weight: 400; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.router-scope-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding: 8px 12px; background: color-mix(in srgb, var(--accent) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border)); border-radius: var(--radius-sm); font-size: 13px; }
.router-scope-bar .rsb-label { color: var(--text-secondary); }
.router-scope-bar select { background: var(--surface-elevated); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 5px 8px; font-size: 13px; max-width: 240px; font-weight: 600; }
.topbar-burger { display: none; margin-right: 6px; flex-shrink: 0; color: var(--text-secondary); }
.sidebar-backdrop { display: none; }

.page { flex: 1; overflow-y: auto; padding: 24px 28px 48px; }
.page-fullbleed { flex: 1; overflow: hidden; padding: 0; display: flex; flex-direction: column; }

/* ============================== MOBILE / RESPONSIVE ============================== */
@media (max-width: 820px) {
  .app-shell { height: auto; min-height: 100vh; overflow: visible; }
  /* Боковое меню уезжает за экран, выезжает по бургеру. */
  .sidebar {
    position: fixed; top: 0; bottom: 0; left: 0; z-index: 120;
    transform: translateX(-100%); transition: transform 200ms ease;
    box-shadow: var(--shadow-modal);
    padding-top: env(safe-area-inset-top, 0px);
  }
  .app-shell.sidebar-open .sidebar { transform: translateX(0); }
  .sidebar.collapsed { width: var(--sidebar-w); } /* на мобиле не сжимаем, а прячем */
  .sidebar-toggle { display: none; } /* сворачивание не нужно — есть бургер */
  .sidebar-backdrop {
    display: block; position: fixed; inset: 0; z-index: 110;
    background: rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity 200ms ease;
  }
  .app-shell.sidebar-open .sidebar-backdrop { opacity: 1; pointer-events: auto; }

  .main-column { height: auto; min-height: 100vh; overflow: visible; }
  .topbar-burger { display: inline-flex; }
  .topbar { height: auto; min-height: 56px; padding: 10px 14px; position: sticky; top: 0; z-index: 40;
    padding-top: calc(10px + env(safe-area-inset-top, 0px)); }
  .topbar-subtitle { display: none; }
  .topbar-title { font-size: 15px; }

  .page { padding: 16px 14px 60px; overflow-y: visible; }
  /* Статистика сверху — слайдер со свайпом/стрелками влево-вправо. */
  .kpi-slider { padding: 12px 14px 0; }
  .kpi-grid { gap: 8px; }
  .kpi-card { flex: 0 0 auto; min-width: 150px; padding: 12px 14px; }
  .kpi-value { font-size: 20px; }
  .kpi-nav { top: 12px; width: 30px; font-size: 18px; }
  .kpi-prev { left: 16px; } .kpi-next { right: 16px; }

  /* Граф: холст фиксированной высоты, тулбар прокручивается по горизонтали. */
  .page-fullbleed { overflow: visible; }
  .infra-layout { flex-direction: column; min-height: 0; }
  #infra-canvas-wrap { height: 62vh; min-height: 340px; flex: none; }
  #infra-canvas { height: 100%; min-height: 0; }
  .infra-toolbar { overflow-x: auto; flex-wrap: nowrap; padding: 10px 14px; -webkit-overflow-scrolling: touch; }
  .infra-toolbar button, .infra-toolbar .btn { flex-shrink: 0; }

  /* Инспекторы графа/маршрутов — как нижняя «шторка» с «ручкой» и прокруткой. */
  #infra-inspector, #routes-inspector {
    position: fixed !important; inset: auto 0 0 0 !important; width: auto !important; max-width: none !important;
    height: auto; max-height: 82vh; overflow-y: auto; -webkit-overflow-scrolling: touch;
    border: none; border-top: 1px solid var(--border);
    border-radius: 16px 16px 0 0; box-shadow: var(--shadow-modal); z-index: 130;
    padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 12px);
  }
  /* «Ручка» вверху шторки. */
  #infra-inspector::before, #routes-inspector::before {
    content: ''; position: sticky; top: 0; display: block;
    width: 40px; height: 4px; margin: 8px auto 2px; border-radius: 3px; background: var(--border-hover);
  }
  #infra-inspector .inspector-header, #routes-inspector .inspector-header {
    position: sticky; top: 10px; background: var(--surface); z-index: 2;
  }
  #infra-inspector .inspector-tabs { position: sticky; top: 62px; background: var(--surface); z-index: 1; }
  .inspector-header strong { font-size: 14px; overflow: hidden; text-overflow: ellipsis; }
  .inspector-body { padding: 14px; }
  .inspector-body .row { flex-wrap: wrap; }
  .inspector-body button, .inspector-body .btn { flex: 1 1 auto; }
  .routes-layout { flex-direction: column; }

  /* Таблицы данных (не в форме) — горизонтальная прокрутка вместо разъезда. */
  .card { padding: 14px 14px; }
  .card table { display: block; overflow-x: auto; white-space: nowrap; }
  /* Таблицы-формы (label/поле) — складываются в столбик, поля на всю ширину. */
  form table, form table tbody, form table tr { display: block; width: 100%; white-space: normal; overflow: visible; }
  form table td { display: block; width: 100%; padding: 2px 0; border: none; }
  form table td:first-child { color: var(--text-muted); font-size: 12.5px; padding-top: 12px; }
  form table input, form table select, form table textarea { width: 100%; }

  /* Модалки почти во весь экран. */
  .modal { width: 100%; max-width: 100%; max-height: 92vh; padding: 20px 16px; border-radius: var(--radius-lg); }
  .modal-overlay { padding: 8px; align-items: flex-end; }

  /* Формы: поля в строку переносятся и тянутся на всю ширину. */
  .row { flex-wrap: wrap; }
  input, select, textarea, .field-row { max-width: 100%; }

  /* Мастер добавления сервера на весь экран, левая колонка шагов скрыта. */
  .wizard-nav { display: none; }
  .wizard-role-grid { grid-template-columns: 1fr 1fr; }
  .wizard-review-grid { grid-template-columns: 1fr; }

  .pending-banner { left: 12px; right: 12px; bottom: 12px; transform: none; justify-content: space-between; }
}
@media (max-width: 460px) {
  .wizard-role-grid { grid-template-columns: 1fr; }
  .settings-tabs { gap: 0; }
  .settings-tab { padding: 9px 10px; font-size: 13px; }
}

/* ============================== KPI ============================== */
/* Слайдер: горизонтальная лента плашек со стрелками ‹ › по краям. Стрелки
   появляются (через JS) только когда карточки не влезают — и на десктопе при
   узком окне, и на мобильном; лента скроллится порциями, а не переносится/сжимается. */
.kpi-slider { position: relative; margin-bottom: 20px; padding: 16px 20px 0; }
.kpi-grid { display: flex; flex-wrap: nowrap; gap: 12px;
  overflow-x: auto; -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity;
  scrollbar-width: none; scroll-behavior: smooth; }
.kpi-grid::-webkit-scrollbar { display: none; }
.kpi-nav { position: absolute; top: 16px; bottom: 0; width: 34px; z-index: 5;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
  border: 1px solid var(--border); border-radius: var(--radius-md);
  background: var(--surface); color: var(--text); font-size: 20px; line-height: 1;
  box-shadow: 0 2px 10px rgba(0,0,0,.18); transition: opacity .15s, background .15s; }
.kpi-nav:hover { background: var(--surface-elevated); color: var(--accent); }
.kpi-nav:disabled { opacity: .35; cursor: default; }
.kpi-nav[hidden] { display: none; }
.kpi-prev { left: 24px; }
.kpi-next { right: 24px; }
.kpi-card { flex: 1 0 160px; min-width: 160px; scroll-snap-align: start;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; }
.kpi-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; }
.kpi-value { font-size: 26px; font-weight: 700; letter-spacing: -.01em; }
.kpi-meta { font-size: 12px; color: var(--text-secondary); margin-top: 4px; display: flex; align-items: center; gap: 5px; }

/* ============================== TYPOGRAPHY ============================== */
h1, h2, h3, h4 { font-weight: 600; letter-spacing: -.01em; margin: 0 0 4px; }
.page h2:first-child, .page h3:first-child { margin-top: 0; }
.muted { color: var(--text-muted); font-size: 13px; }
.text-secondary { color: var(--text-secondary); }
.section-heading { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
.section-desc { font-size: 12.5px; color: var(--text-muted); margin-bottom: 14px; }

/* ============================== BUTTONS ============================== */
button, .btn {
  font-family: var(--font); font-size: 13.5px; font-weight: 500;
  padding: 8px 14px; border-radius: var(--radius-sm);
  border: 1px solid transparent;
  background: var(--accent); color: #fff;
  cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
  transition: background 120ms ease, border-color 120ms ease, transform 80ms ease, opacity 120ms ease;
  white-space: nowrap;
}
button:hover, .btn:hover { background: var(--accent-hover); }
button:active, .btn:active { background: var(--accent-active); transform: translateY(1px); }
button:disabled, .btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
button.secondary, .btn.secondary {
  background: rgba(255,255,255,.06); color: var(--text-secondary);
  border-color: var(--border);
}
button.secondary:hover, .btn.secondary:hover { background: rgba(255,255,255,.09); color: var(--text); border-color: var(--border-hover); }
button.ghost, .btn.ghost { background: transparent; color: var(--text-secondary); border-color: transparent; }
button.ghost:hover, .btn.ghost:hover { background: rgba(255,255,255,.05); color: var(--text); }
button.danger, .btn.danger { background: var(--danger); color: #fff; }
button.danger:hover, .btn.danger:hover { background: var(--danger-hover); }
button.icon-btn { padding: 7px; }

/* ============================== INPUTS / SELECT ============================== */
input, select, textarea {
  font-family: var(--font); font-size: 13.5px;
  padding: 8px 12px; border-radius: var(--radius-sm);
  background: #18181c; border: 1px solid var(--border);
  color: var(--text); width: auto;
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
input::placeholder, textarea::placeholder { color: var(--text-muted); }
input:hover, select:hover, textarea:hover { border-color: var(--border-hover); }
input:focus, select:focus, textarea:focus {
  outline: none; border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-glow);
}
textarea { font-family: var(--font); resize: vertical; }
select { appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px;
}

/* ============================== BADGES ============================== */
.badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.badge.ok { background: color-mix(in srgb, var(--success) 15%, transparent); color: var(--success); }
.badge.down { background: color-mix(in srgb, var(--danger) 15%, transparent); color: var(--danger); }
.badge.warn { background: color-mix(in srgb, var(--warning) 15%, transparent); color: var(--warning); }
.badge.unknown { background: color-mix(in srgb, var(--text-muted) 18%, transparent); color: var(--text-secondary); }
.badge.maintenance { background: color-mix(in srgb, var(--info) 15%, transparent); color: var(--info); }

/* ============================== CARDS ============================== */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 20px 22px; margin-bottom: 16px; box-shadow: var(--shadow-card); }
.card h2 { font-size: 15px; }

/* ============================== TABLES ============================== */
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 11px 10px; font-size: 13.5px; }
th { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); }
td { border-bottom: 1px solid var(--border); }
tbody tr { transition: background 100ms ease; }
tbody tr:hover { background: rgba(255,255,255,.025); }
tbody tr:last-child td { border-bottom: none; }

/* ============================== FORM STRUCTURE ============================== */
.field-row { margin-bottom: 12px; }
.field-row label { display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 5px; font-weight: 500; }
.field-row input, .field-row select, .field-row textarea { width: 100%; }
.field-row textarea { min-height: 90px; font-family: ui-monospace, 'SF Mono', Consolas, monospace; font-size: 12px; }
form.inline { display: inline; }
.row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ============================== FLASH / ISSUES ============================== */
.flash { padding: 11px 15px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13.5px; border: 1px solid transparent; }
.flash.error { background: color-mix(in srgb, var(--danger) 12%, transparent); border-color: color-mix(in srgb, var(--danger) 30%, transparent); color: #ffb3b3; }
.flash.success { background: color-mix(in srgb, var(--success) 12%, transparent); border-color: color-mix(in srgb, var(--success) 30%, transparent); color: #9fe8c3; }
.issue-list { list-style: none; padding: 0; margin: 0 0 12px; }
.issue-list li { padding: 7px 11px; border-radius: var(--radius-sm); margin-bottom: 4px; font-size: 13px; }
.issue-list li.error { background: color-mix(in srgb, var(--danger) 12%, transparent); }
.issue-list li.warning { background: color-mix(in srgb, var(--warning) 12%, transparent); }
.diff-list { list-style: none; padding: 0; margin: 0; font-family: ui-monospace, monospace; font-size: 13px; }
.diff-list li { padding: 4px 0; }
.diff-list li.add { color: var(--success); }
.diff-list li.remove { color: var(--danger); }
.diff-list li.change { color: var(--warning); }
.provision-log pre { max-height: 200px; overflow: auto; white-space: pre-wrap; font-size: 12px; background: rgba(255,255,255,.04); padding: 10px; border-radius: var(--radius-sm); }

/* ============================== INFRASTRUCTURE GRAPH ============================== */
.infra-layout { display: flex; flex: 1; min-height: 0; }
.infra-toolbar { display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.infra-toolbar .spacer { flex: 1; }
/* Кнопки тулбара: иконка + подпись; подпись прячется на узких экранах. */
.infra-toolbar button, .infra-toolbar .btn, .infra-toolbar .tb-menu-btn { display: inline-flex; align-items: center; gap: 7px; }
.infra-toolbar .ic { font-size: 15px; line-height: 1; }
/* Выпадающее меню «Ещё». */
.tb-menu { position: relative; display: inline-flex; }
.tb-dropdown { position: absolute; top: calc(100% + 6px); left: 0; z-index: 60; min-width: 210px;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md);
  box-shadow: 0 10px 34px rgba(0,0,0,.28); padding: 6px; display: flex; flex-direction: column; gap: 2px; }
.tb-dropdown[hidden] { display: none; }
.tb-dropdown > button, .tb-dropdown > a { display: flex; align-items: center; gap: 9px; width: 100%;
  text-align: left; background: none; border: 0; color: var(--text); padding: 9px 11px; border-radius: 7px;
  font-size: 13px; cursor: pointer; text-decoration: none; }
.tb-dropdown > button:hover, .tb-dropdown > a:hover { background: var(--surface-elevated); color: var(--accent); }
.tb-dropdown .ic { font-size: 15px; width: 18px; text-align: center; }
@media (max-width: 720px) {
  /* В мобильной версии — только иконки, без подписей (меню «Ещё» сохраняет подписи пунктов). */
  .infra-toolbar > button .lbl, .infra-toolbar .tb-menu-btn .lbl, .infra-toolbar > .btn .lbl { display: none; }
  .infra-toolbar > button, .infra-toolbar .tb-menu-btn, .infra-toolbar > .btn { padding-left: 11px; padding-right: 11px; }
  .infra-toolbar .ic { font-size: 17px; }
}
#infra-canvas-wrap { position: relative; flex: 1; min-width: 0; min-height: 0; display: flex; }
/* Неблокирующий оверлей загрузки поверх области графа (кнопки тулбара кликабельны:
   тулбар вне обёртки, а сам оверлей — pointer-events:none). */
.graph-loading {
  position: absolute; inset: 0; z-index: 45; display: flex; align-items: center; justify-content: center;
  background: rgba(21,21,25,.45); pointer-events: none;
}
.graph-loading.hidden { display: none; }
.graph-loading-card {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  background: var(--surface-elevated); border: 1px solid var(--border); border-radius: var(--radius-lg);
  padding: 18px 26px; box-shadow: var(--shadow-dropdown);
}
.graph-spinner {
  width: 34px; height: 34px; border-radius: 50%;
  border: 3px solid var(--border); border-top-color: var(--accent); animation: gl-spin .8s linear infinite;
}
@keyframes gl-spin { to { transform: rotate(360deg); } }
.graph-loading-pct { font-weight: 700; font-size: 15px; }
.graph-loading-label { color: var(--text-secondary); font-size: 12px; }
#infra-canvas {
  flex: 1; min-width: 0; position: relative;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 28px 28px;
  background-color: var(--bg);
}

.graph-selection-bar {
  position: absolute; top: 14px; left: 50%; transform: translateX(-50%); z-index: 40;
  background: var(--surface-elevated); border: 1px solid var(--border); border-radius: 999px;
  padding: 8px 10px 8px 16px; display: flex; align-items: center; gap: 10px;
  box-shadow: var(--shadow-dropdown); font-size: 13px;
}
.graph-selection-bar #graph-selection-count { color: var(--accent); }

#infra-inspector { width: 400px; max-width: 40vw; border-left: 1px solid var(--border); background: var(--surface); overflow-y: auto; flex-shrink: 0; animation: drawer-in 180ms ease-out; }
#infra-inspector.hidden { display: none; }
@keyframes drawer-in { from { transform: translateX(16px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@media (max-width: 860px) {
  /* Эти правила идут ПОСЛЕ базовых — поэтому именно они «побеждают» на мобиле
     (медиа-запрос не добавляет специфичности, решает порядок в источнике).
     Делаем раскладку графа блочной с фиксированной высотой, иначе flex-цепочка
     при height:auto у main-column схлопывала холст в 0 → граф не было видно. */
  .infra-layout { display: block; }
  #infra-canvas-wrap { display: block; position: relative; flex: none; width: 100%; height: 60vh; min-height: 360px; }
  #infra-canvas { display: block; height: 100%; min-height: 0; width: 100%; }
  #infra-inspector { width: auto; max-width: none; position: fixed; inset: auto 0 0 0; max-height: 75vh; border-left: none; border-top: 1px solid var(--border); border-radius: var(--radius-lg) var(--radius-lg) 0 0; box-shadow: var(--shadow-modal); z-index: 60; }
}

/* Полноэкранное окно «Подробнее» (инспектор сервера): иконочная навигация слева. */
.detail-overlay { position: fixed; inset: 0; z-index: 1100; background: rgba(0,0,0,.55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 24px; }
.detail-shell { width: 100%; height: 100%; max-width: 1200px; max-height: 92vh; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow-modal); display: flex; overflow: hidden; }
.detail-sidebar { width: 25%; min-width: 210px; max-width: 300px; background: var(--surface-elevated, #1d1d22); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 16px 12px; }
.detail-title { font-weight: 700; font-size: 15px; padding: 4px 10px 14px; word-break: break-word; }
.detail-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.detail-nav button { display: flex; align-items: center; gap: 10px; background: none; border: none; color: var(--text-secondary); padding: 10px 12px; border-radius: 9px; cursor: pointer; font: inherit; font-size: 14px; text-align: left; }
.detail-nav button .di { font-size: 17px; width: 22px; text-align: center; }
.detail-nav button:hover { background: rgba(255,255,255,.05); color: var(--text); }
.detail-nav button.active { background: var(--accent); color: #fff; }
.detail-close { margin-top: 10px; background: var(--surface2, #26262c); color: var(--text); border: none; border-radius: 9px; padding: 9px; cursor: pointer; font: inherit; }
.detail-content { flex: 1; overflow-y: auto; padding: 22px 26px; min-width: 0; }
@media (max-width: 760px) {
  .detail-overlay { padding: 0; }
  .detail-shell { max-width: none; max-height: none; height: 100%; border-radius: 0; flex-direction: column; }
  .detail-sidebar { width: auto; max-width: none; min-width: 0; flex-direction: row; overflow-x: auto; padding: 10px; gap: 6px; align-items: center; }
  .detail-title { display: none; }
  .detail-nav { flex-direction: row; gap: 6px; flex: 1; }
  .detail-nav button .dl { display: none; }
  .detail-close { margin: 0 0 0 auto; white-space: nowrap; }
  .detail-content { padding: 16px 14px; }
}

/* ============================== CLIENT POLICY (Keenetic) ============================== */
.cp-tabs { display: flex; gap: 4px; padding: 0 20px; border-bottom: 1px solid var(--border); }
.cp-tab { background: none; border: none; color: var(--text-muted); padding: 10px 14px; font-size: 13.5px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; }
.cp-tab:hover { color: var(--text-secondary); }
.cp-tab.active { color: var(--text); border-bottom-color: var(--accent); }
.cp-panel { padding: 16px 20px; }
.cp-panel.hidden { display: none; }
.cp-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 14px 16px; margin-bottom: 10px; cursor: pointer; }
.cp-card:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--border)); }
.cp-card-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.cp-card-meta { color: var(--text-muted); font-size: 12.5px; margin-top: 4px; }

/* ============================== ROUTES TREE ============================== */
.routes-layout { display: flex; flex: 1; min-height: 0; }
.routes-toolbar { display: flex; align-items: center; gap: 10px; padding: 12px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.routes-toolbar .spacer { flex: 1; }
.routes-toolbar input[type="search"] { min-width: 240px; }
.routes-tree-pane { flex: 1; min-width: 0; overflow-y: auto; padding: 10px 8px 40px; }
.routes-tree-pane .tree-section-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); text-transform: uppercase; padding: 8px 10px 6px; }

.tree-row {
  display: flex; align-items: center; gap: 6px; padding: 6px 8px 6px 4px;
  border-radius: var(--radius-sm); border-left: 2px solid transparent; cursor: pointer;
  user-select: none; position: relative;
}
.tree-row:hover { background: rgba(255,255,255,.035); }
.tree-row.selected { background: rgba(255,255,255,.05); border-left-color: var(--accent); }
.tree-row.multi-selected { background: color-mix(in srgb, var(--accent) 10%, transparent); }
.tree-row.dragover { outline: 1px dashed var(--accent); outline-offset: -1px; }
.tree-row.disabled-row .tree-label { color: var(--text-muted); }
.tree-row.search-hidden { display: none; }
.tree-row mark { background: color-mix(in srgb, var(--warning) 45%, transparent); color: inherit; border-radius: 3px; padding: 0 1px; }

.tree-indent { flex-shrink: 0; }
.tree-checkbox-slot { width: 18px; flex-shrink: 0; display: flex; align-items: center; opacity: 0; transition: opacity 100ms ease; }
.tree-row:hover .tree-checkbox-slot, .tree-row.multi-selected .tree-checkbox-slot, .routes-tree-pane.multiselect-active .tree-checkbox-slot { opacity: 1; }
.tree-toggle {
  width: 18px; height: 18px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  color: var(--text-muted); border-radius: 4px; background: none; border: none; cursor: pointer; padding: 0;
}
.tree-toggle:hover { background: rgba(255,255,255,.08); color: var(--text); }
.tree-toggle { transition: transform 120ms ease; }
.tree-toggle.spacer-only { visibility: hidden; }

.tree-icon { flex-shrink: 0; display: flex; align-items: center; color: var(--text-muted); }
.tree-icon.folder { color: var(--accent-purple); }
.tree-icon.imported { color: var(--info); }
.tree-icon.route { color: var(--text-secondary); }
.tree-icon.error { color: var(--warning); }
.tree-icon.muted { color: var(--text-disabled) !important; }

.tree-empty-hint { display: flex; align-items: center; gap: 8px; padding: 6px 8px; }
.tree-empty-hint button { font-size: 12px; padding: 4px 9px; }

.tree-label { font-size: 13.5px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tree-meta { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-shrink: 0; padding-left: 10px; }
.tree-badge { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.tree-badge.proxy { color: var(--accent); }
.tree-count { font-size: 11.5px; color: var(--text-disabled); min-width: 18px; text-align: right; }

.inline-create-row { display: flex; align-items: center; gap: 6px; padding: 4px 8px; }
.inline-create-row input { flex: 1; padding: 5px 8px; font-size: 13px; }

.bulk-toolbar {
  position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 10px;
  background: var(--surface-elevated); border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: 8px 12px; margin: 0 4px 8px; box-shadow: var(--shadow-dropdown);
}
.bulk-toolbar strong { font-size: 13px; }
.bulk-toolbar .spacer { flex: 1; }

#routes-inspector { width: 380px; max-width: 40vw; border-left: 1px solid var(--border); background: var(--surface); overflow-y: auto; flex-shrink: 0; animation: drawer-in 180ms ease-out; }
#routes-inspector.hidden { display: none; }
.inspector-rule-list { list-style: none; margin: 0 0 10px; padding: 0; }
.inspector-rule-list li { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.inspector-rule-list li:last-child { border-bottom: none; }
.inspector-rule-list .rule-type { font-size: 10.5px; text-transform: uppercase; color: var(--text-muted); letter-spacing: .04em; }

@media (max-width: 860px) {
  .routes-layout { flex-direction: column; }
  #routes-inspector { width: auto; max-width: none; position: fixed; inset: auto 0 0 0; max-height: 75vh; border-left: none; border-top: 1px solid var(--border); border-radius: var(--radius-lg) var(--radius-lg) 0 0; box-shadow: var(--shadow-modal); z-index: 60; }
}

.inspector-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--border); }
.inspector-header strong { font-size: 14.5px; font-weight: 600; }
.inspector-tabs { display: flex; gap: 2px; padding: 8px 14px 0; border-bottom: 1px solid var(--border); overflow-x: auto; }
.inspector-tabs button { background: none; border: none; color: var(--text-muted); padding: 8px 12px; border-bottom: 2px solid transparent; border-radius: 0; cursor: pointer; font-size: 12.5px; font-weight: 500; white-space: nowrap; }
.inspector-tabs button:hover { color: var(--text-secondary); background: none; }
.inspector-tabs button.active { color: var(--text); border-bottom-color: var(--accent); font-weight: 600; }
.inspector-body { padding: 18px; }

/* ============================== MODAL ============================== */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 16px; animation: overlay-in 150ms ease-out; }
.modal-overlay.hidden { display: none; }
@keyframes overlay-in { from { opacity: 0; } to { opacity: 1; } }
.modal { background: var(--surface-elevated); border-radius: var(--radius-lg); border: 1px solid var(--border); width: 520px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: var(--shadow-modal); animation: modal-in 180ms cubic-bezier(.2,.8,.2,1); }
@keyframes modal-in { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal.modal-wide { width: 760px; }
.modal h3 { margin-top: 0; font-size: 16.5px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }

/* ============================== TOAST ============================== */
.toast-stack { position: fixed; bottom: 20px; right: 20px; z-index: 1300; display: flex; flex-direction: column; gap: 8px; max-width: 360px; }
.toast { padding: 12px 16px; border-radius: var(--radius-sm); background: var(--surface-elevated); border: 1px solid var(--border); box-shadow: var(--shadow-dropdown); font-size: 13.5px; animation: toast-in 180ms ease-out; border-left: 3px solid var(--text-muted); }
.toast.success { border-left-color: var(--success); }
.toast.error { border-left-color: var(--danger); }
@keyframes toast-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

/* ============================== RISK BANNER ============================== */
.security-banner { border-radius: var(--radius-lg); padding: 12px 16px; margin-bottom: 16px; font-size: 13.5px; }
.security-banner.level-error { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--danger) 40%, var(--border)); }
.security-banner.level-warn { background: color-mix(in srgb, var(--warning, #f5b942) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--warning, #f5b942) 40%, var(--border)); }
.security-banner { position: relative; }
.security-banner .sb-dismiss { position: absolute; top: 8px; right: 8px; width: 26px; height: 26px; padding: 0; font-size: 13px; color: var(--text-muted); }
.security-banner .sb-head { font-weight: 700; margin-bottom: 6px; padding-right: 28px; }
.security-banner ul { margin: 0 0 6px; padding-left: 20px; }
.security-banner li { margin: 2px 0; }
.security-banner a { color: var(--accent); font-weight: 600; text-decoration: none; }
.risk-banner { background: color-mix(in srgb, var(--danger) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--danger) 35%, var(--border)); border-radius: var(--radius-lg); padding: 10px 14px; font-size: 13px; }
.risk-banner.hidden { display: none; }
.risk-banner a { color: var(--danger); font-weight: 600; }
.export-menu { position: relative; display: inline-block; }
.export-dropdown { position: absolute; right: 0; top: calc(100% + 6px); z-index: 50; min-width: 240px;
  background: var(--surface-elevated); border: 1px solid var(--border); border-radius: var(--radius-lg);
  box-shadow: var(--shadow-dropdown); padding: 6px; display: flex; flex-direction: column; }
.export-dropdown.hidden { display: none; }
.export-dropdown a { display: block; padding: 9px 12px; border-radius: 8px; color: var(--text); text-decoration: none; font-size: 14px; white-space: nowrap; }
.export-dropdown a:hover { background: var(--surface2, rgba(255,255,255,.06)); }
.export-dropdown .export-manage { border-top: 1px solid var(--border); margin-top: 4px; padding-top: 10px; color: var(--text-secondary); font-size: 13px; }
.update-banner { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border)); border-radius: var(--radius-lg); padding: 10px 14px; font-size: 13px; }
.update-banner.hidden { display: none; }
.update-banner a { color: var(--accent); font-weight: 600; }

/* ============================== PENDING BANNER ============================== */
.pending-banner { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--surface-elevated); border: 1px solid var(--border); color: var(--text); padding: 10px 12px 10px 18px; border-radius: 999px; box-shadow: var(--shadow-modal); display: flex; align-items: center; gap: 12px; z-index: 90; font-size: 13px; }
.pending-banner.hidden { display: none; }
.pending-banner button { background: var(--accent); color: #fff; }
.pending-banner #pending-count { color: var(--accent); font-weight: 700; }

/* ============================== CONTEXT MENU / DROPDOWN ============================== */
.context-menu { position: fixed; background: var(--surface-elevated); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow-dropdown); z-index: 1250; min-width: 190px; overflow: hidden; padding: 4px; animation: dropdown-in 120ms ease-out; }
@keyframes dropdown-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.context-menu button { display: block; width: 100%; text-align: left; padding: 8px 12px; background: none; border: none; color: var(--text); border-radius: 6px; font-size: 13px; font-weight: 400; }
.context-menu button:hover { background: rgba(255,255,255,.06); }
.context-menu button.danger { color: var(--danger); }
.context-menu hidden, .hidden { display: none !important; }

/* ============================== EMPTY STATE ============================== */
.empty-state { text-align: center; padding: 64px 20px; color: var(--text-muted); }
.empty-state-icon { width: 52px; height: 52px; border-radius: 14px; background: var(--surface); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; color: var(--text-muted); }
.empty-state h3 { color: var(--text); font-size: 15px; margin-bottom: 6px; }

/* ============================== SKELETON ============================== */
.skeleton { background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 37%, rgba(255,255,255,.04) 63%); background-size: 400% 100%; animation: skeleton-shine 1.4s ease infinite; border-radius: var(--radius-sm); }
@keyframes skeleton-shine { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

/* ============================== CHECKBOX / SWITCH ============================== */
.vr-checkbox { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px; user-select: none; }
.vr-checkbox input { position: absolute; opacity: 0; width: 18px; height: 18px; margin: 0; cursor: pointer; }
.vr-checkbox .box { width: 18px; height: 18px; border-radius: 5px; border: 1px solid var(--border-hover); background: #18181c; display: flex; align-items: center; justify-content: center; transition: all 120ms ease; flex-shrink: 0; }
.vr-checkbox input:checked + .box { background: var(--accent); border-color: var(--accent); }
.vr-checkbox input:checked + .box::after { content: ''; width: 5px; height: 9px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg) translate(-1px, -1px); }
.vr-checkbox input:focus-visible + .box { outline: 2px solid var(--accent); outline-offset: 2px; }

.vr-switch { position: relative; display: inline-flex; align-items: center; width: 38px; height: 22px; cursor: pointer; }
.vr-switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.vr-switch .track { position: absolute; inset: 0; background: rgba(255,255,255,.12); border-radius: 999px; transition: background 150ms ease; }
.vr-switch .thumb { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 150ms ease; }
.vr-switch input:checked ~ .track { background: var(--accent); }
.vr-switch input:checked ~ .thumb { transform: translateX(16px); }

/* ============================== LOGIN ============================== */
.auth-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; background: var(--sidebar-bg); }
.auth-shell::before, .auth-shell::after { content: ''; position: absolute; width: 60vw; height: 60vw; border-radius: 50%; filter: blur(80px); pointer-events: none; }
.auth-shell::before { top: -20%; right: -10%; background: radial-gradient(circle, rgba(139,124,255,.10), transparent 60%); }
.auth-shell::after { bottom: -20%; left: -10%; background: radial-gradient(circle, rgba(255,79,135,.07), transparent 60%); }
.auth-card { position: relative; z-index: 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 32px 30px; width: 320px; box-shadow: var(--shadow-modal); }
.auth-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 22px; }

/* ============================== FULLSCREEN WIZARD ============================== */
.wizard-overlay {
  position: fixed; inset: 0; z-index: 300; display: none; flex-direction: column;
  background: var(--sidebar-bg);
  background-image:
    radial-gradient(circle at 80% 10%, rgba(139,124,255,.08), transparent 40%),
    radial-gradient(circle at 10% 90%, rgba(255,79,135,.05), transparent 40%);
}
.wizard-overlay.open { display: flex; animation: overlay-in 180ms ease-out; }
.wizard-header { height: 72px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid var(--border); }
.wizard-brand { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600; }
.wizard-brand-mark { width: 26px; height: 26px; border-radius: 8px; background: linear-gradient(135deg, var(--accent), var(--accent-purple)); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; }
.wizard-body { flex: 1; min-height: 0; display: flex; overflow: hidden; }
.wizard-nav { width: 280px; flex-shrink: 0; padding: 28px 16px; overflow-y: auto; border-right: 1px solid var(--border); }
.wizard-step-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--radius-sm); margin-bottom: 2px; cursor: default; opacity: .45; transition: opacity 150ms ease, background 150ms ease; }
.wizard-step-item.done { opacity: .85; cursor: pointer; }
.wizard-step-item.done:hover { background: rgba(255,255,255,.04); }
.wizard-step-item.active { opacity: 1; background: rgba(255,255,255,.05); border-left: 2px solid var(--accent); padding-left: 10px; }
.wizard-step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--surface-elevated); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: var(--text-secondary); flex-shrink: 0; }
.wizard-step-item.active .wizard-step-num { background: var(--accent); border-color: var(--accent); color: #fff; }
.wizard-step-item.done .wizard-step-num { color: var(--success); }
.wizard-step-title { font-size: 13.5px; font-weight: 600; }
.wizard-step-sub { font-size: 11.5px; color: var(--text-muted); }
.wizard-content { flex: 1; overflow-y: auto; display: flex; justify-content: center; padding: 44px 24px; }
.wizard-content-inner { width: 100%; max-width: 720px; }
.wizard-footer { height: 80px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-top: 1px solid var(--border); }

.wizard-role-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.wizard-role-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; cursor: pointer; transition: border-color 120ms ease, background 120ms ease; }
.wizard-role-card:hover { border-color: var(--border-hover); }
.wizard-role-card.selected { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
.wizard-role-icon { color: var(--text-secondary); margin-bottom: 8px; }
.wizard-role-card.selected .wizard-role-icon { color: var(--accent); }
.wizard-role-label { font-size: 13px; font-weight: 600; margin-bottom: 3px; }
.wizard-role-desc { font-size: 11.5px; color: var(--text-muted); line-height: 1.4; }

.wizard-protocol-list { display: flex; flex-direction: column; gap: 8px; }
.wizard-protocol-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px; cursor: pointer; transition: border-color 120ms ease, background 120ms ease; }
.wizard-protocol-card:hover { border-color: var(--border-hover); }
.wizard-protocol-card.selected { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, var(--surface)); }

.wizard-review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 24px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 20px 22px; }
.wizard-review-grid .muted { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }

.wizard-success-check { width: 64px; height: 64px; border-radius: 50%; background: color-mix(in srgb, var(--success) 15%, transparent); color: var(--success); display: flex; align-items: center; justify-content: center; margin: 0 auto; animation: success-pop 320ms cubic-bezier(.2,.8,.2,1); }
@keyframes success-pop { from { transform: scale(.7); opacity: 0; } to { transform: scale(1); opacity: 1; } }

@media (max-width: 860px) {
  .wizard-nav { display: none; }
  .wizard-role-grid { grid-template-columns: 1fr 1fr; }
}
/* Embed-режим: страница настроек внутри iframe инспектора сервера — без общего
   каркаса (сайдбар/топбар), только контент с небольшим отступом. */
body.embed { background: var(--bg); }
body.embed .embed-page { padding: 16px; max-width: 920px; margin: 0 auto; }
body.embed .card { margin-bottom: 14px; }
</style>
</head>
<?php if ($embed): ?>
<body class="embed">
<script>
  window.PANEL_I18N = <?= json_encode(\App\I18n::jsDict(), JSON_UNESCAPED_UNICODE) ?>;
  window.PANEL_LANG = <?= json_encode(\App\I18n::lang()) ?>;
  window.T = function (k) { var s = (window.PANEL_I18N && window.PANEL_I18N[k]) || k; for (var i = 1; i < arguments.length; i++) { s = s.replace(/%[sd]/, arguments[i]); } return s; };
</script>
<div class="page embed-page" id="page-content">
<?php self::flashMessages(); return; endif; ?>
<body>
<div class="app-shell" id="app-shell">
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <aside class="sidebar" id="app-sidebar">
    <div class="sidebar-brand">
      <a href="/dashboard.php" class="sidebar-brand-link" title="VPS Router">
        <img class="sidebar-brand-logo" src="/assets/img/logo.svg" alt="VPS Router">
        <img class="sidebar-brand-mark-img" src="/assets/img/logo-mark.svg" alt="VPS Router" aria-hidden="true">
      </a>
    </div>
    <nav class="sidebar-nav">
      <?php foreach (self::$navGroups as $groupTitle => $items): ?>
        <?php
        // Пункты с 'feature' показываем только когда модуль-возможность активен.
        $visible = [];
        foreach ($items as $file => $item) {
            if (!empty($item['feature']) && !\App\Modules\ModuleManager::featureActive($item['feature'])) {
                continue;
            }
            $visible[$file] = $item;
        }
        if (!$visible) {
            continue;
        }
        ?>
        <div class="sidebar-group">
          <div class="sidebar-group-title"><?= htmlspecialchars(t($groupTitle)) ?></div>
          <?php foreach ($visible as $file => $item): ?>
            <a href="/<?= $file ?>" class="sidebar-item <?= $current === $file ? 'active' : '' ?>">
              <?= self::icon($item['icon']) ?>
              <span class="sidebar-label"><?= htmlspecialchars(t($item['label'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-status"><span class="dot"></span><span class="sidebar-label"><?= htmlspecialchars(t('chrome.online')) ?></span></div>
      <div class="sidebar-lang sidebar-label" style="display:flex;gap:6px;padding:4px 10px 8px">
        <?php foreach (\App\I18n::available() as $code => $name): ?>
          <a href="?lang=<?= htmlspecialchars($code) ?>" class="lang-opt <?= \App\I18n::lang() === $code ? 'active' : '' ?>" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars(strtoupper($code)) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="sidebar-user">
        <a class="sidebar-user-link" href="/settings.php#account" title="<?= htmlspecialchars(t('chrome.account')) ?>">
          <div class="sidebar-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(Auth::username() ?? '?', 0, 1))) ?></div>
          <div class="sidebar-user-info">
            <strong><?= htmlspecialchars(Auth::username() ?? '') ?></strong>
            <span class="muted"><?= htmlspecialchars(t('chrome.account')) ?></span>
          </div>
        </a>
        <a class="sidebar-logout icon-btn" href="/logout.php" title="<?= htmlspecialchars(t('chrome.logout')) ?>"><?= self::icon('logout', 16) ?></a>
        <button class="sidebar-toggle icon-btn" id="sidebar-toggle-btn" title="<?= htmlspecialchars(t('chrome.collapse')) ?>" type="button"><?= self::icon('chevron', 16) ?></button>
      </div>
      <a class="sidebar-donate sidebar-label" href="https://boosty.to/iygen/donate" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars(t('chrome.donate')) ?>">❤ <?= htmlspecialchars(t('chrome.donate')) ?></a>
      <div class="sidebar-copyright sidebar-label">© <?= date('Y') ?> Ygen</div>
    </div>
  </aside>
  <div class="main-column">
    <header class="topbar">
      <button class="topbar-burger icon-btn" id="topbar-burger" type="button" aria-label="Меню">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <div style="min-width:0;flex:1">
        <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
        <?php if ($subtitle): ?><div class="topbar-subtitle"><?= htmlspecialchars($subtitle) ?></div><?php endif; ?>
      </div>
      <div class="topbar-right" id="topbar-actions"></div>
    </header>
    <script>
      window.PANEL_I18N = <?= json_encode(\App\I18n::jsDict(), JSON_UNESCAPED_UNICODE) ?>;
      window.PANEL_LANG = <?= json_encode(\App\I18n::lang()) ?>;
      // t('ключ', арг1, ...) — перевод для JS, %s/%d подставляются по порядку.
      window.T = function (k) {
        var s = (window.PANEL_I18N && window.PANEL_I18N[k]) || k;
        for (var i = 1; i < arguments.length; i++) { s = s.replace(/%[sd]/, arguments[i]); }
        return s;
      };
    </script>
    <div class="<?= in_array($current, ['dashboard.php', 'groups.php'], true) ? 'page-fullbleed' : 'page' ?>" id="page-content">
        <?php
        self::flashMessages();
        self::securityBanner();
    }

    /**
     * Плашка «Настраиваю роутер: …» на страницах, работающих в контексте одного
     * роутера (Маршруты/Устройства/Настройки/Keenetic). Показывается только при
     * >1 роутере; выбор дублирует клик по узлу-роутеру в графе. Страницы
     * вызывают её сразу после header().
     */
    public static function routerScopeBar(): void
    {
        if (\App\RouterContext::count() < 2) {
            return;
        }
        $cur = \App\RouterContext::currentId();
        ?>
        <div class="router-scope-bar">
          <span class="rsb-label"><?= htmlspecialchars(t('chrome.configuring')) ?></span>
          <select onchange="location.href='?router='+encodeURIComponent(this.value)">
            <?php foreach (\App\RouterContext::routers() as $__r): ?>
              <option value="<?= (int) $__r['id'] ?>" <?= (int) $__r['id'] === $cur ? 'selected' : '' ?>><?= htmlspecialchars($__r['name']) ?><?= !empty($__r['is_self']) ? ' · ' . htmlspecialchars(t('chrome.this_server')) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php
    }

    /**
     * Предупреждение о безопасности сервера панели (устаревшая ОС/PHP,
     * непоставленные обновления). Показывается на каждой странице, пока
     * проблема не устранена. Проверки мгновенные (см. App\SecurityAudit).
     */
    public static function securityBanner(): void
    {
        $findings = \App\SecurityAudit::findings();
        if (!$findings) {
            return;
        }
        $hasError = false;
        foreach ($findings as $f) {
            if ($f['level'] === 'error') {
                $hasError = true;
            }
        }
        // Ключ для «скрыть»: зависит от набора проблем — если появится новая,
        // баннер покажется снова, даже если старый был скрыт.
        $sig = substr(hash('sha256', json_encode($findings)), 0, 12);
        ?>
        <div class="security-banner <?= $hasError ? 'level-error' : 'level-warn' ?>" id="security-banner" data-sig="<?= $sig ?>">
          <button type="button" class="sb-dismiss icon-btn" aria-label="×" title="×">✕</button>
          <div class="sb-head">⚠ <?= htmlspecialchars(t('sec.banner_title')) ?></div>
          <ul>
            <?php foreach ($findings as $f): ?>
              <li><?= htmlspecialchars(t($f['key'], ...$f['args'])) ?></li>
            <?php endforeach; ?>
          </ul>
          <a href="/settings.php#system"><?= htmlspecialchars(t('sec.banner_more')) ?> →</a>
        </div>
        <script>
        (function () {
          var b = document.getElementById('security-banner');
          if (!b) return;
          var key = 'vr_sec_dismissed';
          try { if (localStorage.getItem(key) === b.dataset.sig) { b.hidden = true; return; } } catch (e) {}
          var x = b.querySelector('.sb-dismiss');
          if (x) x.addEventListener('click', function () {
            b.hidden = true;
            try { localStorage.setItem(key, b.dataset.sig); } catch (e) {}
          });
        })();
        </script>
        <?php
    }

    public static function footer(bool $embed = false): void
    {
        if ($embed) {
            ?>
    </div>
</body>
</html>
            <?php
            return;
        }
        ?>
    </div>
  </div>
</div>
<script>
(function () {
  var KEY = 'vr_sidebar_collapsed';
  var sidebar = document.getElementById('app-sidebar');
  var btn = document.getElementById('sidebar-toggle-btn');
  if (sidebar && btn) {
    if (localStorage.getItem(KEY) === '1') sidebar.classList.add('collapsed');
    btn.addEventListener('click', function () {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem(KEY, sidebar.classList.contains('collapsed') ? '1' : '0');
    });
  }

  // Мобильное off-canvas меню: бургер открывает, бэкдроп/клик по пункту закрывает.
  var shell = document.getElementById('app-shell');
  var burger = document.getElementById('topbar-burger');
  var backdrop = document.getElementById('sidebar-backdrop');
  function closeNav() { if (shell) shell.classList.remove('sidebar-open'); }
  if (burger && shell) {
    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      shell.classList.toggle('sidebar-open');
    });
  }
  if (backdrop) backdrop.addEventListener('click', closeNav);
  if (sidebar) {
    sidebar.querySelectorAll('.sidebar-item, .lang-opt, .sidebar-logout').forEach(function (a) {
      a.addEventListener('click', closeNav);
    });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });
})();

// Плашка «Изменения не применены»: маршруты/наборы/устройства меняются в БД
// сразу, а до sing-box доходят только после Apply — без напоминания это
// выглядит как «поменял, а ничего не изменилось».
(function () {
  var bar = document.getElementById('topbar-actions');
  var csrf = document.querySelector('meta[name="csrf-token"]');
  if (!bar || !csrf) return;
  var el = document.createElement('div');
  el.id = 'pending-apply-pill';
  el.style.cssText = 'display:none;align-items:center;gap:10px;padding:6px 8px 6px 14px;border-radius:10px;background:color-mix(in srgb, var(--warning) 14%, transparent);border:1px solid color-mix(in srgb, var(--warning) 40%, transparent);font-size:13px;margin-right:10px';
  var L = window.PANEL_I18N || {};
  el.innerHTML = '<span id="pending-apply-text">' + (L['chrome.pending'] || '') + '</span><button type="button" id="pending-apply-btn" style="padding:5px 12px">' + (L['chrome.apply'] || 'Apply') + '</button>';
  bar.prepend(el);
  var text = el.querySelector('#pending-apply-text');
  var btn = el.querySelector('#pending-apply-btn');

  function check() {
    fetch('/api/infrastructure.php?action=pending', { headers: { 'X-CSRF-Token': csrf.content } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s) return;
        el.style.display = s.pending ? 'flex' : 'none';
        text.textContent = s.error ? (L['chrome.pending_config_err'] || '%s').replace('%s', s.error) : (L['chrome.pending'] || '');
        btn.style.display = s.error ? 'none' : '';
      }).catch(function () {});
  }
  btn.addEventListener('click', function () {
    btn.disabled = true; btn.textContent = L['chrome.applying'] || 'Applying…';
    fetch('/api/infrastructure.php?action=apply', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf.content, 'Content-Type': 'application/json' },
      body: JSON.stringify({ description: 'Apply from header: ' + document.title })
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || (res.d.result && res.d.result.exit_code !== 0)) {
          text.textContent = '✕ ' + ((res.d && res.d.error) || (res.d.result && (res.d.result.stderr || res.d.result.stdout)) || 'error');
        } else {
          text.textContent = L['chrome.applied'] || '✓';
          setTimeout(check, 1500);
        }
      }).catch(function (e) { text.textContent = '✕ ' + e.message; })
      .finally(function () { btn.disabled = false; btn.textContent = L['chrome.apply'] || 'Apply'; });
  });
  check();
  setInterval(check, 8000);
  window.addEventListener('focus', check);
  window.PanelPendingCheck = check;
})();
</script>
</body>
</html>
        <?php
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** Забрать и очистить флеши (для своих каркасов, напр. клиентского портала). */
    public static function takeFlashes(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    private static function flashMessages(): void
    {
        if (empty($_SESSION['flash'])) {
            return;
        }
        foreach ($_SESSION['flash'] as $f) {
            $cls = htmlspecialchars($f['type']);
            $msg = htmlspecialchars($f['message']);
            echo "<div class=\"flash $cls\">$msg</div>";
        }
        unset($_SESSION['flash']);
    }

    public static function applyResultFlash(array $result): void
    {
        if ($result['exit_code'] === 0) {
            self::flash('success', t('flash.applied', trim($result['stdout'])));
        } else {
            self::flash('error', t('flash.apply_err', trim($result['stderr'] ?: $result['stdout'])));
        }
    }
}
