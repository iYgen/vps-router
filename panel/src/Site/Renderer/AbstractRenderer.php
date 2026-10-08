<?php

namespace App\Site\Renderer;

use App\Site\BusinessData;
use App\Site\DesignConfig;
use App\Site\SiteContext;

/**
 * Базовый renderer: общие переиспользуемые компоненты (header, hero, pricing-grid
 * + 6 стилей карточек, features, FAQ, CTA, contacts, footer) и токены палитры/
 * типографики. Конкретные шаблоны наследуются и КОМПОНУЮТ их по-разному (порядок
 * секций, стиль pricing, hero, ширина) — структура разная, данные одни.
 */
abstract class AbstractRenderer
{
    /** Ключ шаблона (tpl-<key> как класс body для scoped-CSS). */
    abstract public function key(): string;

    /** Полный HTML публичного сайта. */
    abstract public function renderWebsite(SiteContext $ctx): string;

    /** Доп. CSS шаблона (scoped под .tpl-<key>). Переопределяется. */
    protected function templateCss(SiteContext $ctx): string
    {
        return '';
    }

    protected function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    // ───────────────────────── страница/токены ─────────────────────────

    protected function page(SiteContext $ctx, string $inner): string
    {
        $d = $ctx->design;
        $brand = $this->e((string) ($d->brand()['name'] ?? ($d->get('brand.name', 'VPN'))));
        $title = $this->e((string) ($d->get('seo.title', $brand)));
        $demoBanner = $ctx->demo
            ? '<div class="demo-banner">DEMO — показаны примерные тарифы (в админ-предпросмотре). Реальные тарифы появятся после их создания.</div>'
            : '';
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="x-vpsrouter-site" content="1">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<title>' . $title . '</title>'
            . $this->fonts($d)
            . '<style>' . $this->baseCss() . $this->cssVars($d) . $this->componentCss() . $this->templateCss($ctx)
            . ($d->advancedCss() !== '' ? "\n/* advanced */\n" . $d->advancedCss() : '') . '</style>'
            . '</head><body class="tpl-' . $this->e($this->key()) . '">' . $demoBanner . $inner
            . '<script>(function(){var b=document.querySelector(".site-head .burger");if(!b)return;var h=b.closest(".site-head");b.addEventListener("click",function(){var o=h.classList.toggle("nav-open");b.setAttribute("aria-expanded",o);});h.querySelectorAll("nav a").forEach(function(a){a.addEventListener("click",function(){h.classList.remove("nav-open");b.setAttribute("aria-expanded","false");});});})();</script>'
            . '</body></html>';
    }

    protected function fonts(DesignConfig $d): string
    {
        $fams = array_unique(array_filter([(string) ($d->typography()['heading'] ?? 'Inter'), (string) ($d->typography()['body'] ?? 'Inter')]));
        $q = [];
        foreach ($fams as $f) {
            $q[] = 'family=' . rawurlencode($f) . ':wght@400;500;600;700;800';
        }
        return $q ? '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . implode('&', $q) . '&display=swap">' : '';
    }

    protected function cssVars(DesignConfig $d): string
    {
        $p = $d->palette();
        $t = $d->typography();
        $l = $d->get('layout', []);
        $scale = (string) ($t['scale'] ?? 'normal');
        $h1 = ['normal' => '40px', 'large' => '52px', 'xl' => '64px'][$scale] ?? '40px';
        $width = (string) ($l['width'] ?? 'contained') === 'full' ? '1180px' : '1060px';
        $v = fn($k, $def) => $this->e((string) ($p[$k] ?? $def));
        return ':root{'
            . '--primary:' . $v('primary', '#2563eb') . ';--bg:' . $v('bg', '#f7f8fb') . ';--surface:' . $v('surface', '#fff') . ';'
            . '--surface2:' . $v('surface2', '#eef1f6') . ';--text:' . $v('text', '#14141a') . ';--muted:' . $v('muted', '#667085') . ';'
            . '--border:' . $v('border', 'rgba(16,24,40,.1)') . ';--price:' . $v('price', '#14141a') . ';--popular:' . $v('popular', '#2563eb') . ';'
            . '--radius:' . $this->e((string) ($l['radius'] ?? '14px')) . ';--container:' . $width . ';'
            . '--fh:"' . $this->e((string) ($t['heading'] ?? 'Inter')) . '",system-ui,sans-serif;'
            . '--fb:"' . $this->e((string) ($t['body'] ?? 'Inter')) . '",system-ui,sans-serif;'
            . '--h1:' . $h1 . ';'
            . 'color-scheme:' . ((int) ($p['dark'] ?? 0) === 1 ? 'dark' : 'light') . ';}';
    }

    protected function baseCss(): string
    {
        return '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:var(--fb);font-size:16px;line-height:1.55}'
            . 'h1,h2,h3{font-family:var(--fh);line-height:1.1;margin:0 0 .4em;text-wrap:balance}h1{font-size:var(--h1);font-weight:800}h2{font-size:30px;font-weight:700}h3{font-size:19px;font-weight:700}'
            . 'a{color:var(--primary);text-decoration:none}img{max-width:100%}p{margin:0 0 1em;color:var(--muted)}'
            . '.container{max-width:var(--container);margin:0 auto;padding:0 20px}.section{padding:64px 0}'
            . '.btn{display:inline-block;background:var(--primary);color:#fff;border:0;border-radius:calc(var(--radius) - 4px);padding:13px 22px;font-weight:700;font-size:15px;cursor:pointer}'
            . '.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}.btn.block{display:block;text-align:center;width:100%}'
            . '.demo-banner{background:#ffdd57;color:#111;text-align:center;padding:7px 12px;font-size:13px;font-weight:600}'
            . '.badge{display:inline-block;background:var(--popular);color:#fff;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}'
            . '.muted{color:var(--muted)}';
    }

