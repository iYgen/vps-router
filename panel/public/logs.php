<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();
View::header(t('logs.title'), t('logs.subtitle'));
?>
<style>
.lg-top{display:flex;align-items:center;gap:10px;padding:16px 20px 0;flex-wrap:wrap}
.lg-top input,.lg-top select{padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:#18181c;color:var(--text);font:inherit;font-size:13px}
.lg-top input[type=search]{min-width:200px}
.lg-term{margin:14px 20px;background:#0d0d10;border:1px solid var(--border);border-radius:10px;padding:12px 14px;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.55;color:#c8c8d0;
  height:62vh;overflow:auto;white-space:pre-wrap;word-break:break-word}
.lg-err{color:var(--danger)}
</style>

<div class="lg-top">
  <label style="margin:0;color:var(--text-muted);font-size:13px"><?= htmlspecialchars(t('logs.lines')) ?></label>
  <select id="lg-lines">
    <option value="100">100</option><option value="200" selected>200</option>
    <option value="500">500</option><option value="1000">1000</option>
  </select>
  <input type="search" id="lg-grep" placeholder="<?= htmlspecialchars(t('logs.filter')) ?>">
  <label style="margin:0;display:flex;align-items:center;gap:6px;color:var(--text-muted);font-size:13px">
    <input type="checkbox" id="lg-auto" style="width:auto"> <?= htmlspecialchars(t('logs.auto')) ?>
  </label>
  <button class="secondary" id="lg-refresh" type="button"><?= htmlspecialchars(t('logs.refresh')) ?></button>
</div>

<div class="lg-term" id="lg-term"><?= htmlspecialchars(t('logs.loading')) ?></div>

<script>
(function () {
  var term = document.getElementById('lg-term');
  var errText = <?= json_encode(t('logs.error')) ?>;
  var timer = null;
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  async function load(){
    var lines = document.getElementById('lg-lines').value;
    var grep = document.getElementById('lg-grep').value.trim();
    try {
      var r = await fetch('/api/singbox-log.php?lines='+encodeURIComponent(lines)+'&grep='+encodeURIComponent(grep), {headers:{'Accept':'application/json'}});
      if (!r.ok) return;
      var d = await r.json();
      var atBottom = term.scrollTop + term.clientHeight >= term.scrollHeight - 30;
      if (!d.ok) { term.innerHTML = '<span class="lg-err">'+esc(errText)+' '+esc(d.error||'')+'</span>'; return; }
      term.textContent = d.lines.length ? d.lines.join('\n') : '—';
      if (atBottom) term.scrollTop = term.scrollHeight;
    } catch(e){}
  }
  function setAuto(){ if (document.getElementById('lg-auto').checked) { if(!timer) timer=setInterval(load, 3000); } else { clearInterval(timer); timer=null; } }
  document.getElementById('lg-refresh').onclick = load;
  document.getElementById('lg-lines').onchange = load;
  document.getElementById('lg-grep').addEventListener('input', function(){ clearTimeout(this._t); this._t=setTimeout(load, 400); });
  document.getElementById('lg-auto').onchange = setAuto;
  load();
})();
</script>

<?php View::footer(); ?>
