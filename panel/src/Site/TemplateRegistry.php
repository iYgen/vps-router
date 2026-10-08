<?php

namespace App\Site;

/**
 * Реестр СТРУКТУРНЫХ шаблонов. Шаблон задаёт композицию (порядок секций, стиль
 * pricing, hero, ширину) и дефолтную палитру/типографику. Один и тот же бизнес-
 * dataset рисуется разными renderer'ами по-разному (не switch по цвету).
 */
class TemplateRegistry
{
    /** @return array<string,array> key => {name,description,renderer,max_plans,defaults} */
    public static function all(): array
    {
        return [
            'modern-saas' => [
                'name'        => 'Modern SaaS',
                'description' => 'Светлый минимализм: много воздуха, 3–4 ровные карточки, спокойная типографика.',
                'renderer'    => Renderer\ModernSaasRenderer::class,
                'max_plans'   => 4,
                'defaults'    => [
                    'palette'    => ['preset' => 'minimal', 'primary' => '#2563eb', 'bg' => '#f7f8fb', 'surface' => '#ffffff', 'surface2' => '#eef1f6', 'text' => '#14141a', 'muted' => '#667085', 'border' => 'rgba(16,24,40,.10)', 'price' => '#14141a', 'popular' => '#2563eb', 'dark' => 0],
                    'typography' => ['heading' => 'Inter', 'body' => 'Inter', 'scale' => 'normal'],
                    'layout'     => ['width' => 'contained', 'radius' => '14px'],
                    'hero'       => ['enabled' => 1, 'layout' => 'centered', 'title' => 'Доступ к вашей сети без лишних ограничений', 'subtitle' => 'Быстро, стабильно, под контролем.', 'cta' => 'Выбрать тариф'],
                    'pricing'    => ['layout' => '3col', 'card' => 'classic'],
                    'sections'   => ['hero', 'features', 'pricing', 'faq', 'cta', 'footer'],
                ],
            ],
            'premium-dark' => [
                'name'        => 'Premium Dark',
                'description' => 'Тёмный премиум: крупный hero с подсветкой, стеклянные карточки, акцент на «популярном».',
                'renderer'    => Renderer\PremiumDarkRenderer::class,
                'max_plans'   => 4,
                'defaults'    => [
                    'palette'    => ['preset' => 'midnight', 'primary' => '#7c5cff', 'bg' => '#0e0f1a', 'surface' => '#17182a', 'surface2' => '#1f2138', 'text' => '#eef0ff', 'muted' => '#9aa0c0', 'border' => 'rgba(255,255,255,.12)', 'price' => '#ffffff', 'popular' => '#7c5cff', 'dark' => 1],
                    'typography' => ['heading' => 'Manrope', 'body' => 'Inter', 'scale' => 'large'],
                    'layout'     => ['width' => 'contained', 'radius' => '18px'],
                    'hero'       => ['enabled' => 1, 'layout' => 'centered', 'title' => 'Ваш интернет. Без лишних ограничений.', 'subtitle' => 'Защищённый доступ к вашей инфраструктуре.', 'cta' => 'Выбрать тариф'],
                    'pricing'    => ['layout' => 'featured-center', 'card' => 'glass'],
                    'sections'   => ['hero', 'pricing', 'features', 'faq', 'footer'],
                ],
            ],
            'editorial' => [
                'name'        => 'Editorial / Promo',
                'description' => 'Яркий рекламный стиль: асимметричные карточки разного размера, декоративные акценты, бейджи.',
                'renderer'    => Renderer\EditorialRenderer::class,
                'max_plans'   => 3,
                'defaults'    => [
                    'palette'    => ['preset' => 'promo', 'primary' => '#ff4f87', 'bg' => '#fff7f2', 'surface' => '#ffffff', 'surface2' => '#fdeee6', 'text' => '#1b1726', 'muted' => '#8a7f93', 'border' => 'rgba(27,23,38,.10)', 'price' => '#ff3d6e', 'popular' => '#ff4f87', 'dark' => 0],
                    'typography' => ['heading' => 'Unbounded', 'body' => 'Inter', 'scale' => 'xl'],
                    'layout'     => ['width' => 'full', 'radius' => '22px'],
                    'hero'       => ['enabled' => 1, 'layout' => 'split', 'title' => 'Больше свободы в сети', 'subtitle' => 'Подключайтесь за минуту.', 'cta' => 'Подключить'],
                    'pricing'    => ['layout' => 'editorial', 'card' => 'editorial'],
                    'sections'   => ['hero', 'pricing', 'features', 'contacts', 'footer'],
                ],
            ],
            'pricing-first' => [
                'name'        => 'Pricing-first',
                'description' => 'Короткий hero и сразу плотная сетка тарифов — для тех, кто хочет быстро увидеть цены.',
                'renderer'    => Renderer\PricingFirstRenderer::class,
                'max_plans'   => 6,
                'defaults'    => [
                    'palette'    => ['preset' => 'ocean', 'primary' => '#0ea5a0', 'bg' => '#f3f7f8', 'surface' => '#ffffff', 'surface2' => '#e7eef0', 'text' => '#0f1b1d', 'muted' => '#5c7378', 'border' => 'rgba(15,27,29,.10)', 'price' => '#0f766e', 'popular' => '#0ea5a0', 'dark' => 0],
                    'typography' => ['heading' => 'Inter', 'body' => 'Inter', 'scale' => 'normal'],
                    'layout'     => ['width' => 'contained', 'radius' => '12px'],
                    'hero'       => ['enabled' => 1, 'layout' => 'compact', 'title' => 'Тарифы', 'subtitle' => 'Выберите подходящий план.', 'cta' => ''],
                    'pricing'    => ['layout' => '4col', 'card' => 'compact'],
                    'sections'   => ['hero', 'pricing', 'features', 'faq', 'footer'],
                ],
            ],
            'membership' => [
                'name'        => 'Product / Membership',
                'description' => 'Сервис как подписочный продукт: как это работает, планы с преимуществами, сильный CTA.',
                'renderer'    => Renderer\MembershipRenderer::class,
                'max_plans'   => 4,
                'defaults'    => [
                    'palette'    => ['preset' => 'purple', 'primary' => '#6d28d9', 'bg' => '#faf8ff', 'surface' => '#ffffff', 'surface2' => '#f1ecfb', 'text' => '#1c1630', 'muted' => '#6b647e', 'border' => 'rgba(28,22,48,.10)', 'price' => '#6d28d9', 'popular' => '#6d28d9', 'dark' => 0],
                    'typography' => ['heading' => 'Manrope', 'body' => 'Inter', 'scale' => 'large'],
                    'layout'     => ['width' => 'contained', 'radius' => '18px'],
                    'hero'       => ['enabled' => 1, 'layout' => 'centered', 'title' => 'Членство в приватной сети', 'subtitle' => 'Один аккаунт — все ваши устройства.', 'cta' => 'Присоединиться'],
                    'pricing'    => ['layout' => '3col', 'card' => 'feature-first'],
                    'sections'   => ['hero', 'how', 'pricing', 'benefits', 'faq', 'cta', 'footer'],
                ],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function defaults(string $key): array
    {
        return self::all()[$key]['defaults'] ?? (self::all()['modern-saas']['defaults']);
    }

    public static function rendererClass(string $key): string
    {
        $r = self::all()[$key]['renderer'] ?? null;
        return $r ?: Renderer\ModernSaasRenderer::class;
    }
}