    protected function componentCss(): string
    {
        return
            // header
            '.site-head{border-bottom:1px solid var(--border)}.site-head .container{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-top:16px;padding-bottom:16px;flex-wrap:wrap}'
            . '.site-head nav{display:flex;gap:22px;align-items:center}.site-head .logo{font-family:var(--fh);font-weight:800;font-size:20px;color:var(--text)}.site-head .logo img{max-height:34px;vertical-align:middle}'
            // бургер (мобилка): три полоски → крестик
            . '.burger{display:none;flex-direction:column;gap:5px;background:none;border:0;cursor:pointer;padding:8px;margin-left:auto}'
            . '.burger span{display:block;width:24px;height:2px;background:var(--text);border-radius:2px;transition:transform .25s ease,opacity .2s ease}'
            . '@media(max-width:760px){'
            . '.site-head .burger{display:inline-flex}'
            . '.site-head nav{display:none;flex-basis:100%;flex-direction:column;align-items:stretch;gap:4px;padding:8px 0 4px}'
            . '.site-head nav a{padding:10px 6px;border-radius:8px}.site-head nav a.btn{text-align:center}'
            . '.site-head.nav-open nav{display:flex}'
            . '.site-head.nav-open .burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}'
            . '.site-head.nav-open .burger span:nth-child(2){opacity:0}'
            . '.site-head.nav-open .burger span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}'
            . '}'
            // hero
            . '.hero{text-align:center}.hero.split .container{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;text-align:left}'
            . '.hero p.sub{font-size:19px;max-width:620px;margin:0 auto 26px}.hero.split p.sub{margin-left:0}.hero .ctas{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}.hero.split .ctas{justify-content:flex-start}'
            . '.hero .art{aspect-ratio:4/3;border-radius:var(--radius);background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 30%,transparent),color-mix(in srgb,var(--popular) 12%,transparent));border:1px solid var(--border)}'
            // pricing grids
            . '.pricing-grid{display:grid;gap:20px}.pricing-grid.c2{grid-template-columns:repeat(2,1fr)}.pricing-grid.c3{grid-template-columns:repeat(3,1fr)}.pricing-grid.c4{grid-template-columns:repeat(4,1fr)}'
            . '.pricing-h{display:flex;flex-direction:column;gap:12px}'
            . '@media(max-width:860px){.pricing-grid.c2,.pricing-grid.c3,.pricing-grid.c4{grid-template-columns:1fr}.hero.split .container{grid-template-columns:1fr}}'
            // cards (base)
            . '.pc{position:relative;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:26px 24px;display:flex;flex-direction:column;gap:14px}'
            . '.pc .nm{font-family:var(--fh);font-weight:700;font-size:18px}.pc .pr{color:var(--price);font-weight:800;font-size:38px;line-height:1}.pc .pr small{font-size:14px;color:var(--muted);font-weight:500}'
            . '.pc .spec{color:var(--muted);font-size:14px}.pc ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;font-size:14.5px}.pc ul li{display:flex;gap:8px}.pc ul li::before{content:"✓";color:var(--primary);font-weight:800}'
            . '.pc .cta{margin-top:auto}.pc .ribbon{position:absolute;top:-12px;left:50%;transform:translateX(-50%);white-space:nowrap}'
            . '.pc.pop{border-color:var(--popular);box-shadow:0 0 0 2px color-mix(in srgb,var(--popular) 45%,transparent)}'
            // card: horizontal
            . '.pc.h{flex-direction:row;align-items:center;gap:20px;padding:18px 22px}.pc.h .pr{font-size:26px}.pc.h .grow{flex:1}'
            // features / faq / contacts / footer
            . '.feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}.feat-grid .f{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px}'
            . '@media(max-width:860px){.feat-grid{grid-template-columns:1fr}}'
            . '.faq .q{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:10px}.faq .q b{display:block;margin-bottom:6px}'
            . '.site-foot{border-top:1px solid var(--border);padding:32px 0;color:var(--muted);font-size:14px}.site-foot .container{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}'
            . '.cta-band{text-align:center;background:var(--surface2);border-radius:var(--radius);padding:48px 24px}'
            . '.how{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;counter-reset:s}.how .st{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px}.how .st b{display:block;font-size:26px;color:var(--primary)}@media(max-width:860px){.how{grid-template-columns:1fr 1fr}}'
            // ───── motion: ховер-микровзаимодействия (общие для всех шаблонов) ─────
            . '.btn{transition:transform .15s ease,box-shadow .22s ease,filter .2s ease;will-change:transform}'
            . '.btn:hover{transform:translateY(-2px);box-shadow:0 12px 26px color-mix(in srgb,var(--primary) 38%,transparent);filter:saturate(1.08)}'
            . '.btn:active{transform:translateY(0);box-shadow:none}'
            . '.pc{transition:transform .24s cubic-bezier(.2,.7,.3,1),box-shadow .26s ease,border-color .2s ease}'
            . '.pc:hover{transform:translateY(-6px);border-color:color-mix(in srgb,var(--primary) 45%,var(--border));box-shadow:0 24px 52px rgba(0,0,0,.14)}'
            . '.pc .cta .btn,.pc .btn.cta{transition:transform .15s ease,box-shadow .22s ease,filter .2s ease}'
            . '.pc:hover .ribbon{transform:translateX(-50%) translateY(-2px) scale(1.04)}'
            . '.ribbon{transition:transform .24s ease}'
            . '.site-head nav a:not(.btn){position:relative;transition:color .2s ease}'
            . '.site-head nav a:not(.btn)::after{content:"";position:absolute;left:0;right:100%;bottom:-4px;height:2px;background:var(--primary);transition:right .25s ease}'
            . '.site-head nav a:not(.btn):hover{color:var(--primary)}.site-head nav a:not(.btn):hover::after{right:0}'
            . '.feat-grid .f{transition:transform .2s ease,box-shadow .22s ease,border-color .2s ease}'
            . '.feat-grid .f:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,.1);border-color:color-mix(in srgb,var(--primary) 35%,var(--border))}'
            . '.how .st{transition:transform .2s ease}.how .st:hover{transform:translateY(-4px)}'
            . '.faq .q{transition:border-color .2s ease,background .2s ease}.faq .q:hover{border-color:color-mix(in srgb,var(--primary) 40%,var(--border))}'
            . '.logo{transition:opacity .2s ease}.logo:hover{opacity:.82}'
            . '@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition:none!important}.btn:hover,.pc:hover,.feat-grid .f:hover,.how .st:hover{transform:none}}';
    }

    // ───────────────────────── компоненты ─────────────────────────

