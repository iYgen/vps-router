<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();
View::header(t('modules.title'), t('modules.subtitle'));
?>
<style>
.mod-wrap{padding:20px;max-width:900px}
.mod-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:12px}
.mod-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.mod-row .grow{flex:1;min-width:0}
.mod-name{font-weight:600;font-size:15px}
.mod-meta{color:var(--text-muted);font-size:12.5px;margin-top:2px}
.mod-desc{color:var(--text-secondary);font-size:13px;margin-top:6px}
.mod-badge{display:inline-block;font-size:11.5px;padding:1px 9px;border-radius:20px}
.mod-badge.on{background:rgba(53,208,127,.15);color:#35d07f}
.mod-badge.off{background:rgba(255,255,255,.07);color:var(--text-muted)}
.mod-badge.kept{background:rgba(139,124,255,.15);color:#b7acff;margin-left:6px}
.mod-actions{display:flex;gap:8px;flex-wrap:wrap}
.mod-actions button{padding:7px 14px;border:none;border-radius:8px;font:inherit;font-weight:600;cursor:pointer}
.mod-actions .sec{background:var(--surface2,#26262c);color:var(--text)}
.mod-actions .danger{background:rgba(255,92,92,.15);color:#ff8f8f}
.mod-actions .pri{background:var(--accent);color:#fff}
h2.mod-h{font-size:15px;margin:22px 0 10px}
.mod-upload{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px}
.mod-empty{color:var(--text-muted);font-size:13px;padding:8px 0}
#mod-flash{margin:0 0 12px}
</style>

<div class="mod-wrap">
  <div id="mod-flash"></div>

  <div class="mod-card">
    <div class="mod-name"><?= htmlspecialchars(t('modules.upload')) ?></div>
    <div class="mod-meta"><?= htmlspecialchars(t('modules.upload_hint')) ?></div>
    <form class="mod-upload" id="mod-upload-form">
      <input type="file" name="archive" accept=".vmod,application/json" required>
      <button class="pri" type="submit"><?= htmlspecialchars(t('modules.upload_btn')) ?></button>
    </form>
  </div>

  <h2 class="mod-h"><?= htmlspecialchars(t('modules.installed')) ?></h2>
  <div id="mod-installed"><div class="mod-empty"><?= htmlspecialchars(t('common.loading')) ?></div></div>

  <h2 class="mod-h"><?= htmlspecialchars(t('modules.available')) ?></h2>
  <div id="mod-available"><div class="mod-empty"><?= htmlspecialchars(t('common.loading')) ?></div></div>
</div>

<script src="/assets/js/panel-loader.js?v=<?= filemtime(__DIR__ . '/assets/js/panel-loader.js') ?>"></script>
<script>
(function () {
  var CSRF = <?= json_encode(Auth::csrfToken()) ?>;
  function sleep(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }
  // Долгое действие с единым индикатором (кольцо). Минимальная длительность,
  // чтобы процесс был виден, даже если бэкенд ответил мгновенно.
  async function withProgress(title, steps, expectedSec, fn) {
    var pg = (window.PanelLoader) ? window.PanelLoader.progress(title, expectedSec, steps) : null;
    var started = Date.now();
    try {
      var r = await fn();
      var minMs = Math.max(0, (expectedSec * 1000 * 0.6) - (Date.now() - started));
      if (minMs > 0) await sleep(minMs);
      return r;
    } finally {
      if (pg) pg.done();
    }
  }
  var L = <?= json_encode([
      'active' => t('modules.active'), 'inactive' => t('modules.inactive'),
      'activate' => t('modules.activate'), 'deactivate' => t('modules.deactivate'),
      'remove' => t('modules.remove'), 'install' => t('modules.install'),
      'none_installed' => t('modules.none_installed'), 'none_available' => t('modules.none_available'),
      'kept' => t('modules.settings_kept'), 'type' => t('modules.type'), 'version' => t('modules.version'),
      'confirm_remove' => t('modules.confirm_remove'), 'confirm_keep' => t('modules.confirm_keep'),
      'err' => t('modules.error'),
      'installing' => t('modules.installing'), 'activating' => t('modules.activating'), 'deactivating' => t('modules.deactivating'),
      'removing' => t('modules.removing'),
      'step_unpack' => t('modules.step_unpack'), 'step_register' => t('modules.step_register'),
      'step_activate' => t('modules.step_activate'), 'step_apply' => t('modules.step_apply'),
      'remove_note' => t('modules.remove_note'), 'keep_label' => t('modules.keep_label'), 'cancel' => t('modules.cancel'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  function flash(msg, ok){ var f=document.getElementById('mod-flash'); f.innerHTML='<div class="flash '+(ok?'success':'error')+'">'+esc(msg)+'</div>'; setTimeout(function(){f.innerHTML='';},5000); }

  /* Стилизованная модалка удаления (без браузерного confirm). Галочка решает,
     удалять ли данные/настройки модуля. Promise → {keep} или null (отмена). */
  function confirmRemove(name){
    return new Promise(function(resolve){
      var ov=document.createElement('div');
      ov.style.cssText='position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)';
      ov.innerHTML=
        '<div role="dialog" aria-modal="true" style="background:var(--surface-elevated,#1d1d22);border:1px solid var(--border);border-radius:16px;padding:24px 26px;max-width:460px;width:92%;box-shadow:var(--shadow-modal,0 24px 80px rgba(0,0,0,.45))">'
        +'<div style="font-weight:600;font-size:16px;margin-bottom:8px">'+esc(L.remove)+' «'+esc(name)+'»?</div>'
        +'<p class="muted" style="font-size:13px;margin:0 0 14px">'+esc(L.remove_note)+'</p>'
        +'<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;margin-bottom:20px"><input type="checkbox" id="mr-keep" checked style="width:auto;margin-top:2px"> <span>'+esc(L.keep_label)+'</span></label>'
        +'<div style="display:flex;justify-content:flex-end;gap:8px">'
        +'<button id="mr-cancel" style="padding:8px 16px;border:none;border-radius:8px;font:inherit;font-weight:600;cursor:pointer;background:var(--surface2,#26262c);color:var(--text)">'+esc(L.cancel)+'</button>'
        +'<button id="mr-ok" style="padding:8px 16px;border:none;border-radius:8px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,92,92,.15);color:#ff8f8f">'+esc(L.remove)+'</button>'
        +'</div></div>';
      document.body.appendChild(ov);
      function close(res){ ov.remove(); document.removeEventListener('keydown',onKey); resolve(res); }
      function onKey(e){ if(e.key==='Escape') close(null); }
      ov.querySelector('#mr-cancel').onclick=function(){ close(null); };
      ov.querySelector('#mr-ok').onclick=function(){ close({keep: ov.querySelector('#mr-keep').checked}); };
      ov.addEventListener('click',function(e){ if(e.target===ov) close(null); });
      document.addEventListener('keydown',onKey);
    });
  }

  async function api(action, body, isForm){
    var opt = { method:'POST', headers:{ 'X-CSRF-Token': CSRF } };
    if (isForm) { opt.body = body; }
    else { opt.headers['Content-Type']='application/json'; opt.body = JSON.stringify(body||{}); }
    var r = await fetch('/api/modules.php?action='+action, opt);
    var d = await r.json();
    if (!r.ok) throw new Error(d.error || ('HTTP '+r.status));
    return d;
  }

  function badge(m){
    return '<span class="mod-badge '+(m.active?'on':'off')+'">'+esc(m.active?L.active:L.inactive)+'</span>'
      + (m.has_settings && !m.active ? '<span class="mod-badge kept">'+esc(L.kept)+'</span>' : '');
  }

  function installedCard(m){
    var el = document.createElement('div'); el.className='mod-card';
    el.innerHTML =
      '<div class="mod-row"><div class="grow">'
      + '<div class="mod-name">'+esc(m.name)+' '+badge(m)+'</div>'
      + '<div class="mod-meta">'+esc(L.type)+': '+esc(m.type)+' · '+esc(L.version)+' '+esc(m.version)+' · '+esc(m.id)+'</div>'
      + (m.manifest && m.manifest.description ? '<div class="mod-desc">'+esc(m.manifest.description)+'</div>' : '')
      + '</div><div class="mod-actions">'
      + '<button class="sec" data-act="'+(m.active?'deactivate':'activate')+'">'+esc(m.active?L.deactivate:L.activate)+'</button>'
      + '<button class="danger" data-act="remove">'+esc(L.remove)+'</button>'
      + '</div></div>';
    el.querySelector('[data-act="'+(m.active?'deactivate':'activate')+'"]').onclick = async function(){
      var on = m.active;
      try {
        await withProgress(on ? L.deactivating : L.activating, [on ? L.step_apply : L.step_activate], 1.3,
          function(){ return api(on ? 'deactivate' : 'activate', {id:m.id}); });
        await load();
      } catch(e){ flash(e.message,false); }
    };
    el.querySelector('[data-act="remove"]').onclick = async function(){
      var choice = await confirmRemove(m.name);
      if (!choice) return;
      try {
        await withProgress(L.removing, [L.step_apply], 1.1, function(){ return api('remove', {id:m.id, keep_settings: choice.keep}); });
        flash('OK', true); await load();
      } catch(e){ flash(e.message,false); }
    };
    return el;
  }

  function availableCard(a){
    var el = document.createElement('div'); el.className='mod-card';
    el.innerHTML =
      '<div class="mod-row"><div class="grow">'
      + '<div class="mod-name">'+esc(a.name)+'</div>'
      + '<div class="mod-meta">'+esc(L.type)+': '+esc(a.type)+' · '+esc(L.version)+' '+esc(a.version)+' · '+esc(a.archive)+'</div>'
      + (a.description ? '<div class="mod-desc">'+esc(a.description)+'</div>' : '')
      + '</div><div class="mod-actions"><button class="pri" data-install>'+esc(L.install)+'</button></div></div>';
    el.querySelector('[data-install]').onclick = async function(){
      try {
        await withProgress(L.installing, [L.step_unpack, L.step_register, L.step_activate], 1.8,
          function(){ return api('install', {archive:a.archive}); });
        flash('OK', true); await load();
      } catch(e){ flash(e.message,false); }
    };
    return el;
  }

  async function load(){
    try {
      var r = await fetch('/api/modules.php', { headers:{ 'Accept':'application/json' } });
      var d = await r.json();
      var ins = document.getElementById('mod-installed'); ins.innerHTML='';
      if (!d.installed.length) ins.innerHTML='<div class="mod-empty">'+esc(L.none_installed)+'</div>';
      else d.installed.forEach(function(m){ ins.appendChild(installedCard(m)); });
      var av = document.getElementById('mod-available'); av.innerHTML='';
      if (!d.available.length) av.innerHTML='<div class="mod-empty">'+esc(L.none_available)+'</div>';
      else d.available.forEach(function(a){ av.appendChild(availableCard(a)); });
    } catch(e){ flash(L.err+' '+e.message, false); }
  }

  document.getElementById('mod-upload-form').addEventListener('submit', async function(e){
    e.preventDefault();
    var fd = new FormData(e.target);
    try { await api('upload', fd, true); e.target.reset(); flash('OK', true); await load(); }
    catch(err){ flash(err.message, false); }
  });

  load();
})();
</script>

<?php View::footer(); ?>
