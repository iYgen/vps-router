<?php
/**
 * Настройки конкретного роутера/узла: Reality, протоколы (inbounds) и блокировки.
 * Раньше жили во вкладках «Настройки» (панель), теперь вынесены в инспектор самого
 * сервера (Инфраструктура → узел → «Конфигурация») и подключаются сюда же в
 * embed-режиме `settings.php?embed=1&router=<id>`.
 *
 * Ожидает в области видимости (готовит settings.php): $settings, $hasKeys, $fields,
 * $secretFields, $popularSniDomains, и классы Auth/DeviceInbounds/RiskScanner/t().
 */

use App\Auth;
use App\DeviceInbounds;
use App\RiskScanner;
?>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.reality.title')) ?></h2>
  <p class="muted"><?= htmlspecialchars(t('settings.reality.desc')) ?></p>
  <div class="row">
    <form method="post" onsubmit="<?= $hasKeys ? "return confirm('Сервер уже настроен. Перегенерировать ключи? Все уже выданные ссылки/QR устройств перестанут подключаться, пока их не переоткрыть заново на странице «Устройства».');" : '' ?>">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="generate_reality">
      <button type="submit" class="secondary">
        <?= $hasKeys ? htmlspecialchars(t('settings.reality.regenerate')) : htmlspecialchars(t('settings.reality.generate')) ?>
      </button>
    </form>
    <?php if ($hasKeys): ?>
      <form method="post">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="refresh_network">
        <button type="submit" class="secondary" title="<?= htmlspecialchars(t('settings.reality.refresh_hint')) ?>"><?= htmlspecialchars(t('settings.reality.refresh_net')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <form method="post" style="margin-top:16px">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="field-row">
      <label><?= htmlspecialchars(t('settings.reality.sni_preset')) ?></label>
      <select id="sni-preset" onchange="if (this.value) document.querySelector('[name=reality_server_name]').value = this.value;">
        <option value=""><?= htmlspecialchars(t('settings.reality.sni_custom')) ?></option>
        <?php foreach ($popularSniDomains as $domain): ?>
          <option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" style="margin-bottom:0"><?= htmlspecialchars(t('settings.reality.sni_note')) ?></p>
    </div>
    <table>
      <?php foreach ($fields as $key => [$label, $placeholder]): ?>
        <?php $isSecret = in_array($key, $secretFields, true); ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td>
            <?php if ($isSecret && !empty($settings[$key])): ?>
              <input name="<?= $key ?>" placeholder="<?= htmlspecialchars(t('settings.secret_set')) ?>" style="width:100%">
            <?php else: ?>
              <input name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key] ?? '') ?>" placeholder="<?= htmlspecialchars($placeholder) ?>" style="width:100%">
            <?php endif; ?>
            <?php if ($key === 'reality_listen_port' && isset(RiskScanner::suspiciousPorts()[(int) ($settings[$key] ?? 0)])): ?>
              <p class="muted" style="margin:4px 0 0;color:var(--warning)">
                <?= htmlspecialchars(t('settings.reality.port_warn', (int) $settings[$key], RiskScanner::suspiciousPorts()[(int) $settings[$key]])) ?>
              </p>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:12px"><button type="submit"><?= htmlspecialchars(t('common.save_apply')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.protocols.title')) ?></h2>
  <div class="flash" style="background:color-mix(in srgb, var(--info) 12%, transparent);border:1px solid color-mix(in srgb, var(--info) 35%, transparent)">
    <?= htmlspecialchars(t('settings.protocols.where', $settings['reality_public_host'] ?? $settings['reality_listen_ip'] ?? '—')) ?>
  </div>
  <p class="muted"><?= htmlspecialchars(t('settings.protocols.desc')) ?></p>
  <form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_inbounds">
    <table>
      <tr><th style="text-align:left"><?= htmlspecialchars(t('settings.protocols.col_on')) ?></th><th style="text-align:left"><?= htmlspecialchars(t('settings.protocols.col_proto')) ?></th><th style="text-align:left"><?= htmlspecialchars(t('settings.protocols.col_port')) ?></th></tr>
      <?php foreach (DeviceInbounds::PROTOCOLS as $protocol => $meta): ?>
        <tr>
          <td><input type="checkbox" name="inbound[<?= $protocol ?>][enabled]" value="1" <?= DeviceInbounds::isEnabled($protocol) ? 'checked' : '' ?>></td>
          <td><strong><?= htmlspecialchars($meta['label']) ?></strong><br><span class="muted" style="font-size:12.5px"><?= htmlspecialchars($meta['desc']) ?></span></td>
          <td style="white-space:nowrap">
            <?php if ($protocol === 'vless'): ?>
              <?= (int) DeviceInbounds::port('vless') ?>/tcp <span class="muted"><?= htmlspecialchars(t('settings.protocols.vless_port_note')) ?></span>
            <?php else: ?>
              <input type="number" name="inbound[<?= $protocol ?>][port]" min="1" max="65535" value="<?= (int) DeviceInbounds::port($protocol) ?>" style="width:90px">
              /<?= htmlspecialchars($meta['transport']) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted"><?= htmlspecialchars(t('settings.protocols.footer')) ?></p>
    <div style="margin-top:12px"><button type="submit"><?= htmlspecialchars(t('common.save_apply')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.adblock.title')) ?></h2>
  <p class="muted"><?= htmlspecialchars(t('settings.adblock.desc')) ?></p>
  <form method="post" class="row">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_adblock">
    <label style="display:flex;align-items:center;gap:8px;font-weight:500">
      <input type="checkbox" name="adblock_enabled" value="1" <?= ($settings['adblock_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
      <?= htmlspecialchars(t('settings.adblock.toggle')) ?>
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin-top:10px">
      <input type="checkbox" name="block_quic" value="1" <?= ($settings['block_quic'] ?? '1') === '1' ? 'checked' : '' ?>>
      <?= htmlspecialchars(t('settings.quic.toggle')) ?>
    </label>
    <p class="muted" style="margin:6px 0 0"><?= htmlspecialchars(t('settings.quic.desc')) ?></p>
    <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin-top:10px">
      <input type="checkbox" name="smart_dns" value="1" <?= ($settings['smart_dns'] ?? '1') === '1' ? 'checked' : '' ?>>
      <?= htmlspecialchars(t('settings.smartdns.toggle')) ?>
    </label>
    <p class="muted" style="margin:6px 0 0"><?= htmlspecialchars(t('settings.smartdns.desc')) ?></p>
    <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin-top:10px">
      <input type="checkbox" name="list_fetch_via_exit" value="1" <?= ($settings['list_fetch_via_exit'] ?? '0') === '1' ? 'checked' : '' ?>>
      <?= htmlspecialchars(t('settings.listfetch.toggle')) ?>
    </label>
    <p class="muted" style="margin:6px 0 0"><?= htmlspecialchars(t('settings.listfetch.desc')) ?></p>
    <button type="submit" style="margin-top:10px"><?= htmlspecialchars(t('common.save_apply')) ?></button>
  </form>
</div>