    protected function header(SiteContext $ctx, string $variant = 'classic'): string
    {
        $d = $ctx->design;
        $brand = (string) ($d->get('brand.name', 'VPN'));
        $logo = (string) ($d->get('brand.logo', ''));
        $cta = (string) ($d->get('hero.cta', 'Выбрать тариф')) ?: 'Выбрать тариф';
        $logoHtml = $logo !== '' ? '<img src="' . $this->e($logo) . '" alt="' . $this->e($brand) . '">' : $this->e($brand);
        $center = $variant === 'centered' ? 'style="justify-content:center"' : '';
        // Навигация строится ТОЛЬКО из включённых секций и настройки шапки — поэтому
        // выключенный FAQ/и т.п. в шапке не показывается.
        $sections = $ctx->design->sections();
        $labels = ['pricing' => 'Тарифы', 'features' => 'Преимущества', 'how' => 'Как это работает', 'faq' => 'FAQ', 'contacts' => 'Контакты'];
        $items = $ctx->design->get('header.items', null);
        if (!is_array($items)) {
            $items = array_keys($labels); // по умолчанию — все доступные
        }
        $links = '';
        foreach ($items as $it) {
            if (isset($labels[$it]) && in_array($it, $sections, true)) {
                $links .= '<a href="#' . $this->e($it) . '">' . $this->e($labels[$it]) . '</a>';
            }
        }
        $showLogin = (int) $ctx->design->get('header.login', 1) === 1;
        $login = $showLogin ? '<a href="' . BusinessData::ctaUrl() . '">Войти</a>' : '';
        $ctaHref = in_array('pricing', $sections, true) ? '#pricing' : BusinessData::ctaUrl();
        return '<header class="site-head"><div class="container" ' . $center . '>'
            . '<a class="logo" href="#">' . $logoHtml . '</a>'
            . '<button class="burger" type="button" aria-label="Меню" aria-expanded="false"><span></span><span></span><span></span></button>'
            . '<nav>' . $links . $login
            . '<a class="btn" href="' . $ctaHref . '">' . $this->e($cta) . '</a></nav>'
            . '</div></header>';
    }

    protected function hero(SiteContext $ctx): string
    {
        $h = $ctx->design->hero();
        if (empty($h['enabled'])) {
            return '';
        }
        $layout = (string) ($h['layout'] ?? 'centered');
        $title = $this->e((string) ($h['title'] ?? ''));
        $sub = $this->e((string) ($h['subtitle'] ?? ''));
        $cta = (string) ($h['cta'] ?? '');
        $ctas = '<div class="ctas"><a class="btn" href="#pricing">' . $this->e($cta ?: 'Выбрать тариф') . '</a>'
            . '<a class="btn ghost" href="' . BusinessData::ctaUrl() . '">Личный кабинет</a></div>';
        $text = '<div><h1>' . $title . '</h1><p class="sub">' . $sub . '</p>' . $ctas . '</div>';
        $art = $layout === 'split' ? '<div class="art"></div>' : '';
        $pad = $layout === 'compact' ? 'style="padding:40px 0 20px"' : '';
        return '<section class="hero ' . $this->e($layout) . ' section" ' . $pad . '><div class="container">' . $text . $art . '</div></section>';
    }

    /** Pricing: обёртка-раскладка + карточки выбранного стиля. */
    protected function pricingSection(SiteContext $ctx, ?string $layout = null, ?string $card = null): string
    {
        $pr = $ctx->design->pricing();
        $layout = $layout ?: (string) ($pr['layout'] ?? '3col');
        $card = $card ?: (string) ($pr['card'] ?? 'classic');
        $plans = $ctx->plans;
        if (!$plans) {
            return '<section id="pricing" class="section"><div class="container"><h2>Тарифы</h2><p>Тарифы скоро появятся.</p></div></section>';
        }
        $inner = $this->pricingGrid($ctx, $plans, $layout, $card);
        return '<section id="pricing" class="section"><div class="container"><h2 style="text-align:center;margin-bottom:28px">Тарифы</h2>' . $inner . '</div></section>';
    }

    protected function pricingGrid(SiteContext $ctx, array $plans, string $layout, string $card): string
    {
        if ($layout === 'horizontal') {
            $cards = implode('', array_map(fn($p) => $this->card($p, 'horizontal'), $plans));
            return '<div class="pricing-h">' . $cards . '</div>';
        }
        if ($layout === 'featured-center') {
            // «популярный» — крупно по центру, остальные по бокам
            usort($plans, fn($a, $b) => ($b['featured'] ? 1 : 0) <=> ($a['featured'] ? 1 : 0));
            $cards = '';
            foreach ($plans as $i => $p) {
                $cards .= $this->card($p, $i === 0 ? 'premium' : $card);
            }
            return '<div class="pricing-grid c3" style="align-items:center">' . $cards . '</div>';
        }
        if ($layout === 'editorial') {
            return $this->editorialGrid($ctx, $plans, $card);
        }
        $cols = ['2col' => 'c2', '3col' => 'c3', '4col' => 'c4'][$layout] ?? 'c3';
        $cards = implode('', array_map(fn($p) => $this->card($p, $card), $plans));
        return '<div class="pricing-grid ' . $cols . '">' . $cards . '</div>';
    }

    /** Асимметрия: один крупный featured сверху + остальные меньшими снизу. */
    protected function editorialGrid(SiteContext $ctx, array $plans, string $card): string
    {
        usort($plans, fn($a, $b) => ($b['featured'] ? 1 : 0) <=> ($a['featured'] ? 1 : 0));
        $big = array_shift($plans);
        $top = '<div style="max-width:420px;margin:0 auto 20px">' . $this->card($big, 'editorial') . '</div>';
        $rest = implode('', array_map(fn($p) => $this->card($p, 'classic'), $plans));
        return $top . '<div class="pricing-grid c2" style="max-width:720px;margin:0 auto">' . $rest . '</div>';
    }

