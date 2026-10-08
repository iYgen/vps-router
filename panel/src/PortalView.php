<?php

namespace App;

use App\Models\PortalTemplate;
use App\Models\Setting;

/**
 * Лёгкий самостоятельный каркас клиентского портала (без админского сайдбара).
 * Оформление берётся из активного шаблона (PortalTemplate): палитра, свой CSS,
 * логотип, произвольные хедер/футер — всё настраивается в Биллинг → Оформление.
 */
class PortalView
{
    private static ?array $tpl = null;

    public static function tpl(): array
    {
        if (self::$tpl === null) {
            self::$tpl = PortalTemplate::active() ?? [];
        }
        return self::$tpl;
    }

    public static function planStyle(): string
    {
        $s = (string) (self::tpl()['plan_style'] ?? 'cards');
        return in_array($s, PortalTemplate::PLAN_STYLES, true) ? $s : 'cards';
    }

    public static function layoutWidth(): string
    {
        return (string) (self::tpl()['layout_width'] ?? 'contained') === 'full' ? 'full' : 'contained';
    }

    /** Произвольный вводный блок шаблона (над тарифами). */
    public static function intro(): void
    {
        $html = (string) (self::tpl()['intro_html'] ?? '');
        if ($html !== '') {
            echo '<div class="portal-intro">' . $html . '</div>';
        }
    }

