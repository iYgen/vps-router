<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Modules\ModuleManager;
use App\View;

Auth::requireLogin();

// Форматы экспорта берём из активных router-модулей (модульная система).
$exportFormats = ModuleManager::routerExports();

View::header(t('nav.routes'), t('routes.subtitle'));
View::routerScopeBar();
?>

<div class="routes-toolbar">
  <input type="search" id="routes-search" placeholder="<?= htmlspecialchars(t('routes.search')) ?>">
  <div class="spacer"></div>
  <span class="muted" id="routes-summary"></span>
  <button class="secondary" id="btn-routes-sync" title="<?= htmlspecialchars(t('routes.sync_title')) ?>"><?= htmlspecialchars(t('routes.sync')) ?></button>
  <?php if ($exportFormats): ?>
  <div class="export-menu" id="export-menu">
    <button class="secondary" id="btn-export" type="button" aria-haspopup="true" aria-expanded="false"><?= htmlspecialchars(t('routes.export')) ?> ▾</button>
    <div class="export-dropdown hidden" id="export-dropdown" role="menu">
      <?php foreach ($exportFormats as $e): ?>
        <a role="menuitem" href="/api/keenetic-export.php?format=<?= htmlspecialchars(urlencode($e['format'])) ?>"><?= htmlspecialchars($e['label']) ?></a>
      <?php endforeach; ?>
      <a role="menuitem" class="export-manage" href="/modules.php"><?= htmlspecialchars(t('routes.export_manage')) ?></a>
    </div>
  </div>
  <?php else: ?>
  <a class="btn secondary" href="/modules.php"><?= htmlspecialchars(t('routes.export_manage')) ?></a>
  <?php endif; ?>
  <?php if (ModuleManager::featureActive('route-presets')): ?>
  <button class="secondary" id="btn-routes-presets"><?= htmlspecialchars(t('routes.presets')) ?></button>
  <?php endif; ?>
  <button id="btn-routes-add"><?= htmlspecialchars(t('routes.add')) ?></button>
</div>

