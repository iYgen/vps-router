<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Setting;
use App\View;

Auth::requireLogin();

View::header(t('dash.title'), t('dash.subtitle'));
?>
<script>window.PANEL_CONFIG = {
  graphRefreshInterval: <?= (int) (Setting::get('graph_refresh_interval', '30')) ?>,
  features: { probeIntel: <?= \App\Modules\ModuleManager::featureActive('probe-intel') ? 'true' : 'false' ?>, tgProxy: <?= \App\Modules\ModuleManager::featureActive('tg-proxy') ? 'true' : 'false' ?> }
};</script>

<div class="kpi-slider" id="kpi-slider">
  <button type="button" class="kpi-nav kpi-prev" id="kpi-prev" aria-label="←" hidden>‹</button>
  <div class="kpi-grid" id="kpi-grid">
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.servers')) ?></div><div class="kpi-value" id="kpi-servers">—</div><div class="kpi-meta" id="kpi-servers-meta"></div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.connections')) ?></div><div class="kpi-value" id="kpi-connections">—</div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.sets')) ?></div><div class="kpi-value" id="kpi-sets">—</div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.routes')) ?></div><div class="kpi-value" id="kpi-routes">—</div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.rate')) ?></div><div class="kpi-value" id="kpi-rate" style="font-size:16px">—</div><div class="kpi-meta" id="kpi-transfer"></div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.online')) ?></div><div class="kpi-value" id="kpi-online">—</div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.uptime')) ?></div><div class="kpi-value" id="kpi-uptime" style="font-size:16px">—</div></div>
    <div class="kpi-card"><div class="kpi-label"><?= htmlspecialchars(t('dash.kpi.exits')) ?></div><div class="kpi-value" id="kpi-exits">—</div><div class="kpi-meta" id="kpi-latency"></div></div>
  </div>
  <button type="button" class="kpi-nav kpi-next" id="kpi-next" aria-label="→" hidden>›</button>
</div>

<div class="risk-banner hidden" id="risk-banner" style="margin:0 20px 12px"></div>
<?php if (\App\Modules\ModuleManager::featureActive('update-check')): ?>
<div class="update-banner hidden" id="update-banner" style="margin:0 20px 12px"></div>
<?php endif; ?>

<div class="infra-toolbar">
  <button id="btn-wizard" title="<?= htmlspecialchars(t('dash.add_server_hint')) ?>"><span class="ic" aria-hidden="true">➕</span><span class="lbl"><?= htmlspecialchars(t('dash.add_server')) ?></span></button>
  <button class="secondary" id="btn-add-server" title="<?= htmlspecialchars(t('dash.manual')) ?>"><span class="ic" aria-hidden="true">✍️</span><span class="lbl"><?= htmlspecialchars(t('dash.manual')) ?></span></button>

  <div class="tb-menu" id="tb-more">
    <button type="button" class="secondary tb-menu-btn" id="btn-more" aria-haspopup="true" aria-expanded="false" title="<?= htmlspecialchars(t('dash.more')) ?>"><span class="ic" aria-hidden="true">⋯</span><span class="lbl"><?= htmlspecialchars(t('dash.more')) ?></span></button>
    <div class="tb-dropdown" role="menu" hidden>
      <button type="button" role="menuitem" id="btn-bulk-routes"><span class="ic" aria-hidden="true">🧭</span><?= htmlspecialchars(t('dash.bulk_routes')) ?></button>
      <button type="button" role="menuitem" id="btn-server-sets"><span class="ic" aria-hidden="true">🗂️</span><?= htmlspecialchars(t('dash.server_sets')) ?></button>
      <button type="button" role="menuitem" id="btn-export-import"><span class="ic" aria-hidden="true">📦</span><?= htmlspecialchars(t('dash.export_import')) ?></button>
      <a role="menuitem" href="/audit.php"><span class="ic" aria-hidden="true">📝</span><?= htmlspecialchars(t('dash.audit')) ?></a>
    </div>
  </div>

  <div class="spacer"></div>
  <button class="secondary" id="btn-toggle-devices"
    data-show="<?= htmlspecialchars(t('dash.devices_show')) ?>"
    data-hide="<?= htmlspecialchars(t('dash.devices_hide')) ?>"
    title="<?= htmlspecialchars(t('dash.devices_show')) ?>"><span class="ic" aria-hidden="true">💻</span><span class="lbl"></span></button>
  <button class="secondary" id="btn-fit" title="<?= htmlspecialchars(t('dash.fit')) ?>"><span class="ic" aria-hidden="true">⤢</span><span class="lbl"><?= htmlspecialchars(t('dash.fit')) ?></span></button>
</div>

<div class="infra-layout">
  <div id="infra-canvas-wrap">
    <div id="infra-canvas"></div>
    <div id="graph-loading" class="graph-loading hidden" aria-hidden="true"></div>
  </div>
  <div id="infra-inspector" class="hidden"></div>
</div>

<div class="pending-banner hidden" id="pending-banner">
  <span><span id="pending-count">0</span> <?= htmlspecialchars(t('dash.pending', '')) ?></span>
  <button id="btn-review-apply"><?= htmlspecialchars(t('dash.review_apply')) ?></button>