    /** Одна карточка тарифа в заданном стиле (A classic / premium / glass / editorial / horizontal / feature-first). */
    protected function card(array $p, string $style): string
    {
        $cur = $this->e($p['currency']);
        $price = $p['is_free'] ? 'бесплатно' : $this->e($p['price_label']) . ' <small>' . $cur . '</small>';
        $period = '<small>/ ' . (int) $p['period_days'] . ' дн.</small>';
        $cta = '<a class="btn block cta" href="' . BusinessData::ctaUrl() . '">Подключить</a>';
        $pop = $p['featured'] ? ' pop' : '';
        $ribbon = $p['featured'] ? '<span class="badge ribbon">Популярный</span>' : '';
        $specLine = (int) $p['period_days'] . ' дн · ' . (int) $p['device_limit'] . ' устр' . ($p['traffic_label'] ? ' · ' . $this->e($p['traffic_label']) : '');
        $feats = $p['features'] ? '<ul>' . implode('', array_map(fn($f) => '<li>' . $this->e($f) . '</li>', $p['features'])) . '</ul>' : '<div class="spec">' . $specLine . '</div>';

        if ($style === 'horizontal') {
            return '<div class="pc h' . $pop . '"><div class="nm">' . $this->e($p['name']) . '</div>'
                . '<div class="spec grow">' . $specLine . '</div><div class="pr">' . $price . '</div>'
                . '<a class="btn cta" href="' . BusinessData::ctaUrl() . '">Подключить</a></div>';
        }
        if ($style === 'feature-first') {
            return '<div class="pc' . $pop . '">' . $ribbon . '<div class="nm">' . $this->e($p['name']) . '</div>'
                . $feats . '<div class="pr">' . $price . ' ' . $period . '</div>' . $cta . '</div>';
        }
        if ($style === 'editorial') {
            return '<div class="pc' . $pop . '" style="text-align:center;padding:40px 28px">' . $ribbon
                . '<div class="nm" style="font-size:24px">' . $this->e($p['name']) . '</div>'
                . '<div class="pr" style="font-size:52px;margin:10px 0">' . $price . '</div>'
                . '<div class="spec">' . $specLine . '</div><div style="margin-top:18px">' . $cta . '</div></div>';
        }
        // classic / premium / glass — различаются оформлением (CSS в шаблоне), структура карточки общая
        $big = $style === 'premium' ? ' style="padding:34px 28px"' : '';
        return '<div class="pc ' . $this->e($style) . $pop . '"' . $big . '>' . $ribbon
            . '<div class="nm">' . $this->e($p['name']) . '</div>'
            . '<div class="pr">' . $price . ' ' . $period . '</div>'
            . ($p['description'] ? '<div class="spec">' . $this->e($p['description']) . '</div>' : '')
            . $feats . $cta . '</div>';
    }

    protected function features(SiteContext $ctx): string
    {
        $items = $ctx->design->features()['items'] ?? null;
        if (!is_array($items) || !$items) {
            $items = [
                ['t' => 'Быстрое подключение', 'd' => 'Доступ за пару минут, без настройки вручную.'],
                ['t' => 'Несколько устройств', 'd' => 'Один аккаунт — телефон, ноутбук, роутер.'],
                ['t' => 'Под контролем', 'd' => 'Трафик, лимиты и устройства — в личном кабинете.'],
            ];
        }
        $cards = implode('', array_map(fn($f) => '<div class="f"><h3>' . $this->e((string) ($f['t'] ?? '')) . '</h3><p>' . $this->e((string) ($f['d'] ?? '')) . '</p></div>', $items));
        $title = $this->e((string) ($ctx->design->features()['title'] ?? 'Почему выбирают нас'));
        return '<section id="features" class="section"><div class="container"><h2 style="text-align:center;margin-bottom:28px">' . $title . '</h2><div class="feat-grid">' . $cards . '</div></div></section>';
    }

    protected function howItWorks(SiteContext $ctx): string
    {
        $steps = [['Выберите тариф'], ['Оплатите'], ['Получите доступ'], ['Подключите устройства']];
        $html = '';
        foreach ($steps as $i => $s) {
            $html .= '<div class="st"><b>' . ($i + 1) . '</b><h3>' . $this->e($s[0]) . '</h3></div>';
        }
        return '<section class="section"><div class="container"><h2 style="text-align:center;margin-bottom:28px">Как это работает</h2><div class="how">' . $html . '</div></div></section>';
    }

    protected function benefits(SiteContext $ctx): string
    {
        return $this->features($ctx);
    }

    protected function faq(SiteContext $ctx): string
    {
        $items = $ctx->design->faq()['items'] ?? null;
        if (!is_array($items) || !$items) {
            $items = [
                ['q' => 'Как начать?', 'a' => 'Выберите тариф, зарегистрируйтесь и оплатите — доступ активируется сразу.'],
                ['q' => 'Сколько устройств можно подключить?', 'a' => 'Зависит от тарифа — лимит указан в карточке.'],
                ['q' => 'Можно ли продлить?', 'a' => 'Да, продление доступно в личном кабинете в любой момент.'],
            ];
        }
        $qs = implode('', array_map(fn($i) => '<div class="q"><b>' . $this->e((string) ($i['q'] ?? '')) . '</b><span class="muted">' . $this->e((string) ($i['a'] ?? '')) . '</span></div>', $items));
        return '<section id="faq" class="section"><div class="container" style="max-width:760px"><h2 style="text-align:center;margin-bottom:24px">Вопросы и ответы</h2><div class="faq">' . $qs . '</div></div></section>';
    }

    protected function ctaBand(SiteContext $ctx): string
    {
        $cta = $this->e((string) ($ctx->design->get('hero.cta', 'Выбрать тариф')) ?: 'Выбрать тариф');
        return '<section class="section"><div class="container"><div class="cta-band"><h2>Готовы начать?</h2><p>Подключение занимает пару минут.</p><a class="btn" href="#pricing">' . $cta . '</a></div></div></section>';
    }

    protected function contacts(SiteContext $ctx): string
    {
        $c = $ctx->design->contacts();
        $rows = [];
        foreach (['email' => 'Email', 'telegram' => 'Telegram', 'vk' => 'VK', 'support' => 'Поддержка'] as $k => $lbl) {
            if (!empty($c[$k])) {
                $rows[] = '<div><span class="muted">' . $lbl . ':</span> ' . $this->e((string) $c[$k]) . '</div>';
            }
        }
        if (!$rows) {
            return '';
        }
        return '<section id="contacts" class="section"><div class="container" style="max-width:600px"><h2>Контакты</h2>' . implode('', $rows) . '</div></section>';
    }

    protected function footer(SiteContext $ctx): string
    {
        $brand = $this->e((string) ($ctx->design->get('brand.name', 'VPN')));
        $copy = $this->e((string) ($ctx->design->get('footer.copyright', '© ' . date('Y') . ' ' . $brand)));
        return '<footer class="site-foot"><div class="container"><div>' . $brand . '</div><div>' . $copy . '</div></div></footer>';
    }

    /** Отрисовать секции по порядку из дизайна (hero/features/pricing/how/benefits/faq/cta/contacts/footer). */
    protected function sectionsHtml(SiteContext $ctx): string
    {
        $map = [
            'hero' => fn() => $this->hero($ctx),
            'features' => fn() => $this->features($ctx),
            'pricing' => fn() => $this->pricingSection($ctx),
            'how' => fn() => $this->howItWorks($ctx),
            'benefits' => fn() => $this->benefits($ctx),
            'faq' => fn() => $this->faq($ctx),
            'cta' => fn() => $this->ctaBand($ctx),
            'contacts' => fn() => $this->contacts($ctx),
            'footer' => fn() => $this->footer($ctx),
        ];
        $out = '';
        foreach ($ctx->design->sections() as $s) {
            if (isset($map[$s])) {
                $out .= $map[$s]();
            }
        }
        return $out;
    }

