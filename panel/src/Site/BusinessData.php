<?php

namespace App\Site;

use App\Billing;
use App\Models\Plan;

/**
 * Единый источник БИЗНЕС-данных для всех renderer'ов. Тянет реальные тарифы из
 * billing-системы (Plan). Demo-данные отдаёт ТОЛЬКО для админ-preview и помечает
 * их как demo — в production fake-данных нет (§101).
 */
class BusinessData
{
    /**
     * Нормализованные тарифы для вывода: реальные поля billing + готовые подписи.
     * @return array<int,array>
     */
    public static function plans(): array
    {
        return array_map([self::class, 'normalize'], Plan::all(true));
    }

    public static function hasPlans(): bool
    {
        return self::plans() !== [];
    }

    /** Demo-тарифы ТОЛЬКО для админ-preview, когда реальных ещё нет. */
    public static function demoPlans(): array
    {
        $mk = fn($name, $price, $days, $gb, $dev, $feat, $featured = 0) => self::normalize([
            'id' => 0, 'name' => $name, 'price' => $price, 'currency' => 'RUB', 'period_days' => $days,
            'device_limit' => $dev, 'traffic_gb' => $gb, 'description' => '', 'features' => $feat, 'featured' => $featured,
        ]);
        return [
            $mk('Лайт', 199, 30, 10, 1, "10 ГБ трафика\n1 устройство\nБазовый доступ"),
            $mk('Медиум', 299, 30, 15, 2, "15 ГБ трафика\n2 устройства\nПриоритетный доступ", 1),
            $mk('Максимум', 599, 30, 50, 5, "50 ГБ трафика\n5 устройств\nПоддержка 24/7"),
        ];
    }

    private static function normalize(array $p): array
    {
        $tg = $p['traffic_gb'] ?? null;
        $features = trim((string) ($p['features'] ?? ''));
        return [
            'id'            => (int) ($p['id'] ?? 0),
            'name'          => (string) ($p['name'] ?? ''),
            'price'         => (float) ($p['price'] ?? 0),
            'price_label'   => Billing::priceLabel((float) ($p['price'] ?? 0)),
            'currency'      => (string) ($p['currency'] ?? 'RUB'),
            'period_days'   => (int) ($p['period_days'] ?? 30),
            'device_limit'  => (int) ($p['device_limit'] ?? 1),
            'traffic_gb'    => $tg !== null ? (float) $tg : null,
            'traffic_label' => Billing::trafficLabel($tg !== null ? (float) $tg : null),
            'description'   => (string) ($p['description'] ?? ''),
            'features'      => $features === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $features)))),
            'featured'      => !empty($p['featured']),
            'is_free'       => ((float) ($p['price'] ?? 0)) == 0.0,
        ];
    }

    /** Куда ведёт CTA тарифа — существующий billing-flow (регистрация/вход → покупка). */
    public static function ctaUrl(): string
    {
        return '/portal-login.php';
    }
}
