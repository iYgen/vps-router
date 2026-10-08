<?php

namespace App\Site\Renderer;

use App\Site\SiteContext;

/** Pricing-first: короткий hero, сразу плотная сетка тарифов. */
class PricingFirstRenderer extends AbstractRenderer
{
    public function key(): string
    {
        return 'pricing-first';
    }

    public function renderWebsite(SiteContext $ctx): string
    {
        return $this->page($ctx, $this->header($ctx) . $this->sectionsHtml($ctx));
    }

    protected function templateCss(SiteContext $ctx): string
    {
        return '.tpl-pricing-first .hero{padding:44px 0 8px}.tpl-pricing-first .hero h1{font-size:34px}.tpl-pricing-first .hero .sub{font-size:16px;margin-bottom:8px}'
            . '.tpl-pricing-first #pricing{padding-top:28px}'
            . '.tpl-pricing-first .pc{padding:20px 18px;gap:10px;border-radius:12px}.tpl-pricing-first .pc .pr{font-size:30px}'
            . '.tpl-pricing-first .pricing-grid{gap:14px}'
            . '.tpl-pricing-first .pc .nm{font-size:16px}'
            // быстрые «снэпповые» реакции, подсветка акцентной полосой сверху
            . '.tpl-pricing-first .pc{transition:transform .12s ease,box-shadow .15s ease,border-color .12s ease;border-top:3px solid transparent}'
            . '.tpl-pricing-first .pc:hover{transform:translateY(-3px) scale(1.012);border-top-color:var(--primary);box-shadow:0 16px 34px rgba(15,27,29,.14)}'
            . '.tpl-pricing-first .btn{transition-duration:.1s}';
    }
}
