<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Billing;
use App\Billing\GatewayRegistry;
use App\DeviceInbounds;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Subscription;
use App\Modules\ModuleManager;
use App\PortalAuth;
use App\PortalView;
use App\View;

if (!ModuleManager::featureActive('billing') || Setting::get('billing_portal_enabled', '0') !== '1') {
    http_response_code(404);
    die('Not found');
}
PortalAuth::requireLogin();
$me = PortalAuth::current();
$meId = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';
    // Действия, требующие подтверждённого email.
    if (in_array($action, ['add_device', 'buy', 'activate_free', 'change_plan', 'buy_addon', 'topup'], true) && PortalAuth::needsVerification($me)) {
        View::flash('error', t('portal.verify_required'));
        header('Location: /portal.php');
        exit;
    }
    try {
        if ($action === 'change_password') {
            PortalAuth::changePassword($meId, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
            View::flash('success', t('portal.flash.pw_changed'));
        } elseif ($action === 'activate_free') {
            Billing::activateFree($meId, (int) ($_POST['plan_id'] ?? 0));
            View::flash('success', t('portal.flash.activated'));
        } elseif ($action === 'add_device') {
            Billing::addDevice($meId, trim((string) ($_POST['name'] ?? 'device')));
            View::flash('success', t('portal.flash.device_added'));
        } elseif ($action === 'del_device') {
            $cid = (int) $_POST['client_id'];
            $c = Client::find($cid);
            if ($c && (int) ($c['subscriber_id'] ?? 0) === $meId) { // только своё
                Billing::removeDevice($cid);
                View::flash('success', t('portal.flash.device_removed'));
            }
        } elseif ($action === 'buy') {
            $gw = GatewayRegistry::get((string) ($_POST['gateway'] ?? ''));
            $plan = Plan::find((int) ($_POST['plan_id'] ?? 0));
            if (!$gw || !$gw->isConfigured() || !$plan || !$plan['enabled']) {
                throw new \RuntimeException(t('billing.err.gateway'));
            }
            $payId = Payment::create([
                'subscriber_id' => $meId,
                'plan_id'       => (int) $plan['id'],
                'amount'        => (float) $plan['price'],
                'currency'      => (string) $plan['currency'],
                'method'        => $gw->id(),
                'status'        => 'pending',
                'period_days'   => (int) $plan['period_days'],
            ]);
            $created = $gw->createPayment([
                'amount'      => (float) $plan['price'],
                'currency'    => (string) $plan['currency'],
                'description' => (string) $plan['name'],
                'return_url'  => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/portal.php',
                'order_id'    => (string) $payId,
            ]);
            \App\Database::get()->prepare('UPDATE billing_payments SET external_id = ? WHERE id = ?')->execute([$created['external_id'], $payId]);
            header('Location: ' . $created['url']); // уводим на страницу оплаты шлюза
            exit;
        } elseif ($action === 'change_plan') {
            Billing::changePlan($meId, (int) ($_POST['plan_id'] ?? 0));
            View::flash('success', t('portal.flash.plan_changed'));
        } elseif ($action === 'cancel') {
            Billing::cancelSubscription($meId, true);
            View::flash('success', t('portal.flash.cancelled'));
        } elseif ($action === 'buy_addon') {
            Billing::buyAddon($meId, (string) ($_POST['atype'] ?? ''), (float) str_replace(',', '.', (string) ($_POST['qty'] ?? '0')));
            View::flash('success', t('portal.flash.addon_bought'));
        } elseif ($action === 'topup') {
            $gw = GatewayRegistry::get((string) ($_POST['gateway'] ?? ''));
            $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
            if (!$gw || !$gw->isConfigured() || $amount <= 0) {
                throw new \RuntimeException(t('billing.err.gateway'));
            }
            $cur = (string) \App\Models\Setting::get('billing_currency', 'RUB');
            $payId = Payment::create(['subscriber_id' => $meId, 'amount' => $amount, 'currency' => $cur, 'method' => $gw->id(), 'status' => 'pending', 'period_days' => 0, 'purpose' => 'topup']);
            $created = $gw->createPayment(['amount' => $amount, 'currency' => $cur, 'description' => t('portal.topup'), 'return_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/portal.php', 'order_id' => (string) $payId]);
            \App\Database::get()->prepare('UPDATE billing_payments SET external_id = ? WHERE id = ?')->execute([$created['external_id'], $payId]);
            header('Location: ' . $created['url']);
            exit;
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }
    header('Location: /portal.php');
    exit;
}

// ── Данные для личного кабинета (рендерится конструктором: Site::cabinet) ──
$sub = Subscription::forSubscriber($meId);
$state = Billing::subscriptionState($sub);
$usage = Billing::usageInfo($meId);
$plans = \App\Site\BusinessData::plans();
$gateways = array_map(fn($g) => $g->id(), GatewayRegistry::configured());

$devices = [];
foreach (Subscriber::devices($meId) as $d) {
    $uris = [];
    foreach (DeviceInbounds::clientConfigs($d) as $proto => $entry) {
        if (!empty($entry['uri'])) {
            $uris[$proto] = $entry['uri'];
        }
    }
    $devices[] = ['id' => (int) $d['id'], 'name' => $d['name'], 'revoked' => $d['revoked'] ?? 0, 'uris' => $uris];
}

$data = [
    'subscriber'   => $me,
    'subscription' => $sub,
    'usage'        => $usage,
    'devices'      => $devices,
    'payments'     => Payment::forSubscriber($meId, 10),
    'plans'        => $plans,
    'gateways'     => $gateways,
    'subState'     => $state,
    'needsVerify'  => PortalAuth::needsVerification($me),
    'balance'      => Billing::balanceOf($meId),
    'currency'     => (string) \App\Models\Setting::get('billing_currency', 'RUB'),
    'current_plan_id' => $sub['plan_id'] ? (int) $sub['plan_id'] : null,
    'addon_traffic_price' => (float) \App\Models\Setting::get('billing_addon_traffic_price', '0'),
    'addon_device_price'  => (float) \App\Models\Setting::get('billing_addon_device_price', '0'),
    'extra_traffic' => (float) ($sub['extra_traffic_gb'] ?? 0),
    'extra_devices' => (int) ($sub['extra_devices'] ?? 0),
];

header('Content-Type: text/html; charset=utf-8');
echo \App\Site\Site::cabinet(\App\Models\SiteDesign::published(), $data);
