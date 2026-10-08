<?php

namespace App\Billing;

use App\Models\Setting;

/**
 * CryptoCloud. Ключи: billing_cc_api_key + billing_cc_shop_id (Setting).
 * createPayment → /v2/invoice/create; verifyPaid → /v2/invoice/merchant/info
 * (истина — ответ API). https://docs.cryptocloud.plus
 */
class CryptoCloudGateway implements PaymentGateway
{
    private const API = 'https://api.cryptocloud.plus/v2';

    public function id(): string
    {
        return 'cryptocloud';
    }

    private function apiKey(): string
    {
        return trim((string) Setting::get('billing_cc_api_key', ''));
    }

    private function shopId(): string
    {
        return trim((string) Setting::get('billing_cc_shop_id', ''));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->shopId() !== '';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Token ' . $this->apiKey(), 'Content-Type' => 'application/json'];
    }

    public function createPayment(array $ctx): array
    {
        $payload = json_encode([
            'amount'   => (float) $ctx['amount'],
            'currency' => $ctx['currency'] ?? 'RUB',
            'shop_id'  => $this->shopId(),
            'order_id' => (string) ($ctx['order_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        [$status, $json] = GatewayRegistry::http('POST', self::API . '/invoice/create', $this->headers(), $payload);
        if ($status >= 300 || !$json || ($json['status'] ?? '') !== 'success' || empty($json['result']['uuid'])) {
            throw new \RuntimeException('CryptoCloud: ' . ($json['result'] ?? "HTTP $status"));
        }
        return [
            'url'         => (string) ($json['result']['link'] ?? ''),
            'external_id' => (string) $json['result']['uuid'],
        ];
    }

    public function externalIdFromWebhook(array $headers, string $rawBody): ?string
    {
        parse_str($rawBody, $form);
        $id = $form['invoice_id'] ?? ($form['uuid'] ?? null);
        if (!$id) {
            $d = json_decode($rawBody, true);
            $id = is_array($d) ? ($d['invoice_id'] ?? ($d['uuid'] ?? null)) : null;
        }
        return $id ? (string) $id : null;
    }

    public function verifyPaid(string $externalId): bool
    {
        if (!preg_match('/^[A-Z0-9_-]{1,64}$/i', $externalId)) {
            return false;
        }
        $payload = json_encode(['uuids' => [$externalId]]);
        [$status, $json] = GatewayRegistry::http('POST', self::API . '/invoice/merchant/info', $this->headers(), $payload);
        if ($status >= 300 || !$json || ($json['status'] ?? '') !== 'success') {
            return false;
        }
        foreach ((array) ($json['result'] ?? []) as $inv) {
            if (($inv['uuid'] ?? '') === $externalId && in_array(($inv['status'] ?? ''), ['paid', 'overpaid'], true)) {
                return true;
            }
        }
        return false;
    }
}