    // ════════════════════════ ЛИЧНЫЙ КАБИНЕТ ════════════════════════

    /** Личный кабинет (dashboard) на реальных данных подписчика; 5 раскладок. */
    public function renderCabinet(SiteContext $ctx): string
    {
        $cab = $ctx->design->cabinet();
        $layout = (string) ($cab['layout'] ?? 'sidebar');
        if (!in_array($layout, ['sidebar', 'topnav', 'cards', 'premium', 'minimal'], true)) {
            $layout = 'sidebar';
        }
        $all = ['subscription' => 'Подписка', 'usage' => 'Трафик', 'devices' => 'Устройства', 'addons' => 'Дополнения', 'plans' => 'Тарифы', 'balance' => 'Баланс', 'payments' => 'Платежи', 'profile' => 'Профиль'];
        $enabled = $ctx->design->get('cabinet.blocks', array_keys($all));
        if (!is_array($enabled) || !$enabled) {
            $enabled = array_keys($all);
        }
        $blockHtml = [];
        foreach ($enabled as $b) {
            $m = 'cab' . ucfirst($b);
            if (method_exists($this, $m)) {
                $h = $this->$m($ctx);
                if ($h !== '') {
                    $blockHtml[$b] = '<section id="cab-' . $this->e($b) . '" class="cab-block">' . $h . '</section>';
                }
            }
        }
        $menu = '';
        foreach ($enabled as $b) {
            if (isset($blockHtml[$b])) {
                $menu .= '<a href="#cab-' . $this->e($b) . '">' . $this->e($all[$b] ?? $b) . '</a>';
            }
        }
        $brand = (string) ($ctx->design->get('brand.name', 'VPN'));
        $logo = (string) ($ctx->design->get('brand.logo', ''));
        $logoHtml = $logo !== '' ? '<img src="' . $this->e($logo) . '" alt="' . $this->e($brand) . '">' : $this->e($brand);
        $logout = '<form method="post" action="/portal-login.php" style="margin:0">' . $this->csrf() . '<input type="hidden" name="action" value="logout"><button class="btn ghost" type="submit">' . $this->e(t('portal.logout')) . '</button></form>';
        $verify = $ctx->needsVerify ? '<div class="cab-warn">' . $this->e(t('portal.verify_banner')) . ' ' . $this->e((string) ($ctx->subscriber['email'] ?? '')) . ' — <form method="post" action="/portal-login.php" style="display:inline">' . $this->csrf() . '<input type="hidden" name="action" value="resend"><button class="linklike" type="submit">' . $this->e(t('portal.resend')) . '</button></form></div>' : '';
        $hi = '<div class="cab-hi"><h1>' . $this->e(t('portal.hi')) . ', ' . $this->e((string) ($ctx->subscriber['name'] ?? '')) . '</h1></div>';
        $content = $verify . ($layout === 'sidebar' || $layout === 'topnav' ? $hi : '') . implode('', $blockHtml);

        $burger = '<button class="cab-burger" type="button" aria-label="Меню"><span></span><span></span><span></span></button>';
        if ($layout === 'sidebar') {
            $chrome = '<div class="cab-shell sidebar"><aside class="cab-side"><div class="cab-side-head"><div class="cab-logo">' . $logoHtml . '</div>' . $burger . '</div><nav class="cab-nav">' . $menu . '</nav><div class="cab-side-foot">' . $logout . '</div></aside><main class="cab-main">' . $content . '</main></div>';
        } elseif ($layout === 'topnav' || $layout === 'cards') {
            $gridClass = $layout === 'cards' ? ' cab-main--grid' : '';
            $chrome = '<header class="cab-top"><div class="cab-logo">' . $logoHtml . '</div>' . $burger . '<nav class="cab-nav">' . $menu . '</nav><div class="cab-logout">' . $logout . '</div></header><main class="cab-main' . $gridClass . '">' . $content . '</main>';
        } elseif ($layout === 'premium') {
            $chrome = '<header class="cab-top premium"><div class="cab-logo">' . $logoHtml . '</div><div>' . $logout . '</div></header><main class="cab-main cab-premium">' . $hi . $verify . implode('', $blockHtml) . '</main>';
        } else { // minimal
            $chrome = '<header class="cab-top minimal"><div class="cab-logo">' . $logoHtml . '</div><div>' . $logout . '</div></header><main class="cab-main cab-minimal">' . $content . '</main>';
        }
        // Вкладки: в раскладках с меню (sidebar/topnav) блоки переключаются, а не идут подряд.
        $tabs = in_array($layout, ['sidebar', 'topnav'], true);
        return $this->cabinetPage($ctx, $chrome, array_key_exists('devices', $blockHtml), $tabs);
    }

    protected function csrf(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . $this->e(\App\Auth::csrfToken()) . '">';
    }

    protected function cabinetPage(SiteContext $ctx, string $inner, bool $withQr, bool $tabs = false): string
    {
        $d = $ctx->design;
        $brand = $this->e((string) $d->get('brand.name', 'VPN'));
        $demo = $ctx->demo ? '<div class="demo-banner">DEMO — показаны примерные данные (админ-предпросмотр кабинета).</div>' : '';
        $qr = $withQr ? '<script src="/assets/vendor/qrcode.min.js"></script><script>document.querySelectorAll(".cab-qr").forEach(function(el){try{var q=qrcode(0,"M");q.addData(el.dataset.uri);q.make();el.innerHTML=q.createImgTag(4,6);}catch(e){el.remove();}});</script>' : '';
        // Вкладки: клик по пункту меню показывает один блок, прячет остальные.
        $tabJs = $tabs ? '<script>(function(){var links=[].slice.call(document.querySelectorAll(".cab-nav a[href^=\'#cab-\']")),blocks=[].slice.call(document.querySelectorAll(".cab-block"));if(!links.length||!blocks.length)return;function act(id){blocks.forEach(function(b){b.hidden=(b.id!==id);});links.forEach(function(l){l.classList.toggle("active",l.getAttribute("href")==="#"+id);});try{location.hash=id;}catch(e){}}links.forEach(function(l){l.addEventListener("click",function(e){e.preventDefault();act(l.getAttribute("href").slice(1));});});var want=(location.hash||"").slice(1);act(blocks.some(function(b){return b.id===want;})?want:blocks[0].id);})();</script>' : '';
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="x-vpsrouter-site" content="1">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<title>' . $this->e(t('portal.title')) . ' — ' . $brand . '</title>'
            . $this->fonts($d)
            . '<style>' . $this->baseCss() . $this->cssVars($d) . $this->cabinetCss() . ($d->advancedCss() !== '' ? "\n" . $d->advancedCss() : '') . '</style>'
            . '</head><body class="cab' . ($tabs ? ' cab-tabs' : '') . '">' . $demo . $inner . $qr . $tabJs
            . '<script>(function(){var b=document.querySelector(".cab-burger");if(!b)return;var box=b.closest(".cab-side,.cab-top");b.addEventListener("click",function(){box.classList.toggle("nav-open");});box.querySelectorAll(".cab-nav a").forEach(function(a){a.addEventListener("click",function(){box.classList.remove("nav-open");});});})();</script>'
            . '</body></html>';
    }

