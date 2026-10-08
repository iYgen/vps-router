/**
 * Мастер «Добавить сервер» — fullscreen onboarding-экран (не маленький
 * modal). Раскладка: левая колонка — бренд + нумерованные шаги, центр —
 * текущий шаг, низ — Назад/Далее. Полностью отдельный оверлей поверх
 * dashboard.php (НЕ через общий #modal-overlay — тому нужен компактный
 * центрированный modal, а не полноэкранный wizard).
 *
 * Переиспользует существующий API панели (window.Panel из
 * infrastructure.js) — здесь только презентационный слой, никакой новой
 * бизнес-логики.
 */
(function () {
  'use strict';

  // Текст ролей/протоколов/шагов берётся из i18n (ключи js.wz.*) в момент
  // отрисовки — см. roleLabel()/protoLabel()/STEPS ниже. Здесь только данные.
  const ROLE_CARDS = [
    { value: 'exit', icon: 'door' },
    { value: 'router', icon: 'compass' },
    { value: 'proxy', icon: 'shuffle' },
    { value: 'gateway', icon: 'globe' },
    { value: 'vpn', icon: 'lock' },
    { value: 'generic', icon: 'plus' },
  ];

  const PROTOCOL_CARDS = [
    { value: 'vless', badge: 'recommended' },
    { value: 'amneziawg' },
    { value: 'wireguard', warn: true },
    { value: 'shadowsocks' },
    { value: 'hysteria2' },
    { value: 'tuic' },
    { value: 'trojan' },
  ];
  const NEEDS_DOMAIN = ['hysteria2', 'tuic', 'trojan'];

  const STEPS = [
    { key: 'general', num: '01' },
    { key: 'access', num: '02' },
    { key: 'protocol', num: '03' },
    { key: 'review', num: '04' },
    { key: 'install', num: '05' },
    { key: 'routes', num: '06' },
    { key: 'success', num: '07' },
  ];
  // Переводы (window.T определён в View.php до загрузки этого скрипта).
  function T() { return window.T ? window.T.apply(null, arguments) : arguments[0]; }
  function roleLabel(v) { return T('js.wz.role_' + v); }
  function roleDesc(v) { return T('js.wz.role_' + v + '_d'); }
  function protoLabel(v) { return T('js.wz.proto_' + v); }
  function protoDesc(v) { return T('js.wz.proto_' + v + '_d'); }
  function stepTitle(k) { return T('js.wz.step_' + k); }
  function stepSub(k) { return T('js.wz.step_' + k + '_sub'); }

  const SVG_ICONS = {
    door: '<path d="M5 21V5a1 1 0 0 1 1-1h6l6 3v14"/><path d="M12 21V4"/><circle cx="9" cy="12" r="1"/>',
    compass: '<circle cx="12" cy="12" r="9"/><path d="M15 9l-2 6-6 2 2-6z"/>',
    shuffle: '<path d="M3 6h3l9 12h4"/><path d="M14 6h4l3 3-3 3"/><path d="M3 18h3l3-4"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18Z"/>',
    lock: '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    check: '<path d="M4 12l5 5L20 6"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    arrowLeft: '<path d="M19 12H5M11 18l-6-6 6-6"/>',
    arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    spinner: '<path d="M12 3v3M12 18v3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M3 12h3M18 12h3M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
  };
  function svg(name, size) {
    size = size || 18;
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${SVG_ICONS[name] || ''}</svg>`;
  }

  let overlay = null;
  let stepIndex = 0;
  let state = null;

  function P() {
    if (!window.Panel) throw new Error('Мастер требует загруженный infrastructure.js (window.Panel не найден)');
    return window.Panel;
  }
  function esc(str) { return P().escapeHtml(str); }

  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'wizard-overlay';
    document.body.appendChild(overlay);
    return overlay;
  }

  function openAddServerWizard() {
    state = {
      server: { name: '', role: 'exit', host: '', ssh_port: 22, ssh_user: 'root', ssh_private_key: '', ssh_password: '' },
      authMode: 'password',
      sshReady: false,
      protocol: 'vless',
      camouflage: { domain: '', preset: '' },
      domain: '',
      testResult: null,
      serverId: null,
      connectionId: null,
      exitServerId: null,
      installResult: null,
      selectedRouteIds: [],
      newDestinations: '',
    };
    stepIndex = 0;
    const el = ensureOverlay();
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
    render();
  }

  function closeWizard() {
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  function goTo(key) {
    const idx = STEPS.findIndex((s) => s.key === key);
    if (idx >= 0) { stepIndex = idx; render(); }
  }
  function next() { if (stepIndex < STEPS.length - 1) { stepIndex++; render(); } }
  function back() { if (stepIndex > 0) { stepIndex--; render(); } }

  function shellChrome() {
    const step = STEPS[stepIndex];
    const stepsNav = STEPS.map((s, i) => {
      const done = i < stepIndex;
      const active = i === stepIndex;
      return `
        <div class="wizard-step-item ${active ? 'active' : ''} ${done ? 'done' : ''}" data-step-nav="${s.key}">
          <div class="wizard-step-num">${done ? svg('check', 13) : s.num}</div>
          <div>
            <div class="wizard-step-title">${esc(stepTitle(s.key))}</div>
            <div class="wizard-step-sub">${esc(stepSub(s.key))}</div>
          </div>
        </div>`;
    }).join('');

    return `
      <div class="wizard-header">
        <div class="wizard-brand"><span class="wizard-brand-mark">VR</span> VPS Router <span class="muted" style="font-weight:400">${esc(T('js.wz.brand_suffix'))}</span></div>
        <div class="row" style="gap:16px">
          <span class="muted">${stepIndex + 1} / ${STEPS.length}</span>
          <button class="icon-btn ghost" id="wz-close" title="${esc(T('js.wz.close'))}">${svg('x', 18)}</button>
        </div>
      </div>
      <div class="wizard-body">
        <nav class="wizard-nav">${stepsNav}</nav>
        <div class="wizard-content"><div class="wizard-content-inner" id="wz-step-content"></div></div>
      </div>
      <div class="wizard-footer" id="wz-footer"></div>
    `;
  }

  function render() {
    const el = ensureOverlay();
    el.innerHTML = shellChrome();
    el.querySelector('#wz-close').addEventListener('click', closeWizard);
    el.querySelectorAll('[data-step-nav]').forEach((nav) => {
      nav.addEventListener('click', () => {
        const targetIdx = STEPS.findIndex((s) => s.key === nav.dataset.stepNav);
        if (targetIdx <= stepIndex) goTo(nav.dataset.stepNav); // назад — свободно, вперёд — только уже пройденное
      });
    });
    renderStepBody();
  }

  function stepHeading(title, desc) {
    return `<h2 style="font-size:22px;margin-bottom:6px">${esc(title)}</h2><p class="muted" style="margin-bottom:28px;font-size:13.5px">${desc}</p>`;
  }

  function footer(backLabel, nextLabel, opts) {
    opts = opts || {};
    const el = document.getElementById('wz-footer');
    el.innerHTML = `
      <div>${stepIndex > 0 && !opts.hideBack ? `<button class="secondary" id="wz-back">${svg('arrowLeft', 15)} ${esc(backLabel || T('js.wz.back'))}</button>` : ''}</div>
      <div>${nextLabel ? `<button id="wz-next" ${opts.nextDisabled ? 'disabled' : ''}>${esc(nextLabel)} ${opts.noArrow ? '' : svg('arrowRight', 15)}</button>` : ''}</div>
    `;
    if (!opts.hideBack && stepIndex > 0) el.querySelector('#wz-back').addEventListener('click', back);
    if (nextLabel) el.querySelector('#wz-next').addEventListener('click', opts.onNext || next);
  }

  function renderStepBody() {
    const key = STEPS[stepIndex].key;
    ({ general: renderGeneral, access: renderAccess, protocol: renderProtocol, review: renderReview, install: renderInstall, routes: renderRoutes, success: renderSuccess })[key]();
  }

  // ---------------------------------------------------------------- 01 --
  function renderGeneral() {
    const content = document.getElementById('wz-step-content');
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_general'), T('js.wz.g_head_desc'))}
      <div class="field-row"><label>${esc(T('js.wz.g_name_label'))}</label><input id="f-name" placeholder="${esc(T('js.wz.g_name_ph'))}" value="${esc(state.server.name)}"></div>
      <div class="field-row" style="margin-top:22px"><label>${esc(T('js.wz.g_role_label'))}</label>
        <div class="wizard-role-grid">
          ${ROLE_CARDS.map((r) => `
            <div class="wizard-role-card ${state.server.role === r.value ? 'selected' : ''}" data-role="${r.value}">
              <div class="wizard-role-icon">${svg(r.icon, 18)}</div>
              <div class="wizard-role-label">${esc(roleLabel(r.value))}</div>
              <div class="wizard-role-desc">${esc(roleDesc(r.value))}</div>
            </div>
          `).join('')}
        </div>
        <div class="wizard-role-card" data-free-exit style="margin-top:10px;border-color:var(--warning,#f5b942);cursor:pointer">
          <div class="wizard-role-label">${esc(T('js.wz.free_label'))}</div>
          <div class="wizard-role-desc">${T('js.wz.free_desc')}</div>
        </div>
      </div>
    `;
    content.querySelectorAll('[data-role]').forEach((card) => {
      card.addEventListener('click', () => {
        state.server.role = card.dataset.role;
        content.querySelectorAll('[data-role]').forEach((c) => c.classList.toggle('selected', c === card));
      });
    });
    const freeCard = content.querySelector('[data-free-exit]');
    if (freeCard) {
      freeCard.addEventListener('click', () => {
        closeWizard();
        if (typeof window.openFreeExitModal === 'function') {
          window.openFreeExitModal();
        } else {
          location.href = '/servers.php';
        }
      });
    }
    footer(null, T('js.wz.next'), { hideBack: true, onNext: () => {
      const name = document.getElementById('f-name').value.trim();
      if (!name) { P().toast(T('js.wz.err_name'), 'error'); return; }
      state.server.name = name;
      next();
    } });
  }

  // ---------------------------------------------------------------- 02 --
  function renderAccess() {
    const content = document.getElementById('wz-step-content');
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_access'), T('js.wz.a_head_desc', esc(state.server.name)))}
      <div class="row" style="align-items:flex-start">
        <div class="field-row" style="flex:2"><label>${esc(T('js.wz.a_host_label'))}</label><input id="f-host" placeholder="195.0.113.10" value="${esc(state.server.host)}"></div>
        <div class="field-row" style="flex:1"><label>${esc(T('js.wz.a_port_label'))}</label><input id="f-port" type="number" value="${state.server.ssh_port}"></div>
      </div>
      <div class="field-row"><label>${esc(T('js.wz.a_user_label'))}</label><input id="f-user" value="${esc(state.server.ssh_user)}"></div>
      <div class="field-row"><label>${esc(T('js.wz.a_authmode_label'))}</label>
        <div class="wizard-protocol-list" style="grid-template-columns:1fr 1fr">
          <div class="wizard-protocol-card ${state.authMode === 'password' ? 'selected' : ''}" data-auth-mode="password">
            <div class="row" style="justify-content:space-between"><strong>${esc(T('js.wz.a_pw_title'))}</strong><span class="badge ok">${esc(T('js.wz.a_pw_badge'))}</span></div>
            <div class="muted" style="font-size:12.5px;margin-top:4px">${esc(T('js.wz.a_pw_desc'))}</div>
          </div>
          <div class="wizard-protocol-card ${state.authMode === 'key' ? 'selected' : ''}" data-auth-mode="key">
            <div class="row" style="justify-content:space-between"><strong>${esc(T('js.wz.a_key_title'))}</strong></div>
            <div class="muted" style="font-size:12.5px;margin-top:4px">${esc(T('js.wz.a_key_desc'))}</div>
          </div>
        </div>
      </div>
      <div class="field-row" data-auth="password"><label>${esc(T('js.wz.a_pw_field'))}</label>
        <input id="f-password" type="password" autocomplete="off" placeholder="${esc(T('js.wz.a_pw_ph'))}" value="${esc(state.server.ssh_password)}">
      </div>
      <div class="field-row" data-auth="key"><label>${esc(T('js.wz.a_key_field'))}</label>
        <textarea id="f-key" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" style="min-height:110px;font-family:ui-monospace,monospace;font-size:12px">${esc(state.server.ssh_private_key)}</textarea>
      </div>
      <div class="row" style="margin-top:4px">
        <button class="secondary" id="f-test" type="button">${esc(T('js.wz.a_test_btn'))}</button>
        <span id="f-test-result"></span>
      </div>
      <div id="f-test-diagnosis"></div>
    `;
    const syncAuth = () => {
      content.querySelectorAll('[data-auth-mode]').forEach((c) => c.classList.toggle('selected', c.dataset.authMode === state.authMode));
      content.querySelectorAll('[data-auth]').forEach((el) => { el.style.display = el.dataset.auth === state.authMode ? '' : 'none'; });
    };
    content.querySelectorAll('[data-auth-mode]').forEach((card) => {
      card.addEventListener('click', () => { state.authMode = card.dataset.authMode; syncAuth(); });
    });
    syncAuth();
    content.querySelector('#f-test').addEventListener('click', async () => {
      const btn = content.querySelector('#f-test');
      const resultEl = document.getElementById('f-test-result');
      const key = document.getElementById('f-key').value.trim();
      const password = document.getElementById('f-password').value;
      if (state.authMode === 'key' && !key) { resultEl.innerHTML = `<span class="muted">${esc(T('js.wz.a_need_key'))}</span>`; return; }
      if (state.authMode === 'password' && !password) { resultEl.innerHTML = `<span class="muted">${esc(T('js.wz.a_need_pw'))}</span>`; return; }
      btn.disabled = true; btn.innerHTML = `${svg('spinner', 14)} ${esc(T('js.wz.a_connecting'))}`;
      const progress = P().showProgress(T('js.wz.a_prog_title'), 25, [
        T('js.wz.a_prog1'), T('js.wz.a_prog2'), T('js.wz.a_prog3'), T('js.wz.a_prog4'), T('js.wz.a_prog5'),
      ]);
      resultEl.innerHTML = '';
      document.getElementById('f-test-diagnosis').innerHTML = '';
      try {
        const panel = P();
        let r;
        if (state.authMode === 'password') {
          r = await panel.api.testPassword({
            host: document.getElementById('f-host').value.trim(),
            ssh_port: Number(document.getElementById('f-port').value || 22),
            ssh_user: document.getElementById('f-user').value || 'root',
            password,
          });
        } else {
          const tempId = await panel.api.createServer({
            name: state.server.name + ' ' + T('js.wz.a_check_suffix'), role: 'generic',
            host: document.getElementById('f-host').value,
            ssh_port: Number(document.getElementById('f-port').value || 22),
            ssh_user: document.getElementById('f-user').value || 'root',
            ssh_private_key: key, enabled: false,
          });
          r = await panel.api.testConnection(tempId.id);
          await panel.api.deleteServer(tempId.id);
        }
        if (r.ok) {
          resultEl.innerHTML = `<span style="color:var(--success)">${svg('check', 14)} ${esc(r.hostname || 'OK')} · ${esc(r.os || '')}</span>`;
        } else {
          resultEl.innerHTML = '';
          const diag = document.getElementById('f-test-diagnosis');
          diag.innerHTML = panel.renderSshDiagnosis(r);
          panel.wireUseSshPort(diag, (port) => {
            document.getElementById('f-port').value = port;
            diag.innerHTML = `<div class="flash success">${esc(T('js.wz.a_port_filled', port))}</div>`;
          });
        }
      } catch (err) {
        resultEl.innerHTML = `<span style="color:var(--danger)">${esc(err.message)}</span>`;
      } finally {
        progress.done();
        btn.disabled = false; btn.textContent = T('js.wz.a_test_btn');
      }
    });
    footer(T('js.wz.back'), T('js.wz.next'), { onNext: () => {
      const host = document.getElementById('f-host').value.trim();
      if (!host) { P().toast(T('js.wz.err_host'), 'error'); return; }
      state.server.host = host;
      state.server.ssh_port = Number(document.getElementById('f-port').value || 22);
      state.server.ssh_user = document.getElementById('f-user').value || 'root';
      state.server.ssh_private_key = state.authMode === 'key' ? document.getElementById('f-key').value : '';
      state.server.ssh_password = state.authMode === 'password' ? document.getElementById('f-password').value : '';
      next();
    } });
  }

  // ---------------------------------------------------------------- 03 --
  function renderProtocol() {
    const content = document.getElementById('wz-step-content');
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_protocol'), T('js.wz.p_head_desc', esc(state.server.name)))}
      <div class="wizard-protocol-list">
        ${PROTOCOL_CARDS.map((p) => `
          <div class="wizard-protocol-card ${state.protocol === p.value ? 'selected' : ''}" data-protocol="${p.value}">
            <div class="row" style="justify-content:space-between">
              <strong>${esc(protoLabel(p.value))}</strong>
              ${p.badge ? `<span class="badge ok">${esc(T('js.wz.badge_recommended'))}</span>` : ''}
              ${p.warn ? `<span class="badge warn">${esc(T('js.wz.badge_block_risk'))}</span>` : ''}
            </div>
            <div class="muted" style="font-size:12.5px;margin-top:4px">${esc(protoDesc(p.value))}</div>
          </div>
        `).join('')}
      </div>
      <div id="wz-domain-field" style="margin-top:16px"></div>
    `;
    function renderDomainField() {
      const box = document.getElementById('wz-domain-field');
      if (NEEDS_DOMAIN.includes(state.protocol)) {
        box.innerHTML = `<div class="field-row"><label>${esc(T('js.wz.p_domain_label', esc(state.server.host)))}</label><input id="f-domain" placeholder="exit.example.com" value="${esc(state.domain)}"></div>`;
      } else if (state.protocol === 'vless') {
        box.innerHTML = `
          <div class="field-row"><label>${esc(T('js.wz.p_camo_label'))}</label>
            <input id="f-camo-domain" placeholder="${esc(T('js.wz.p_camo_ph'))}" value="${esc(state.camouflage.domain)}"></div>
          <div class="field-row"><label>${esc(T('js.wz.p_cover_label'))} <span class="muted">${esc(T('js.wz.p_cover_optional'))}</span></label>
            <select id="f-camo-preset">
              <option value="">${esc(T('js.wz.p_cover_none'))}</option>
              <option value="updates">${esc(T('js.wz.p_cover_updates'))}</option>
              <option value="status">${esc(T('js.wz.p_cover_status'))}</option>
              <option value="maintenance">${esc(T('js.wz.p_cover_maintenance'))}</option>
            </select>
            <p class="muted" style="margin-top:6px;font-size:12px">${esc(T('js.wz.p_cover_hint'))}</p>
          </div>`;
        const sel = document.getElementById('f-camo-preset');
        if (state.camouflage.preset) sel.value = state.camouflage.preset;
      } else {
        box.innerHTML = '';
      }
    }
    renderDomainField();
    content.querySelectorAll('[data-protocol]').forEach((card) => {
      card.addEventListener('click', () => {
        state.protocol = card.dataset.protocol;
        content.querySelectorAll('[data-protocol]').forEach((c) => c.classList.toggle('selected', c === card));
        renderDomainField();
      });
    });
    footer(T('js.wz.back'), T('js.wz.next'), { onNext: () => {
      if (NEEDS_DOMAIN.includes(state.protocol)) {
        const domain = document.getElementById('f-domain').value.trim();
        if (!domain) { P().toast(T('js.wz.err_domain'), 'error'); return; }
        state.domain = domain;
      }
      if (state.protocol === 'vless') {
        state.camouflage.domain = document.getElementById('f-camo-domain').value.trim();
        state.camouflage.preset = document.getElementById('f-camo-preset').value;
        if (state.camouflage.preset && !state.camouflage.domain) {
          P().toast(T('js.wz.err_cover_domain'), 'error'); return;
        }
      }
      next();
    } });
  }

  // ---------------------------------------------------------------- 04 --
  function renderReview() {
    const content = document.getElementById('wz-step-content');
    const sshSuffix = state.server.ssh_private_key ? T('js.wz.r_key_set')
      : state.server.ssh_password ? T('js.wz.r_by_pw') : T('js.wz.r_no_key');
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_review'), T('js.wz.r_head_desc'))}
      <div class="wizard-review-grid">
        <div><div class="muted">${esc(T('js.wz.r_server'))}</div><strong>${esc(state.server.name)}</strong></div>
        <div><div class="muted">${esc(T('js.wz.r_role'))}</div><strong>${esc(roleLabel(state.server.role))}</strong></div>
        <div><div class="muted">${esc(T('js.wz.r_host'))}</div><strong>${esc(state.server.host)}</strong></div>
        <div><div class="muted">${esc(T('js.wz.r_ssh'))}</div><strong>${esc(state.server.ssh_user)}@${esc(state.server.host)}:${state.server.ssh_port} ${esc(sshSuffix)}</strong></div>
        <div><div class="muted">${esc(T('js.wz.r_protocol'))}</div><strong>${esc(protoLabel(state.protocol))}</strong></div>
        ${state.domain ? `<div><div class="muted">${esc(T('js.wz.r_domain'))}</div><strong>${esc(state.domain)}</strong></div>` : ''}
        ${state.protocol === 'vless' && state.camouflage.domain ? `<div><div class="muted">${esc(T('js.wz.r_camo'))}</div><strong>${esc(state.camouflage.domain)}${state.camouflage.preset ? esc(T('js.wz.r_camo_cover')) : ''}</strong></div>` : ''}
      </div>
    `;
    footer(T('js.wz.back'), T('js.wz.r_create_btn'), { onNext: createServerAndConnection });
  }

  async function createServerAndConnection() {
    const btn = document.getElementById('wz-next');
    btn.disabled = true; btn.innerHTML = `${svg('spinner', 14)} ${esc(T('js.wz.c_creating'))}`;
    try {
      const panel = P();
      const target = await panel.api.createServer({
        name: state.server.name, role: state.server.role, host: state.server.host,
        ssh_port: state.server.ssh_port, ssh_user: state.server.ssh_user,
        ssh_private_key: state.server.ssh_private_key || undefined, enabled: true,
      });
      state.serverId = target.id;
      state.sshReady = !!state.server.ssh_private_key;

      if (state.server.ssh_password) {
        btn.innerHTML = `${svg('spinner', 14)} ${esc(T('js.wz.c_creating_key'))}`;
        const bootProgress = panel.showProgress(T('js.wz.c_boot_title'), 15, [
          T('js.wz.c_boot1'), T('js.wz.c_boot2'), T('js.wz.c_boot3'), T('js.wz.c_boot4'),
        ]);
        let boot;
        try { boot = await panel.api.bootstrapSsh(target.id, state.server.ssh_password); } finally { bootProgress.done(); }
        if (!boot.ok && boot.diagnosis) {
          await panel.api.deleteServer(target.id).catch(() => {});
          state.serverId = null;
          state.server.ssh_password = '';
          throw new Error(boot.raw_error + ' ' + T('js.wz.c_boot_fail_suffix'));
        }
        state.server.ssh_password = ''; // больше не нужен — дальше только ключ
        if (boot.ok) {
          state.sshReady = true;
          panel.toast(T('js.wz.c_key_done'), 'success');
        } else {
          panel.toast(T('js.wz.c_key_fail', boot.raw_error || T('js.wz.c_err_generic')), 'error');
        }
      }

      const selfServer = panel.getState().servers.find((s) => s.is_self);
      if (!selfServer) throw new Error(T('js.wz.c_no_self'));

      const body = { source_server_id: selfServer.id, target_server_id: target.id, type: state.protocol, label: null };
      if (state.protocol === 'amneziawg' || state.protocol === 'wireguard') {
        body.wireguard = { endpoint_host: state.server.host, endpoint_port: state.protocol === 'amneziawg' ? 51900 : 51820 };
      } else if (state.protocol === 'vless') {
        body.vless = { endpoint_host: state.server.host, endpoint_port: 443, transport: 'reality' };
      } else if (state.protocol === 'shadowsocks') {
        body.shadowsocks = { endpoint_host: state.server.host, endpoint_port: 8388 };
      } else if (NEEDS_DOMAIN.includes(state.protocol)) {
        body[state.protocol] = { endpoint_host: state.server.host, endpoint_port: 443, domain: state.domain };
      }

      const conn = await panel.api.createConnection(body);
      state.connectionId = conn.id;
      state.exitServerId = conn.exit_server_id;
      panel.toast(T('js.wz.c_created'), 'success');
      next();
    } catch (err) {
      P().toast(err.message, 'error');
      btn.disabled = false; btn.innerHTML = `${esc(T('js.wz.r_create_btn'))} ${svg('arrowRight', 15)}`;
    }
  }

  // ---------------------------------------------------------------- 05 --
  function renderInstall() {
    const content = document.getElementById('wz-step-content');
    const hasKey = state.sshReady;
    const sslPart = NEEDS_DOMAIN.includes(state.protocol) ? T('js.wz.i_ssl_cert') : '';
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_install'), T('js.wz.i_head_desc', esc(state.server.name), esc(state.server.host), esc(protoLabel(state.protocol)), esc(sslPart)))}
      ${!hasKey ? `<div class="flash error">${esc(T('js.wz.i_no_ssh'))}</div>` : ''}
      <div id="wz-install-box"></div>
    `;
    const box = document.getElementById('wz-install-box');
    if (hasKey) {
      box.innerHTML = `<button id="wz-install-btn" type="button" style="margin-top:8px">${esc(T('js.wz.i_install_btn'))}</button><div id="wz-install-result" style="margin-top:14px"></div>`;
      box.querySelector('#wz-install-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true; btn.innerHTML = `${svg('spinner', 14)} ${esc(T('js.wz.i_installing'))}`;
        const installProgress = P().showProgress(T('js.wz.i_prog_title'), 75, [
          T('js.wz.i_prog1'), T('js.wz.i_prog2'), T('js.wz.i_prog3'), T('js.wz.i_prog4'), T('js.wz.i_prog5'), T('js.wz.i_prog6'),
        ]);
        const resultEl = document.getElementById('wz-install-result');
        try {
          const result = await P().api.provisionConnection(state.connectionId);
          state.installResult = result;
          resultEl.innerHTML = `<div class="flash ${result.ok ? 'success' : 'error'}">${result.ok ? svg('check', 14) + ' ' + esc(T('js.wz.i_ok')) : svg('x', 14) + ' ' + esc(T('js.wz.i_fail'))}</div>`;
          // Маскировка Reality (сайт-прикрытие / свой домен), если задана на шаге «Протокол».
          if (result.ok && state.protocol === 'vless' && state.camouflage.domain) {
            installProgress.step(state.camouflage.preset ? T('js.wz.i_camo_cover') : T('js.wz.i_camo_domain'));
            try {
              const c = await P().api.setCamouflage(state.connectionId, { domain: state.camouflage.domain, preset: state.camouflage.preset, brand: state.server.name });
              resultEl.innerHTML += `<div class="flash ${c.ok ? 'success' : 'error'}">${esc(c.message || (c.ok ? T('js.wz.i_camo_ok') : T('js.wz.i_camo_fail')))}</div>`;
            } catch (ce) {
              resultEl.innerHTML += `<div class="flash error">${esc(T('js.wz.i_camo_prefix', ce.message))}</div>`;
            }
          }
        } catch (err) {
          resultEl.innerHTML = `<div class="flash error">${esc(err.message)}</div>`;
        } finally {
          installProgress.done();
          btn.disabled = false; btn.textContent = T('js.wz.i_install_btn');
        }
      });
    }
    footer(T('js.wz.back'), T('js.wz.next'));
  }

  // ---------------------------------------------------------------- 06 --
  function renderRoutes() {
    const content = document.getElementById('wz-step-content');
    const bulkCount = state.newDestinations.split('\n').map((s) => s.trim()).filter(Boolean).length;
    content.innerHTML = `
      ${stepHeading(T('js.wz.step_routes'), T('js.wz.ro_head_desc', esc(state.server.name)))}
      <div class="field-row">
        <label>${esc(T('js.wz.ro_existing_label'))}</label>
        <div class="row" style="align-items:center;gap:10px">
          <button type="button" class="secondary" id="wz-pick-routes">${esc(T('js.wz.ro_pick_btn'))}</button>
          <span class="muted" id="wz-routes-count">${state.selectedRouteIds.length ? esc(T('js.wz.ro_selected', state.selectedRouteIds.length)) : esc(T('js.wz.ro_none'))}</span>
        </div>
      </div>
      <details style="margin-top:16px" ${bulkCount ? 'open' : ''}>
        <summary style="cursor:pointer;font-weight:500">${esc(T('js.wz.ro_bulk_summary'))}</summary>
        <textarea id="f-destinations" style="min-height:120px;margin-top:8px" placeholder="youtube.com&#10;discord.com">${esc(state.newDestinations)}</textarea>
        <p class="muted" style="margin:4px 0 0">${esc(T('js.wz.ro_bulk_hint'))}</p>
      </details>
    `;
    content.querySelector('#wz-pick-routes').addEventListener('click', () => {
      window.RouteTreePicker.open({
        title: T('js.wz.ro_picker_title'),
        preselect: state.selectedRouteIds,
        confirmLabel: T('js.wz.ro_picker_confirm'),
        onConfirm: (ids) => {
          state.selectedRouteIds = ids;
          content.querySelector('#wz-routes-count').textContent = ids.length ? T('js.wz.ro_selected', ids.length) : T('js.wz.ro_none');
        },
      });
    });
    footer(T('js.wz.back'), T('js.wz.next'), { onNext: async () => {
      const panel = P();
      state.newDestinations = content.querySelector('#f-destinations').value;
      const destinations = state.newDestinations.split('\n').map((s) => s.trim()).filter(Boolean);
      if (!state.exitServerId) { next(); return; }
      try {
        // Привязываем выбранные существующие маршруты к этому exit-серверу.
        for (const routeId of state.selectedRouteIds) {
          await panel.api.updateRoute(routeId, { exit_server_id: state.exitServerId });
        }
        // И создаём новые адреса, если введены.
        if (destinations.length) {
          await panel.api.bulkRoutes({ destinations, type: 'domain_suffix', exit_server_id: state.exitServerId });
        }
        const total = state.selectedRouteIds.length + destinations.length;
        if (total) panel.toast(T('js.wz.ro_bound', total), 'success');
      } catch (err) {
        panel.toast(err.message, 'error');
      }
      next();
    } });
  }

  // ---------------------------------------------------------------- 07 --
  function renderSuccess() {
    const content = document.getElementById('wz-step-content');
    content.innerHTML = `
      <div style="text-align:center;padding:20px 0 8px">
        <div class="wizard-success-check">${svg('check', 30)}</div>
        <h2 style="font-size:22px;margin:18px 0 6px">${esc(T('js.wz.s_title'))}</h2>
        <p class="muted" style="max-width:420px;margin:0 auto 28px">${T('js.wz.s_desc', esc(state.server.name))}</p>
      </div>
    `;
    footer(null, null, { hideBack: true });
    const footerEl = document.getElementById('wz-footer');
    footerEl.innerHTML = `
      <div></div>
      <div class="row">
        <button class="secondary" id="wz-add-another">${esc(T('js.wz.s_add_another'))}</button>
        <button id="wz-finish">${esc(T('js.wz.s_finish'))}</button>
      </div>
    `;
    footerEl.querySelector('#wz-add-another').addEventListener('click', () => openAddServerWizard());
    footerEl.querySelector('#wz-finish').addEventListener('click', async () => {
      closeWizard();
      await P().loadGraph();
    });
  }

  window.PanelMasters = window.PanelMasters || {};
  window.PanelMasters.openAddServerWizard = openAddServerWizard;
})();