    public static function header(string $title, bool $authed = false): void
    {
        $t = self::tpl();
        $brand = htmlspecialchars((string) Setting::get('portal_brand', 'VPN'));
        $logo = trim((string) Setting::get('portal_logo', ''));
        $v = fn(string $k, string $d) => htmlspecialchars((string) ($t[$k] ?? $d), ENT_QUOTES);
        $dark = (int) ($t['dark'] ?? 1) === 1;
        // Хедер/футер пишет администратор (доверенный) — выводим как есть.
        $headerHtml = (string) ($t['header_html'] ?? '');
        $footerHtml = (string) ($t['footer_html'] ?? '');
        $customCss = (string) ($t['custom_css'] ?? '');
        ?><!doctype html>
<html lang="<?= htmlspecialchars(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($title) ?> — <?= $brand ?></title>
<style>
:root{
  color-scheme:<?= $dark ? 'dark' : 'light' ?>;
  --bg:<?= $v('bg', '#17171a') ?>;--surface:<?= $v('surface', '#1d1d22') ?>;--surface2:<?= $v('surface2', '#222228') ?>;
  --border:<?= $v('border', 'rgba(255,255,255,.08)') ?>;--text:<?= $v('text', '#f5f5f7') ?>;--muted:<?= $v('muted', '#a1a1aa') ?>;
  --accent:<?= $v('accent', '#ff4f87') ?>;--radius:<?= $v('radius', '14px') ?>;
  --ok:#39d98a;--warn:#f5b942;--danger:#ff5c6c;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--accent)}
.wrap{max-width:860px;margin:0 auto;padding:24px 16px calc(32px + env(safe-area-inset-bottom,0px))}
body.w-full .wrap{max-width:1180px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:12px}
.brand{font-weight:700;font-size:18px;display:flex;align-items:center;gap:10px}
.brand img{max-height:40px;max-width:180px;display:block}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.card h2,.card h3{margin-top:0}
.muted{color:var(--muted)}
label{display:block;font-size:13px;color:var(--muted);margin:10px 0 4px}
input[type=text],input[type=email],input[type=password],input:not([type]){width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--surface2);color:var(--text);font-size:15px}
button,.btn{display:inline-block;background:var(--accent);color:#fff;border:0;border-radius:9px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}
button.secondary,.btn.secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
button.danger{background:var(--danger)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.badge.ok{background:color-mix(in srgb,var(--ok) 18%,transparent);color:var(--ok)}
.badge.warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:var(--warn)}
.badge.danger{background:color-mix(in srgb,var(--danger) 18%,transparent);color:var(--danger)}
.bar{height:10px;border-radius:999px;background:var(--surface2);overflow:hidden;margin-top:6px}
.bar>i{display:block;height:100%;background:var(--accent)}
.flash{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
.flash.error{background:color-mix(in srgb,var(--danger) 15%,transparent);border:1px solid color-mix(in srgb,var(--danger) 40%,transparent)}
.flash.success{background:color-mix(in srgb,var(--ok) 15%,transparent);border:1px solid color-mix(in srgb,var(--ok) 40%,transparent)}
.flash.warn{background:color-mix(in srgb,var(--warn) 15%,transparent);border:1px solid color-mix(in srgb,var(--warn) 40%,transparent)}
.plan{border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.dev{border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:10px}
.portal-header,.portal-footer,.portal-intro{margin-bottom:16px}
.portal-footer{margin-top:8px;color:var(--muted);font-size:13px}
code{font-family:ui-monospace,monospace;font-size:12.5px;word-break:break-all}

/* ---- Тарифы: стиль «cards» (сетка pricing-карточек, как у операторов) ---- */
.plans-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;align-items:stretch}
.pcard{position:relative;display:flex;flex-direction:column;gap:10px;border:1px solid var(--border);border-radius:var(--radius);padding:22px 20px;background:var(--surface)}
.pcard.featured{border-color:var(--accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 45%,transparent)}
.pcard .ribbon{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--accent);color:#fff;font-size:11px;font-weight:700;padding:3px 12px;border-radius:999px;white-space:nowrap}
.pcard .pname{font-size:17px;font-weight:700}
.pcard .pprice{font-size:30px;font-weight:800;line-height:1}
.pcard .pprice small{font-size:13px;font-weight:500;color:var(--muted)}
.pcard .pdesc{color:var(--muted);font-size:13px}
.pcard .pfeat{list-style:none;padding:0;margin:4px 0 0;display:flex;flex-direction:column;gap:6px;font-size:13.5px}
.pcard .pfeat li{display:flex;gap:8px}
.pcard .pfeat li::before{content:"✓";color:var(--accent);font-weight:700}
.pcard .pcta{margin-top:auto}
.pcard .pcta button,.pcard .pcta .btn{width:100%;text-align:center}

/* ---- Стиль «table» (сравнение тарифов колонками) ---- */
.plans-table-wrap{overflow-x:auto}
table.plans-table{border-collapse:collapse;width:100%;min-width:420px}
table.plans-table th,table.plans-table td{border:1px solid var(--border);padding:12px 14px;text-align:center}
table.plans-table thead th{font-size:16px}
table.plans-table thead th.featured{color:var(--accent)}
table.plans-table th.rowhdr,table.plans-table td.rowhdr{text-align:left;color:var(--muted);font-size:13px;background:var(--surface2)}
table.plans-table .tprice{font-size:22px;font-weight:800}

/* ---- Стиль «rows» (широкие строки) ---- */
.plans-rows{display:flex;flex-direction:column;gap:12px}
.prow{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;background:var(--surface)}
.prow.featured{border-color:var(--accent)}
.prow .pprice{font-size:22px;font-weight:800}
<?= $customCss ? "\n/* custom */\n" . $customCss . "\n" : '' ?>
</style>
</head>
<body class="w-<?= self::layoutWidth() ?>">
<div class="wrap">
  <div class="top">
    <div class="brand"><?= $logo !== '' ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . $brand . '">' : $brand ?></div>
    <?php if ($authed): ?>
      <form method="post" action="/portal-login.php" style="margin:0">
        <?= Auth::csrfField() ?><input type="hidden" name="action" value="logout">
        <button class="secondary" type="submit"><?= htmlspecialchars(t('portal.logout')) ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($headerHtml !== ''): ?><div class="portal-header"><?= $headerHtml ?></div><?php endif; ?>
  <?php foreach (View::takeFlashes() as $f): ?>
    <div class="flash <?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['message']) ?></div>
  <?php endforeach; ?>
<?php
    }

    public static function footer(): void
    {
        $t = PortalTemplate::active() ?? [];
        $footerHtml = (string) ($t['footer_html'] ?? '');
        if ($footerHtml !== '') {
            echo '<div class="portal-footer">' . $footerHtml . '</div>';
        }
        ?>
</div>
</body>
</html>
<?php
    }
}
