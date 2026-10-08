<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Installer;

// Мастер доступен только пока панель не установлена (нет админа).
if (Installer::isInstalled()) {
    header('Location: /login.php');
    exit;
}
// «Уникальная ссылка»: если install.sh выдал токен — требуем его в ?t=.
// Если токена нет (ручная установка) — accessAllowed пускает по обратной совместимости.
$installToken = (string) ($_GET['t'] ?? '');
if (!Installer::accessAllowed($installToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Установщик открывается по одноразовой ссылке из вывода deploy/install.sh (…/install.php?t=…).";
    exit;
}

$csrf = Auth::csrfToken();
$detectedIp = \App\NetworkInfo::detectPublicIp() ?: '';
// В мастере установки по умолчанию английский (это внешний ресурс), пока
// пользователь не переключит язык явно (?lang=…, что также ставит cookie).
if (!isset($_GET['lang']) && !isset($_COOKIE['panel_lang'])) {
    \App\I18n::setLang('en');
}
$lang = \App\I18n::lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('inst.title')) ?></title>
<link href="/assets/fonts/inter.css" rel="stylesheet">
<style>
:root { color-scheme: dark; --bg:#151519; --surface:#1d1d22; --surface2:#26262c; --border:rgba(255,255,255,.08);
  --text:#f5f5f7; --text-muted:#71717a; --accent:#ff4f87; --accent-hover:#ff689a; --accent-purple:#8b7cff;
  --success:#35d07f; --danger:#ff5c5c; --radius:12px; }
* { box-sizing:border-box; }
body { margin:0; min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px;
  font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text); }
.wz { width:100%; max-width:560px; background:var(--surface); border:1px solid var(--border); border-radius:16px;
  padding:28px 28px 22px; box-shadow:0 24px 80px rgba(0,0,0,.45); }
.wz-brand { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.wz-mark { width:30px; height:30px; border-radius:9px; background:linear-gradient(135deg,var(--accent),var(--accent-purple));
  display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; }
.wz-steps { display:flex; gap:6px; margin:18px 0 20px; }
.wz-steps .dot { flex:1; height:4px; border-radius:2px; background:var(--surface2); }
.wz-steps .dot.on { background:var(--accent); }
h1 { font-size:18px; margin:0; } h2 { font-size:16px; margin:0 0 6px; }
p.muted { color:var(--text-muted); font-size:13px; margin:0 0 14px; }
label { display:block; font-size:13px; margin:12px 0 5px; color:var(--text-muted); }
input, select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:#18181c;
  color:var(--text); font:inherit; font-size:14px; }
input:focus, select:focus { outline:none; border-color:var(--accent); }
.opt { border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:10px; cursor:pointer; }
.opt.sel { border-color:var(--accent); background:rgba(255,79,135,.06); }
.opt b { display:block; } .opt span { color:var(--text-muted); font-size:12.5px; }
.row { display:flex; gap:10px; align-items:center; }
.actions { display:flex; justify-content:space-between; margin-top:22px; }
button { padding:10px 18px; border:none; border-radius:8px; background:var(--accent); color:#fff; font:inherit;
  font-weight:600; cursor:pointer; } button:hover{ background:var(--accent-hover);} button.secondary{ background:var(--surface2); color:var(--text);} button:disabled{opacity:.5;cursor:default;}
