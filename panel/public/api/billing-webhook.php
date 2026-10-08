<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Billing;
use App\Billing\GatewayRegistry;
use App\Models\AuditLog;

/**
 * Приём webhook от платёжного шлюза. ПУБЛИЧНЫЙ (шлюз не знает CSRF/сессию), но
 * webhook НЕ источник истины: из тела берём только external_id, а факт оплаты
 * переспрашиваем у API шлюза (verifyPaid). Поэтому подделать оплату нельзя.
 * URL для шлюза: /api/billing-webhook.php?gw=yookassa (или cryptocloud).
 */
header('Content-Type: application/json');

$gwId = $_GET['gw'] ?? '';
$gw = GatewayRegistry::get((string) $gwId);
if (!$gw || !$gw->isConfigured()) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

$externalId = $gw->externalIdFromWebhook($headers, $raw);
if (!$externalId) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    if ($gw->verifyPaid($externalId)) {
        Billing::applyGatewayPayment($gw->id(), $externalId);
        AuditLog::record('billing.webhook_paid', $gw->id() . ':' . $externalId);
        echo json_encode(['ok' => true]);
    } else {
        // Платёж ещё не оплачен/не подтверждён — отвечаем 200, чтобы шлюз не ретраил бесконечно.
        echo json_encode(['ok' => true, 'paid' => false]);
    }
} catch (\Throwable $e) {
    AuditLog::record('billing.webhook_error', $gw->id() . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
