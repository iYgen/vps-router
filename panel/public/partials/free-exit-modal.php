<?php
// Общая модалка импорта бесплатных exit-серверов (free-vpn-subscriptions).
// Подключается на servers.php и dashboard.php; открывается window.openFreeExitModal().
// Требует: залогиненную сессию, csrf-meta (есть в View::header), t()/window.T.
?>
<div id="fx-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px">
  <div class="card" style="max-width:640px;width:100%;margin:0">
    <div class="row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0"><?= htmlspecialchars(t('fx.modal_title')) ?></h2>
      <button type="button" class="secondary" id="fx-close">✕</button>
    </div>
    <div class="flash error" style="margin:12px 0"><?= htmlspecialchars(t('fx.warning')) ?></div>
    <div class="row" style="align-items:center;gap:8px">
      <label><?= htmlspecialchars(t('fx.country')) ?></label>
      <select id="fx-country"></select>
      <button type="button" class="secondary" id="fx-refresh"><?= htmlspecialchars(t('fx.refresh')) ?></button>
    </div>
    <div id="fx-list" style="margin-top:12px;max-height:320px;overflow:auto"></div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:12px">
      <input type="checkbox" id="fx-pool"> <span><?= htmlspecialchars(t('fx.build_pool')) ?></span>
    </label>
    <div class="row" style="margin-top:12px">
      <button type="button" id="fx-import"><?= htmlspecialchars(t('fx.import_btn')) ?></button>
    </div>
    <div id="fx-msg"></div>
  </div>
</div>

<script>
(function () {
  var T = window.T || function (k) { return k; };
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var modal = document.getElementById('fx-modal');
  var listEl = document.getElementById('fx-list');
  var countryEl = document.getElementById('fx-country');
  var msgEl = document.getElementById('fx-msg');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };

  var COUNTRIES = <?= json_encode(\App\FreeSubscriptions::COUNTRIES) ?>;
  countryEl.innerHTML = '<option value="">' + esc(<?= json_encode(t('fx.country_any')) ?>) + '</option>' +
    COUNTRIES.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('');

  function open() { modal.style.display = 'flex'; load(); }
  function close() { modal.style.display = 'none'; msgEl.innerHTML = ''; }
  window.openFreeExitModal = open;
  document.getElementById('fx-close').addEventListener('click', close);
  document.getElementById('fx-refresh').addEventListener('click', load);
  countryEl.addEventListener('change', load);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

  async function load() {
    listEl.innerHTML = '<p class="muted">' + esc(<?= json_encode(t('fx.loading')) ?>) + '</p>';
    try {
      var r = await fetch('/api/free-exits.php?action=list&country=' + encodeURIComponent(countryEl.value), { headers: { 'X-CSRF-Token': csrf } });
      var data = await r.json();
      if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
      if (!data.nodes.length) { listEl.innerHTML = '<p class="muted">' + esc(<?= json_encode(t('fx.none')) ?>) + '</p>'; return; }
      listEl.innerHTML = '<table style="width:100%"><tr><th></th><th>' + esc(<?= json_encode(t('fx.col_type')) ?>) +
        '</th><th>' + esc(<?= json_encode(t('fx.col_server')) ?>) + '</th></tr>' +
        data.nodes.map(function (n) {
          return '<tr><td><input type="checkbox" class="fx-node" value="' + esc(n.tag) + '"></td>' +
            '<td>' + esc(n.type) + '</td>' +
            '<td class="muted" style="word-break:break-all">' + esc(n.server) + ':' + n.port + (n.country ? ' · ' + esc(n.country) : '') + '</td></tr>';
        }).join('') + '</table>';
    } catch (e) {
      listEl.innerHTML = '<div class="flash error">' + esc(T('fx.load_fail', e.message)) + '</div>';
    }
  }

  document.getElementById('fx-import').addEventListener('click', async function () {
    var tags = Array.prototype.map.call(document.querySelectorAll('.fx-node:checked'), function (c) { return c.value; });
    if (!tags.length) { msgEl.innerHTML = '<div class="flash error">' + esc(<?= json_encode(t('fx.pick')) ?>) + '</div>'; return; }
    var btn = this; btn.disabled = true;
    msgEl.innerHTML = '<p class="muted">…</p>';
    try {
      var r = await fetch('/api/free-exits.php?action=import', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        body: JSON.stringify({ country: countryEl.value, tags: tags, build_pool: document.getElementById('fx-pool').checked })
      });
      var data = await r.json();
      if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
      msgEl.innerHTML = '<div class="flash success">' + esc(T('fx.imported', data.created.length)) + '</div>';
      setTimeout(function () { location.reload(); }, 900);
    } catch (e) {
      msgEl.innerHTML = '<div class="flash error">' + esc(e.message) + '</div>';
      btn.disabled = false;
    }
  });
})();
</script>