.err { background:rgba(255,92,92,.12); border:1px solid rgba(255,92,92,.3); color:#ffb3b3; padding:10px 12px; border-radius:8px; font-size:13px; margin-bottom:12px; }
.plog { list-style:none; padding:0; margin:8px 0 0; }
.plog li { padding:8px 0; border-bottom:1px solid var(--border); font-size:14px; display:flex; gap:10px; align-items:center; }
.plog li .st { width:18px; text-align:center; }
.plog li.pending { color:var(--text-muted); } .plog li.ok .st { color:var(--success); } .plog li.run .st { color:var(--accent); }
.wz-lang { text-align:center; margin-top:16px; font-size:12px; }
.wz-lang a { color:var(--text-muted); text-decoration:none; margin:0 4px; } .wz-lang a.on { color:var(--accent); }
.done-check { width:60px;height:60px;border-radius:50%;background:rgba(53,208,127,.15);color:var(--success);
  display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 14px; }
.term { background:#0d0d10; border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin:10px 0;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; line-height:1.5; color:#c8c8d0;
  max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word; }
.verdict { padding:10px 12px; border-radius:8px; font-size:13px; margin:10px 0; border:1px solid var(--border); }
.verdict.green { background:rgba(53,208,127,.12); border-color:rgba(53,208,127,.35); color:#a7e8c6; }
.verdict.yellow { background:rgba(255,193,7,.10); border-color:rgba(255,193,7,.35); color:#f0d488; }
.verdict.red { background:rgba(255,92,92,.10); border-color:rgba(255,92,92,.35); color:#ffb3b3; }
</style>
</head>
<body>
<div class="wz">
  <div class="wz-brand"><div class="wz-mark">V</div><h1><?= htmlspecialchars(t('inst.title')) ?></h1></div>
  <div class="wz-steps" id="wz-dots"></div>
  <div id="wz-body"></div>
  <div class="wz-lang">
    <?php $tokenQs = $installToken !== '' ? '&amp;t=' . rawurlencode($installToken) : ''; ?>
    <?php foreach (\App\I18n::available() as $code => $name): ?>
      <a href="?lang=<?= rawurlencode($code) . $tokenQs ?>" class="<?= $lang === $code ? 'on' : '' ?>"><?= htmlspecialchars(strtoupper($code)) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  var TOKEN = <?= json_encode($installToken) ?>;
  var DETECTED_IP = <?= json_encode($detectedIp) ?>;
  var T = window.T || function (k) { return k; };
  var L = <?= json_encode([
    'welcome' => t('inst.welcome'), 'welcome_desc' => t('inst.welcome_desc'),
    'pf_title' => t('inst.pf_title'), 'pf_desc' => t('inst.pf_desc'), 'pf_running' => t('inst.pf_running'),
    'pf_next' => t('inst.pf_next'), 'pf_skip' => t('inst.pf_skip'), 'pf_error' => t('inst.pf_error'),
    'pf_v_green' => t('inst.pf_v_green'), 'pf_v_yellow' => t('inst.pf_v_yellow'), 'pf_v_red' => t('inst.pf_v_red'),
    'pf_share_btn' => t('inst.pf_share_btn'),
    'step_mode' => t('inst.step_mode'), 'mode_fresh' => t('inst.mode_fresh'), 'mode_fresh_desc' => t('inst.mode_fresh_desc'),
    'mode_restore' => t('inst.mode_restore'), 'mode_restore_desc' => t('inst.mode_restore_desc'), 'backup_file' => t('inst.backup_file'),
    'step_components' => t('inst.step_components'), 'components_desc' => t('inst.components_desc'), 'components_amnezia' => t('inst.components_amnezia'),
    'components_run' => t('inst.components_run'), 'components_running' => t('inst.components_running'), 'components_ok' => t('inst.components_ok'),
    'components_fail' => t('inst.components_fail'), 'components_retry' => t('inst.components_retry'),
    'step_cert' => t('inst.step_cert'), 'cert_desc' => t('inst.cert_desc'), 'cert_domain' => t('inst.cert_domain'), 'cert_email' => t('inst.cert_email'),
    'cert_skip' => t('inst.cert_skip'), 'cert_issue' => t('inst.cert_issue'), 'cert_running' => t('inst.cert_running'), 'cert_ok' => t('inst.cert_ok'),
    'cert_fail' => t('inst.cert_fail'), 'cert_bad_domain' => t('inst.cert_bad_domain'),
    'step_reality' => t('inst.step_reality'), 'reality_desc' => t('inst.reality_desc'), 'sni' => t('inst.sni'), 'detected' => t('inst.detected'),
    'step_admin' => t('inst.step_admin'), 'admin_desc' => t('inst.admin_desc'), 'login' => t('inst.login'),
    'password' => t('inst.password'), 'password2' => t('inst.password2'), 'pw_mismatch' => t('inst.pw_mismatch'), 'pw_short' => t('inst.pw_short'),
    'install_btn' => t('inst.install_btn'), 'back' => t('inst.back'), 'next' => t('inst.next'), 'start' => t('inst.start'),
    'progress_title' => t('inst.progress_title'), 'p_restore' => t('inst.p_restore'), 'p_configure' => t('inst.p_configure'),
    'p_admin' => t('inst.p_admin'), 'done_title' => t('inst.done_title'), 'done_desc' => t('inst.done_desc'), 'to_login' => t('inst.to_login'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  var state = { step: 0, mode: 'fresh', amnezia: true, domain: '', email: '', sni: '', backup: null, user: '', pass: '', pass2: '', done: {} };
  var STEPS = ['welcome', 'preflight', 'mode', 'components', 'cert', 'reality', 'admin', 'progress'];
  // Компоненты (sing-box и т.п.) и TLS нужны в обоих режимах; Reality — только при установке с нуля.
  function visibleSteps() { return state.mode === 'restore' ? ['welcome','preflight','mode','components','cert','admin','progress'] : STEPS; }

  // --- Сохранение прогресса мастера -------------------------------------
  // Чтобы перезагрузка страницы И смена языка (она делает полный переход на
  // ?lang=…) не сбрасывали мастер на первый шаг и не заставляли проходить уже
  // выполненные шаги заново. Пароль и файл бэкапа НЕ сохраняем. Ключ привязан
  // к токену установки, чтобы прогресс чужой установки не подхватился.
  var SKEY = 'vpsr_install_' + (TOKEN || 'notoken');
  var SAVE_KEYS = ['step','mode','amnezia','domain','email','sni','user','done'];
  function saveState() {
    try { var s = {}; SAVE_KEYS.forEach(function (k) { s[k] = state[k]; }); localStorage.setItem(SKEY, JSON.stringify(s)); } catch (e) {}
  }
  function loadState() {
    try {
      var raw = localStorage.getItem(SKEY); if (!raw) return;
      var s = JSON.parse(raw); if (!s || typeof s !== 'object') return;
      SAVE_KEYS.forEach(function (k) { if (s[k] !== undefined) state[k] = s[k]; });
      if (!state.done || typeof state.done !== 'object') state.done = {};
    } catch (e) {}
  }
  function clearState() { try { localStorage.removeItem(SKEY); } catch (e) {} }

  var body = document.getElementById('wz-body');
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  function dots() {
    var vs = visibleSteps();
    document.getElementById('wz-dots').innerHTML = vs.map(function (_, i) {
      return '<div class="dot ' + (i <= state.step ? 'on' : '') + '"></div>';
    }).join('');
  }
  function go(d) { state.step += d; if (state.step < 0) state.step = 0; saveState(); render(); }

  function render() {
    dots();
    var key = visibleSteps()[state.step];
    if (key === 'welcome') return renderWelcome();
    if (key === 'preflight') return renderPreflight();
    if (key === 'mode') return renderMode();
    if (key === 'components') return renderComponents();
    if (key === 'cert') return renderCert();
    if (key === 'reality') return renderReality();
    if (key === 'admin') return renderAdmin();
    if (key === 'progress') return renderProgress();
  }
  function nav(backable, nextLabel, onNext) {
    return '<div class="actions"><div>' + (backable ? '<button class="secondary" id="wz-back">' + esc(L.back) + '</button>' : '') + '</div>' +
      '<button id="wz-next">' + esc(nextLabel) + '</button></div>';
  }
  function bindNav(onNext) {
    var b = document.getElementById('wz-back'); if (b) b.onclick = function () { go(-1); };
    document.getElementById('wz-next').onclick = onNext;
  }

  function renderWelcome() {
    body.innerHTML = '<h2>' + esc(L.welcome) + '</h2><p class="muted">' + esc(L.welcome_desc) + '</p>' + nav(false, L.next);
    bindNav(function () { go(1); });
  }
  function renderPreflight() {
    body.innerHTML = '<h2>' + esc(L.pf_title) + '</h2><p class="muted">' + esc(L.pf_desc) + '</p>' +
      '<div class="verdict" id="pf-verdict" style="display:none"></div>' +
      '<div class="term" id="pf-term"></div>' + nav(true, L.pf_next);
    var termEl = document.getElementById('pf-term');
    var nextBtn = document.getElementById('wz-next');
    nextBtn.disabled = true;
    var acc = '';
    function append(t){ termEl.textContent += t + '\n'; termEl.scrollTop = termEl.scrollHeight; }
    function allowSkip(){ nextBtn.disabled = false; }
    function fail(){ append(L.pf_error); allowSkip(); }
    append(L.pf_running);

    var es;
    try { es = new EventSource('/api/install-run.php?action=preflight&t=' + encodeURIComponent(TOKEN)); }
    catch (e) { fail(); bindNav(function(){ go(1); }); return; }

    es.onmessage = function (ev) { acc += ev.data + '\n'; append(ev.data); };
    es.addEventListener('done', function () {
      es.close();
      var v = document.getElementById('pf-verdict');
      var cls = 'green', msg = L.pf_v_green;
      if (/RED/.test(acc)) { cls = 'red'; msg = L.pf_v_red; }
      else if (/YELLOW/.test(acc)) { cls = 'yellow'; msg = L.pf_v_yellow; }
      v.className = 'verdict ' + cls; v.textContent = msg; v.style.display = 'block';
      if (cls !== 'green') { addSharePlan(v); }
      allowSkip();
    });
    es.addEventListener('timeout', function () { es.close(); fail(); });
    es.onerror = function () { es.close(); fail(); };

    // Кнопка «показать план разделения 443» (dry-run, безопасно — ничего не меняет).
    function addSharePlan(afterEl) {
      var btn = document.createElement('button');
      btn.className = 'secondary'; btn.type = 'button'; btn.textContent = L.pf_share_btn;
      btn.style.margin = '4px 0 0';
      afterEl.insertAdjacentElement('afterend', btn);
      btn.onclick = function () {
        btn.disabled = true;
        var box = document.createElement('div'); box.className = 'term'; box.style.marginTop = '8px';
        btn.insertAdjacentElement('afterend', box);
        var ses;
        try { ses = new EventSource('/api/install-run.php?action=share443&t=' + encodeURIComponent(TOKEN)); }
        catch (e) { box.textContent = L.pf_error; return; }
        ses.onmessage = function (ev) { box.textContent += ev.data + '\n'; box.scrollTop = box.scrollHeight; };
        ses.addEventListener('done', function () { ses.close(); });
        ses.addEventListener('timeout', function () { ses.close(); });
        ses.onerror = function () { ses.close(); };
      };
    }

    bindNav(function () { if (es) es.close(); go(1); });
  }

  function renderMode() {
    body.innerHTML = '<h2>' + esc(L.step_mode) + '</h2>' +
      '<div class="opt ' + (state.mode==='fresh'?'sel':'') + '" data-m="fresh"><b>' + esc(L.mode_fresh) + '</b><span>' + esc(L.mode_fresh_desc) + '</span></div>' +
      '<div class="opt ' + (state.mode==='restore'?'sel':'') + '" data-m="restore"><b>' + esc(L.mode_restore) + '</b><span>' + esc(L.mode_restore_desc) + '</span></div>' +
      '<div id="restore-file" ' + (state.mode==='restore'?'':'style="display:none"') + '><label>' + esc(L.backup_file) + '</label><input type="file" id="wz-backup" accept=".json,application/json"></div>' +
      nav(true, L.next);
    body.querySelectorAll('[data-m]').forEach(function (el) {
      el.onclick = function () { state.mode = el.dataset.m; saveState(); render(); };
    });
    bindNav(function () {
      if (state.mode === 'restore') {
        var f = document.getElementById('wz-backup');
        if (!f.files.length) { showErr(L.backup_file); return; }
        state.backup = f.files[0];
      }
      go(1);
    });
  }
  // Общий помощник: живой лог привилегированного шага через SSE.
  // onDone(code) вызывается по событию done/timeout/error (code!=0 при неуспехе).
  function streamStep(action, termEl, onDone) {
    var es, finished = false;
    function finish(code) { if (finished) return; finished = true; try { es && es.close(); } catch (e) {} onDone(code); }
    function append(t) { termEl.textContent += t + '\n'; termEl.scrollTop = termEl.scrollHeight; }
    try { es = new EventSource('/api/install-run.php?action=' + action + '&t=' + encodeURIComponent(TOKEN)); }
    catch (e) { append(String(e)); finish(1); return; }
    es.onmessage = function (ev) { append(ev.data); };
    es.addEventListener('done', function (ev) { finish(parseInt(ev.data, 10) || 0); });
    es.addEventListener('timeout', function () { finish(1); });
    es.addEventListener('error', function () { finish(1); });
    es.onerror = function () { finish(1); };
  }

  function renderComponents() {
    body.innerHTML = '<h2>' + esc(L.step_components) + '</h2><p class="muted">' + esc(L.components_desc) + '</p>' +
      '<label class="row" style="cursor:pointer"><input type="checkbox" id="wz-amnezia" style="width:auto;margin-right:8px"' + (state.amnezia ? ' checked' : '') + '>' + esc(L.components_amnezia) + '</label>' +
      '<div class="verdict" id="cmp-verdict" style="display:none"></div>' +
      '<div class="term" id="cmp-term" style="display:none"></div>' +
      '<div style="margin-top:12px"><button id="cmp-run" type="button">' + esc(L.components_run) + '</button></div>' +
      nav(true, L.next);
    var nextBtn = document.getElementById('wz-next');
    nextBtn.disabled = true; // разрешаем «Далее» только после попытки установки
    var runBtn = document.getElementById('cmp-run');
    var termEl = document.getElementById('cmp-term');
    var amzEl = document.getElementById('wz-amnezia');

    runBtn.onclick = async function () {
      runBtn.disabled = true; amzEl.disabled = true;
      state.amnezia = amzEl.checked;
      termEl.style.display = 'block'; termEl.textContent = L.components_running + '\n';
      try {
        var fd = new FormData(); fd.append('amnezia', state.amnezia ? '1' : '0');
        await call('prep_components', fd);
      } catch (e) { termEl.textContent += String(e.message || e) + '\n'; }
      streamStep('components', termEl, function (code) {
        var v = document.getElementById('cmp-verdict');
        v.className = 'verdict ' + (code === 0 ? 'green' : 'yellow');
        v.textContent = code === 0 ? L.components_ok : L.components_fail;
        v.style.display = 'block';
        nextBtn.disabled = false; // даже при ошибке даём продолжить/повторить
        runBtn.disabled = false; amzEl.disabled = false;
        runBtn.textContent = L.components_retry;
        if (code === 0) { state.done.components = true; saveState(); }
      });
    };
    // Уже устанавливали в этой сессии мастера (перезагрузка / смена языка) —
    // показываем шаг выполненным и не заставляем ставить компоненты заново.
    if (state.done && state.done.components) {
      var vd = document.getElementById('cmp-verdict');
      vd.className = 'verdict green'; vd.textContent = L.components_ok; vd.style.display = 'block';
      nextBtn.disabled = false;
      runBtn.textContent = L.components_retry;
    }
    bindNav(function () { go(1); });
  }

  function renderCert() {
    body.innerHTML = '<h2>' + esc(L.step_cert) + '</h2><p class="muted">' + esc(L.cert_desc) + '</p>' +
      '<label>' + esc(L.cert_domain) + '</label><input id="wz-domain" placeholder="panel.example.com" value="' + esc(state.domain) + '">' +
      '<label>' + esc(L.cert_email) + '</label><input id="wz-email" type="email" placeholder="you@example.com" value="' + esc(state.email) + '">' +
      '<div class="verdict" id="crt-verdict" style="display:none"></div>' +
      '<div class="term" id="crt-term" style="display:none"></div>' +
      '<div style="margin-top:12px"><button id="crt-run" type="button">' + esc(L.cert_issue) + '</button></div>' +
      nav(true, L.cert_skip);
    var runBtn = document.getElementById('crt-run');
    var termEl = document.getElementById('crt-term');
    var domEl = document.getElementById('wz-domain');

    // Сертификат невозможен без домена, поэтому кнопка выпуска активна только
    // когда домен введён. Пустой домен — шаг необязателен, проходим «Пропустить».
    var emailEl = document.getElementById('wz-email');
    var domainOk = function (d) { return /^[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z0-9.-]+$/.test(d); };
    var syncRunBtn = function () { runBtn.disabled = !domainOk(domEl.value.trim()); };
    // Сохраняем ввод, чтобы смена языка / перезагрузка его не теряли.
    domEl.oninput = function () { state.domain = domEl.value.trim(); syncRunBtn(); saveState(); };
    emailEl.oninput = function () { state.email = emailEl.value.trim(); saveState(); };
    syncRunBtn();

    runBtn.onclick = async function () {
      state.domain = document.getElementById('wz-domain').value.trim();
      state.email = document.getElementById('wz-email').value.trim();
      if (!domainOk(state.domain)) { showErr(L.cert_bad_domain); return; }
      runBtn.disabled = true;
      termEl.style.display = 'block'; termEl.textContent = L.cert_running + '\n';
      try {
        var fd = new FormData(); fd.append('domain', state.domain); fd.append('email', state.email);
        await call('prep_cert', fd);
      } catch (e) { termEl.textContent += String(e.message || e) + '\n'; runBtn.disabled = false; return; }
      streamStep('cert', termEl, function (code) {
        var v = document.getElementById('crt-verdict');
        v.className = 'verdict ' + (code === 0 ? 'green' : 'yellow');
        v.textContent = code === 0 ? L.cert_ok : L.cert_fail;
        v.style.display = 'block';
        runBtn.disabled = false;
        // Кнопка внизу после выпуска — «Далее», а не «Пропустить».
        document.getElementById('wz-next').textContent = L.next;
        if (code === 0) { state.done.cert = true; saveState(); }
      });
    };
    // «Пропустить»/«Далее» — просто переходим дальше (домен не обязателен).
    bindNav(function () { go(1); });
  }

  function renderReality() {
    body.innerHTML = '<h2>' + esc(L.step_reality) + '</h2><p class="muted">' + esc(L.reality_desc) + '</p>' +
      (DETECTED_IP ? '<p class="muted">' + esc(L.detected) + ' <b>' + esc(DETECTED_IP) + '</b></p>' : '') +
      '<label>' + esc(L.sni) + '</label><input id="wz-sni" placeholder="www.microsoft.com" value="' + esc(state.sni) + '">' +
      nav(true, L.next);
    bindNav(function () { state.sni = document.getElementById('wz-sni').value.trim(); go(1); });
  }
  function renderAdmin() {
    body.innerHTML = '<h2>' + esc(L.step_admin) + '</h2><p class="muted">' + esc(L.admin_desc) + '</p>' +
      '<label>' + esc(L.login) + '</label><input id="wz-user" autocomplete="username" value="' + esc(state.user) + '">' +
      '<label>' + esc(L.password) + '</label><input type="password" id="wz-pass" autocomplete="new-password">' +
      '<label>' + esc(L.password2) + '</label><input type="password" id="wz-pass2" autocomplete="new-password">' +
      nav(true, L.start);
    bindNav(function () {
      state.user = document.getElementById('wz-user').value.trim();
      state.pass = document.getElementById('wz-pass').value;
      state.pass2 = document.getElementById('wz-pass2').value;
      if (state.pass.length < 10) { showErr(L.pw_short); return; }
      if (state.pass !== state.pass2) { showErr(L.pw_mismatch); return; }
      go(1);
    });
  }

  function showErr(msg) {
    var e = document.createElement('div'); e.className = 'err'; e.textContent = msg;
    body.insertBefore(e, body.firstChild); setTimeout(function(){ e.remove(); }, 5000);
  }

  async function call(action, formData) {
    formData.append('csrf_token', CSRF);
    var r = await fetch('/api/install.php?action=' + action, { method: 'POST', body: formData });
    var data = await r.json();
    if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
    return data;
  }

  async function renderProgress() {
    var steps = [];
    if (state.mode === 'restore') steps.push({ id: 'restore', label: L.p_restore });
    else steps.push({ id: 'configure', label: L.p_configure });
    steps.push({ id: 'admin', label: L.p_admin });

    body.innerHTML = '<h2>' + esc(L.progress_title) + '</h2><ul class="plog" id="plog">' +
      steps.map(function (s) { return '<li class="pending" data-id="' + s.id + '"><span class="st">○</span>' + esc(s.label) + '</li>'; }).join('') +
      '</ul>';
    document.getElementById('wz-dots').querySelectorAll('.dot').forEach(function(d){ d.classList.add('on'); });

    function mark(id, cls, icon) { var li = body.querySelector('[data-id="'+id+'"]'); if(!li)return; li.className='plog-'+cls+' '+cls; li.querySelector('.st').textContent=icon; }
    try {
      for (var i = 0; i < steps.length; i++) {
        var s = steps[i];
        mark(s.id, 'run', '⟳');
        if (s.id === 'restore') { var fd = new FormData(); fd.append('backup', state.backup); await call('import', fd); }
        else if (s.id === 'configure') { var fd2 = new FormData(); fd2.append('sni', state.sni); await call('configure', fd2); }
        else if (s.id === 'admin') { var fd3 = new FormData(); fd3.append('username', state.user); fd3.append('password', state.pass); await call('create_admin', fd3); }
        mark(s.id, 'ok', '✓');
      }
      clearState(); // установка завершена — убираем сохранённый прогресс мастера
      body.innerHTML += '<div style="text-align:center;margin-top:22px"><div class="done-check">✓</div><h2>' + esc(L.done_title) + '</h2>' +
        '<p class="muted">' + esc(L.done_desc) + '</p><button onclick="location.href=\'/login.php\'">' + esc(L.to_login) + '</button></div>';
    } catch (e) {
      showErr(e.message);
      body.innerHTML += '<div class="actions"><button class="secondary" id="wz-retry">' + esc(L.back) + '</button></div>';
      document.getElementById('wz-retry').onclick = function () { state.step = visibleSteps().indexOf('admin'); render(); };
    }
  }

  // Восстанавливаем прогресс (после перезагрузки или смены языка).
  loadState();
  (function clampResume() {
    var vs = visibleSteps();
    if (state.step < 0) state.step = 0;
    if (state.step > vs.length - 1) state.step = vs.length - 1;
    // Не возобновляемся прямо на финальном шаге (он сам запускает создание
    // админа) — откатываемся на «админ», чтобы установка стартовала по кнопке.
    if (vs[state.step] === 'progress') state.step = vs.indexOf('admin');
  })();
  render();
})();
</script>
</body>
</html>
