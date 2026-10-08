<?php

namespace App\Billing;

/** Реестр платёжных шлюзов + общий HTTP-помощник (curl). */
class GatewayRegistry
{
    /** @return PaymentGateway[] все известные шлюзы (сконфигурированные и нет) */
    public static function all(): array
    {
        return [new YooKassaGateway(), new CryptoCloudGateway()];
    }

    /** @return PaymentGateway[] только настроенные (есть ключи) */
    public static function configured(): array
    {
        return array_values(array_filter(self::all(), fn(PaymentGateway $g) => $g->isConfigured()));
    }

    public static function get(string $id): ?PaymentGateway
    {
        foreach (self::all() as $g) {
            if ($g->id() === $id) {
                return $g;
            }
        }
        return null;
    }

    /**
     * HTTP-запрос к API шлюза. Возвращает [status, body(array|null), raw].
     * @param array<string,string> $headers
     */
    public static function http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [$status, is_array($json) ? $json : null, is_string($raw) ? $raw : ''];
    }
}