    protected function cabinetCss(): string
    {
        return 'body.cab{background:var(--bg)}'
            . '.cab-shell.sidebar{display:flex;min-height:100vh}'
            . '.cab-side{flex:0 0 240px;background:var(--surface);border-right:1px solid var(--border);padding:22px 18px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}'
            . '.cab-logo{font-family:var(--fh);font-weight:800;font-size:20px;margin-bottom:22px}.cab-logo img{max-height:34px}'
            . '.cab-nav{display:flex;flex-direction:column;gap:2px}.cab-top .cab-nav{flex-direction:row;gap:16px}'
            . '.cab-nav a{color:var(--muted);text-decoration:none;padding:9px 11px;border-radius:9px;font-weight:600;font-size:14px;transition:background .15s,color .15s}'
            . '.cab-nav a:hover{background:var(--surface2);color:var(--text)}'
            . '.cab-nav a.active{background:var(--primary);color:#fff}'
            . '.cab-side-foot{margin-top:auto}'
            . '.cab-top{display:flex;align-items:center;justify-content:space-between;gap:16px;background:var(--surface);border-bottom:1px solid var(--border);padding:14px 22px;position:sticky;top:0;z-index:5}'
            . '.cab-main{flex:1;max-width:1080px;margin:0 auto;width:100%;padding:26px 22px 60px}'
            . '.cab-main--grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;align-items:start}.cab-main--grid .cab-hi,.cab-main--grid .cab-warn{grid-column:1/-1}'
            . '.cab-premium{max-width:960px}.cab-minimal{max-width:640px}'
            . '.cab-hi h1{font-size:26px;margin:0 0 14px}'
            . '.cab-block{margin-bottom:18px}'
            . '.cab-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px}'
            . '.cab-card h2{font-size:17px;margin:0 0 12px}'
            . '.cab-warn{background:color-mix(in srgb,#f5b942 16%,transparent);border:1px solid color-mix(in srgb,#f5b942 45%,transparent);border-radius:12px;padding:10px 14px;margin-bottom:16px;font-size:14px}'
            . '.linklike{background:none;border:0;color:var(--primary);cursor:pointer;padding:0;font:inherit;text-decoration:underline}'
            . '.cab-kv{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--muted);font-size:14px;margin-bottom:12px}.cab-kv b{color:var(--text)}'
            . '.cab-bar{height:12px;border-radius:999px;background:var(--surface2);overflow:hidden;margin-top:8px}.cab-bar>i{display:block;height:100%;background:var(--primary)}'
            . '.cab-dev{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:10px}'
            . '.cab-dev .nm{font-weight:700}.cab-dev .uris{flex:1 1 100%;display:flex;flex-direction:column;gap:6px;margin-top:6px}'
            . '.cab-dev input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-family:ui-monospace,monospace;font-size:12px}'
            . '.cab-qr{background:#fff;padding:8px;border-radius:8px;display:inline-block}'
            . '.cab-table{width:100%;border-collapse:collapse;font-size:13.5px}.cab-table td,.cab-table th{text-align:left;padding:8px 6px;border-bottom:1px solid var(--border)}.cab-table th{color:var(--muted);font-weight:600}'
            . '.cab-plans{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}'
            . '.cab-plan{border:1px solid var(--border);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:8px}.cab-plan.pop{border-color:var(--popular)}'
            . '.cab-plan .pr{font-weight:800;font-size:24px;color:var(--price)}.cab-plan .pr small{font-size:13px;color:var(--muted);font-weight:500}'
            . '.cab-plan .btn{margin-top:auto}'
            . '.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
            . '.cab-side-head{display:flex;align-items:center;justify-content:space-between;gap:10px}'
            . '.cab-burger{display:none;flex-direction:column;gap:5px;background:none;border:0;cursor:pointer;padding:8px}'
            . '.cab-burger span{display:block;width:22px;height:2px;background:var(--text);border-radius:2px;transition:transform .25s ease,opacity .2s ease}'
            . '@media(max-width:820px){'
            . '.cab-shell.sidebar{flex-direction:column}.cab-side{flex-basis:auto;width:100%;height:auto;position:static;flex-direction:column;align-items:stretch}'
            . '.cab-top{flex-wrap:wrap}'
            . '.cab-burger{display:inline-flex}'
            . '.cab-nav{display:none;flex-direction:column;flex-wrap:nowrap;width:100%;gap:4px;margin-top:8px}'
            . '.cab-top .cab-nav{flex-direction:column;flex-basis:100%;order:3}.cab-top .cab-logout{order:2}'
            . '.cab-side.nav-open .cab-nav,.cab-top.nav-open .cab-nav{display:flex}'
            . '.cab-side-foot{margin:8px 0 0}'
            . '.nav-open .cab-burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}'
            . '.nav-open .cab-burger span:nth-child(2){opacity:0}'
            . '.nav-open .cab-burger span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}'
            . '.cab-main--grid{grid-template-columns:1fr}}';
    }

    // ── блоки кабинета (реальные данные; отсутствующие — скрываются) ──

