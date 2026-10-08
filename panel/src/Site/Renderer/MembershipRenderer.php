<?php

namespace App\Site\Renderer;

use App\Site\SiteContext;

/** Подписочный продукт/membership: «как это работает», планы с преимуществами, сильный CTA. */
class MembershipRenderer extends AbstractRenderer
{
    public function key(): string
    {
        return 'membership';
    }

    public function renderWebsite(SiteContext $ctx): string
    {
        return $this->page($ctx, $this->header($ctx) . $this->sectionsHtml($ctx));
    }

    protected function templateCss(SiteContext $ctx): string
    {
        return '.tpl-membership .hero{padding:88px 0 56px}'
            . '.tpl-membership .pc{padding:32px 26px;border-radius:20px}'
            . '.tpl-membership .pc.pop{background:linear-gradient(180deg,color-mix(in srgb,var(--popular) 10%,var(--surface)),var(--surface))}'
            . '.tpl-membership .pc ul li{padding:2px 0}'
            . '.tpl-membership .cta-band{background:linear-gradient(135deg,var(--primary),color-mix(in srgb,var(--popular) 80%,#000));color:#fff}'
            . '.tpl-membership .cta-band h2,.tpl-membership .cta-band p{color:#fff}.tpl-membership .cta-band .btn{background:#fff;color:var(--primary)}'
            // premium-лифт карточек, галочки «проявляются», шиммер на CTA
            . '.tpl-membership .pc:hover{transform:translateY(-7px);box-shadow:0 30px 70px color-mix(in srgb,var(--popular) 22%,rgba(0,0,0,.12))}'
            . '.tpl-membership .pc ul li{transition:transform .2s ease}.tpl-membership .pc:hover ul li{transform:translateX(3px)}'
            . '.tpl-membership .pc ul li::before{transition:transform .2s ease}.tpl-membership .pc:hover ul li::before{transform:scale(1.25)}'
            . '.tpl-membership .cta-band .btn{background-image:linear-gradient(90deg,#fff 0 40%,color-mix(in srgb,var(--primary) 18%,#fff) 50%,#fff 60% 100%);background-size:220% 100%;background-position:100% 0}'
            . '.tpl-membership .cta-band .btn:hover{animation:memShm 1s linear infinite;transform:translateY(-2px) scale(1.04)}'
            . '@keyframes memShm{to{background-position:-120% 0}}';
    }
}