</div>

<div class="modal-overlay hidden" id="modal-overlay"></div>
<div class="toast-stack" id="toast-stack"></div>

<script src="/assets/js/panel-loader.js?v=<?= filemtime(__DIR__ . '/assets/js/panel-loader.js') ?>"></script>
<script src="/assets/vendor/cytoscape.min.js"></script>
<script src="/assets/vendor/lodash-shim.js"></script>
<script src="/assets/vendor/cytoscape-edgehandles.min.js"></script>
<script src="/assets/js/route-tree-picker.js?v=<?= filemtime(__DIR__ . "/assets/js/route-tree-picker.js") ?>"></script>
<script src="/assets/js/infrastructure.js?v=<?= filemtime(__DIR__ . "/assets/js/infrastructure.js") ?>"></script>
<script src="/assets/js/masters/add-server-wizard.js?v=<?= filemtime(__DIR__ . "/assets/js/masters/add-server-wizard.js") ?>"></script>

<script>
// Живые KPI дашборда: скорость (по дельте), онлайн-устройства, аптайм, exit'ы.
(function () {
  var prev = null;
  var SB_DOWN = <?= json_encode(t('dash.singbox_down')) ?>;
  function fmt(b) { b = +b || 0; var u = ['Б','КБ','МБ','ГБ','ТБ'], i = 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return (b >= 100 ? b.toFixed(0) : b.toFixed(1)) + ' ' + u[i]; }
  function dur(s) { if (s == null) return '—'; s = +s; var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return (d ? d + 'д ' : '') + (h ? h + 'ч ' : '') + m + 'м'; }
  function set(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }
  async function tick() {
    try {
      var r = await fetch('/api/dashboard-stats.php', { headers: { 'Accept': 'application/json' } });
      if (!r.ok) return;
      var s = await r.json();
      var rateEl = document.getElementById('kpi-rate');
      if (s.singbox_active === false) {
        set('kpi-rate', SB_DOWN);
        if (rateEl) rateEl.style.color = 'var(--danger)';
      } else {
        if (rateEl) rateEl.style.color = '';
        if (s.clash_up && prev && prev.clash_up && s.ts > prev.ts) {
          var dt = (s.ts - prev.ts) / 1000;
          var dn = Math.max(0, s.download_total - prev.download_total) / dt;
          var up = Math.max(0, s.upload_total - prev.upload_total) / dt;
          set('kpi-rate', '↓' + fmt(dn) + '/s  ↑' + fmt(up) + '/s');
        } else if (!s.clash_up) {
          set('kpi-rate', '—');
        }
      }
      set('kpi-transfer', s.transfer_total != null ? ('Σ ' + fmt(s.transfer_total)) : '');
      set('kpi-online', s.devices_online + ' / ' + s.devices_total);
      set('kpi-uptime', dur(s.uptime));
      set('kpi-exits', s.exits_healthy + ' / ' + s.exits_total);
      set('kpi-latency', s.exits_latency != null ? ('~' + s.exits_latency + ' ms') : '');
      prev = s;
    } catch (e) { /* тихо: дашборд не должен падать из-за метрик */ }
  }
  tick();
  setInterval(tick, 3000);
})();

// KPI-слайдер: стрелки ‹ › появляются только когда плашки не влезают; скролл порциями.
(function () {
  var grid = document.getElementById('kpi-grid'),
      prev = document.getElementById('kpi-prev'),
      next = document.getElementById('kpi-next');
  if (!grid || !prev || !next) return;
  function sync() {
    var over = grid.scrollWidth - grid.clientWidth > 4;
    var x = grid.scrollLeft;
    prev.hidden = !over;
    next.hidden = !over;
    if (over) {
      prev.disabled = x <= 1;
      next.disabled = x >= grid.scrollWidth - grid.clientWidth - 1;
    }
  }
  function step(dir) {
    grid.scrollBy({ left: dir * Math.max(200, grid.clientWidth * 0.8), behavior: 'smooth' });
  }
  prev.addEventListener('click', function () { step(-1); });
  next.addEventListener('click', function () { step(1); });
  grid.addEventListener('scroll', sync, { passive: true });
  window.addEventListener('resize', sync);
  sync();
  setTimeout(sync, 300); // после подстановки значений ширины могут измениться
})();

// Тулбар инфраструктуры: выпадающее меню «Ещё».
(function () {
  var wrap = document.getElementById('tb-more');
  if (!wrap) return;
  var btn = document.getElementById('btn-more'),
      menu = wrap.querySelector('.tb-dropdown');
  function close() { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  function open() { menu.hidden = false; btn.setAttribute('aria-expanded', 'true'); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    menu.hidden ? open() : close();
  });
  // Пункты меню закрывают его (сами обработчики кликов навешивает infrastructure.js по id).
  menu.addEventListener('click', function () { close(); });
  document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>

<?php include __DIR__ . '/partials/free-exit-modal.php'; ?>

<?php View::footer(); ?>