    protected function cabSubscription(SiteContext $ctx): string
    {
        $sub = $ctx->subscription;
        $stLabel = ['active' => t('billing.st.active'), 'expired' => t('billing.st.expired'), 'cancelled' => t('billing.st.cancelled'), 'none' => t('billing.st.none')][$ctx->subState] ?? $ctx->subState;
        $stCls = ['active' => 'ok', 'expired' => 'down', 'cancelled' => 'unknown', 'none' => 'warn'][$ctx->subState] ?? 'unknown';
        $kv = '<div class="cab-kv"><div>' . $this->e(t('portal.status')) . ': <span class="badge ' . $stCls . '" style="color:#fff;background:var(--primary)">' . $this->e($stLabel) . '</span></div>';
        if ($sub && $ctx->subState !== 'none' && !empty($sub['expires_at'])) {
            $kv .= '<div>' . $this->e(t('billing.until')) . ' <b>' . $this->e(date('d.m.Y', strtotime((string) $sub['expires_at']))) . '</b></div>';
        }
        $kv .= '</div>';
        $cancel = '';
        if ($ctx->subState === 'active') {
            $cancel = '<form method="post" action="/portal.php" onsubmit="return confirm(\'' . $this->e(t('portal.cancel_confirm')) . '\')" style="margin-top:6px">' . $this->csrf()
                . '<input type="hidden" name="action" value="cancel"><button class="btn ghost" type="submit" style="font-size:13px">' . $this->e(t('portal.cancel_sub')) . '</button></form>';
        }
        return '<div class="cab-card"><h2>' . $this->e(t('portal.title')) . '</h2>' . $kv . $cancel . '</div>';
    }

    protected function cabBalance(SiteContext $ctx): string
    {
        $bal = '<div class="cab-kv"><div style="font-size:22px;font-weight:800;color:var(--text)">' . $this->e(\App\Billing::priceLabel($ctx->balance)) . ' ' . $this->e($ctx->currency) . '</div></div>';
        $top = '';
        if ($ctx->gateways) {
            $gw = $this->e((string) $ctx->gateways[0]);
            $top = '<form method="post" action="/portal.php" class="row" style="margin-top:8px">' . $this->csrf()
                . '<input type="hidden" name="action" value="topup"><input type="hidden" name="gateway" value="' . $gw . '">'
                . '<input type="number" step="0.01" min="1" name="amount" placeholder="' . $this->e(t('portal.topup_amount')) . '" required style="max-width:160px;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)">'
                . '<button class="btn" type="submit">' . $this->e(t('portal.topup')) . '</button></form>';
        }
        return '<div class="cab-card"><h2>' . $this->e(t('portal.balance')) . '</h2>' . $bal . $top . '</div>';
    }

    protected function cabAddons(SiteContext $ctx): string
    {
        if ($ctx->subState !== 'active' || ($ctx->addonTrafficPrice <= 0 && $ctx->addonDevicePrice <= 0)) {
            return ''; // допы не настроены или подписка не активна
        }
        $rows = '';
        if ($ctx->addonDevicePrice > 0) {
            $rows .= '<form method="post" action="/portal.php" class="row" style="margin-bottom:8px">' . $this->csrf()
                . '<input type="hidden" name="action" value="buy_addon"><input type="hidden" name="atype" value="device">'
                . '<span style="flex:1">' . $this->e(t('portal.addon_device')) . ' — ' . $this->e(\App\Billing::priceLabel($ctx->addonDevicePrice)) . ' ' . $this->e($ctx->currency) . '/' . $this->e(t('portal.dev_short')) . '</span>'
                . '<input type="number" min="1" value="1" name="qty" style="width:64px;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)">'
                . '<button class="btn" type="submit">' . $this->e(t('portal.buy_addon')) . '</button></form>';
        }
        if ($ctx->addonTrafficPrice > 0) {
            $rows .= '<form method="post" action="/portal.php" class="row">' . $this->csrf()
                . '<input type="hidden" name="action" value="buy_addon"><input type="hidden" name="atype" value="traffic">'
                . '<span style="flex:1">' . $this->e(t('portal.addon_traffic')) . ' — ' . $this->e(\App\Billing::priceLabel($ctx->addonTrafficPrice)) . ' ' . $this->e($ctx->currency) . '/' . $this->e(t('unit.gb')) . '</span>'
                . '<input type="number" min="1" value="5" name="qty" style="width:64px;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)">'
                . '<button class="btn" type="submit">' . $this->e(t('portal.buy_addon')) . '</button></form>';
        }
        $extras = ($ctx->extraDevices || $ctx->extraTraffic) ? '<p class="muted" style="font-size:12.5px;margin:10px 0 0">' . $this->e(t('portal.addons_active')) . ': +' . $ctx->extraDevices . ' ' . $this->e(t('portal.dev_short')) . ', +' . $this->e(\App\Billing::trafficLabel($ctx->extraTraffic) ?: '0') . '</p>' : '';
        return '<div class="cab-card"><h2>' . $this->e(t('portal.addons')) . '</h2><p class="muted" style="font-size:12.5px;margin:0 0 10px">' . $this->e(t('portal.addons_hint')) . '</p>' . $rows . $extras . '</div>';
    }

    protected function cabUsage(SiteContext $ctx): string
    {
        $u = $ctx->usage;
        if (($u['limit_gb'] ?? null) === null) {
            return ''; // нет лимита — блок скрыт
        }
        $limit = (float) $u['limit_gb'];
        $used = (float) ($u['used_gb'] ?? 0);
        $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
        $col = $pct >= 100 ? 'var(--danger,#ff5c6c)' : ($pct >= 90 ? '#f5b942' : 'var(--primary)');
        $blocked = !empty($u['blocked']) ? '<p class="muted" style="color:#ff5c6c;font-size:13px;margin:8px 0 0">' . $this->e(t('portal.traffic_blocked')) . '</p>' : '';
        return '<div class="cab-card"><h2>' . $this->e(t('portal.traffic')) . '</h2>'
            . '<div class="cab-kv"><div><b>' . $used . '</b> / ' . $limit . ' ' . $this->e(t('portal.gb')) . ' (' . $pct . '%)</div></div>'
            . '<div class="cab-bar"><i style="width:' . $pct . '%;background:' . $col . '"></i></div>' . $blocked . '</div>';
    }

