<?php
/**
 * Поля формы тарифа (billing.php, разделы «Тарифы»). Ожидает $plan в области
 * видимости (строка billing_plans или дефолты для нового тарифа).
 */
/** @var array $plan */
$tg = $plan['traffic_gb'] ?? null;
?>
<div class="row" style="gap:10px;flex-wrap:wrap;align-items:end">
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.name')) ?></div><input name="name" value="<?= htmlspecialchars((string) ($plan['name'] ?? '')) ?>" required style="min-width:160px"></label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.price')) ?></div><input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars((string) ($plan['price'] ?? '0')) ?>" style="width:100px"></label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.currency')) ?></div><input name="currency" value="<?= htmlspecialchars((string) ($plan['currency'] ?? 'RUB')) ?>" style="width:64px"></label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.period')) ?></div><input type="number" min="1" name="period_days" value="<?= (int) ($plan['period_days'] ?? 30) ?>" style="width:74px"></label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.devices')) ?></div><input type="number" min="1" name="device_limit" value="<?= (int) ($plan['device_limit'] ?? 1) ?>" style="width:64px"></label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.f.traffic')) ?></div><input type="number" step="0.1" min="0" name="traffic_gb" value="<?= $tg !== null ? htmlspecialchars((string) (float) $tg) : '' ?>" placeholder="∞" style="width:80px"></label>
</div>
<div class="row" style="gap:16px;margin-top:8px">
  <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" name="enabled" value="1" <?= !empty($plan['enabled']) ? 'checked' : '' ?>> <?= htmlspecialchars(t('billing.f.enabled')) ?></label>
  <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" name="featured" value="1" <?= !empty($plan['featured']) ? 'checked' : '' ?>> <?= htmlspecialchars(t('billing.f.featured')) ?></label>
</div>
<label style="margin-top:8px"><?= htmlspecialchars(t('billing.f.description')) ?></label>
<input name="description" value="<?= htmlspecialchars((string) ($plan['description'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('billing.f.description_ph')) ?>">
<label><?= htmlspecialchars(t('billing.f.features')) ?></label>
<textarea name="features" rows="3" style="width:100%;font-size:13px" placeholder="<?= htmlspecialchars(t('billing.f.features_ph')) ?>"><?= htmlspecialchars((string) ($plan['features'] ?? '')) ?></textarea>
