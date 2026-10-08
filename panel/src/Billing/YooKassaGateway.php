<?php

namespace App\Billing;

use App\Models\Setting;

/**
 * YooKassa (ЮKassa). Ключи: billing_yk_shop_id + billing_yk_secret (Setting).
 * createPayment → payment с confirmation.redirect; verifyPaid → GET /payments/{id}
 * (истина — ответ API, не webhook). https://yookassa.ru/developers/api
 */
class YooKassaGateway implements PaymentGateway
{
    private const API = 'https://api.yookassa.ru/v3';

    public function id(): string
    {
        return 'yookassa';
    }

    private function shopId(): string
    {
        return trim((string) Setting::get('billing_yk_shop_id', ''));
    }

    private function secret(): string
    {
        return trim((string) Setting::get('billing_yk_secret', ''));
    }

    public function isConfigured(): bool
    {
        return $this->shopId() !== '' && $this->secret() !== '';
    }

    private function auth(): string
    {
        return 'Basic ' . base64_encode($this->shopId() . ':' . $this->secret());
    }

    public function createPayment(array $ctx): array
    {
        $payload = json_encode([
            'amount'       => ['value' => number_format((float) $ctx['amount'], 2, '.', ''), 'currency' => $ctx['currency'] ?? 'RUB'],
            'capture'      => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $ctx['return_url']],
            'description'  => (string) ($ctx['description'] ?? 'Subscription'),
            'metadata'     => ['order_id' => (string) ($ctx['order_id'] ?? '')],
        ], JSON_UNESCAPED_UNICODE);
        [$status, $json] = GatewayRegistry::http('POST', self::API . '/payments', [
            'Authorization'   => $this->auth(),
            'Idempotence-Key' => bin2hex(random_bytes(16)),
            'Content-Type'    => 'application/json',
        ], $payload);
        if ($status >= 300 || !$json || empty($json['id'])) {
            throw new \RuntimeException('YooKassa: ' . ($json['description'] ?? "HTTP $status"));
        }
        return [
            'url'         => (string) ($json['confirmation']['confirmation_url'] ?? ''),
            'external_id' => (string) $json['id'],
        ];
    }

    public function externalIdFromWebhook(array $headers, string $rawBody): ?string
    {
        $d = json_decode($rawBody, true);
        return is_array($d) && !empty($d['object']['id']) ? (string) $d['object']['id'] : null;
    }

    public function verifyPaid(string $externalId): bool
    {
        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $externalId)) {
            return false;
        }
        [$status, $json] = GatewayRegistry::http('GET', self::API . '/payments/' . $externalId, [
            'Authorization' => $this->auth(),
        ]);
        return $status < 300 && is_array($json) && ($json['status'] ?? '') === 'succeeded' && !empty($json['paid']);
    }
}