    protected function cabDevices(SiteContext $ctx): string
    {
        $rows = '';
        foreach ($ctx->devices as $d) {
            $uris = '';
            $first = '';
            foreach (($d['uris'] ?? []) as $proto => $uri) {
                if ($first === '') {
                    $first = (string) $uri;
                }
                $uris .= '<input readonly onclick="this.select()" value="' . $this->e((string) $uri) . '">';
            }
            $qr = $first !== '' ? '<div class="cab-qr" data-uri="' . $this->e($first) . '"></div>' : '';
            $badge = empty($d['revoked']) ? '<span class="badge ok" style="background:var(--primary);color:#fff">' . $this->e(t('portal.dev_on')) . '</span>' : '<span class="badge">' . $this->e(t('portal.dev_off')) . '</span>';
            $del = isset($d['id']) ? '<form method="post" action="/portal.php" style="margin-left:auto" onsubmit="return confirm(\'' . $this->e(t('portal.confirm_del_device')) . '\')">' . $this->csrf() . '<input type="hidden" name="action" value="del_device"><input type="hidden" name="client_id" value="' . (int) $d['id'] . '"><button class="btn ghost" type="submit" style="padding:6px 12px;font-size:12px">' . $this->e(t('common.delete')) . '</button></form>' : '';
            $rows .= '<div class="cab-dev"><span class="nm">' . $this->e((string) ($d['name'] ?? '')) . '</span> ' . $badge . $del
                . ($qr || $uris ? '<div class="uris">' . $qr . $uris . '</div>' : '') . '</div>';
        }
        if ($rows === '') {
            $rows = '<p class="muted">' . $this->e(t('portal.no_devices')) . '</p>';
        }
        $add = '<form method="post" action="/portal.php" class="row" style="margin-top:10px">' . $this->csrf() . '<input type="hidden" name="action" value="add_device"><input type="text" name="name" placeholder="' . $this->e(t('portal.device_name')) . '" required style="max-width:240px;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)"><button class="btn" type="submit">' . $this->e(t('portal.add_device')) . '</button></form>';
        return '<div class="cab-card"><h2>' . $this->e(t('portal.devices')) . '</h2>' . $rows . $add . '</div>';
    }

    protected function cabPlans(SiteContext $ctx): string
    {
        if (!$ctx->plans) {
            return '';
        }
        $cards = '';
        foreach ($ctx->plans as $p) {
            $free = !empty($p['is_free']);
            $price = $free ? $this->e(t('portal.free')) : $this->e($p['price_label']) . ' <small>' . $this->e($p['currency']) . '</small>';
            $isCurrent = $ctx->currentPlanId !== null && (int) $p['id'] === $ctx->currentPlanId;
            if ($isCurrent) {
                $cta = '<span class="badge" style="background:var(--primary);color:#fff;align-self:flex-start">' . $this->e(t('portal.current_plan')) . '</span>';
            } elseif ($ctx->subState === 'active' && !$free) {
                // активная подписка + платный тариф → смена с пересчётом/доплатой
                $cta = '<form method="post" action="/portal.php" onsubmit="return confirm(\'' . $this->e(t('portal.change_confirm')) . '\')">' . $this->csrf() . '<input type="hidden" name="action" value="change_plan"><input type="hidden" name="plan_id" value="' . (int) $p['id'] . '"><button class="btn block" type="submit">' . $this->e(t('portal.change_plan')) . '</button></form>';
            } elseif ($free) {
                $cta = '<form method="post" action="/portal.php">' . $this->csrf() . '<input type="hidden" name="action" value="activate_free"><input type="hidden" name="plan_id" value="' . (int) $p['id'] . '"><button class="btn block" type="submit"' . ($ctx->subState === 'active' ? ' disabled' : '') . '>' . $this->e(t('portal.activate_free')) . '</button></form>';
            } elseif ($ctx->gateways) {
                $gw = $this->e((string) $ctx->gateways[0]);
                $cta = '<form method="post" action="/portal.php">' . $this->csrf() . '<input type="hidden" name="action" value="buy"><input type="hidden" name="plan_id" value="' . (int) $p['id'] . '"><input type="hidden" name="gateway" value="' . $gw . '"><button class="btn block" type="submit">' . $this->e(t('portal.pay')) . '</button></form>';
            } else {
                $cta = '<span class="muted" style="font-size:12px">' . $this->e(t('portal.no_gateway')) . '</span>';
            }
            $cards .= '<div class="cab-plan' . ($isCurrent ? ' pop' : ($p['featured'] ? ' pop' : '')) . '"><div style="font-weight:700">' . $this->e($p['name']) . '</div>'
                . '<div class="pr">' . $price . ' <small>/ ' . (int) $p['period_days'] . ' дн.</small></div>'
                . '<div class="muted" style="font-size:12.5px">' . (int) $p['device_limit'] . ' ' . $this->e(t('portal.dev_short')) . ($p['traffic_label'] ? ' · ' . $this->e($p['traffic_label']) : '') . '</div>'
                . $cta . '</div>';
        }
        $title = $ctx->subState === 'active' ? t('portal.renew') : t('portal.buy');
        return '<div class="cab-card"><h2>' . $this->e($title) . '</h2><div class="cab-plans">' . $cards . '</div></div>';
    }

    protected function cabPayments(SiteContext $ctx): string
    {
        if (!$ctx->payments) {
            return '';
        }
        $rows = '';
        foreach ($ctx->payments as $p) {
            $rows .= '<tr><td>' . $this->e(date('d.m.Y', strtotime((string) ($p['created_at'] ?? 'now')))) . '</td>'
                . '<td>' . $this->e(\App\Billing::priceLabel((float) ($p['amount'] ?? 0))) . ' ' . $this->e((string) ($p['currency'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($p['method'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($p['status'] ?? '')) . '</td></tr>';
        }
        return '<div class="cab-card"><h2>' . $this->e(t('billing.sec.payments')) . '</h2><table class="cab-table"><thead><tr><th>' . $this->e(t('billing.f.date')) . '</th><th>' . $this->e(t('billing.f.amount')) . '</th><th>' . $this->e(t('billing.f.method')) . '</th><th>' . $this->e(t('billing.f.status')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    protected function cabProfile(SiteContext $ctx): string
    {
        $s = $ctx->subscriber ?? [];
        $pw = '<form method="post" action="/portal.php" style="margin-top:14px;max-width:360px">' . $this->csrf()
            . '<input type="hidden" name="action" value="change_password">'
            . '<div style="font-weight:600;margin-bottom:8px">' . $this->e(t('portal.change_pw')) . '</div>'
            . '<input type="password" name="current_password" placeholder="' . $this->e(t('portal.current_pw')) . '" required autocomplete="current-password" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)">'
            . '<input type="password" name="new_password" placeholder="' . $this->e(t('portal.new_password')) . '" required minlength="8" autocomplete="new-password" style="width:100%;margin-top:8px;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)">'
            . '<div style="margin-top:10px"><button class="btn" type="submit">' . $this->e(t('common.save')) . '</button></div></form>';
        return '<div class="cab-card"><h2>' . $this->e(t('portal.name')) . '</h2>'
            . '<div class="cab-kv"><div><b>' . $this->e((string) ($s['name'] ?? '')) . '</b></div><div>' . $this->e((string) ($s['email'] ?? '')) . '</div></div>' . $pw . '</div>';
    }
}
