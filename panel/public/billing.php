<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Billing;
use App\Billing\GatewayRegistry;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Subscription;
use App\Modules\ModuleManager;
use App\PortalMail;
use App\View;

Auth::requireLogin();

if (!ModuleManager::featureActive('billing')) {
    View::header(t('nav.billing'));
    echo '<div class="card"><p class="muted">' . htmlspecialchars(t('billing.disabled')) . ' <a href="/modules.php">' . htmlspecialchars(t('billing.enable_link')) . '</a></p></div>';
    View::footer();
    exit;
}

$linkResult = null; // URL онлайн-оплаты, созданный на этом запросе (показываем после редиректа нельзя — показываем сразу)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'plan_create':
                Plan::create($_POST);
                AuditLog::record('billing.plan_create', (string) ($_POST['name'] ?? ''));
                View::flash('success', t('billing.flash.plan_saved'));
                break;
            case 'plan_update':
                Plan::update((int) $_POST['id'], $_POST);
                View::flash('success', t('billing.flash.plan_saved'));
                break;
            case 'plan_delete':
                Plan::delete((int) $_POST['id']);
                View::flash('success', t('billing.flash.plan_deleted'));
                break;
            case 'plan_reorder':
                Plan::reorder(array_filter(explode(',', (string) ($_POST['order'] ?? ''))));
                View::flash('success', t('billing.flash.order_saved'));
                break;

            case 'subscriber_create':
                Subscriber::create($_POST);
                View::flash('success', t('billing.flash.subscriber_saved'));
                break;
            case 'subscriber_update':
                Subscriber::update((int) $_POST['id'], $_POST);
                View::flash('success', t('billing.flash.subscriber_saved'));
                break;
            case 'subscriber_delete':
                Subscriber::delete((int) $_POST['id']);
                View::flash('success', t('billing.flash.subscriber_deleted'));
                break;

            case 'device_assign':
                Billing::assignDevice((int) $_POST['subscriber_id'], (int) $_POST['client_id']);
                View::flash('success', t('billing.flash.device_assigned'));
                break;
            case 'device_unassign':
                Billing::unassignDevice((int) $_POST['client_id']);
                View::flash('success', t('billing.flash.device_unassigned'));
                break;

            case 'extend_manual':
                $planId = ($_POST['plan_id'] ?? '') !== '' ? (int) $_POST['plan_id'] : null;
                $plan = $planId ? Plan::find($planId) : null;
                $days = (int) ($_POST['days'] ?? 0) ?: ($plan ? (int) $plan['period_days'] : 30);
                $amount = ($_POST['amount'] ?? '') !== '' ? (float) $_POST['amount'] : ($plan ? (float) $plan['price'] : 0);
                Billing::recordManualPayment((int) $_POST['subscriber_id'], $planId, $amount, $days, (string) ($_POST['comment'] ?? ''));
                View::flash('success', t('billing.flash.extended'));
                break;

            case 'cancel_sub':
                Billing::cancelSubscription((int) $_POST['subscriber_id']);
                View::flash('success', t('billing.flash.cancelled'));
                break;

            case 'create_link':
                $gw = GatewayRegistry::get((string) ($_POST['gateway'] ?? ''));
                if (!$gw || !$gw->isConfigured()) {
                    throw new \RuntimeException(t('billing.err.gateway'));
                }
                $planId = (int) $_POST['plan_id'];
                $plan = Plan::find($planId);
                if (!$plan) {
                    throw new \RuntimeException(t('billing.err.plan'));
                }
                $subscriberId = (int) $_POST['subscriber_id'];
                $payId = Payment::create([
                    'subscriber_id' => $subscriberId,
                    'plan_id'       => $planId,
                    'amount'        => (float) $plan['price'],
                    'currency'      => (string) $plan['currency'],
                    'method'        => $gw->id(),
                    'status'        => 'pending',
                    'period_days'   => (int) $plan['period_days'],
                ]);
                $created = $gw->createPayment([
                    'amount'      => (float) $plan['price'],
                    'currency'    => (string) $plan['currency'],
                    'description' => $plan['name'] . ' — ' . (Subscriber::find($subscriberId)['name'] ?? ''),
                    'return_url'  => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/billing.php',
                    'order_id'    => (string) $payId,
                ]);
                // Сохраняем external_id к платежу (для сверки по webhook).
                $stmt = \App\Database::get()->prepare('UPDATE billing_payments SET external_id = ? WHERE id = ?');
                $stmt->execute([$created['external_id'], $payId]);
                AuditLog::record('billing.link_created', $gw->id() . " pay=$payId");
                $_SESSION['billing_link'] = $created['url'];
                break;

            case 'save_portal':
                Setting::set('billing_portal_enabled', !empty($_POST['billing_portal_enabled']) ? '1' : '0');
                Setting::set('billing_portal_register', !empty($_POST['billing_portal_register']) ? '1' : '0');
                Setting::set('portal_email_verify', !empty($_POST['portal_email_verify']) ? '1' : '0');
                Setting::set('portal_brand', trim((string) ($_POST['portal_brand'] ?? '')) ?: 'VPN');
                // Фиксируем публичный хост панели (для ссылок в письмах из cron, где нет HTTP_HOST).
                if (!empty($_SERVER['HTTP_HOST'])) {
                    Setting::set('panel_public_host', (string) $_SERVER['HTTP_HOST']);
                }
                View::flash('success', t('billing.flash.portal_saved'));
                break;

            case 'save_mail':
                foreach (['verify', 'reminder'] as $k) {
                    Setting::set("portal_mail_{$k}_subject", trim((string) ($_POST["{$k}_subject"] ?? '')));
                    Setting::set("portal_mail_{$k}_body", (string) ($_POST["{$k}_body"] ?? ''));
                }
                View::flash('success', t('billing.flash.mail_saved'));
                break;

            case 'save_gateways':
                Setting::set('billing_currency', trim((string) ($_POST['billing_currency'] ?? 'RUB')) ?: 'RUB');
                Setting::set('billing_yk_shop_id', trim((string) ($_POST['billing_yk_shop_id'] ?? '')));
                if (($_POST['billing_yk_secret'] ?? '') !== '') {
                    Setting::set('billing_yk_secret', trim((string) $_POST['billing_yk_secret']));
                }
                Setting::set('billing_cc_shop_id', trim((string) ($_POST['billing_cc_shop_id'] ?? '')));
                if (($_POST['billing_cc_api_key'] ?? '') !== '') {
                    Setting::set('billing_cc_api_key', trim((string) $_POST['billing_cc_api_key']));
                }
                Setting::set('billing_addon_traffic_price', (string) (float) str_replace(',', '.', (string) ($_POST['addon_traffic_price'] ?? '0')));
                Setting::set('billing_addon_device_price', (string) (float) str_replace(',', '.', (string) ($_POST['addon_device_price'] ?? '0')));
                View::flash('success', t('billing.flash.gateways_saved'));
                break;
            case 'balance_adjust':
                $amt = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
                if ($amt != 0.0) {
                    Billing::adjustBalance((int) $_POST['subscriber_id'], $amt, trim((string) ($_POST['reason'] ?? '')) ?: 'Корректировка (админ)');
                    View::flash('success', t('billing.flash.balance_adjusted'));
                }
                break;
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }
    // Ссылку на оплату показываем сразу (в сессии переживёт редирект).
    header('Location: /billing.php' . (($action === 'create_link') ? '#payments' : ''));
    exit;
}

