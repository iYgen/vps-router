<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();
View::header(t('conn.title'), t('conn.subtitle'));
?>
<style>
.cn-top{display:flex;align-items:center;gap:12px;padding:16px 20px 0;flex-wrap:wrap}
.cn-stat{color:var(--text-muted);font-size:13px}
.cn-stat b{color:var(--text)}
.cn-hint{color:var(--text-muted);font-size:12px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 10px}
.cn-wrap{padding:14px 20px}
.cn-tbl{width:100%;border-collapse:collapse}
.cn-tbl th{text-align:left;color:var(--text-muted);font-weight:500;font-size:12px;padding:6px 10px;border-bottom:1px solid var(--border)}
.cn-tbl td{padding:7px 10px;border-bottom:1px solid var(--border);font-size:13px;white-space:nowrap}
.cn-host{max-width:280px;overflow:hidden;text-overflow:ellipsis}
.cn-flag{margin-right:6px}
.cn-exit{display:inline-block;padding:1px 8px;border-radius:999px;background:rgba(139,124,255,.14);color:var(--accent-purple);font-size:11.5px}
.cn-exit.direct{background:rgba(255,255,255,.06);color:var(--text-muted)}
.cn-dl{color:var(--text)}.cn-ul{color:var(--text-muted)}
.cn-muted{color:var(--text-muted)}
@media(max-width:720px){.cn-opt{display:none}}
</style>

<div class="cn-top">
  <span class="cn-stat"><?= htmlspecialchars(t('conn.active')) ?>: <b id="cn-count">—</b></span>
  <span class="cn-stat">↓<b id="cn-dl">—</b> ↑<b id="cn-ul">—</b></span>
  <div style="flex:1"></div>
  <span class="cn-hint" id="cn-geohint" hidden><?= htmlspecialchars(t('conn.geoip_hint')) ?></span>
</div>

<div class="cn-wrap">
  <div id="cn-msg" class="cn-muted" style="padding:10px 0"><?= htmlspecialchars(t('conn.loading')) ?></div>
  <table class="cn-tbl" id="cn-tbl" hidden>
    <thead><tr>
      <th><?= htmlspecialchars(t('conn.col_dest')) ?></th>
      <th><?= htmlspecialchars(t('conn.col_exit')) ?></th>
      <th class="cn-opt"><?= htmlspecialchars(t('conn.col_net')) ?></th>
      <th><?= htmlspecialchars(t('conn.col_traffic')) ?></th>
      <th class="cn-opt"><?= htmlspecialchars(t('conn.col_age')) ?></th>
    </tr></thead>
    <tbody id="cn-body"></tbody>
  </table>
</div>

<script>
(function () {
  var msg = document.getElementById('cn-msg'), tbl = document.getElementById('cn-tbl'), body = document.getElementById('cn-body');
  var L = { down: <?= json_encode(t('conn.clash_down')) ?>, empty: <?= json_encode(t('conn.empty')) ?> };
  function fmt(b){ b=+b||0; var u=['Б','КБ','МБ','ГБ','ТБ'],i=0; while(b>=1024&&i<u.length-1){b/=1024;i++;} return (b>=100?b.toFixed(0):b.toFixed(1))+' '+u[i]; }
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  function flag(cc){ if(!cc||cc.length!==2) return '🌐'; try { return String.fromCodePoint.apply(null,[...cc.toUpperCase()].map(function(c){return 0x1F1E6+c.charCodeAt(0)-65;})); } catch(e){ return '🌐'; } }
  function age(startIso){ if(!startIso) return ''; var s=Math.max(0,(Date.now()-new Date(startIso).getTime())/1000); if(s<60) return Math.floor(s)+'с'; if(s<3600) return Math.floor(s/60)+'м'; return Math.floor(s/3600)+'ч'; }

  async function tick(){
    var s;
    try { var r=await fetch('/api/connections-live.php',{headers:{'Accept':'application/json'}}); if(!r.ok) return; s=await r.json(); }
    catch(e){ return; }
    document.getElementById('cn-geohint').hidden = !!s.geoip;
    if (!s.clash_up) { tbl.hidden=true; msg.hidden=false; msg.textContent=L.down; return; }
    var list=s.connections||[], names=s.exit_names||{};
    document.getElementById('cn-count').textContent = list.length;
    var tdl=0,tul=0; list.forEach(function(c){ tdl+=c.down; tul+=c.up; });
    document.getElementById('cn-dl').textContent = fmt(tdl); document.getElementById('cn-ul').textContent = fmt(tul);
    if (!list.length){ tbl.hidden=true; msg.hidden=false; msg.textContent=L.empty; return; }
    msg.hidden=true; tbl.hidden=false;
    body.innerHTML = list.map(function(c){
      var nm = names[c.outbound] || c.outbound || '—';
      var direct = (c.outbound==='direct-rf'||c.outbound==='');
      var dest = c.dest_ip ? (c.dest_ip + (c.dest_port?(':'+c.dest_port):'')) : '';
      return '<tr>'+
        '<td class="cn-host" title="'+esc(c.host)+' '+esc(dest)+'"><span class="cn-flag">'+flag(c.cc)+'</span>'+esc(c.host)+'<div class="cn-muted" style="font-size:11px">'+esc(dest)+'</div></td>'+
        '<td><span class="cn-exit '+(direct?'direct':'')+'">'+esc(nm)+'</span></td>'+
        '<td class="cn-opt cn-muted">'+esc(c.network)+'</td>'+
        '<td><span class="cn-dl">↓'+fmt(c.down)+'</span> <span class="cn-ul">↑'+fmt(c.up)+'</span></td>'+
        '<td class="cn-opt cn-muted">'+age(c.start)+'</td>'+
      '</tr>';
    }).join('');
  }
  tick(); setInterval(tick, 3000);
})();
</script>

<?php View::footer(); ?>
