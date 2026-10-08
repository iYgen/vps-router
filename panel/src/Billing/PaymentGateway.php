<?php

namespace App\Billing;

/**
 * Платёжный шлюз. Контракт прост и безопасен: webhook НЕ является источником
 * истины — он лишь триггерит verify(), который переспрашивает статус у самого
 * шлюза по его API. Так подделанный webhook не может «оплатить» подписку.
 */
interface PaymentGateway
{
    public function id(): string;

    /** Настроен ли (есть ключи) — иначе шлюз не показывается и не используется. */
    public function isConfigured(): bool;

    /**
     * Создать платёж. $ctx: amount, currency, description, return_url, order_id.
     * @return array{url:string, external_id:string}
     */
    public function createPayment(array $ctx): array;

    /**
     * Разобрать входящий webhook и вернуть external_id платежа для проверки,
     * либо null, если это не наш/непонятный запрос. Подтверждение статуса —
     * отдельным verify() (переспрос у API шлюза).
     */
    public function externalIdFromWebhook(array $headers, string $rawBody): ?string;

    /** Переспросить у API шлюза: оплачен ли платёж $externalId. */
    public function verifyPaid(string $externalId): bool;
}
