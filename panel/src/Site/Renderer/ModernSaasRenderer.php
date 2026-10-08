<?php

namespace App\Site\Renderer;

use App\Site\SiteContext;

/** Светлый минималистичный SaaS: много воздуха, ровные классические карточки. */
class ModernSaasRenderer extends AbstractRenderer
{
    public function key(): string
    {
        return 'modern-saas';
    }

    public function renderWebsite(SiteContext $ctx): string
    {
        return $this->page($ctx, $this->header($ctx) . $this->sectionsHtml($ctx));
    }

    protected function templateCss(SiteContext $ctx): string
    {
        return '.tpl-modern-saas .section{padding:76px 0}'
            . '.tpl-modern-saas h1{letter-spacing:-.02em}'
            . '.tpl-modern-saas .pc{box-shadow:0 1px 2px rgba(16,24,40,.04)}'
            . '.tpl-modern-saas .hero .sub{color:var(--muted)}'
            // спокойный ровный лифт
            . '.tpl-modern-saas .pc:hover{box-shadow:0 20px 48px rgba(16,24,40,.12)}'
            . '.tpl-modern-saas .pc .pr{transition:color .2s ease}.tpl-modern-saas .pc:hover .pr{color:var(--primary)}';
    }
}