if (!empty($_SESSION['billing_link'])) {
    $linkResult = $_SESSION['billing_link'];
    unset($_SESSION['billing_link']);
}

$plans = Plan::all();
$subscribers = Subscriber::all();
$payments = Payment::recent(30);
$unassigned = array_values(array_filter(Client::all(), fn($c) => empty($c['subscriber_id'])));
$configuredGateways = GatewayRegistry::configured();
$cur = (string) Setting::get('billing_currency', 'RUB');

$stateBadge = static function (string $st): string {
    $map = ['active' => 'ok', 'expired' => 'down', 'cancelled' => 'unknown', 'none' => 'unknown'];
    $label = ['active' => t('billing.st.active'), 'expired' => t('billing.st.expired'), 'cancelled' => t('billing.st.cancelled'), 'none' => t('billing.st.none')];
    return '<span class="badge ' . ($map[$st] ?? 'unknown') . '">' . htmlspecialchars($label[$st] ?? $st) . '</span>';
};

View::header(t('nav.billing'));
?>

<style>
  .b-shell{display:flex;gap:20px;align-items:flex-start}
  .b-nav{display:flex;flex-direction:column;gap:2px;flex:0 0 200px;position:sticky;top:16px}
  .b-navitem{display:flex;align-items:center;gap:10px;background:none;border:none;border-radius:8px;color:var(--text-secondary);padding:9px 12px;font-size:14px;font-weight:500;cursor:pointer;text-align:left;width:100%}
  .b-navitem:hover{background:var(--surface)!important;color:var(--text)!important}
  .b-navitem.active,.b-navitem.active:hover{background:var(--surface-elevated)!important;color:var(--accent)!important}
  .b-content{flex:1;min-width:0}
  @media (max-width:760px){.b-shell{flex-direction:column}.b-nav{flex-direction:row;flex-wrap:wrap;position:static;flex-basis:auto;width:100%}.b-navitem{width:auto}}
  .b-table{width:100%;border-collapse:collapse;font-size:13.5px}
  .b-table th{text-align:left;color:var(--text-muted);font-weight:600;padding:6px 8px}
  .b-table td{padding:7px 8px;border-top:1px solid var(--border);vertical-align:top}
