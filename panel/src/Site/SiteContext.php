<?php

namespace App\Site;

/** Контекст рендера: design-config + бизнес-данные + режим (preview/demo). */
class SiteContext
{
    // Данные кабинета (заполняются фабрикой cabinet()).
    public ?array $subscriber = null;
    public ?array $subscription = null;
    public array $usage = [];
    public array $devices = [];           // [{name, revoked, uris:[proto=>uri]}]
    public array $payments = [];
    public array $gateways = [];          // id настроенных шлюзов
    public string $subState = 'none';     // active|expired|cancelled|none
    public bool $needsVerify = false;
    public float $balance = 0;
    public string $currency = 'RUB';
    public ?int $currentPlanId = null;
    public float $addonTrafficPrice = 0;
    public float $addonDevicePrice = 0;
    public float $extraTraffic = 0;
    public int $extraDevices = 0;

    public function __construct(
        public DesignConfig $design,
        public array $plans,
        public bool $isPreview = false,
        public bool $demo = false,
        public string $device = 'desktop'
    ) {
    }

    /**
     * Контекст публичного сайта. В preview без реальных тарифов — demo (помеченные).
     */
    public static function build(string $template, array $savedConfig, bool $isPreview = false, string $device = 'desktop'): self
    {
        $plans = BusinessData::plans();
        $demo = false;
        if ($plans === [] && $isPreview) {
            $plans = BusinessData::demoPlans();
            $demo = true;
        }
        return new self(new DesignConfig($template, $savedConfig), $plans, $isPreview, $demo, $device);
    }

    /** Контекст личного кабинета (реальные данные подписчика). */
    public static function cabinet(string $template, array $savedConfig, array $data, string $device = 'desktop'): self
    {
        $ctx = new self(new DesignConfig($template, $savedConfig), $data['plans'] ?? [], false, !empty($data['demo']), $device);
        $ctx->subscriber = $data['subscriber'] ?? null;
        $ctx->subscription = $data['subscription'] ?? null;
        $ctx->usage = $data['usage'] ?? [];
        $ctx->devices = $data['devices'] ?? [];
        $ctx->payments = $data['payments'] ?? [];
        $ctx->gateways = $data['gateways'] ?? [];
        $ctx->subState = (string) ($data['subState'] ?? 'none');
        $ctx->needsVerify = !empty($data['needsVerify']);
        $ctx->balance = (float) ($data['balance'] ?? 0);
        $ctx->currency = (string) ($data['currency'] ?? 'RUB');
        $ctx->currentPlanId = isset($data['current_plan_id']) ? (int) $data['current_plan_id'] : null;
        $ctx->addonTrafficPrice = (float) ($data['addon_traffic_price'] ?? 0);
        $ctx->addonDevicePrice = (float) ($data['addon_device_price'] ?? 0);
        $ctx->extraTraffic = (float) ($data['extra_traffic'] ?? 0);
        $ctx->extraDevices = (int) ($data['extra_devices'] ?? 0);
        return $ctx;
    }

    /** Demo-данные кабинета для админ-preview (помечены). */
    public static function cabinetDemo(string $template, array $savedConfig, string $device = 'desktop'): self
    {
        $plans = BusinessData::plans() ?: BusinessData::demoPlans();
        return self::cabinet($template, $savedConfig, [
            'demo'         => true,
            'subscriber'   => ['name' => 'Demo User', 'email' => 'demo@example.com'],
            'subscription' => ['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() + 20 * 86400)],
            'usage'        => ['limit_gb' => 50, 'used_gb' => 32.4, 'blocked' => false],
            'devices'      => [
                ['name' => 'Ноутбук', 'revoked' => 0, 'uris' => ['vless' => 'vless://demo@host:443?...']],
                ['name' => 'Телефон', 'revoked' => 0, 'uris' => []],
            ],
            'payments'     => [
                ['created_at' => date('Y-m-d H:i:s', time() - 5 * 86400), 'amount' => 599, 'currency' => 'RUB', 'method' => 'yookassa', 'status' => 'paid'],
            ],
            'plans'        => $plans,
            'gateways'     => [],
            'subState'     => 'active',
        ], $device);
    }
}
