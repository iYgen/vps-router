<?php

namespace App\Site;

use App\Models\SiteDesign;

/** Фасад: выбрать дизайн → собрать контекст → отрендерить нужным шаблоном. */
class Site
{
    /** HTML публичного сайта для строки дизайна (или дефолта), $preview — демо при отсутствии тарифов. */
    public static function website(?array $designRow, bool $preview = false, string $device = 'desktop'): string
    {
        $template = (string) ($designRow['template'] ?? 'modern-saas');
        if (!TemplateRegistry::get($template)) {
            $template = 'modern-saas';
        }
        $config = SiteDesign::decode($designRow);
        $ctx = SiteContext::build($template, $config, $preview, $device);
        $class = TemplateRegistry::rendererClass($template);
        /** @var Renderer\AbstractRenderer $renderer */
        $renderer = new $class();
        return $renderer->renderWebsite($ctx);
    }

    /** HTML личного кабинета (использует палитру/типографику опубликованного дизайна). */
    public static function cabinet(?array $designRow, array $data, string $device = 'desktop'): string
    {
        $template = (string) ($designRow['template'] ?? 'modern-saas');
        if (!TemplateRegistry::get($template)) {
            $template = 'modern-saas';
        }
        $config = SiteDesign::decode($designRow);
        $ctx = !empty($data['demo'])
            ? SiteContext::cabinetDemo($template, $config, $device)
            : SiteContext::cabinet($template, $config, $data, $device);
        $class = TemplateRegistry::rendererClass($template);
        /** @var Renderer\AbstractRenderer $renderer */
        $renderer = new $class();
        return $renderer->renderCabinet($ctx);
    }
}