</style>

<?php if ($linkResult): ?>
<div class="card" id="payments" style="border-color:var(--accent)">
  <h3 style="margin-top:0"><?= htmlspecialchars(t('billing.link_ready')) ?></h3>
  <input type="text" readonly value="<?= htmlspecialchars($linkResult) ?>" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace">
  <p class="muted" style="margin:8px 0 0"><?= htmlspecialchars(t('billing.link_hint')) ?></p>
</div>
<?php endif; ?>

<div class="b-shell">
  <nav class="b-nav" id="b-nav">
    <button type="button" class="b-navitem active" data-sec="subscribers"><span>👥</span><?= htmlspecialchars(t('billing.sec.subscribers')) ?></button>
    <button type="button" class="b-navitem" data-sec="plans"><span>🏷️</span><?= htmlspecialchars(t('billing.sec.plans')) ?></button>
    <button type="button" class="b-navitem" data-sec="payments"><span>💳</span><?= htmlspecialchars(t('billing.sec.payments')) ?></button>
    <button type="button" class="b-navitem" data-sec="mail"><span>✉️</span><?= htmlspecialchars(t('billing.sec.mail')) ?></button>
    <button type="button" class="b-navitem" data-sec="gateways"><span>⚙️</span><?= htmlspecialchars(t('billing.sec.gateways')) ?></button>
  </nav>
  <div class="b-content">

  <!-- ================= ПОДПИСЧИКИ ================= -->
  <section class="b-section" data-sec="subscribers">
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:center">
        <h2 style="margin:0"><?= htmlspecialchars(t('billing.sec.subscribers')) ?></h2>
        <button type="button" onclick="document.getElementById('sub-new').hidden=!document.getElementById('sub-new').hidden"><?= htmlspecialchars(t('billing.add_subscriber')) ?></button>
      </div>
      <form method="post" id="sub-new" hidden class="row" style="margin-top:12px;gap:8px;flex-wrap:wrap">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="subscriber_create">
        <input name="name" placeholder="<?= htmlspecialchars(t('billing.f.name')) ?>" required>
        <input type="email" name="email" placeholder="email">
        <input name="note" placeholder="<?= htmlspecialchars(t('billing.f.note')) ?>">
        <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
      </form>
    </div>

    <?php if (!$subscribers): ?>
      <div class="card"><p class="muted"><?= htmlspecialchars(t('billing.empty_subscribers')) ?></p></div>
    <?php endif; ?>

    <?php foreach ($subscribers as $s):
      $sub = Subscription::forSubscriber((int) $s['id']);
      $state = Billing::subscriptionState($sub);
      $devices = Subscriber::devices((int) $s['id']);
    ?>
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
          <strong style="font-size:15px"><?= htmlspecialchars($s['name']) ?></strong>
          <?= $stateBadge($state) ?>
          <?php if ($sub && $state !== 'none'): ?><span class="muted" style="font-size:12.5px"> · <?= htmlspecialchars(t('billing.until')) ?> <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $sub['expires_at']))) ?></span><?php endif; ?>
          <span class="muted" style="font-size:12.5px"> · <?= htmlspecialchars(t('billing.balance')) ?>: <b><?= htmlspecialchars(Billing::priceLabel((float) ($s['balance'] ?? 0))) ?> <?= htmlspecialchars($cur) ?></b></span>
          <?php if ($sub && (($sub['extra_devices'] ?? 0) || ($sub['extra_traffic_gb'] ?? 0))): ?><span class="muted" style="font-size:12.5px"> · +<?= (int) $sub['extra_devices'] ?><?= htmlspecialchars(t('portal.dev_short')) ?>, +<?= htmlspecialchars(Billing::trafficLabel((float) $sub['extra_traffic_gb']) ?: '0') ?></span><?php endif; ?>
          <?php if ($s['email']): ?><br><span class="muted" style="font-size:12.5px"><?= htmlspecialchars($s['email']) ?></span><?php endif; ?>
          <form method="post" class="row" style="gap:6px;margin-top:6px">
            <?= Auth::csrfField() ?><input type="hidden" name="action" value="balance_adjust"><input type="hidden" name="subscriber_id" value="<?= (int) $s['id'] ?>">
            <input type="number" step="0.01" name="amount" placeholder="<?= htmlspecialchars(t('billing.balance_adjust')) ?>" style="width:120px">
            <input name="reason" placeholder="<?= htmlspecialchars(t('billing.f.note')) ?>" style="width:140px">
            <button type="submit" class="secondary" style="font-size:12px"><?= htmlspecialchars(t('billing.balance_apply')) ?></button>
          </form>
        </div>
        <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('billing.confirm_del_subscriber'), ENT_QUOTES) ?>')">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="subscriber_delete">
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <button class="danger secondary" type="submit" style="font-size:12px"><?= htmlspecialchars(t('common.delete')) ?></button>
        </form>
      </div>

      <!-- устройства подписчика -->
      <div style="margin-top:10px">
        <div class="muted" style="font-size:12.5px;margin-bottom:4px"><?= htmlspecialchars(t('billing.devices')) ?> (<?= count($devices) ?>)</div>
        <?php foreach ($devices as $d): ?>
          <span class="badge <?= empty($d['revoked']) ? 'ok' : 'down' ?>" style="margin:0 4px 4px 0"><?= htmlspecialchars($d['name']) ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= htmlspecialchars(t('billing.confirm_unassign'), ENT_QUOTES) ?>')">
              <?= Auth::csrfField() ?><input type="hidden" name="action" value="device_unassign"><input type="hidden" name="client_id" value="<?= (int) $d['id'] ?>">
              <button type="submit" title="<?= htmlspecialchars(t('billing.unassign')) ?>" style="background:none;border:none;color:inherit;cursor:pointer;padding:0 0 0 4px">✕</button>
            </form>
          </span>
        <?php endforeach; ?>
        <?php if ($unassigned): ?>
        <form method="post" class="row" style="gap:6px;margin-top:6px">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="device_assign">
          <input type="hidden" name="subscriber_id" value="<?= (int) $s['id'] ?>">
          <select name="client_id" required style="max-width:220px">
            <option value=""><?= htmlspecialchars(t('billing.assign_device')) ?>…</option>
            <?php foreach ($unassigned as $u): ?><option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="secondary"><?= htmlspecialchars(t('billing.assign')) ?></button>
        </form>
        <?php endif; ?>
      </div>

      <!-- действия: продлить вручную / онлайн-ссылка / отмена -->
      <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--border);display:flex;gap:16px;flex-wrap:wrap">
        <form method="post" class="row" style="gap:6px;flex-wrap:wrap;align-items:end">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="extend_manual">
          <input type="hidden" name="subscriber_id" value="<?= (int) $s['id'] ?>">
          <label style="font-size:12px">
            <div class="muted"><?= htmlspecialchars(t('billing.plan')) ?></div>
            <select name="plan_id"><option value=""><?= htmlspecialchars(t('billing.no_plan')) ?></option>
              <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= (int) $p['period_days'] ?>д)</option><?php endforeach; ?>
            </select>
          </label>
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.days')) ?></div><input type="number" name="days" min="1" placeholder="30" style="width:80px"></label>
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.amount')) ?></div><input type="number" step="0.01" name="amount" style="width:100px"></label>
          <button type="submit"><?= htmlspecialchars(t('billing.extend_manual')) ?></button>
        </form>

        <?php if ($configuredGateways && $plans): ?>
        <form method="post" class="row" style="gap:6px;flex-wrap:wrap;align-items:end">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="create_link">
          <input type="hidden" name="subscriber_id" value="<?= (int) $s['id'] ?>">
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.plan')) ?></div>
            <select name="plan_id" required><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> — <?= htmlspecialchars((string) $p['price']) ?> <?= htmlspecialchars($p['currency']) ?></option><?php endforeach; ?></select>
          </label>
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.gateway')) ?></div>
            <select name="gateway" required><?php foreach ($configuredGateways as $g): ?><option value="<?= htmlspecialchars($g->id()) ?>"><?= htmlspecialchars($g->id()) ?></option><?php endforeach; ?></select>
          </label>
          <button type="submit" class="secondary"><?= htmlspecialchars(t('billing.create_link')) ?></button>
        </form>
        <?php endif; ?>

        <?php if ($sub && $state === 'active'): ?>
        <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('billing.confirm_cancel'), ENT_QUOTES) ?>')">
          <?= Auth::csrfField() ?><input type="hidden" name="action" value="cancel_sub"><input type="hidden" name="subscriber_id" value="<?= (int) $s['id'] ?>">
          <button type="submit" class="danger secondary" style="font-size:12px"><?= htmlspecialchars(t('billing.cancel_sub')) ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>

  <!-- ================= ТАРИФЫ ================= -->
  <section class="b-section" data-sec="plans" hidden>
    <div class="card">
      <div class="srv-sec-head" style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0"><?= htmlspecialchars(t('billing.sec.plans')) ?></h2>
        <span class="muted" style="font-size:12.5px"><?= htmlspecialchars(t('billing.plan_drag_hint')) ?></span>
      </div>
      <div id="plan-list" style="margin-top:12px">
        <?php foreach ($plans as $p): ?>
          <details class="plan-item" data-id="<?= (int) $p['id'] ?>" style="border:1px solid var(--border);border-radius:10px;margin-bottom:8px">
            <summary style="cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 12px">
              <span class="drag-handle" title="<?= htmlspecialchars(t('billing.plan_drag')) ?>" style="cursor:grab;color:var(--text-muted)">⠿</span>
              <strong><?= htmlspecialchars($p['name']) ?></strong>
              <?php if ($p['featured']): ?><span class="badge ok" style="font-size:11px"><?= htmlspecialchars(t('portal.popular')) ?></span><?php endif; ?>
              <?php if (!$p['enabled']): ?><span class="badge unknown" style="font-size:11px"><?= htmlspecialchars(t('exit.st.disabled')) ?></span><?php endif; ?>
              <span class="muted" style="margin-left:auto;font-size:12.5px"><?= htmlspecialchars(Billing::priceLabel((float) $p['price'])) ?> <?= htmlspecialchars($p['currency']) ?> · <?= (int) $p['period_days'] ?><?= htmlspecialchars(t('portal.days')) ?></span>
            </summary>
            <form method="post" style="padding:0 12px 12px">
              <?= Auth::csrfField() ?><input type="hidden" name="action" value="plan_update"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <?php $plan = $p; include __DIR__ . '/partials/plan-fields.php'; ?>
              <div class="row" style="margin-top:10px;gap:8px">
                <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
                <button type="submit" formnovalidate class="danger secondary" style="font-size:12px"
                        formaction="/billing.php" onclick="this.form.querySelector('[name=action]').value='plan_delete';return confirm('<?= htmlspecialchars(t('billing.confirm_del_plan'), ENT_QUOTES) ?>')"><?= htmlspecialchars(t('common.delete')) ?></button>
              </div>
            </form>
          </details>
        <?php endforeach; ?>
      </div>
      <form method="post" id="plan-order-form" style="margin-top:4px">
        <?= Auth::csrfField() ?><input type="hidden" name="action" value="plan_reorder"><input type="hidden" name="order" id="plan-order-input">
        <button type="submit" class="secondary" id="plan-order-save" style="font-size:12px" hidden><?= htmlspecialchars(t('billing.save_order')) ?></button>
      </form>

      <details style="border:1px dashed var(--border);border-radius:10px;padding:10px 12px;margin-top:10px">
        <summary style="cursor:pointer"><strong><?= htmlspecialchars(t('billing.add_plan')) ?></strong></summary>
        <form method="post" style="margin-top:10px">
          <?= Auth::csrfField() ?><input type="hidden" name="action" value="plan_create">
          <?php $plan = ['name' => '', 'price' => '0', 'currency' => $cur, 'period_days' => 30, 'device_limit' => 1, 'traffic_gb' => null, 'featured' => 0, 'enabled' => 1, 'description' => '', 'features' => '']; include __DIR__ . '/partials/plan-fields.php'; ?>
          <div style="margin-top:10px"><button type="submit"><?= htmlspecialchars(t('billing.add_plan')) ?></button></div>
        </form>
      </details>
    </div>

    <script>
    (function(){
      var list=document.getElementById('plan-list'); if(!list) return;
      var saveBtn=document.getElementById('plan-order-save'), orderInput=document.getElementById('plan-order-input');
      var dragEl=null;
      list.querySelectorAll('.plan-item').forEach(function(it){
        var h=it.querySelector('.drag-handle');
        h.addEventListener('mousedown',function(){it.draggable=true;});
        it.addEventListener('dragstart',function(e){dragEl=it;e.dataTransfer.effectAllowed='move';it.style.opacity=.5;});
        it.addEventListener('dragend',function(){it.style.opacity='';it.draggable=false;dirty();});
        it.addEventListener('dragover',function(e){e.preventDefault();var t=e.currentTarget;if(dragEl&&dragEl!==t){var r=t.getBoundingClientRect();var after=(e.clientY-r.top)/r.height>0.5;list.insertBefore(dragEl,after?t.nextSibling:t);}});
      });
      function dirty(){ if(saveBtn) saveBtn.hidden=false; }
      document.getElementById('plan-order-form').addEventListener('submit',function(){
        orderInput.value=Array.from(list.querySelectorAll('.plan-item')).map(function(x){return x.dataset.id;}).join(',');
      });
    })();
    </script>
  </section>

  <!-- ================= ПЛАТЕЖИ ================= -->
  <section class="b-section" data-sec="payments" hidden>
    <div class="card">
      <h2 style="margin-top:0"><?= htmlspecialchars(t('billing.sec.payments')) ?></h2>
      <?php if (!$payments): ?><p class="muted"><?= htmlspecialchars(t('billing.no_payments')) ?></p><?php else: ?>
      <table class="b-table">
        <thead><tr><th><?= htmlspecialchars(t('billing.f.date')) ?></th><th><?= htmlspecialchars(t('billing.subscriber')) ?></th><th><?= htmlspecialchars(t('billing.f.amount')) ?></th><th><?= htmlspecialchars(t('billing.f.method')) ?></th><th><?= htmlspecialchars(t('billing.f.status')) ?></th><th><?= htmlspecialchars(t('billing.days')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="muted"><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $p['created_at']))) ?></td>
            <td><?= htmlspecialchars((string) $p['subscriber_name']) ?></td>
            <td><?= htmlspecialchars((string) $p['amount']) ?> <?= htmlspecialchars($p['currency']) ?></td>
            <td><?= htmlspecialchars($p['method']) ?></td>
            <td><span class="badge <?= $p['status'] === 'paid' ? 'ok' : ($p['status'] === 'pending' ? 'warn' : 'unknown') ?>"><?= htmlspecialchars($p['status']) ?></span></td>
            <td class="muted"><?= (int) $p['period_days'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <!-- ================= ПИСЬМА ================= -->
  <section class="b-section" data-sec="mail" hidden>
    <div class="card">
      <h2 style="margin-top:0"><?= htmlspecialchars(t('billing.sec.mail')) ?></h2>
      <p class="muted"><?= htmlspecialchars(t('billing.mail.hint')) ?> <code>{brand} {name} {link} {date} {plan}</code></p>
      <form method="post">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="save_mail">
        <?php foreach (['verify' => t('billing.mail.verify'), 'reminder' => t('billing.mail.reminder')] as $k => $lbl): ?>
          <h3><?= htmlspecialchars($lbl) ?></h3>
          <label><?= htmlspecialchars(t('billing.mail.subject')) ?></label>
          <input name="<?= $k ?>_subject" value="<?= htmlspecialchars(PortalMail::subject($k)) ?>">
          <label><?= htmlspecialchars(t('billing.mail.body')) ?></label>
          <textarea name="<?= $k ?>_body" rows="5" style="width:100%;font-family:ui-monospace,monospace;font-size:13px"><?= htmlspecialchars(PortalMail::body($k)) ?></textarea>
        <?php endforeach; ?>
        <div style="margin-top:12px"><button type="submit"><?= htmlspecialchars(t('common.save')) ?></button></div>
      </form>
    </div>
  </section>

  <!-- ================= ОПЛАТА (ШЛЮЗЫ + ПОРТАЛ) ================= -->
  <section class="b-section" data-sec="gateways" hidden>
    <div class="card">
      <h2 style="margin-top:0"><?= htmlspecialchars(t('billing.portal.title')) ?></h2>
      <p class="muted"><?= htmlspecialchars(t('billing.portal.desc')) ?></p>
      <form method="post" style="display:flex;flex-direction:column;gap:10px;max-width:620px">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="save_portal">
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
          <input type="checkbox" name="billing_portal_enabled" value="1" <?= Setting::get('billing_portal_enabled', '0') === '1' ? 'checked' : '' ?> style="width:auto">
          <?= htmlspecialchars(t('billing.portal.enabled')) ?>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
          <input type="checkbox" name="billing_portal_register" value="1" <?= Setting::get('billing_portal_register', '1') === '1' ? 'checked' : '' ?> style="width:auto">
          <?= htmlspecialchars(t('billing.portal.register')) ?>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
          <input type="checkbox" name="portal_email_verify" value="1" <?= Setting::get('portal_email_verify', '1') === '1' ? 'checked' : '' ?> style="width:auto">
          <?= htmlspecialchars(t('billing.portal.email_verify')) ?>
        </label>
        <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('billing.portal.brand')) ?></label>
        <input name="portal_brand" value="<?= htmlspecialchars((string) Setting::get('portal_brand', 'VPN')) ?>" style="max-width:260px">
        <p class="muted" style="font-size:12px;margin:0"><?= htmlspecialchars(t('billing.portal.url')) ?>: <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?>/portal.php</code></p>
        <div><button type="submit"><?= htmlspecialchars(t('common.save')) ?></button></div>
      </form>
    </div>
    <div class="card">
      <h2 style="margin-top:0"><?= htmlspecialchars(t('billing.sec.gateways')) ?></h2>
      <p class="muted"><?= htmlspecialchars(t('billing.gateways_desc')) ?></p>
      <form method="post" style="display:flex;flex-direction:column;gap:10px;max-width:620px">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="save_gateways">
        <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('billing.f.currency')) ?></label>
        <input name="billing_currency" value="<?= htmlspecialchars($cur) ?>" style="width:120px">

        <hr style="border:none;border-top:1px solid var(--border);margin:6px 0">
        <div style="font-weight:600"><?= htmlspecialchars(t('billing.addons.title')) ?></div>
        <p class="muted" style="font-size:12px;margin:0"><?= htmlspecialchars(t('billing.addons.hint')) ?></p>
        <div class="row" style="gap:12px">
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.addons.device_price')) ?></div><input type="number" step="0.01" min="0" name="addon_device_price" value="<?= htmlspecialchars((string) Setting::get('billing_addon_device_price', '0')) ?>" style="width:120px"></label>
          <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.addons.traffic_price')) ?></div><input type="number" step="0.01" min="0" name="addon_traffic_price" value="<?= htmlspecialchars((string) Setting::get('billing_addon_traffic_price', '0')) ?>" style="width:120px"></label>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:6px 0">
        <div style="font-weight:600">YooKassa</div>
        <label class="muted" style="font-size:13px">shopId</label>
        <input name="billing_yk_shop_id" value="<?= htmlspecialchars((string) Setting::get('billing_yk_shop_id', '')) ?>">
        <label class="muted" style="font-size:13px">secretKey</label>
        <input name="billing_yk_secret" placeholder="<?= Setting::get('billing_yk_secret') ? '•••••• (' . htmlspecialchars(t('billing.keep_secret')) . ')' : '' ?>">
        <p class="muted" style="font-size:12px;margin:0">webhook: <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?>/api/billing-webhook.php?gw=yookassa</code></p>

        <hr style="border:none;border-top:1px solid var(--border);margin:6px 0">
        <div style="font-weight:600">CryptoCloud</div>
        <label class="muted" style="font-size:13px">shop_id</label>
        <input name="billing_cc_shop_id" value="<?= htmlspecialchars((string) Setting::get('billing_cc_shop_id', '')) ?>">
        <label class="muted" style="font-size:13px">api_key</label>
        <input name="billing_cc_api_key" placeholder="<?= Setting::get('billing_cc_api_key') ? '•••••• (' . htmlspecialchars(t('billing.keep_secret')) . ')' : '' ?>">
        <p class="muted" style="font-size:12px;margin:0">webhook: <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?>/api/billing-webhook.php?gw=cryptocloud</code></p>

        <div style="margin-top:8px"><button type="submit"><?= htmlspecialchars(t('common.save')) ?></button></div>
      </form>
    </div>
  </section>

  </div>
</div>

<script>
(function(){
  var nav=document.getElementById('b-nav'); if(!nav) return;
  function show(sec){
    document.querySelectorAll('.b-navitem').forEach(function(b){b.classList.toggle('active',b.dataset.sec===sec);});
    document.querySelectorAll('.b-section').forEach(function(s){s.hidden=(s.dataset.sec!==sec);});
    try{localStorage.setItem('vr_billing_sec',sec);}catch(e){}
  }
  nav.querySelectorAll('.b-navitem').forEach(function(b){b.addEventListener('click',function(){show(b.dataset.sec);location.hash=b.dataset.sec;});});
  var start=(location.hash||'').replace('#','');
  if(!document.querySelector('.b-section[data-sec="'+start+'"]')){try{start=localStorage.getItem('vr_billing_sec');}catch(e){}}
  if(document.querySelector('.b-section[data-sec="'+start+'"]'))show(start);
})();
</script>

<?php View::footer(); ?>