<?php if ($exportFormats): ?>
<script>
(function () {
  var btn = document.getElementById('btn-export');
  var dd = document.getElementById('export-dropdown');
  if (!btn || !dd) return;
  function close() { dd.classList.add('hidden'); btn.setAttribute('aria-expanded', 'false'); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = dd.classList.toggle('hidden') === false;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  // Клик вне меню или по пункту — закрыть. Esc — тоже.
  document.addEventListener('click', function (e) { if (!document.getElementById('export-menu').contains(e.target)) close(); });
  dd.addEventListener('click', function () { close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>

<div class="pz-overlay hidden" id="preset-modal">
  <div class="pz-dialog">
    <h2><?= htmlspecialchars(t('routes.presets_title')) ?></h2>
    <p class="muted" style="font-size:13px;margin:0 0 12px"><?= htmlspecialchars(t('routes.presets_desc')) ?></p>
    <div id="pz-list" class="pz-list"></div>
    <label style="display:block;margin:14px 0 5px;font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('routes.presets_pick_exit')) ?></label>
    <select id="pz-exit"></select>
    <div class="pz-actions">
      <button class="secondary" type="button" id="pz-cancel"><?= htmlspecialchars(t('routes.presets_cancel')) ?></button>
      <button type="button" id="pz-add"><?= htmlspecialchars(t('routes.presets_add')) ?></button>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
    <h2 style="font-size:15px"><?= htmlspecialchars(t('routes.import_title')) ?></h2>
    <p class="muted" style="font-size:12.5px;margin:0 0 10px"><?= htmlspecialchars(t('routes.import_desc')) ?></p>
    <select id="pz-source"></select>
    <input id="pz-url" placeholder="<?= htmlspecialchars(t('routes.import_url_ph')) ?>" style="width:100%;margin-top:8px;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:#18181c;color:var(--text);font:inherit">
    <div class="pz-actions">
      <button class="secondary" type="button" id="pz-import"><?= htmlspecialchars(t('routes.import_go')) ?></button>
    </div>
    <div class="pz-msg" id="pz-msg"></div>
  </div>
</div>
<style>
.pz-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:60;padding:16px}
.pz-dialog{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px 24px;width:100%;max-width:440px;max-height:86vh;overflow:auto}
.pz-dialog h2{margin:0 0 4px;font-size:17px}
.pz-list{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.pz-chip{display:flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;cursor:pointer;font-size:13px}
.pz-chip.sel{border-color:var(--accent);background:rgba(255,79,135,.07)}
.pz-chip input{width:auto;margin:0}
#pz-exit{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:#18181c;color:var(--text);font:inherit}
.pz-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
.pz-msg{margin-top:10px;font-size:13px;color:var(--danger)}
@media(max-width:520px){.pz-list{grid-template-columns:1fr}}
</style>

<div class="ri-bar">
  <span class="ri-ic">🔍</span>
  <input type="search" id="ri-input" placeholder="<?= htmlspecialchars(t('routes.inspect_ph')) ?>">
  <button class="secondary" type="button" id="ri-go"><?= htmlspecialchars(t('routes.inspect_go')) ?></button>
  <span class="ri-result" id="ri-result"></span>
</div>
<style>
.ri-bar{display:flex;align-items:center;gap:8px;padding:0 20px 10px;flex-wrap:wrap}
.ri-ic{opacity:.7}
.ri-bar input{flex:0 1 260px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:#18181c;color:var(--text);font:inherit;font-size:13px}
.ri-result{font-size:13px}
.ri-result .exit{padding:1px 8px;border-radius:999px;background:rgba(139,124,255,.14);color:var(--accent-purple)}
.ri-result .exit.direct{background:rgba(255,255,255,.06);color:var(--text-muted)}
.ri-result .via{color:var(--text-muted)}
.ri-result .geo{color:var(--text-muted)}
</style>

<div class="routes-layout">
  <div class="routes-tree-pane" id="routes-tree-pane">
    <div class="empty-state"><p class="muted"><?= htmlspecialchars(t('routes.loading')) ?></p></div>
  </div>
  <div id="routes-inspector" class="hidden"></div>
</div>

<div class="modal-overlay hidden" id="modal-overlay"></div>
<div class="toast-stack" id="toast-stack"></div>

<script src="/assets/js/panel-loader.js?v=<?= filemtime(__DIR__ . '/assets/js/panel-loader.js') ?>"></script>
<script src="/assets/js/routes-tree.js?v=<?= filemtime(__DIR__ . '/assets/js/routes-tree.js') ?>"></script>
<script>
// Пресеты маршрутов: добавление популярных сервисов (geosite) в один клик.
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var modal = document.getElementById('preset-modal');
  var listEl = document.getElementById('pz-list');
  var exitEl = document.getElementById('pz-exit');
  var msgEl = document.getElementById('pz-msg');
  var L = { pick: <?= json_encode(t('routes.presets_need_pick')) ?>, none: <?= json_encode(t('routes.presets_no_exits')) ?>, err: <?= json_encode(t('routes.presets_err')) ?> };
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  async function open() {
    msgEl.textContent = ''; listEl.innerHTML = ''; exitEl.innerHTML = '';
    modal.classList.remove('hidden');
    try {
      var pr = await (await fetch('/api/routes.php?action=presets')).json();
      listEl.innerHTML = (pr.presets || []).map(function (p) {
        return '<label class="pz-chip"><input type="checkbox" value="' + esc(p.id) + '">' + esc(p.name) + '</label>';
      }).join('');
      listEl.querySelectorAll('.pz-chip').forEach(function (ch) {
        var cb = ch.querySelector('input');
        cb.addEventListener('change', function () { ch.classList.toggle('sel', cb.checked); });
      });
      var exits = await (await fetch('/api/exit-servers.php')).json();
      if (!exits.length) { exitEl.innerHTML = '<option value="">' + esc(L.none) + '</option>'; }
      else { exitEl.innerHTML = exits.map(function (e) { return '<option value="' + e.id + '">' + esc(e.name) + '</option>'; }).join(''); }
      // Источники внешних списков.
      var srcEl = document.getElementById('pz-source');
      var sr = await (await fetch('/api/routes.php?action=list-sources')).json();
      srcEl.innerHTML = (sr.sources || []).map(function (s) {
        return '<option value="' + esc(s.id) + '" title="' + esc(s.note) + '">' + esc(s.name) + '</option>';
      }).join('') + '<option value="">— по URL —</option>';
    } catch (e) { msgEl.textContent = L.err; }
  }
  function close() { modal.classList.add('hidden'); }

  var presetsBtn = document.getElementById('btn-routes-presets');
  if (presetsBtn) presetsBtn.addEventListener('click', open); // кнопки нет, если модуль «Пресеты» выключен
  document.getElementById('pz-cancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

  // --- Инспектор маршрута: домен/IP -> правило и выход ---
  var riLabels = {
    direct: <?= json_encode(t('routes.inspect_direct')) ?>,
    matched: <?= json_encode(t('routes.inspect_matched')) ?>,
    geo: <?= json_encode(t('routes.inspect_geo')) ?>,
    err: <?= json_encode(t('routes.inspect_err')) ?>
  };
  var riInput = document.getElementById('ri-input');
  var riResult = document.getElementById('ri-result');
  async function riRun() {
    var q = riInput.value.trim();
    if (!q) { riResult.innerHTML = ''; return; }
    riResult.textContent = '…';
    try {
      var r = await fetch('/api/routes.php?action=inspect&q=' + encodeURIComponent(q));
      if (!r.ok) throw new Error();
      var d = await r.json();
      var html;
      if (d.match === 'rule') {
        html = riLabels.matched.replace('%g', esc(d.group)) +
          ' → <span class="exit">' + esc(d.exit) + '</span> <span class="via">(' + esc(d.via) + ')</span>';
      } else {
        html = '<span class="exit direct">' + esc(riLabels.direct) + '</span>';
      }
      if (d.geosite_candidates && d.geosite_candidates.length) {
        html += ' · <span class="geo">' + esc(riLabels.geo) + ': ' +
          d.geosite_candidates.map(function (c) { return 'geosite:' + esc(c.geosite) + ' → ' + esc(c.exit); }).join(', ') + '</span>';
      }
      riResult.innerHTML = html;
    } catch (e) { riResult.textContent = riLabels.err; }
  }
  document.getElementById('ri-go').addEventListener('click', riRun);
  riInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') riRun(); });

  // Импорт внешнего списка.
  var impLabels = {
    done: <?= json_encode(t('routes.import_done')) ?>,
    trunc: <?= json_encode(t('routes.import_truncated')) ?>,
    need: <?= json_encode(t('routes.import_need')) ?>
  };
  document.getElementById('pz-import').addEventListener('click', async function () {
    var src = document.getElementById('pz-source').value;
    var url = document.getElementById('pz-url').value.trim();
    var exitId = exitEl.value;
    var chosen = src || url;
    if (!chosen || !exitId) { msgEl.textContent = impLabels.need; return; }
    this.disabled = true; msgEl.textContent = '…'; msgEl.style.color = '';
    try {
      var r = await fetch('/api/routes.php?action=import-list', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
        body: JSON.stringify(src ? { source: src, exit_server_id: exitId } : { url: url, exit_server_id: exitId })
      });
      var d = await r.json();
      if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
      var txt = impLabels.done.replace('%d', d.domains).replace('%i', d.ips);
      if (d.truncated) txt += ' ' + impLabels.trunc;
      msgEl.style.color = 'var(--success)'; msgEl.textContent = txt;
      setTimeout(function () { location.reload(); }, 1500);
    } catch (e) { msgEl.style.color = 'var(--danger)'; msgEl.textContent = (L.err + ' ' + e.message); this.disabled = false; }
  });

  document.getElementById('pz-add').addEventListener('click', async function () {
    var chosen = Array.prototype.map.call(listEl.querySelectorAll('input:checked'), function (i) { return i.value; });
    var exitId = exitEl.value;
    if (!chosen.length || !exitId) { msgEl.textContent = L.pick; return; }
    this.disabled = true; msgEl.textContent = '';
    try {
      for (var i = 0; i < chosen.length; i++) {
        var r = await fetch('/api/routes.php?action=create-preset', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
          body: JSON.stringify({ preset: chosen[i], exit_server_id: exitId })
        });
        if (!r.ok) { var d = await r.json().catch(function(){return {};}); throw new Error(d.error || 'HTTP ' + r.status); }
      }
      location.reload();
    } catch (e) { msgEl.textContent = (L.err + ' ' + e.message); this.disabled = false; }
  });
})();
</script>

<?php View::footer(); ?>
