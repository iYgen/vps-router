<?php

namespace App\Site\Renderer;

use App\Site\SiteContext;

/** Яркий рекламно-редакционный: асимметричные карточки, декор, крупная цена. */
class EditorialRenderer extends AbstractRenderer
{
    public function key(): string
    {
        return 'editorial';
    }

    public function renderWebsite(SiteContext $ctx): string
    {
        return $this->page($ctx, $this->header($ctx) . $this->sectionsHtml($ctx));
    }

    protected function templateCss(SiteContext $ctx): string
    {
        return '.tpl-editorial body,.tpl-editorial{--shadow:0 30px 80px rgba(0,0,0,.08)}'
            . '.tpl-editorial .hero{padding:90px 0}.tpl-editorial h1{font-size:calc(var(--h1) + 16px);letter-spacing:-.03em}'
            . '.tpl-editorial .hero .art{aspect-ratio:1/1;border-radius:32px;background:conic-gradient(from 210deg,color-mix(in srgb,var(--primary) 55%,transparent),color-mix(in srgb,var(--popular) 20%,transparent),transparent)}'
            . '.tpl-editorial .pc{box-shadow:0 30px 80px rgba(0,0,0,.08);border:0}'
            . '.tpl-editorial .pc.editorial{background:linear-gradient(160deg,var(--surface),var(--surface2))}'
            . '.tpl-editorial .pc .pr{color:var(--price)}'
            . '.tpl-editorial .section:nth-child(even){background:var(--surface2)}'
            // игривое движение: лёгкий наклон + масштаб, медленный поворот арт-блока
            . '.tpl-editorial .pc{transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s ease}'
            . '.tpl-editorial .pc:hover{transform:translateY(-8px) rotate(-.8deg) scale(1.02);box-shadow:0 40px 90px rgba(0,0,0,.14)}'
            . '.tpl-editorial .pc.editorial:hover{transform:translateY(-8px) scale(1.03)}'
            . '.tpl-editorial .hero .art{transition:transform .6s ease}.tpl-editorial .hero:hover .art{transform:rotate(8deg) scale(1.03)}'
            . '.tpl-editorial .btn:hover{transform:translateY(-2px) scale(1.03)}';
    }
}
