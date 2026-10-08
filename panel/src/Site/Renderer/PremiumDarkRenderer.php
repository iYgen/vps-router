<?php

namespace App\Site\Renderer;

use App\Site\SiteContext;

/** Премиальный тёмный: крупный hero с подсветкой, стеклянные карточки, глубина. */
class PremiumDarkRenderer extends AbstractRenderer
{
    public function key(): string
    {
        return 'premium-dark';
    }

    public function renderWebsite(SiteContext $ctx): string
    {
        return $this->page($ctx, $this->header($ctx, 'centered') . $this->sectionsHtml($ctx));
    }

    protected function templateCss(SiteContext $ctx): string
    {
        return '.tpl-premium-dark .hero{position:relative;overflow:hidden;padding:110px 0 80px}'
            . '.tpl-premium-dark .hero::before{content:"";position:absolute;inset:-40% 0 auto 0;height:600px;background:radial-gradient(600px 300px at 50% 0,color-mix(in srgb,var(--primary) 40%,transparent),transparent);pointer-events:none}'
            . '.tpl-premium-dark h1{font-size:calc(var(--h1) + 10px)}'
            . '.tpl-premium-dark .pc{background:color-mix(in srgb,var(--surface) 70%,transparent);backdrop-filter:blur(12px);border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.35)}'
            . '.tpl-premium-dark .pc.glass{background:color-mix(in srgb,var(--surface) 55%,transparent)}'
            . '.tpl-premium-dark .pc.premium{transform:scale(1.05);border-color:var(--popular);box-shadow:0 0 0 1px var(--popular),0 24px 70px color-mix(in srgb,var(--popular) 35%,transparent)}'
            . '.tpl-premium-dark .pc .pr{font-size:44px}'
            . '@media(max-width:860px){.tpl-premium-dark .pc.premium{transform:none}}'
            // усиление свечения при наведении + «дыхание» у featured
            . '.tpl-premium-dark .pc:hover{transform:translateY(-8px);box-shadow:0 0 0 1px var(--popular),0 34px 90px color-mix(in srgb,var(--popular) 45%,transparent)}'
            . '.tpl-premium-dark .pc.premium:hover{transform:scale(1.07) translateY(-6px)}'
            . '.tpl-premium-dark .pc.premium{animation:pdGlow 3.6s ease-in-out infinite}'
            . '@keyframes pdGlow{0%,100%{box-shadow:0 0 0 1px var(--popular),0 24px 70px color-mix(in srgb,var(--popular) 30%,transparent)}50%{box-shadow:0 0 0 1px var(--popular),0 24px 90px color-mix(in srgb,var(--popular) 55%,transparent)}}'
            . '.tpl-premium-dark .btn:hover{box-shadow:0 0 28px color-mix(in srgb,var(--primary) 60%,transparent)}';
    }
}
