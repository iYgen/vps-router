/* global cytoscape */
(function () {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  // ---------------------------------------------------------------- API --
  async function apiCall(url, method, body) {
    const opts = {
      method,
      headers: { 'X-CSRF-Token': csrfToken },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data = null;
    try { data = await res.json(); } catch (e) { /* empty body */ }
    if (!res.ok) {
      const message = (data && data.error) || `HTTP ${res.status}`;
      throw new Error(message);
    }
    return data;
  }

  const api = {
    infrastructure: () => apiCall('/api/infrastructure.php', 'GET'),
    validate: () => apiCall('/api/infrastructure.php?action=validate', 'POST', {}),
    preview: () => apiCall('/api/infrastructure.php?action=preview', 'POST', {}),
    apply: (description) => apiCall('/api/infrastructure.php?action=apply', 'POST', { description }),
    rollback: (versionId) => apiCall('/api/infrastructure.php?action=rollback', 'POST', { version_id: versionId }),

    createServer: (data) => apiCall('/api/servers.php', 'POST', data),
    updateServer: (id, data) => apiCall(`/api/servers.php?id=${id}`, 'PUT', data),
    deleteServer: (id) => apiCall(`/api/servers.php?id=${id}`, 'DELETE'),
    rebootSchedule: (id) => apiCall(`/api/reboot-schedule.php?server_id=${id}`, 'GET'),
    saveRebootSchedule: (data) => apiCall('/api/reboot-schedule.php', 'POST', data),
    testConnection: (id) => apiCall(`/api/servers.php?action=test-connection&id=${id}`, 'POST', {}),
    testPassword: (data) => apiCall('/api/servers.php?action=test-password', 'POST', data),
    bootstrapSsh: (id, password) => apiCall(`/api/servers.php?action=bootstrap-ssh&id=${id}`, 'POST', { password }),
    setPosition: (id, x, y) => apiCall(`/api/servers.php?action=position&id=${id}`, 'POST', { x, y }),
    provisionServer: (id) => apiCall(`/api/servers.php?action=provision&id=${id}`, 'POST', {}),
    riskScan: (id) => apiCall(`/api/servers.php?action=risk-scan&id=${id}`, 'POST', {}),
    diagnostics: (id, port) => apiCall(`/api/servers.php?action=diagnostics&id=${id}`, 'POST', { port }),
    metrics: (id) => apiCall(`/api/servers.php?action=metrics&id=${id}`, 'POST', {}),
    hardenPortKnock: (id, data) => apiCall(`/api/servers.php?action=harden-portknock&id=${id}`, 'POST', data),
    hardenPortscanBan: (id, data) => apiCall(`/api/servers.php?action=harden-portscan-ban&id=${id}`, 'POST', data),
    riskScanHistory: (id) => apiCall(`/api/servers.php?action=risk-scan-history&id=${id}`, 'GET'),
    riskScanLatestAll: () => apiCall('/api/servers.php?action=risk-scan-history', 'GET'),
    serverIncidents: (id) => apiCall(`/api/servers.php?action=incidents&id=${id}`, 'GET'),
    serverIncidentLog: (incidentId) => apiCall(`/api/servers.php?action=incident-log&incident=${incidentId}`, 'GET'),
    probeTimeline: (id) => apiCall(`/api/probe-intel.php?action=timeline&id=${id}`, 'GET'),
    probeEvents: (id, offset) => apiCall(`/api/probe-intel.php?action=events&id=${id}&offset=${offset || 0}`, 'GET'),
    probeBlockIp: (id, ip) => apiCall(`/api/probe-intel.php?action=block-ip&id=${id}`, 'POST', { ip }),
    probeUnblockIp: (id, ip) => apiCall(`/api/probe-intel.php?action=unblock-ip&id=${id}`, 'POST', { ip }),
    probeEnableLog: (id) => apiCall(`/api/probe-intel.php?action=enable-log&id=${id}`, 'POST', {}),
    probeDisableLog: (id) => apiCall(`/api/probe-intel.php?action=disable-log&id=${id}`, 'POST', {}),
    probePullLog: (id) => apiCall(`/api/probe-intel.php?action=pull-log&id=${id}`, 'POST', {}),
    antidpiGet: () => apiCall('/api/antidpi.php', 'GET'),
    antidpiSave: (data) => apiCall('/api/antidpi.php', 'POST', data),
    wgStatus: () => apiCall('/api/wg-status.php', 'GET'),
    tgProxy: (id) => apiCall(`/api/tgproxy.php?action=status&id=${id}`, 'GET'),
    tgProxyAction: (id, data) => apiCall(`/api/tgproxy.php?id=${id}`, 'POST', data),
    pkgStatus: (id) => apiCall(`/api/packages.php?action=status&id=${id}`, 'GET'),
    pkgLog: (id) => apiCall(`/api/packages.php?action=log&id=${id}`, 'GET'),
    pkgUpgrade: (id) => apiCall(`/api/packages.php?id=${id}`, 'POST', {}),

    createConnection: (data) => apiCall('/api/connections.php', 'POST', data),
    deleteConnection: (id) => apiCall(`/api/connections.php?id=${id}`, 'DELETE'),
    provisionConnection: (id) => apiCall(`/api/connections.php?action=provision&id=${id}`, 'POST', {}),
    camouflagePresets: () => apiCall('/api/connections.php?action=camouflage-presets', 'GET'),
    camouflagePrecheck: (id, domain) => apiCall(`/api/connections.php?action=camouflage-precheck&id=${id}&domain=${encodeURIComponent(domain)}`, 'GET'),
    setCamouflage: (id, data) => apiCall(`/api/connections.php?action=camouflage&id=${id}`, 'POST', data),

    createServerSet: (data) => apiCall('/api/server-sets.php', 'POST', data),
    updateServerSet: (id, data) => apiCall(`/api/server-sets.php?id=${id}`, 'PUT', data),
    deleteServerSet: (id, cascade) => apiCall(`/api/server-sets.php?id=${id}${cascade ? '&cascade=1' : ''}`, 'DELETE'),
    setMember: (setId, serverId, op, priority) =>
      apiCall(`/api/server-sets.php?action=members&id=${setId}`, 'POST', { server_id: serverId, op, priority }),

    importInfrastructure: (data) => apiCall('/api/infrastructure-export.php', 'POST', data),

    listRoutes: () => apiCall('/api/routes.php', 'GET'),
    createRoute: (data) => apiCall('/api/routes.php', 'POST', data),
    bulkRoutes: (data) => apiCall('/api/routes.php?action=bulk', 'POST', data),
    updateRoute: (id, data) => apiCall(`/api/routes.php?id=${id}`, 'PUT', data),
    deleteRoute: (id) => apiCall(`/api/routes.php?id=${id}`, 'DELETE'),
  };

  // -------------------------------------------------------------- state --
  let cy = null;
  let trafficState = null;
  let selectedDeviceKey = null;
  let eh = null;
  let state = { servers: [], connections: [], server_sets: [], routes: [] };
  let pendingCount = 0;
  let selectedServerId = null;
  let selectedConnectionId = null;
  let activeInspectorTab = 'overview';

  const ROLE_META = {
    router: { icon: '🧭', color: '#8b7cff' },
    exit: { icon: '🚪', color: '#35d07f' },
    proxy: { icon: '🔀', color: '#8b7cff' },
    vpn: { icon: '🔒', color: '#5b9cff' },
    gateway: { icon: '🌐', color: '#f5b942' },
    storage: { icon: '💾', color: '#a1a1aa' },
    generic: { icon: '🖥️', color: '#a1a1aa' },
  };
  const STATUS_COLOR = {
    online: '#35d07f', warning: '#f5b942', offline: '#ff5c5c',
    unknown: '#71717a', maintenance: '#5b9cff',
  };
  /** Цвет связи по типу протокола (раздел 26 дизайн-спеки: AmneziaWG — accent, SSH — серый, VLESS — purple, остальное — серый). */
  const EDGE_TYPE_COLOR = {
    amneziawg: '#ff4f87', wireguard: '#ff4f87',
    vless: '#8b7cff', hysteria2: '#8b7cff', tuic: '#8b7cff', trojan: '#8b7cff',
    shadowsocks: '#5b9cff',
    ssh: '#71717a', tcp: '#71717a', http: '#71717a', socks: '#71717a', generic: '#71717a',
  };
  function EDGE_COLOR_FN(ele) {
    return EDGE_TYPE_COLOR[ele.data('type')] || 'rgba(255,255,255,.25)';
  }

  // ----------------------------------------------------------- toasts ----
  function toast(message, type) {
    const stack = document.getElementById('toast-stack');
    const el = document.createElement('div');
    el.className = `toast ${type || ''}`;
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(() => el.remove(), 5000);
  }

  function markPending(delta) {
    if (window.PanelPendingCheck) setTimeout(window.PanelPendingCheck, 300);
    pendingCount = Math.max(0, pendingCount + delta);
    const banner = document.getElementById('pending-banner');
    const countEl = document.getElementById('pending-count');
    if (pendingCount > 0) {
      banner.classList.remove('hidden');
      countEl.textContent = pendingCount;
    } else {
      banner.classList.add('hidden');
    }
  }

  // ------------------------------------------------------------- load ----
  async function loadGraph() {
    // Плавный самоанимирующийся индикатор (неблокирующий оверлей), а не скачки по стадиям.
    var box = document.getElementById('graph-loading');
    var loader = (window.PanelLoader && box) ? window.PanelLoader.inline(box, (window.T ? T('dash.graph_loading') : 'Loading…'), 2.5) : null;
    try {
      state = await api.infrastructure();
      try { trafficState = await apiCall('/api/traffic.php?minutes=15', 'GET'); } catch (e) { trafficState = null; }
      renderGraph();
      refreshOpenInspector();
      renderKpis();
      focusHashServer();
    } finally {
      if (loader) loader.done(); else hideGraphLoading();
    }
  }

  // Переход со страницы «Серверы» по ссылке /dashboard.php#server-<id>:
  // открываем инспектор этого узла и подсвечиваем его в графе. Отрабатывает
  // один раз (хеш очищаем), чтобы не перехватывать обычные обновления графа.
  function focusHashServer() {
    const m = /^#server-(\d+)$/.exec(window.location.hash || '');
    if (!m) return;
    const id = parseInt(m[1], 10);
    history.replaceState(null, '', window.location.pathname + window.location.search);
    if (!state.servers.some((s) => s.id === id)) return;
    try { if (cy) { const n = cy.getElementById('s' + id); if (n && n.length) { cy.$(':selected').unselect(); n.select(); cy.animate({ center: { eles: n } }, { duration: 300 }); } } } catch (e) { /* граф мог не инициализироваться */ }
    openInspector(id);
  }

  // --- Неблокирующий оверлей загрузки поверх области графа -------------------
  // Живёт в #infra-canvas-wrap рядом с #infra-canvas, поэтому cy.destroy() его
  // не затирает. pointer-events:none — тулбар и граф остаются кликабельными.
  function showGraphLoading(pct) {
    const box = document.getElementById('graph-loading');
    if (!box) return;
    // Тот же визуал кольца, что и у общего PanelLoader (единая анимация везде),
    // но неблокирующий оверлей поверх области графа.
    if (!box.dataset.ring && window.PanelLoader) {
      box.innerHTML = '<div class="graph-loading-card">' + window.PanelLoader.ringMarkup(88)
        + '<div class="graph-loading-label">' + (window.T ? T('dash.graph_loading') : 'Loading…') + '</div></div>';
      box.dataset.ring = '1';
    }
    if (window.PanelLoader) window.PanelLoader.setRing(box, typeof pct === 'number' ? pct : 0);
    box.classList.remove('hidden');
  }
  function hideGraphLoading() {
    const box = document.getElementById('graph-loading');
    if (box) box.classList.add('hidden');
  }

  // --- Показ устройств на графе (по умолчанию OFF: разгружает граф) ----------
  function graphShowDevices() {
    try { return localStorage.getItem('graph_show_devices') === '1'; } catch (e) { return false; }
  }
  function setGraphShowDevices(on) {
    try { localStorage.setItem('graph_show_devices', on ? '1' : '0'); } catch (e) {}
  }
  function updateDevicesToggleLabel() {
    const btn = document.getElementById('btn-toggle-devices');
    if (!btn) return;
    const on = graphShowDevices();
    const text = on ? (btn.dataset.hide || 'Hide devices') : (btn.dataset.show || 'Show devices');
    const lbl = btn.querySelector('.lbl') || btn;
    lbl.textContent = text;
    btn.title = text;
    btn.classList.toggle('active', on);
  }

  /** Верхние KPI-плашки на dashboard.php — данные из уже загруженного state, без лишних запросов. */
  function renderKpis() {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('kpi-servers', state.servers.length);
    const online = state.servers.filter((s) => s.status === 'online').length;
    const metaEl = document.getElementById('kpi-servers-meta');
    if (metaEl) {
      metaEl.innerHTML = online > 0 ? `<span class="badge ok" style="padding:1px 7px">${online} online</span>` : '';
    }
    set('kpi-connections', state.connections.length);
    set('kpi-sets', state.server_sets.length);
    set('kpi-routes', state.routes.length);
  }

  /** После мутаций (create/update/delete где угодно) держим открытый инспектор в актуальном состоянии. */
  function refreshOpenInspector() {
    if (selectedServerId !== null) {
      const server = state.servers.find((s) => s.id === selectedServerId);
      if (server) {
        renderInspectorShell(server.name);
        renderServerSummary(server);
        return;
      }
    }
    if (selectedConnectionId !== null) {
      const conn = state.connections.find((c) => c.id === selectedConnectionId);
      if (conn) {
        openConnectionInspector(selectedConnectionId);
        return;
      }
    }
    if (selectedDeviceKey && trafficState && trafficState.devices.some((d) => d.key === selectedDeviceKey)) {
      openDeviceInspector(selectedDeviceKey);
      return;
    }
    closeInspector();
  }

  /**
   * Разбор «SSH не отвечает» (App\Diagnostics::analyzeSshFailure): вывод,
   * советы, какие порты открыты локально и что видно извне. Если SSH
   * найден на другом порту — кнопка data-use-ssh-port для подстановки.
   */
  function renderSshDiagnosis(r) {
    const d = r && r.diagnosis;
    if (!d) return `<div class="flash error">✕ ${escapeHtml((r && r.raw_error) || T('js.g.conn_fail'))}</div>`;
    const severe = ['tspu_block', 'tspu_port', 'tspu_ssh_dpi', 'ssh_no_banner', 'unreachable'].includes(d.verdict);
    const ports = Object.entries(d.local || {}).map(([port, p]) => {
      const cls = !p.open ? 'down' : (p.banner && p.kex === false ? 'warn' : 'ok');
      const tag = p.banner ? (p.kex === false ? ' · ' + T('js.g.ssh_breaks') : ' · SSH') : '';
      return `<span class="badge ${cls}" title="${escapeHtml(p.kex_error || p.banner || p.error || '')}" style="margin:2px">${escapeHtml(port)}${tag}</span>`;
    }).join('');
    const ext = d.external || {};
    const nodes = (ext.nodes || []).map((n) =>
      `<span class="badge ${n.ok ? 'ok' : 'down'}" title="${escapeHtml(n.node + ': ' + n.note)}" style="margin:2px">${escapeHtml(n.country || n.node)}</span>`).join('');
    return `
      <div class="flash ${severe ? 'error' : ''}" style="margin-top:10px">
        <strong>${severe ? '⛔' : '⚠'} ${escapeHtml(d.message)}</strong>
        ${(d.advice || []).length ? `<ul style="margin:6px 0 0 18px;padding:0">${d.advice.map((a) => `<li>${escapeHtml(a)}</li>`).join('')}</ul>` : ''}
        ${d.ssh_found_on && d.ssh_found_on !== d.ssh_port ? `<div style="margin-top:8px"><button type="button" data-use-ssh-port="${d.ssh_found_on}">${T('js.g.use_port', d.ssh_found_on)}</button></div>` : ''}
        <div class="muted" style="margin-top:8px;font-size:12px">${T('js.g.ports_from_ru', ports || '—')}</div>
        <div class="muted" style="margin-top:4px;font-size:12px">${T('js.g.port_worldwide', d.ssh_port, nodes || escapeHtml(ext.error || '—'))}</div>
      </div>`;
  }

  /**
   * Всплывающее окно долгой операции: вращающаяся дуга + кольцо прогресса с
   * процентом внутри и примерным оставшимся временем. Реального прогресса
   * сервер не отдаёт, поэтому процент — оценка по ожидаемой длительности:
   * к expectedSec доходит до ~90% и дальше медленно ползёт к 99%, пока
   * операция не завершится (done() доводит до 100% и закрывает окно).
   */
  function showProgress(title, expectedSec, steps) {
    // Единая анимация из panel-loader.js (та же везде). Ниже — локальный fallback.
    if (window.PanelLoader) return window.PanelLoader.progress(title, expectedSec, steps);
    let host = document.getElementById('progress-overlay');
    if (!host) {
      host = document.createElement('div');
      host.id = 'progress-overlay';
      host.style.cssText = 'position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)';
      document.body.appendChild(host);
      const st = document.createElement('style');
      st.textContent = '@keyframes pg-spin{to{transform:rotate(360deg)}}';
      document.head.appendChild(st);
    }
    const R = 52, C = 2 * Math.PI * R;
    host.innerHTML = `
      <div style="background:var(--surface-elevated);border:1px solid var(--border);border-radius:16px;padding:28px 34px;text-align:center;min-width:300px;max-width:420px;box-shadow:var(--shadow-modal)">
        <div style="position:relative;width:132px;height:132px;margin:0 auto 16px">
          <svg width="132" height="132" viewBox="0 0 132 132" style="position:absolute;inset:0">
            <circle cx="66" cy="66" r="${R}" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="9"/>
            <circle id="pg-ring" cx="66" cy="66" r="${R}" fill="none" stroke="var(--accent)" stroke-width="9" stroke-linecap="round"
              stroke-dasharray="${C}" stroke-dashoffset="${C}" transform="rotate(-90 66 66)" style="transition:stroke-dashoffset .4s ease"/>
          </svg>
          <svg width="132" height="132" viewBox="0 0 132 132" style="position:absolute;inset:0;animation:pg-spin 1.1s linear infinite">
            <circle cx="66" cy="66" r="38" fill="none" stroke="var(--accent-purple)" stroke-width="3" stroke-linecap="round" stroke-dasharray="60 180"/>
          </svg>
          <div id="pg-pct" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700">0%</div>
        </div>
        <div style="font-weight:600;font-size:15px">${escapeHtml(title)}</div>
        <div id="pg-step" class="muted" style="margin-top:6px;font-size:12.5px;min-height:18px"></div>
        <div id="pg-eta" class="muted" style="margin-top:4px;font-size:12px"></div>
      </div>`;
    host.style.display = 'flex';
    const ring = host.querySelector('#pg-ring');
    const pctEl = host.querySelector('#pg-pct');
    const stepEl = host.querySelector('#pg-step');
    const etaEl = host.querySelector('#pg-eta');
    const start = Date.now();
    let pct = 0;
    const render = (p) => {
      pct = p;
      ring.setAttribute('stroke-dashoffset', String(C * (1 - p / 100)));
      pctEl.textContent = Math.floor(p) + '%';
    };
    const tick = () => {
      const t = (Date.now() - start) / 1000;
      const p = t <= expectedSec ? (t / expectedSec) * 90 : 90 + 9 * (1 - Math.exp(-(t - expectedSec) / expectedSec));
      render(Math.max(pct, Math.min(99, p)));
      const left = Math.max(0, Math.round(expectedSec - t));
      etaEl.textContent = left > 0 ? T('js.g.eta_left', left) : T('js.g.eta_more', Math.round(t));
      if (steps && steps.length) {
        const i = Math.min(steps.length - 1, Math.floor((t / expectedSec) * steps.length));
        stepEl.textContent = steps[i];
      }
    };
    tick();
    const timer = setInterval(tick, 250);
    return {
      step(text) { steps = null; stepEl.textContent = text; },
      done() {
        clearInterval(timer);
        render(100);
        etaEl.textContent = T('js.g.eta_done', Math.round((Date.now() - start) / 1000));
        setTimeout(() => { host.style.display = 'none'; }, 450);
      },
    };
  }

  /** Блок «Трафик за период / лимит тарифа» в карточке сервера. */
  function renderQuotaBlock(server) {
    const t = server.traffic;
    if (!t) return '';
    const gb = (b) => (b / 1e9).toFixed(b >= 1e11 ? 0 : 1);
    const modeText = { sum: T('js.g.mode_sum'), out: T('js.g.mode_out'), max: T('js.g.mode_max') }[t.mode] || t.mode;
    const color = t.level === 'danger' ? '--danger' : t.level === 'warn' ? '--warning' : '--success';
    const warn = t.level === 'danger'
      ? `<div class="flash error" style="margin-top:6px">${T('js.g.quota_danger', t.percent)}</div>`
      : t.level === 'warn' ? `<div class="flash" style="margin-top:6px">${T('js.g.quota_warn', t.percent)}</div>` : '';
    return `
      <div class="field-row" style="margin-top:12px"><label>${T('js.g.traffic_since', escapeHtml(t.period_start), escapeHtml(modeText))}</label>
        ${t.limit_bytes
          ? `${T('js.g.used_of_limit', gb(t.used_bytes), gb(t.limit_bytes), t.percent)}<div style="background:rgba(255,255,255,.08);border-radius:4px;height:8px;margin-top:4px;overflow:hidden"><div style="width:${Math.min(100, t.percent)}%;height:100%;background:var(${color})"></div></div>`
          : `${gb(t.used_bytes)} ${T('js.g.gb')} <span class="muted">${T('js.g.no_limit')}</span>`}
        <div class="muted" style="font-size:12px;margin-top:4px">${T('js.g.traffic_in')} ${gb(t.rx)} ${T('js.g.gb')} · ${T('js.g.traffic_out')} ${gb(t.tx)} ${T('js.g.gb')}${t.since && t.since > t.period_start ? ' · ' + T('js.g.since_note', escapeHtml(t.since)) : ''}${t.synced ? ' · ' + T('js.g.synced_note') : ''}</div>
        ${warn}
        <form id="traffic-sync-form" class="row" style="margin-top:6px;gap:6px">
          <input name="hoster_gb" type="number" min="0" step="0.1" placeholder="${T('js.g.hoster_gb_ph')}" style="flex:1" required>
          <button type="submit" class="secondary">${T('js.g.reconcile')}</button>
        </form>
      </div>`;
  }

  function wireUseSshPort(container, onUse) {
    const btn = container.querySelector('[data-use-ssh-port]');
    if (btn) btn.addEventListener('click', () => onUse(Number(btn.dataset.useSshPort)));
  }

  /** Поля сервера для PUT — Server::update() требует name/role и перезаписывает остальные значения. */
  function editableServerFields(server) {
    return {
      name: server.name, role: server.role, host: server.host, ssh_port: server.ssh_port, ssh_user: server.ssh_user,
      country: server.country, region: server.region, description: server.description,
      tags: server.tags ? JSON.parse(server.tags) : [], enabled: !!server.enabled,
    };
  }

  /** Метрики из последней проверки (health-check / «Проверить подключение» / клик по серверу). */
  function serverMetrics(server) {
    if (!server.last_check_result) return null;
    try {
      const r = JSON.parse(server.last_check_result);
      return r && r.metrics && r.metrics.ok ? r.metrics : null;
    } catch (e) { return null; }
  }

  const STATUS_TEXT = { online: 'online', offline: 'offline', warning: 'warning', unknown: T('js.g.st_unknown'), maintenance: T('js.g.st_maint') };

  function nodeLabel(server) {
    const meta = ROLE_META[server.role] || ROLE_META.generic;
    const m = serverMetrics(server);
    const load = m
      ? `CPU ${m.cpu_percent != null ? m.cpu_percent + '%' : '—'} · RAM ${m.mem ? m.mem.used_percent + '%' : '—'}`
      : (STATUS_TEXT[server.status] || server.status);
    const t = server.traffic;
    const quota = t && t.percent != null ? `\n${t.level === 'ok' ? '' : '⚠ '}${T('js.g.traffic_pct', t.percent)}` : '';
    return `${meta.icon} ${server.name}\n${load}${quota}`;
  }

  /**
   * Узел графа рисуется как серверная стойка (вертикальный корпус с 3
   * юнитами): светодиоды — статус, полоски на юнитах — CPU / RAM / диск.
   * SVG строится на лету и отдаётся Cytoscape как background-image.
   */
  function serverNodeSvg(server) {
    const quotaLevel = server.traffic && server.traffic.level;
    const status = quotaLevel === 'danger' ? '#ff5c5c' : quotaLevel === 'warn' ? '#f5b942' : (STATUS_COLOR[server.status] || STATUS_COLOR.unknown);
    const accent = (ROLE_META[server.role] || ROLE_META.generic).color;
    const m = serverMetrics(server);
    const levels = [
      m && m.cpu_percent != null ? m.cpu_percent : null,
      m && m.mem ? m.mem.used_percent : null,
      m && m.disk ? m.disk.used_percent : null,
    ];
    const barColor = (v) => (v >= 90 ? '#ff5c5c' : v >= 70 ? '#f5b942' : '#35d07f');
    const units = [0, 1, 2].map((i) => {
      const y = 22 + i * 40;
      const v = levels[i];
      const bar = v == null
        ? `<rect x="16" y="${y + 24}" width="58" height="5" rx="2.5" fill="#2c2c33"/>`
        : `<rect x="16" y="${y + 24}" width="58" height="5" rx="2.5" fill="#2c2c33"/><rect x="16" y="${y + 24}" width="${Math.max(3, Math.round(58 * Math.min(100, v) / 100))}" height="5" rx="2.5" fill="${barColor(v)}"/>`;
      return `
        <rect x="8" y="${y}" width="80" height="34" rx="4" fill="#26262c" stroke="#3a3a42"/>
        <line x1="16" y1="${y + 9}" x2="54" y2="${y + 9}" stroke="#44444d" stroke-width="2" stroke-linecap="round"/>
        <line x1="16" y1="${y + 15}" x2="46" y2="${y + 15}" stroke="#44444d" stroke-width="2" stroke-linecap="round"/>
        <circle cx="72" cy="${y + 10}" r="3.2" fill="${status}"/>
        <circle cx="80" cy="${y + 10}" r="3.2" fill="${i === 0 ? accent : '#44444d'}"/>
        ${bar}`;
    }).join('');
    const svgText = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="150" viewBox="0 0 96 150">
      <rect x="1.5" y="1.5" width="93" height="147" rx="9" fill="#1d1d22" stroke="${status}" stroke-width="3"/>
      <rect x="8" y="8" width="80" height="8" rx="3" fill="${accent}" opacity=".85"/>
      ${units}
      <rect x="30" y="140" width="36" height="3" rx="1.5" fill="#3a3a42"/>
    </svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svgText);
  }

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' ' + T('js.g.u_b');
    const units = [T('js.g.u_kb'), T('js.g.u_mb'), T('js.g.u_gb'), T('js.g.u_tb')];
    let i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < units.length - 1);
    return (n >= 100 ? n.toFixed(0) : n.toFixed(1)) + ' ' + units[i];
  }

  /** Человекочитаемое «куда ушло» по тегу outbound sing-box. */
  function outboundLabel(tag) {
    if (tag === 'direct-rf') return T('js.g.direct_rf');
    if (tag === 'block') return T('js.g.blocked');
    const m = /^exit-(\d+)$/.exec(tag || '');
    if (m) {
      const conn = state.connections.find((c) => c.exit_server_id === Number(m[1]));
      const target = conn && state.servers.find((s) => s.id === conn.target_server_id);
      return target ? target.name : tag;
    }
    return tag || '—';
  }

  function deviceNodeSvg(d) {
    const color = d.online ? '#35d07f' : '#71717a';
    const svgText = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="76" viewBox="0 0 48 76">
      <rect x="2" y="2" width="44" height="72" rx="9" fill="#1d1d22" stroke="${color}" stroke-width="2.5"/>
      <rect x="7" y="10" width="34" height="50" rx="3" fill="#26262c"/>
      <rect x="18" y="5" width="12" height="2.5" rx="1.2" fill="#3a3a42"/>
      <circle cx="24" cy="67" r="3" fill="${color}"/>
      <path d="M13 44 l6-7 5 4 6-9 6 8" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>
    </svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svgText);
  }

  /**
   * Устройства, которые были подключены / качали за последние 15 минут
   * (bin/collect_traffic.php): узлы слева от роутера, связь устройство→роутер
   * подписана трафиком ↑ от устройства / ↓ к устройству.
   */
  function addDeviceNodes() {
    if (!cy || !trafficState || !trafficState.devices || !trafficState.devices.length) return;
    const self = state.servers.find((s) => s.is_self);
    const routerNode = self && cy.getElementById('s' + self.id);
    if (!routerNode || !routerNode.length) return;
    const rp = routerNode.position();
    const list = trafficState.devices.slice(0, 30);
    const step = 110;
    list.forEach((d, i) => {
      const id = 'd-' + d.key.replace(/[^A-Za-z0-9_-]/g, '_');
      cy.add({
        group: 'nodes',
        classes: 'device',
        data: {
          id, deviceKey: d.key,
          label: `${d.name}\n↑${fmtBytes(d.up)} ↓${fmtBytes(d.down)}`,
          svg: deviceNodeSvg(d),
        },
        position: { x: rp.x - 280, y: rp.y + (i - (list.length - 1) / 2) * step },
      });
      cy.add({
        group: 'edges',
        classes: 'device-edge' + (d.online ? ' online' : ''),
        data: { id: id + '-e', source: id, target: routerNode.id(), label: d.online ? 'online' : '' },
      });
    });
  }

  function openDeviceInspector(key) {
    const d = trafficState && trafficState.devices.find((x) => x.key === key);
    if (!d) return;
    selectedServerId = null;
    selectedConnectionId = null;
    selectedDeviceKey = key;
    const inspector = document.getElementById('infra-inspector');
    inspector.classList.remove('hidden');
    const since = d.last_seen ? new Date(d.last_seen * 1000).toLocaleString('ru-RU') : '—';
    const outbounds = Object.entries(d.by_outbound || {}).sort((a, b) => (b[1].up + b[1].down) - (a[1].up + a[1].down));
    inspector.innerHTML = `
      <div class="inspector-header">
        <strong>📱 ${escapeHtml(d.name)}</strong>
        <button class="secondary" data-close>✕</button>
      </div>
      <div class="inspector-body">
        <div class="field-row"><label>${T('js.g.status')}</label>${d.online ? '<span class="badge ok">' + T('devices.online') + '</span>' : '<span class="badge unknown">' + T('devices.offline') + '</span>'} <span class="muted">${T('js.g.last_activity', escapeHtml(since))}</span></div>
        <div class="field-row"><label>${T('js.g.dev_source')}</label>${escapeHtml(d.source_ip || '—')} <span class="muted">${escapeHtml(d.inbound || '')}</span></div>
        <div class="field-row"><label>${T('js.g.dev_traffic_min', trafficState.minutes)}</label>↓ ${fmtBytes(d.down)} ${T('js.g.to_device')} · ↑ ${fmtBytes(d.up)} ${T('js.g.from_device')}</div>
        ${d.totals ? `<div class="field-row"><label>${T('js.g.dev_totals')}${d.totals.since ? T('js.g.dev_totals_since', escapeHtml(d.totals.since)) : ''}</label>
          ↓ ${fmtBytes(d.totals.today_down)} ↑ ${fmtBytes(d.totals.today_up)} · ↓ ${fmtBytes(d.totals.d30_down)} ↑ ${fmtBytes(d.totals.d30_up)} · <b>↓ ${fmtBytes(d.totals.total_down)} ↑ ${fmtBytes(d.totals.total_up)}</b></div>` : ''}
        <div class="field-row"><label>${T('js.g.dev_where')}</label>
          ${outbounds.map(([ob, t]) => `<div>${escapeHtml(outboundLabel(ob))}: ↑${fmtBytes(t.up)} ↓${fmtBytes(t.down)}</div>`).join('') || '<span class="muted">' + T('js.g.no_data') + '</span>'}
        </div>
        <div class="field-row"><label>${T('js.g.dev_sites')}</label>
          <table style="width:100%;font-size:12.5px">
            ${(d.hosts || []).map((h) => `<tr><td style="word-break:break-all">${escapeHtml(h.host)}</td><td class="muted">${escapeHtml(outboundLabel(h.outbound))}</td><td style="white-space:nowrap;text-align:right">↑${fmtBytes(h.up)} ↓${fmtBytes(h.down)}</td></tr>`).join('') || '<tr><td class="muted">' + T('js.g.no_data') + '</td></tr>'}
          </table>
        </div>
        ${d.client_id ? '<p class="muted">' + T('js.g.dev_links_pre') + ' <a href="/devices.php">' + T('js.g.devices_link') + '</a>.</p>' : '<p class="muted">' + T('js.g.dev_unknown') + '</p>'}
      </div>`;
    inspector.querySelector('[data-close]').addEventListener('click', closeInspector);
  }

  function renderGraph() {
    const container = document.getElementById('infra-canvas');
    if (!container.dataset.contextMenuGuarded) {
      // Cytoscape шлёт cxttap асинхронно относительно нативного contextmenu —
      // preventDefault() внутри showContextMenu() иногда не успевает до того,
      // как браузер уже показал свой menu поверх нашего. Глушим нативное меню
      // на всём канвасе на уровне DOM (пустой фон включая — там cxttap вообще
      // не обрабатывается) — один раз на контейнер, не на каждый renderGraph().
      // Capture-фаза: срабатывает ДО того, как cytoscape успеет остановить
      // всплытие события, поэтому нативное меню браузера гарантированно подавлено
      // даже при ПКМ внутри узла/бокса (баг: там выскакивало меню браузера).
      container.addEventListener('contextmenu', (e) => e.preventDefault(), true);
      container.dataset.contextMenuGuarded = '1';
    }
    const elements = [];

    // A. Рамки-контейнеры для наборов серверов (compound-узлы Cytoscape). Узел
    // может иметь только ОДНОГО родителя, поэтому каждый сервер попадает в ПЕРВЫЙ
    // набор, где он состоит (мультипринадлежность по-прежнему видна в инспекторе
    // набора). Рамка авто-подгоняется под своих детей.
    const serverParent = {};    // serverId -> 'set'+setId
    const usedSets = new Set();  // наборы, реально ставшие рамками
    const presentServerIds = new Set(state.servers.map((s) => s.id));
    (state.server_sets || []).forEach((set) => {
      (set.members || []).forEach((m) => {
        if (presentServerIds.has(m.server_id) && serverParent[m.server_id] === undefined) {
          serverParent[m.server_id] = 'set' + set.id;
          usedSets.add(set.id);
        }
      });
    });
    (state.server_sets || []).forEach((set) => {
      if (!usedSets.has(set.id)) return;
      elements.push({
        group: 'nodes',
        classes: 'set-box',
        data: { id: 'set' + set.id, setId: set.id, isSet: true, label: '📦 ' + set.name },
        selectable: true,
        grabbable: false,
      });
    });

    state.servers.forEach((s) => {
      const data = {
        id: 's' + s.id,
        serverId: s.id,
        label: nodeLabel(s),
        role: s.role,
        status: s.status,
        statusColor: STATUS_COLOR[s.status] || STATUS_COLOR.unknown,
        roleColor: (ROLE_META[s.role] || ROLE_META.generic).color,
        svg: serverNodeSvg(s),
      };
      if (serverParent[s.id] !== undefined) data.parent = serverParent[s.id];
      elements.push({
        group: 'nodes',
        data,
        position: { x: s.position_x || 0, y: s.position_y || 0 },
        grabbable: true,
      });
    });

    state.connections.forEach((c) => {
      const executable = state.executable_connection_types && state.executable_connection_types.includes(c.type);
      const routeCount = c.exit_server_id
        ? state.routes.filter((r) => r.enabled && r.resolved_exit_server_id === c.exit_server_id).length
        : 0;
      const exitTraffic = c.exit_server_id && trafficState && trafficState.by_outbound['exit-' + c.exit_server_id];
      const label = (c.label || c.type) + (routeCount > 0 ? ` +${routeCount} route${routeCount === 1 ? '' : 's'}` : '')
        + (exitTraffic ? `
↑${fmtBytes(exitTraffic.up)} ↓${fmtBytes(exitTraffic.down)}` : '');
      elements.push({
        group: 'edges',
        data: {
          id: 'c' + c.id,
          connId: c.id,
          source: 's' + c.source_server_id,
          target: 's' + c.target_server_id,
          label,
          type: c.type,
          executable,
        },
      });
    });

    if (cy) {
      cy.destroy();
    }
    const staleSelectionBar = document.getElementById('graph-selection-bar');
    if (staleSelectionBar) staleSelectionBar.remove();

    cy = cytoscape({
      container,
      elements,
      wheelSensitivity: 0.2,
      minZoom: 0.2,
      maxZoom: 2.5,
      boxSelectionEnabled: true,
      style: [
        {
          selector: 'node',
          style: {
            label: 'data(label)',
            shape: 'round-rectangle',
            'background-opacity': 0,
            'background-image': 'data(svg)',
            'background-fit': 'contain',
            'background-clip': 'none',
            'border-width': 0,
            color: '#f5f5f7',
            'font-family': 'Inter, system-ui, sans-serif',
            'font-weight': 500,
            'font-size': 12,
            width: 64,
            height: 100,
            'text-valign': 'bottom',
            'text-halign': 'center',
            'text-margin-y': 6,
            'text-max-width': '150px',
            'text-wrap': 'wrap',
            'line-height': 1.35,
            'text-background-color': '#17171a',
            'text-background-opacity': 0.85,
            'text-background-padding': 3,
            'text-background-shape': 'round-rectangle',
          },
        },
        {
          selector: 'edge',
          style: {
            width: 1.6,
            'line-color': EDGE_COLOR_FN,
            'target-arrow-shape': 'triangle',
            'arrow-scale': 0.8,
            'target-arrow-color': EDGE_COLOR_FN,
            'curve-style': 'bezier',
            label: 'data(label)',
            'font-family': 'Inter, system-ui, sans-serif',
            'font-size': 10.5,
            color: '#a1a1aa',
            'text-background-color': '#17171a',
            'text-background-opacity': 1,
            'text-background-padding': 3,
            'text-wrap': 'wrap',
          },
        },
        {
          selector: 'node.device',
          style: { width: 34, height: 54, 'font-size': 11 },
        },
        {
          // Рамка-контейнер набора (compound-родитель). Размеры авто по детям.
          selector: 'node.set-box',
          style: {
            shape: 'round-rectangle',
            'background-image': 'none',
            'background-color': '#8b7cff',
            'background-opacity': 0.06,
            'border-width': 1.5,
            'border-style': 'dashed',
            'border-color': '#8b7cff',
            'border-opacity': 0.55,
            label: 'data(label)',
            color: '#c9c2ff',
            'font-size': 13,
            'font-weight': 600,
            'text-valign': 'top',
            'text-halign': 'center',
            'text-margin-y': 2,
            padding: 24,
            'text-background-opacity': 0,
          },
        },
        {
          selector: 'edge.device-edge',
          style: { 'line-style': 'dashed', 'line-color': '#52525b', 'target-arrow-color': '#52525b', width: 1.2, 'font-size': 9.5 },
        },
        {
          selector: 'edge.device-edge.online',
          style: { 'line-color': '#35d07f', 'target-arrow-color': '#35d07f', color: '#35d07f' },
        },
        {
          selector: 'node:selected',
          style: { 'underlay-color': '#ff4f87', 'underlay-opacity': 0.35, 'underlay-padding': 6, 'underlay-shape': 'round-rectangle' },
        },
        {
          selector: 'edge:selected',
          style: { 'line-color': '#ff4f87', 'target-arrow-color': '#ff4f87', width: 2.4 },
        },
        {
          selector: '.eh-handle',
          style: { 'background-color': '#ff4f87', width: 10, height: 10, opacity: 0.9, 'border-width': 2, 'border-color': '#17171a' },
        },
        {
          selector: '.eh-ghost-edge',
          style: { 'line-color': '#ff4f87', 'target-arrow-color': '#ff4f87', opacity: 0.7 },
        },
      ],
      layout: state.servers.every((s) => !s.position_x && !s.position_y)
        ? { name: 'breadthfirst', roots: '#s' + (state.servers.find((s) => s.is_self) || {}).id, spacingFactor: 1.4 }
        : { name: 'preset' },
    });

    eh = cy.edgehandles({
      canConnect: (sourceNode, targetNode) => sourceNode.id() !== targetNode.id(),
      edgeParams: () => ({}),
      hoverDelay: 100,
      snap: true,
    });

    cy.on('dragfree', 'node', (evt) => {
      const node = evt.target;
      if (!node.data('serverId')) return; // устройства — не сохраняем позицию
      const pos = node.position();
      api.setPosition(node.data('serverId'), pos.x, pos.y).catch(() => toast(T('js.g.pos_fail'), 'error'));
    });

    cy.on('tap', 'node', (evt) => {
      const d = evt.target.data();
      if (d.deviceKey) openDeviceInspector(d.deviceKey);
      else if (d.isSet) openServerSetDetailModal(d.setId);
      else openInspector(d.serverId);
    });
    cy.on('tap', 'edge', (evt) => openConnectionInspector(evt.target.data('connId')));
    cy.on('tap', (evt) => { if (evt.target === cy) closeInspector(); });

    cy.on('ehcomplete', (event, sourceNode, targetNode, addedEdge) => {
      cy.remove(addedEdge); // временное ребро edgehandles — настоящее создаём через API после модалки
      openConnectionModal(sourceNode.data('serverId'), targetNode.data('serverId'));
    });

    cy.on('cxttap', 'node', (evt) => {
      const d = evt.target.data();
      if (d.isSet) showContextMenu(evt.originalEvent, buildSetContextMenu(d.setId));
      else if (d.serverId) showContextMenu(evt.originalEvent, buildServerContextMenu(d.serverId));
    });
    cy.on('cxttap', 'edge', (evt) => showContextMenu(evt.originalEvent, buildConnectionContextMenu(evt.target.data('connId'))));

    cy.on('select unselect', updateSelectionBar);

    setupKeyboardShortcuts();

    if (graphShowDevices()) addDeviceNodes();

    const doFit = () => {
      cy.resize(); // пересчитать размеры контейнера (критично на мобиле/после ресайза)
      cy.fit(undefined, 60);
      if (cy.zoom() > 1.2) {
        cy.zoom(1);
        cy.center();
      }
    };
    doFit();
    // Контейнер внутри flex-layout иногда ещё не досчитал финальный размер
    // в момент конструирования cytoscape — повторный fit через кадр (и чуть позже)
    // надёжно подхватывает финальные размеры (иначе граф пустой/не по центру,
    // особенно на мобиле, где высота считается после применения CSS).
    requestAnimationFrame(doFit);
    setTimeout(doFit, 250);
    if (!window.__infraResizeBound) {
      window.__infraResizeBound = true;
      window.addEventListener('resize', () => { if (cy) { cy.resize(); cy.fit(undefined, 60); } });
      window.addEventListener('orientationchange', () => setTimeout(() => { if (cy) { cy.resize(); cy.fit(undefined, 60); } }, 300));
    }

    if (state.servers.length === 0) {
      showEmptyState();
    }
  }

  let keyboardShortcutsInstalled = false;
  function setupKeyboardShortcuts() {
    if (keyboardShortcutsInstalled) return; // cy пересоздаётся при каждом renderGraph(), слушатель — нет
    keyboardShortcutsInstalled = true;
    document.addEventListener('keydown', async (e) => {
      if (e.key === 'Escape') {
        if (eh && eh.stop) eh.stop();
        document.querySelectorAll('.context-menu').forEach((m) => m.remove());
        if (!document.getElementById('modal-overlay').classList.contains('hidden')) {
          closeModal();
        }
        return;
      }
      if (e.key !== 'Delete' && e.key !== 'Backspace') return;
      if (!cy) return;
      const active = document.activeElement;
      if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
        return; // не мешаем удалению текста в полях форм
      }
      await deleteSelectedElements();
    });
  }

  /** Удаляет все выбранные ноды/рёбра одним подтверждением — используется и
   *  клавишей Delete, и кнопкой в плавающей панели множественного выбора. */
  async function deleteSelectedElements() {
    if (!cy) return;
    const selected = cy.$(':selected');
    if (!selected.length) return;

    const nodes = selected.filter((el) => {
      if (!el.isNode() || !el.data('serverId')) return false;
      const server = state.servers.find((s) => s.id === el.data('serverId'));
      return !server || !server.is_self;
    });
    const edges = selected.filter((el) => el.isEdge());
    if (!nodes.length && !edges.length) return;

    const parts = [];
    if (nodes.length) parts.push(T('js.g.n_servers', nodes.length));
    if (edges.length) parts.push(T('js.g.n_conns', edges.length));
    if (!(await confirmDialog(T('js.g.del_selected', parts.join(', ')), T('js.g.del')))) return;

    for (const el of edges) {
      await api.deleteConnection(el.data('connId'));
    }
    for (const el of nodes) {
      await api.deleteServer(el.data('serverId'));
    }
    markPending(nodes.length + edges.length);
    toast(T('js.g.selected_deleted'), 'success');
    await loadGraph();
  }

  function updateSelectionBar() {
    if (!cy) return;
    let bar = document.getElementById('graph-selection-bar');
    const count = cy.$('node:selected, edge:selected').length;
    if (count < 2) {
      if (bar) bar.remove();
      return;
    }
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'graph-selection-bar';
      bar.className = 'graph-selection-bar';
      document.getElementById('infra-canvas').appendChild(bar);
    }
    bar.innerHTML = `
      <span><strong id="graph-selection-count"></strong> ${T('js.g.selected')}</span>
      <button class="secondary" id="btn-selection-clear">${T('js.g.clear_selection')}</button>
      <button class="danger" id="btn-selection-delete">${T('js.g.del')}</button>
    `;
    bar.querySelector('#graph-selection-count').textContent = count;
    bar.querySelector('#btn-selection-clear').addEventListener('click', () => cy.elements().unselect());
    bar.querySelector('#btn-selection-delete').addEventListener('click', deleteSelectedElements);
  }

  function showEmptyState() {
    const el = document.createElement('div');
    el.className = 'empty-state';
    el.style.position = 'absolute';
    el.style.top = '50%';
    el.style.left = '50%';
    el.style.transform = 'translate(-50%,-50%)';
    el.innerHTML = T('js.g.no_servers') + '<br><button id="empty-add-server" style="margin-top:10px">' + T('js.g.add_server') + '</button>';
    document.getElementById('infra-canvas').appendChild(el);
    document.getElementById('empty-add-server').addEventListener('click', openAddServerModal);
  }

  // --------------------------------------------------------- inspector ---
  function closeInspector() {
    document.getElementById('infra-inspector').classList.add('hidden');
    selectedServerId = null;
    selectedConnectionId = null;
    selectedDeviceKey = null;
  }

  // Компактная «шторка»: краткая сводка + «Подробнее» (полноэкранное окно с
  // иконочной навигацией). Раньше тут были все вкладки и куча кнопок сразу.
  function renderInspectorShell(title) {
    const inspector = document.getElementById('infra-inspector');
    inspector.classList.remove('hidden');
    inspector.innerHTML = `
      <div class="inspector-header">
        <strong>${escapeHtml(title)}</strong>
        <button class="secondary" data-close>✕</button>
      </div>
      <div class="inspector-body" id="inspector-body"></div>
      <div style="padding:12px 16px 4px"><button id="btn-server-details" style="width:100%">${T('js.g.details')}</button></div>
    `;
    inspector.querySelector('[data-close]').addEventListener('click', closeInspector);
    const d = inspector.querySelector('#btn-server-details');
    if (d) d.addEventListener('click', () => {
      const server = state.servers.find((s) => s.id === selectedServerId);
      if (server) openServerDetail(server);
    });
  }

  /** Краткая сводка по серверу для шторки (без действий — они в «Подробнее»). */
  function renderServerSummary(server, bodyEl) {
    const body = bodyEl || document.getElementById('inspector-body');
    if (!body) return;
    const result = server.last_check_result ? (() => { try { return JSON.parse(server.last_check_result); } catch (e) { return null; } })() : null;
    const m = result && result.metrics ? result.metrics : null;
    body.innerHTML = `
      <div class="field-row"><label>${T('js.g.status_label')}</label>${statusBadge(server.status)}</div>
      <div class="field-row"><label>${T('js.g.role')}</label>${escapeHtml(server.role)}</div>
      <div class="field-row"><label>Host</label>${escapeHtml(server.host || '—')}</div>
      ${result && result.uptime ? `<div class="field-row"><label>uptime</label>${escapeHtml(result.uptime)}</div>` : ''}
      ${m && m.cpu_percent != null ? `<div class="field-row"><label>CPU</label>${escapeHtml(String(m.cpu_percent))}%</div>` : ''}
      ${m && m.mem && m.mem.used_percent != null ? `<div class="field-row"><label>RAM</label>${escapeHtml(String(m.mem.used_percent))}%</div>` : ''}
    `;
  }

  async function openInspector(serverId) {
    selectedServerId = serverId;
    selectedDeviceKey = null;
    selectedConnectionId = null;
    activeInspectorTab = 'overview';
    const server = state.servers.find((s) => s.id === serverId);
    if (!server) return;
    renderInspectorShell(server.name);
    renderServerSummary(server);
  }

  /**
   * Полноэкранное окно «Подробнее»: слева иконочная навигация по разделам (~25%),
   * справа содержимое раздела (рендерит существующий renderInspectorTab в #detail-body).
   */
  function openServerDetail(server) {
    const items = [
      { tab: 'overview', icon: '📋', label: T('js.g.tab_overview') },
      { tab: 'connections', icon: '🔗', label: T('js.g.tab_connections') },
      { tab: 'sets', icon: '🗂', label: T('js.g.tab_sets') },
      { tab: 'incidents', icon: '🩺', label: T('js.g.tab_incidents') },
    ];
    // «Конфигурация» (Reality/протоколы/блокировки) — для узлов-роутеров и самого
    // входного узла: эти настройки теперь живут в самом сервере, а не на странице «Настройки».
    if (server.is_self || server.role === 'router') {
      items.push({ tab: 'config', icon: '🎛', label: T('js.g.tab_config') });
    }
    // «Активная защита» — только для exit-узлов и когда модуль включён.
    const probeOn = window.PANEL_CONFIG && window.PANEL_CONFIG.features && window.PANEL_CONFIG.features.probeIntel;
    if (probeOn && !server.is_self && (server.role === 'exit')) {
      items.push({ tab: 'probe', icon: '🛡', label: T('js.g.tab_probe') });
    }
    // WG-вход (Keenetic → вход) — только для самого входного узла.
    if (server.is_self) {
      items.push({ tab: 'wgmon', icon: '📡', label: T('js.g.tab_wgmon') });
    }
    // Telegram-прокси — на любом узле, если модуль включён.
    if (window.PANEL_CONFIG && window.PANEL_CONFIG.features && window.PANEL_CONFIG.features.tgProxy) {
      items.push({ tab: 'tgproxy', icon: '✈️', label: T('js.g.tab_tgproxy') });
    }
    items.push({ tab: 'advanced', icon: '⚙️', label: T('js.g.tab_advanced') });
    let cur = activeInspectorTab && items.some((i) => i.tab === activeInspectorTab) ? activeInspectorTab : 'overview';
    const overlay = document.createElement('div');
    overlay.className = 'detail-overlay';
    overlay.innerHTML = `
      <div class="detail-shell">
        <aside class="detail-sidebar">
          <div class="detail-title">${escapeHtml(server.name)}</div>
          <nav class="detail-nav">${items.map((i) => `<button data-tab="${i.tab}"><span class="di">${i.icon}</span><span class="dl">${escapeHtml(i.label)}</span></button>`).join('')}</nav>
          <button class="detail-close" data-detail-close>✕ ${T('common.close')}</button>
        </aside>
        <div class="detail-content" id="detail-body"></div>
      </div>`;
    document.body.appendChild(overlay);
    const contentEl = overlay.querySelector('#detail-body');
    const navBtns = overlay.querySelectorAll('.detail-nav button');
    function select(tab) {
      cur = tab; activeInspectorTab = tab;
      navBtns.forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
      renderInspectorTab(server, tab, contentEl);
    }
    navBtns.forEach((b) => b.addEventListener('click', () => select(b.dataset.tab)));
    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    overlay.querySelector('[data-detail-close]').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', onKey);
    select(cur);
  }

  function statusBadge(status) {
    const cls = { online: 'ok', offline: 'down', warning: 'warn', maintenance: 'maintenance' }[status] || 'unknown';
    return `<span class="badge ${cls}">${escapeHtml(status)}</span>`;
  }

  function renderInspectorTab(server, tab, bodyEl) {
    const body = bodyEl || document.getElementById('detail-body') || document.getElementById('inspector-body');
    if (tab === 'overview') {
      const result = server.last_check_result ? JSON.parse(server.last_check_result) : null;
      body.innerHTML = `
        <div class="field-row"><label>${T('js.g.status_label')}</label>${statusBadge(server.status)} ${server.last_checked_at ? '<span class="muted">' + escapeHtml(server.last_checked_at) + '</span>' : '<span class="muted">' + T('js.g.never_checked') + '</span>'}</div>
        <div class="field-row"><label>${T('js.g.role')}</label>${escapeHtml(server.role)}</div>
        <div class="field-row"><label>Host</label>${escapeHtml(server.host || '—')}</div>
        ${server.is_self
          ? '<div class="field-row"><label>SSH</label><span class="muted">' + T('js.g.ssh_self') + '</span></div>'
          : `<div class="field-row"><label>SSH</label>${escapeHtml(server.ssh_user)}@${escapeHtml(server.host || '?')}:${escapeHtml(String(server.ssh_port))} ${server.has_ssh_key ? '🔑' : '<span class="muted">' + T('js.g.no_key') + '</span>'}</div>`}
        ${result && result.hostname !== undefined ? `<div class="field-row"><label>hostname</label>${escapeHtml(result.hostname || '—')}</div>
        <div class="field-row"><label>OS / kernel</label>${escapeHtml(result.os || '—')} / ${escapeHtml(result.kernel || '—')}</div>
        <div class="field-row"><label>uptime</label>${escapeHtml(result.uptime || '—')}</div>
        ${result.ipv4 && result.ipv4.length ? `<div class="field-row"><label>IPv4</label>${result.ipv4.map(escapeHtml).join('<br>')}</div>` : ''}
        ${server.is_self ? '' : `<div class="field-row"><label>sudo</label>${result.sudo ? T('common.yes') : T('common.no')}</div>`}` : ''}
        <div class="row" style="margin-top:12px">
          <button id="btn-test-conn" ${server.has_ssh_key || server.is_self ? '' : 'disabled title="' + T('js.g.no_ssh_key_title') + '"'}>${server.is_self ? T('js.g.refresh_data') : T('js.g.test_conn')}</button>
          ${!server.is_self && server.host ? `<button class="secondary" id="btn-bootstrap-ssh">${server.has_ssh_key ? T('js.g.recreate_key') : T('js.g.login_by_pw')}</button>` : ''}
          <button class="secondary" id="btn-edit-server">${T('js.g.edit')}</button>
          ${server.is_self ? '' : '<button class="danger" id="btn-delete-server">' + T('js.g.del') + '</button>'}
        </div>
        <div id="bootstrap-ssh-box"></div>
        ${renderQuotaBlock(server)}
        ${server.has_ssh_key || server.is_self ? '<div id="metrics-result"><p class="muted">' + T('js.g.loading_metrics') + '</p></div>' : ''}
        ${server.has_ssh_key || server.is_self ? '<div id="pkg-box" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)"></div>' : ''}
        ${server.host ? `
          <div class="row" style="margin-top:8px">
            <button class="secondary" id="btn-risk-scan">${T('js.g.risk_scan')}</button>
            <button class="secondary" id="btn-diagnostics">${T('js.g.diag')}</button>
            ${server.has_ssh_key ? '<button class="secondary" id="btn-portknock">' + T('js.g.hide_ssh') + '</button>' : ''}
            ${server.has_ssh_key ? `<button class="secondary" id="btn-portscan-ban">${T('js.g.portscan_ban')}${server.portscan_ban_enabled ? T('js.g.enabled_suffix') : ''}</button>` : ''}
          </div>
          <div id="risk-scan-result"></div>
          <div id="diagnostics-result"></div>
        ` : ''}
        ${server.is_self ? `
          <div class="row" style="margin-top:8px">
            <button class="secondary" id="btn-provision-rf">${T('js.g.install_rf')}</button>
          </div>
          <div id="rf-provision-result"></div>
        ` : ''}
        ${server.role === 'router' ? `
          <div class="router-actions" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
            <div class="muted" style="margin-bottom:6px">${T('js.g.router_manage')}${server.device_count !== undefined ? ` · ${T('js.g.router_devices')}: ${server.device_count} · ${T('js.g.router_routes')}: ${server.route_count}` : ''}</div>
            <div class="row">
              <a class="btn secondary" href="/groups.php?router=${server.id}">${T('js.g.router_routes')}</a>
              <a class="btn secondary" href="/devices.php?router=${server.id}">${T('js.g.router_devices')}</a>
              <button type="button" class="secondary" id="btn-router-config">${T('js.g.router_settings')}</button>
              <button id="btn-apply-router">${T('js.g.router_apply')}</button>
            </div>
            <div id="apply-router-result"></div>
          </div>
        ` : ''}
        <div id="test-conn-result"></div>
      `;
      const applyRouterBtn = body.querySelector('#btn-apply-router');
      if (applyRouterBtn) {
        applyRouterBtn.addEventListener('click', async () => {
          const box = body.querySelector('#apply-router-result');
          applyRouterBtn.disabled = true;
          box.innerHTML = '<p class="muted">' + T('js.g.applying') + '</p>';
          try {
            const r = await apiCall('/api/infrastructure.php?action=apply', 'POST', { router_id: server.id, description: 'Apply роутера из графа' });
            const ok = (r.result.exit_code === 0);
            box.innerHTML = `<div class="flash ${ok ? 'success' : 'error'}">${ok ? '✓ ' + T('js.g.applied_ok') : escapeHtml(r.result.stderr || r.result.stdout || 'error')}</div>`;
            toast(ok ? T('js.g.applied_ok') : T('js.g.apply_error', ''), ok ? 'success' : 'error');
          } catch (e) {
            box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
          } finally {
            applyRouterBtn.disabled = false;
          }
        });
      }
      const testBtn = body.querySelector('#btn-test-conn');
      if (testBtn) {
        testBtn.addEventListener('click', async () => {
          testBtn.disabled = true;
          testBtn.textContent = T('js.g.checking');
          const progress = server.is_self ? null : showProgress(T('js.g.prog_test_title'), 25, [T('js.g.prog_ssh'), T('js.g.prog_kex'), T('js.g.prog_scan_ru'), T('js.g.prog_scan_abroad'), T('js.g.prog_analyze')]);
          try {
            const r = await api.testConnection(server.id);
            if (progress) progress.done();
            const box = document.getElementById('test-conn-result');
            if (r.ok) {
              box.innerHTML = `<div class="flash success">✓ SSH connection successful<br>Hostname: ${escapeHtml(r.hostname)}<br>OS: ${escapeHtml(r.os)}<br>Kernel: ${escapeHtml(r.kernel)}<br>Uptime: ${escapeHtml(r.uptime)}</div>`;
            } else {
              box.innerHTML = renderSshDiagnosis(r);
              wireUseSshPort(box, async (port) => { await api.updateServer(server.id, { ...editableServerFields(server), ssh_port: port }); toast(T('js.g.ssh_port_changed', port), 'success'); await loadGraph(); });
            }
            await loadGraph();
          } catch (e) {
            if (progress) progress.done();
            toast(e.message, 'error');
          } finally {
            testBtn.disabled = false;
            testBtn.textContent = server.is_self ? T('js.g.refresh_data') : T('js.g.test_conn');
          }
        });
      }
      const bootstrapBtn = body.querySelector('#btn-bootstrap-ssh');
      if (bootstrapBtn) {
        bootstrapBtn.addEventListener('click', () => {
          const box = document.getElementById('bootstrap-ssh-box');
          box.innerHTML = `
            <form id="bootstrap-ssh-form" class="field-row" style="margin-top:10px">
              <label>${T('js.g.pw_prompt', escapeHtml(server.ssh_user), escapeHtml(server.host))}</label>
              <div class="row">
                <input type="password" name="password" autocomplete="off" required style="flex:1">
                <button type="submit">${T('js.g.create_key_connect')}</button>
              </div>
            </form>`;
          const form = box.querySelector('#bootstrap-ssh-form');
          form.querySelector('input').focus();
          form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button');
            btn.disabled = true; btn.textContent = T('js.g.connecting');
            const progress = showProgress(T('js.g.prog_ssh_setup'), 15, [T('js.g.prog_login_pw'), T('js.g.prog_gen_key'), T('js.g.prog_write_key'), T('js.g.prog_verify_key')]);
            try {
              const r = await api.bootstrapSsh(server.id, form.password.value).finally(() => progress.done());
              if (r.ok) {
                toast(T('js.g.key_installed'), 'success');
                await loadGraph();
              } else {
                box.innerHTML = renderSshDiagnosis(r);
                wireUseSshPort(box, async (port) => { await api.updateServer(server.id, { ...editableServerFields(server), ssh_port: port }); toast(T('js.g.ssh_port_changed_relogin', port), 'success'); await loadGraph(); });
              }
            } catch (err) {
              box.innerHTML = `<div class="flash error">${escapeHtml(err.message)}</div>`;
            }
          });
        });
      }
      body.querySelector('#btn-edit-server').addEventListener('click', () => openEditServerModal(server));
      const syncForm = body.querySelector('#traffic-sync-form');
      if (syncForm) {
        syncForm.addEventListener('submit', async (e) => {
          e.preventDefault();
          try {
            await apiCall(`/api/servers.php?action=traffic-sync&id=${server.id}`, 'POST', { hoster_gb: syncForm.hoster_gb.value });
            toast(T('js.g.hoster_synced'), 'success');
            await loadGraph();
          } catch (err) { toast(err.message, 'error'); }
        });
      }
      const metricsBox = body.querySelector('#metrics-result');
      if (metricsBox) {
        const renderMetrics = (m) => {
          const bar = (percent, cls) => `<div style="background:rgba(255,255,255,.08);border-radius:4px;height:6px;margin-top:4px;overflow:hidden"><div style="width:${Math.min(100, percent)}%;height:100%;background:var(${cls})"></div></div>`;
          const pctCls = (v) => v >= 90 ? '--danger' : v >= 70 ? '--warning' : '--success';
          metricsBox.innerHTML = `
            ${m.load ? `<div class="field-row"><label>CPU${m.cpus ? ` (${T('js.g.cores', m.cpus)})` : ''} — ${T('js.g.load_1_5_15')}</label>${m.cpu_percent != null ? m.cpu_percent + '% · ' : ''}${m.load['1min']} / ${m.load['5min']} / ${m.load['15min']}${bar(m.cpu_percent != null ? m.cpu_percent : m.load['1min'] * 25, pctCls(m.cpu_percent != null ? m.cpu_percent : m.load['1min'] * 25))}</div>` : ''}
            ${m.mem ? `<div class="field-row"><label>RAM</label>${m.mem.used_mb} / ${m.mem.total_mb} ${T('js.g.u_mb')} (${m.mem.used_percent}%)${bar(m.mem.used_percent, pctCls(m.mem.used_percent))}</div>` : ''}
            ${m.disk ? `<div class="field-row"><label>${T('js.g.disk')}</label>${(m.disk.used_kb / 1048576).toFixed(1)} / ${(m.disk.total_kb / 1048576).toFixed(1)} ${T('js.g.u_gb')} (${m.disk.used_percent}%)${bar(m.disk.used_percent, pctCls(m.disk.used_percent))}</div>` : ''}
          `;
        };
        const cached = serverMetrics(server);
        if (cached) renderMetrics(cached);
        api.metrics(server.id).then((m) => {
          if (!m.ok) {
            if (!cached) metricsBox.innerHTML = `<p class="muted">${T('js.g.metrics_na', escapeHtml(m.raw_error || T('js.g.no_data')))}</p>`;
            return;
          }
          renderMetrics(m);
          // Обновляем узел на графе свежей нагрузкой без полной перезагрузки.
          const s = state.servers.find((x) => x.id === server.id);
          if (s && cy) {
            let r = {};
            try { r = JSON.parse(s.last_check_result || '{}') || {}; } catch (e) { r = {}; }
            r.metrics = m;
            s.last_check_result = JSON.stringify(r);
            const node = cy.getElementById('s' + s.id);
            if (node) node.data({ label: nodeLabel(s), svg: serverNodeSvg(s) });
          }
        }).catch((e) => {
          if (!cached) metricsBox.innerHTML = `<p class="muted">${T('js.g.metrics_na', escapeHtml(e.message))}</p>`;
        });
      }
      if (body.querySelector('#pkg-box')) loadPkgStatus(server);
      const cfgBtn = body.querySelector('#btn-router-config');
      if (cfgBtn) {
        cfgBtn.addEventListener('click', () => {
          // Если открыт полноэкранный инспектор — переключаем вкладку «Конфигурация»;
          // иначе открываем его сразу на этой вкладке.
          const navCfg = document.querySelector('.detail-nav button[data-tab="config"]');
          if (navCfg) { navCfg.click(); } else { activeInspectorTab = 'config'; openServerDetail(server); }
        });
      }
      const riskBtn = body.querySelector('#btn-risk-scan');
      if (riskBtn) {
        riskBtn.addEventListener('click', async () => {
          riskBtn.disabled = true;
          riskBtn.textContent = T('js.g.scanning');
          const box = document.getElementById('risk-scan-result');
          box.innerHTML = '<p class="muted">' + T('js.g.risk_running') + '</p>';
          try {
            const r = await api.riskScan(server.id);
            const scoreCls = r.score >= 60 ? 'down' : r.score >= 25 ? 'warn' : 'ok';
            const findingsHtml = r.findings.map((f) => {
              const cls = f.level === 'error' ? 'error' : f.level === 'warning' ? '' : '';
              return `<li class="${f.level === 'error' ? 'flash error' : f.level === 'warning' ? 'flash' : 'muted'}" style="margin-bottom:6px;list-style:none">${escapeHtml(f.message)}</li>`;
            }).join('');
            box.innerHTML = `
              <div class="field-row"><label>${T('js.g.risk_label')}</label><span class="badge ${scoreCls}">${r.score}%</span></div>
              <ul style="padding:0;margin:8px 0 0">${findingsHtml}</ul>
            `;
          } catch (e) {
            box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
          } finally {
            riskBtn.disabled = false;
            riskBtn.textContent = T('js.g.risk_scan');
          }
        });
        // Показываем последний результат (в т.ч. автоматический, из cron bin/risk_scan.php) сразу,
        // не дожидаясь ручного клика — раньше находки нигде не сохранялись между прогонами.
        api.riskScanHistory(server.id).then((history) => {
          if (!history.length) return;
          const last = history[0];
          const scoreCls = last.score >= 60 ? 'down' : last.score >= 25 ? 'warn' : 'ok';
          const box = document.getElementById('risk-scan-result');
          const when = new Date(last.scanned_at.replace(' ', 'T') + 'Z').toLocaleString('ru-RU');
          box.innerHTML = `
            <div class="field-row"><label>${T('js.g.risk_hist', last.triggered_by === 'cron' ? T('js.g.risk_auto') : T('js.g.risk_manual'), escapeHtml(when))}</label><span class="badge ${scoreCls}">${last.score}%</span></div>
            ${history.length > 1 ? `<p class="muted" style="margin:6px 0 0">${T('js.g.history', history.slice(0, 10).map((h) => h.score + '%').join(' → '))}</p>` : ''}
          `;
        }).catch(() => {});
      }

      const diagBtn = body.querySelector('#btn-diagnostics');
      if (diagBtn) {
        diagBtn.addEventListener('click', async () => {
          diagBtn.disabled = true;
          diagBtn.textContent = T('js.g.diag_btn_running');
          const box = document.getElementById('diagnostics-result');
          box.innerHTML = '<p class="muted">' + T('js.g.diag_note') + '</p>';
          try {
            const r = await api.diagnostics(server.id, 443);
            const okBadge = (ok) => `<span class="badge ${ok ? 'ok' : 'down'}">${ok ? 'OK' : T('js.g.error_word')}</span>`;
            const externalRows = r.external.available
              ? r.external.nodes.map((n) => `<tr><td>${escapeHtml(n.country || n.node)}</td><td>${okBadge(n.ok)}</td><td class="muted">${escapeHtml(n.note)}</td></tr>`).join('')
              : `<tr><td colspan="3" class="muted">${escapeHtml(r.external.error || T('js.g.unavailable'))}</td></tr>`;
            box.innerHTML = `
              <div class="field-row"><label>DNS</label>${okBadge(r.dns.resolved)} ${r.dns.address ? escapeHtml(r.dns.address) : ''}</div>
              <div class="field-row"><label>${T('js.g.diag_local')}</label>${okBadge(r.local.reachable)} ${r.local.reachable ? r.local.latency_ms + ' ' + T('js.g.ms') : escapeHtml(r.local.error || '')}</div>
              ${r.tls ? `<div class="field-row"><label>${T('js.g.diag_tls')}</label>${okBadge(r.tls.available && !r.tls.expired)} ${r.tls.subject_cn ? escapeHtml(r.tls.subject_cn) : escapeHtml(r.tls.error || '')}</div>` : ''}
              <div class="field-row"><label>${T('js.g.diag_external')}</label>
                <table><thead><tr><th>${T('js.g.col_node')}</th><th>${T('js.g.status')}</th><th></th></tr></thead><tbody>${externalRows}</tbody></table>
              </div>
              <p class="muted">${T('js.g.diag_disclaimer')}</p>
              <button class="secondary" id="btn-export-diag" type="button">${T('js.g.export_json')}</button>
            `;
            document.getElementById('btn-export-diag').addEventListener('click', () => {
              const blob = new Blob([JSON.stringify(r, null, 2)], { type: 'application/json' });
              const a = document.createElement('a');
              a.href = URL.createObjectURL(blob);
              a.download = `diagnostics-${server.name.replace(/[^a-z0-9]+/gi, '-')}-${Date.now()}.json`;
              a.click();
              URL.revokeObjectURL(a.href);
            });
          } catch (e) {
            box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
          } finally {
            diagBtn.disabled = false;
            diagBtn.textContent = T('js.g.diag');
          }
        });
      }
      const knockBtn = body.querySelector('#btn-portknock');
      if (knockBtn) {
        knockBtn.addEventListener('click', () => openPortKnockModal(server));
      }
      const scanBanBtn = body.querySelector('#btn-portscan-ban');
      if (scanBanBtn) {
        scanBanBtn.addEventListener('click', () => openPortscanBanModal(server));
      }
      const provisionRfBtn = body.querySelector('#btn-provision-rf');
      if (provisionRfBtn) {
        provisionRfBtn.addEventListener('click', async () => {
          if (!(await confirmDialog(T('js.g.provision_rf_confirm', escapeHtml(server.name), escapeHtml(server.host || T('js.g.this_server'))), T('js.g.install')))) return;
          provisionRfBtn.disabled = true;
          provisionRfBtn.textContent = T('js.g.installing_rf');
          const box = document.getElementById('rf-provision-result');
          try {
            const r = await api.provisionServer(server.id);
            box.innerHTML = `<div class="flash ${r.ok ? 'success' : 'error'}"><pre>${escapeHtml((r.stdout || '') + '\n' + (r.stderr || ''))}</pre></div>`;
            toast(r.ok ? T('js.g.install_done') : T('js.g.install_fail'), r.ok ? 'success' : 'error');
          } catch (e) {
            toast(e.message, 'error');
          } finally {
            provisionRfBtn.disabled = false;
            provisionRfBtn.textContent = T('js.g.install_rf');
          }
        });
      }
      const delBtn = body.querySelector('#btn-delete-server');
      if (delBtn) {
        delBtn.addEventListener('click', async () => {
          if (!(await confirmDialog(T('js.g.del_server_confirm', escapeHtml(server.name)), T('js.g.del')))) return;
          try {
            await api.deleteServer(server.id);
            markPending(1);
            toast(T('js.g.server_deleted'), 'success');
            closeInspector();
            await loadGraph();
          } catch (e) {
            toast(e.message, 'error');
          }
        });
      }
    } else if (tab === 'connections') {
      const conns = state.connections.filter((c) => c.source_server_id === server.id || c.target_server_id === server.id);
      body.innerHTML = conns.length
        ? '<table><tr><th>' + T('js.g.col_type') + '</th><th>' + T('js.g.col_direction') + '</th><th></th></tr>' + conns.map((c) => `
            <tr>
              <td>${escapeHtml(c.type)}</td>
              <td class="muted">${escapeHtml(c.source_name)} → ${escapeHtml(c.target_name)}</td>
              <td><button class="danger" data-del-conn="${c.id}">${T('js.g.del_lc')}</button></td>
            </tr>
          `).join('') + '</table>'
        : '<p class="empty-state">' + T('js.g.no_conns') + '</p>';
      body.querySelectorAll('[data-del-conn]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!(await confirmDialog(T('js.g.del_conn'), T('js.g.del')))) return;
          await api.deleteConnection(Number(btn.dataset.delConn));
          markPending(1);
          toast(T('js.g.conn_deleted'), 'success');
          await loadGraph();
          renderInspectorTab(server, 'connections', body);
        });
      });
    } else if (tab === 'sets') {
      const memberOf = state.server_sets.filter((set) => set.members.some((m) => m.server_id === server.id));
      body.innerHTML = `
        <div class="field-row"><label>${T('js.g.server_sets')}</label></div>
        ${state.server_sets.map((set) => {
          const checked = set.members.some((m) => m.server_id === server.id);
          return `<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <input type="checkbox" data-set-toggle="${set.id}" ${checked ? 'checked' : ''}> ${escapeHtml(set.name)}
            <span class="muted">(${escapeHtml(set.strategy)})</span>
          </label>`;
        }).join('') || '<p class="muted">' + T('js.g.no_sets') + '</p>'}
        <button class="secondary" id="btn-new-set">${T('js.g.new_set')}</button>
      `;
      body.querySelectorAll('[data-set-toggle]').forEach((cb) => {
        cb.addEventListener('change', async () => {
          const setId = Number(cb.dataset.setToggle);
          try {
            await api.setMember(setId, server.id, cb.checked ? 'add' : 'remove', 0);
            markPending(1);
            toast(cb.checked ? T('js.g.set_added') : T('js.g.set_removed'), 'success');
            await loadGraph();
          } catch (e) {
            toast(e.message, 'error');
            cb.checked = !cb.checked;
          }
        });
      });
      body.querySelector('#btn-new-set').addEventListener('click', openServerSetsModal);
    } else if (tab === 'advanced') {
      // Связь с маскировкой Reality (заглушка) — vless-связь, ведущая в этот узел.
      const camoConn = state.connections.find((c) => c.target_server_id === server.id && c.type === 'vless');
      body.innerHTML = `
        <p class="muted">${T('js.g.adv_note')}</p>
        ${camoConn ? `
          <div class="field-row" style="flex-direction:column;align-items:stretch">
            <label style="font-weight:600">${T('js.g.camo_title')}</label>
            <p class="muted" style="font-size:12px;margin:2px 0 8px">${T('js.g.camo_server_hint')}</p>
            <button class="secondary" id="btn-open-camo">${T('js.g.camo_open')}</button>
          </div>
          <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
        ` : ''}
        ${server.is_self ? '' : `
          <div class="field-row" style="flex-direction:column;align-items:stretch">
            <label style="font-weight:600">${T('js.g.reboot_title')}</label>
            <p class="muted" style="font-size:12px;margin:2px 0 8px">${T('js.g.reboot_desc')}</p>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input type="checkbox" id="rb-enabled" style="width:auto"> ${T('js.g.reboot_enable')}</label>
            <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
              <select id="rb-freq"><option value="daily">${T('js.g.reboot_daily')}</option><option value="weekly">${T('js.g.reboot_weekly')}</option></select>
              <select id="rb-dow">${[0,1,2,3,4,5,6].map((d) => `<option value="${d}">${T('js.g.dow_' + d)}</option>`).join('')}</select>
              <input type="time" id="rb-time" value="04:00" style="width:auto">
              <span class="muted" style="font-size:12px">UTC</span>
              <button class="secondary" id="rb-save">${T('js.g.reboot_save')}</button>
            </div>
            <div id="rb-status" class="muted" style="font-size:12px;margin-top:6px"></div>
          </div>
          <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
        `}
        <div class="field-row"><label>${T('js.g.raw_record')}</label>
        <textarea readonly>${escapeHtml(JSON.stringify(server, null, 2))}</textarea></div>
      `;
      if (!server.is_self) wireRebootSchedule(server);
      const openCamoBtn = body.querySelector('#btn-open-camo');
      if (openCamoBtn && camoConn) {
        openCamoBtn.addEventListener('click', () => openCamouflageWizard(camoConn.id));
      }
    } else if (tab === 'incidents') {
      renderIncidentsTab(server, body);
    } else if (tab === 'probe') {
      renderProbeTab(server, body);
    } else if (tab === 'wgmon') {
      renderWgMonTab(server, body);
    } else if (tab === 'config') {
      renderConfigTab(server, body);
    } else if (tab === 'tgproxy') {
      renderTgProxyTab(server, body);
    }
  }

  // Вкладка «Telegram-прокси»: MTProto (mtg) и/или SOCKS5 на этом узле.
  async function renderTgProxyTab(server, body) {
    body.innerHTML = `<p class="muted">${T('js.g.tg_desc')}</p><div id="tg-box"><span class="muted">${T('js.g.inc_loading')}</span></div>`;
    const box = body.querySelector('#tg-box');
    let d;
    try { d = await api.tgProxy(server.id); } catch (e) { box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`; return; }
    if (!d || !d.ok && !d.conf) { box.innerHTML = `<div class="flash error">${escapeHtml((d && d.error) || T('js.g.no_data'))}</div>`; return; }
    const c = d.conf || {};
    const linkField = (uri) => uri ? `<input type="text" readonly value="${escapeHtml(uri)}" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;margin-top:6px">` : '';
    const badge = (on) => on ? `<span class="badge ok">${T('js.g.tg_on')}</span>` : `<span class="badge unknown">${T('js.g.tg_off')}</span>`;
    box.innerHTML = `
      <div class="card" style="margin-bottom:12px">
        <div class="row" style="justify-content:space-between;align-items:center"><strong>MTProto (mtg)</strong> ${badge(d.mtg_active)}</div>
        <div class="field-row"><label>${T('js.g.tg_port')}</label><input id="tg-mtg-port" type="number" value="${(c.mtg_port || 8443)}" style="width:110px"></div>
        <div class="field-row"><label>${T('js.g.tg_domain')}</label><input id="tg-mtg-domain" value="${escapeHtml(c.mtg_domain || 'www.cloudflare.com')}" placeholder="www.cloudflare.com"></div>
        <div class="row" style="gap:8px;margin-top:8px">
          <button id="tg-mtg-on">${T('js.g.tg_enable')}</button>
          <button class="secondary" id="tg-mtg-off">${T('js.g.tg_disable')}</button>
          <button class="secondary" id="tg-mtg-regen">${T('js.g.tg_regen')}</button>
        </div>
        ${linkField(d.mtg_link)}
        ${d.mtg_link ? `<div class="tg-qr" data-uri="${escapeHtml(d.mtg_link)}" style="background:#fff;padding:8px;border-radius:8px;display:inline-block;margin-top:8px"></div>` : ''}
      </div>
      <div class="card">
        <div class="row" style="justify-content:space-between;align-items:center"><strong>SOCKS5</strong> ${badge(d.socks_active)}</div>
        ${d.singbox ? '' : `<p class="muted" style="font-size:12.5px;color:var(--warning)">${T('js.g.tg_no_singbox')}</p>`}
        <div class="field-row"><label>${T('js.g.tg_port')}</label><input id="tg-s-port" type="number" value="${(c.socks_port || 1080)}" style="width:110px"></div>
        <div class="field-row"><label>${T('js.g.tg_user')}</label><input id="tg-s-user" value="${escapeHtml(c.socks_user || 'tg')}" style="width:160px"></div>
        <div class="field-row"><label>${T('js.g.tg_pass')}</label><input id="tg-s-pass" value="${escapeHtml(c.socks_pass || '')}" placeholder="${T('js.g.tg_pass_auto')}" style="width:200px"></div>
        <div class="row" style="gap:8px;margin-top:8px">
          <button id="tg-s-on" ${d.singbox ? '' : 'disabled'}>${T('js.g.tg_enable')}</button>
          <button class="secondary" id="tg-s-off">${T('js.g.tg_disable')}</button>
        </div>
        ${linkField(d.socks_link)}
      </div>`;
    const act = async (data, btn) => {
      if (btn) { btn.disabled = true; }
      try { const r = await api.tgProxyAction(server.id, data); if (!r.ok && r.error) toast(r.error, 'error'); else toast(T('js.g.saved'), 'success'); } catch (e) { toast(e.message, 'error'); }
      renderTgProxyTab(server, body);
    };
    box.querySelector('#tg-mtg-on').addEventListener('click', (e) => act({ action: 'mtg_enable', port: +box.querySelector('#tg-mtg-port').value, domain: box.querySelector('#tg-mtg-domain').value }, e.target));
    box.querySelector('#tg-mtg-off').addEventListener('click', (e) => act({ action: 'mtg_disable' }, e.target));
    box.querySelector('#tg-mtg-regen').addEventListener('click', (e) => act({ action: 'regen' }, e.target));
    box.querySelector('#tg-s-on').addEventListener('click', (e) => act({ action: 'socks_enable', port: +box.querySelector('#tg-s-port').value, user: box.querySelector('#tg-s-user').value, pass: box.querySelector('#tg-s-pass').value }, e.target));
    box.querySelector('#tg-s-off').addEventListener('click', (e) => act({ action: 'socks_disable' }, e.target));
    // QR для mtg-ссылки
    var qr = box.querySelector('.tg-qr');
    if (qr && window.qrcode) { try { var q = qrcode(0, 'M'); q.addData(qr.dataset.uri); q.make(); qr.innerHTML = q.createImgTag(4, 6); } catch (e) { /* ignore */ } }
  }

  // Вкладка «Конфигурация»: per-router настройки (Reality/протоколы/блокировки)
  // этого сервера, встроенные из settings.php в embed-режиме. Единый источник —
  // те же формы и обработчики, без дублирования логики в JS.
  function renderConfigTab(server, body) {
    const src = '/settings.php?embed=1&router=' + encodeURIComponent(server.id);
    body.innerHTML = `<iframe src="${src}" title="${escapeHtml(T('js.g.tab_config'))}"
      style="width:100%;height:calc(100vh - 160px);min-height:420px;border:0;border-radius:10px;background:var(--bg)"></iframe>`;
  }

  // Вкладка «WG-вход»: пиры WireGuard/AmneziaWG на входном узле и их last-handshake —
  // чтобы видеть, не режется ли вход под Keenetic (plain WG блокируется быстро).
  async function renderWgMonTab(server, body) {
    body.innerHTML = `<p class="muted">${T('js.g.wg_desc')}</p><div id="wg-box"><span class="muted">${T('js.g.inc_loading')}</span></div>`;
    const box = body.querySelector('#wg-box');
    let d;
    try { d = await api.wgStatus(); } catch (e) { box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`; return; }
    const peers = d.peers || [];
    const ago = (s) => {
      if (s == null) return T('js.g.wg_never');
      if (s < 60) return s + T('js.g.inc_sec');
      if (s < 3600) return Math.round(s / 60) + T('js.g.inc_min');
      return (s / 3600).toFixed(1) + T('js.g.inc_hour');
    };
    const badge = (st) => st === 'fresh' ? `<span class="badge ok">${T('js.g.wg_fresh')}</span>` : (st === 'stale' ? `<span class="badge warn">${T('js.g.wg_stale')}</span>` : `<span class="badge unknown">${T('js.g.wg_never')}</span>`);
    box.innerHTML = `
      <div class="muted" style="font-size:13px;margin-bottom:8px">${T('js.g.wg_summary', d.fresh || 0, d.total || 0)}</div>
      ${peers.length ? `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="text-align:left;color:var(--text-muted)"><th style="padding:4px 6px">${T('js.g.wg_peer')}</th><th style="padding:4px 6px">${T('js.g.wg_endpoint')}</th><th style="padding:4px 6px">${T('js.g.wg_last')}</th><th style="padding:4px 6px"></th></tr></thead>
        <tbody>${peers.map((p) => `<tr style="border-top:1px solid var(--border)">
          <td style="padding:4px 6px;font-family:ui-monospace,monospace" title="${escapeHtml(p.pubkey)}">${escapeHtml(p.iface)} · ${escapeHtml(p.pubkey.slice(0, 12))}…</td>
          <td style="padding:4px 6px;font-family:ui-monospace,monospace">${escapeHtml(p.endpoint || '—')}</td>
          <td style="padding:4px 6px" class="muted">${escapeHtml(ago(p.ago))}</td>
          <td style="padding:4px 6px">${badge(p.status)}</td></tr>`).join('')}</tbody>
      </table></div>` : `<p class="muted">${T('js.g.wg_none')}</p>`}`;
  }

  // Блок «Система: обновления ОС-пакетов» в обзоре узла. Долгий upgrade идёт
  // в фоне на самом сервере (detached) — здесь только запуск, счётчик и журнал.
  async function loadPkgStatus(server) {
    const box = document.getElementById('pkg-box');
    if (!box) return;
    box.innerHTML = `<div class="field-row"><label>${T('js.g.pkg_title')}</label><span class="muted">${T('js.g.pkg_checking')}</span></div>`;
    let st;
    try { st = await api.pkgStatus(server.id); } catch (e) { box.innerHTML = `<div class="field-row"><label>${T('js.g.pkg_title')}</label><span class="muted">${escapeHtml(e.message)}</span></div>`; return; }
    if (!st || !st.ok) { box.innerHTML = `<div class="field-row"><label>${T('js.g.pkg_title')}</label><span class="muted">${escapeHtml((st && st.error) || T('js.g.no_data'))}</span></div>`; return; }
    const logBtn = `<button class="secondary" id="btn-pkg-log">${T('js.g.pkg_log')}</button>`;
    if (st.running) {
      box.innerHTML = `<div class="field-row"><label>${T('js.g.pkg_title')}</label><span class="badge warn">${T('js.g.pkg_running')}</span></div><div class="row" style="margin-top:6px">${logBtn}</div>`;
    } else if (st.count > 0) {
      const sec = st.security > 0 ? ` · <span class="badge warn">${T('js.g.pkg_security', st.security)}</span>` : '';
      box.innerHTML = `
        <div class="field-row"><label>${T('js.g.pkg_title')}</label>${T('js.g.pkg_available', st.count)} <span class="muted">(${escapeHtml(st.manager)})</span>${sec}</div>
        <div class="row" style="margin-top:6px"><button id="btn-pkg-upgrade">${T('js.g.pkg_upgrade')}</button>${logBtn}</div>`;
    } else {
      box.innerHTML = `<div class="field-row"><label>${T('js.g.pkg_title')}</label><span class="badge ok">${T('js.g.pkg_uptodate')}</span> <span class="muted">(${escapeHtml(st.manager)})</span></div>`;
    }
    const up = box.querySelector('#btn-pkg-upgrade');
    if (up) {
      up.addEventListener('click', async () => {
        if (!(await confirmDialog(T('js.g.pkg_confirm'), T('js.g.pkg_upgrade')))) return;
        up.disabled = true;
        try {
          const r = await api.pkgUpgrade(server.id);
          if (!r.ok) { toast(r.error || T('js.g.no_data'), 'error'); up.disabled = false; return; }
          toast(T('js.g.pkg_started'), 'success');
          showPkgLog(server);
          loadPkgStatus(server);
        } catch (e) { toast(e.message, 'error'); up.disabled = false; }
      });
    }
    const lb = box.querySelector('#btn-pkg-log');
    if (lb) lb.addEventListener('click', () => showPkgLog(server));
  }

  // Модалка с хвостом журнала обновления; пока обновление идёт — авто-обновляется.
  function showPkgLog(server) {
    openModal(`<h2 style="margin:0 30px 12px 0">${T('js.g.pkg_log')}</h2><pre id="pkg-log-pre" style="max-height:50vh;overflow:auto;white-space:pre-wrap;font-size:12px;background:var(--surface);padding:10px;border-radius:8px;border:1px solid var(--border)">${T('js.g.pkg_checking')}</pre>`, true);
    let stop = false;
    const tick = async () => {
      const pre = document.getElementById('pkg-log-pre');
      if (!pre || stop) { stop = true; return; }
      try {
        const r = await api.pkgLog(server.id);
        pre.textContent = (r && r.log) ? r.log : T('js.g.no_data');
        pre.scrollTop = pre.scrollHeight;
        if (r && r.running) { setTimeout(tick, 4000); } else { loadPkgStatus(server); }
      } catch (e) { pre.textContent = e.message; }
    };
    tick();
  }

  // Вкладка «Активная защита»: доступность узла локально (вантедж панели) vs
  // извне (check-host) во времени — расхождение = ранний признак блокировки
  // Active Blocking System; плюс опциональный журнал входящих зондирований (кто стучится).
  const PROBE_VERDICT = {
    ok: { cls: 'ok', color: 'var(--success)' },
    rf_degraded: { cls: 'warn', color: 'var(--warning,#f5b942)' },
    rf_blocked: { cls: 'down', color: 'var(--danger)' },
    down: { cls: 'unknown', color: 'var(--text-muted)' },
    unknown: { cls: 'unknown', color: 'var(--text-muted)' },
  };
  async function renderProbeTab(server, body) {
    body.innerHTML = `<p class="muted">${T('js.g.probe_desc')}</p><div id="probe-box"><span class="muted">${T('js.g.inc_loading')}</span></div>`;
    const box = body.querySelector('#probe-box');
    let data;
    try { data = await api.probeTimeline(server.id); } catch (e) { box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`; return; }
    const tl = data.timeline || [];
    const cur = data.current;
    const logOn = data.log && Number(data.log.enabled);
    const logAllowed = !!data.logging_allowed;
    const vMeta = (v) => PROBE_VERDICT[v] || PROBE_VERDICT.unknown;
    const curVerdict = cur ? cur.verdict : 'unknown';
    const strip = tl.length
      ? tl.map((s) => `<span title="${escapeHtml(s.checked_at)} · ${escapeHtml(s.verdict)}" style="display:inline-block;width:6px;height:22px;margin:0 1px;border-radius:2px;background:${vMeta(s.verdict).color}"></span>`).join('')
      : `<span class="muted">${T('js.g.probe_no_data')}</span>`;
    box.innerHTML = `
      <div class="field-row" style="flex-direction:column;align-items:stretch">
        <div class="row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
          <div>${T('js.g.probe_verdict')}: <span class="badge ${vMeta(curVerdict).cls}">${T('js.g.probe_v_' + curVerdict)}</span></div>
          ${cur && cur.checked_at ? `<span class="muted" style="font-size:12px">${T('js.g.probe_last', escapeHtml(cur.checked_at))} UTC</span>` : ''}
        </div>
        <div style="overflow-x:auto;white-space:nowrap;margin-top:10px;padding:6px;background:var(--bg);border:1px solid var(--border);border-radius:8px">${strip}</div>
        <div class="muted" style="font-size:11.5px;margin-top:6px">
          <span style="color:var(--success)">■</span> ${T('js.g.probe_v_ok')} ·
          <span style="color:var(--warning,#f5b942)">■</span> ${T('js.g.probe_v_rf_degraded')} ·
          <span style="color:var(--danger)">■</span> ${T('js.g.probe_v_rf_blocked')} ·
          <span style="color:var(--text-muted)">■</span> ${T('js.g.probe_v_down')}/${T('js.g.probe_v_unknown')}
        </div>
      </div>
      <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
      <div id="antidpi-box"></div>
      <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
      <div class="field-row" style="flex-direction:column;align-items:stretch">
        <label style="font-weight:600">${T('js.g.probe_log_title')}</label>
        <p class="muted" style="font-size:12px;margin:2px 0 8px">${T('js.g.probe_log_hint')}</p>
        <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
          ${!logAllowed
            ? `<span class="badge unknown">${T('js.g.probe_log_off')}</span><span class="muted" style="font-size:12px">${T('js.g.probe_log_blocked')}</span>`
            : (logOn
              ? `<span class="badge ok">${T('js.g.probe_log_on')}</span><button class="secondary" id="probe-log-toggle" data-on="1">${T('js.g.probe_log_disable')}</button><button class="secondary" id="probe-pull">${T('js.g.probe_pull')}</button>`
              : `<span class="badge unknown">${T('js.g.probe_log_off')}</span><button class="secondary" id="probe-log-toggle" data-on="0">${T('js.g.probe_log_enable')}</button>`)}
        </div>
        <div id="probe-log-result" class="muted" style="font-size:12px;margin-top:6px"></div>
      </div>
      <div id="probe-events" style="margin-top:12px"></div>`;

    if (logOn) loadProbeEvents(server, body.querySelector('#probe-events'));
    renderAntiDpiBlock(body.querySelector('#antidpi-box'));

    const toggle = body.querySelector('#probe-log-toggle');
    if (toggle) {
      toggle.addEventListener('click', async () => {
        const on = toggle.dataset.on === '1';
        const res = body.querySelector('#probe-log-result');
        if (!on && !(await confirmDialog(T('js.g.probe_log_confirm'), T('js.g.probe_log_enable')))) return;
        toggle.disabled = true; res.textContent = T('js.g.inc_loading');
        try {
          const r = on ? await api.probeDisableLog(server.id) : await api.probeEnableLog(server.id);
          res.textContent = r.ok ? '' : (r.error || T('js.g.probe_log_fail'));
          if (r.ok) renderProbeTab(server, body); // перерисовать состояние
        } catch (e) { res.textContent = e.message; } finally { toggle.disabled = false; }
      });
    }
    const pull = body.querySelector('#probe-pull');
    if (pull) {
      pull.addEventListener('click', async () => {
        pull.disabled = true;
        try { await api.probePullLog(server.id); loadProbeEvents(server, body.querySelector('#probe-events')); }
        catch (e) { toast(e.message, 'error'); } finally { pull.disabled = false; }
      });
    }
  }
  async function loadProbeEvents(server, el, offset) {
    offset = offset || 0;
    if (!offset) el.innerHTML = `<span class="muted">${T('js.g.inc_loading')}</span>`;
    let data;
    try { data = await api.probeEvents(server.id, offset); } catch (e) { el.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`; return; }
    const rows = data.rows || [];
    if (!offset && !rows.length) { el.innerHTML = `<p class="muted">${T('js.g.probe_no_events')}</p>`; return; }
    const rowHtml = (r) => `<tr style="border-top:1px solid var(--border)">
        <td style="padding:4px 6px;font-family:ui-monospace,monospace">${escapeHtml(r.src_ip)}${r.src_country ? ' <span class="muted">(' + escapeHtml(r.src_country) + ')</span>' : ''}</td>
        <td style="padding:4px 6px">${escapeHtml(String(r.dst_port || ''))}</td>
        <td style="padding:4px 6px">${escapeHtml(String(r.total))}</td>
        <td style="padding:4px 6px">${escapeHtml(String(r.seen_times))}</td>
        <td style="padding:4px 6px" class="muted">${escapeHtml(r.last_seen)}</td>
        <td style="padding:4px 6px">${Number(r.blocked)
          ? `<span class="badge down">${T('js.g.probe_blocked_badge')}</span> <button class="ghost icon-btn" data-unblock="${escapeHtml(r.src_ip)}" title="${T('js.g.probe_unblock')}">↩</button>`
          : `<button class="secondary" data-block="${escapeHtml(r.src_ip)}" style="padding:2px 8px;font-size:12px">${T('js.g.probe_block')}</button>`}</td>
      </tr>`;
    if (!offset) {
      el.innerHTML = `
        <label style="font-weight:600">${T('js.g.probe_events_title')}</label>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:13px">
          <thead><tr style="text-align:left;color:var(--text-muted)"><th style="padding:4px 6px">${T('js.g.probe_col_ip')}</th><th style="padding:4px 6px">${T('js.g.probe_col_port')}</th><th style="padding:4px 6px">${T('js.g.probe_col_hits')}</th><th style="padding:4px 6px">${T('js.g.probe_col_times')}</th><th style="padding:4px 6px">${T('js.g.probe_col_seen')}</th><th></th></tr></thead>
          <tbody id="probe-ev-body"></tbody>
        </table></div>
        <div id="probe-ev-more" style="margin-top:8px"></div>`;
    }
    const tb = el.querySelector('#probe-ev-body');
    tb.insertAdjacentHTML('beforeend', rows.map(rowHtml).join(''));
    const shown = offset + rows.length;
    const moreBox = el.querySelector('#probe-ev-more');
    moreBox.innerHTML = shown < data.total
      ? `<button class="secondary" id="probe-ev-more-btn">${T('js.g.probe_more')} (${shown}/${data.total})</button>`
      : '';
    const moreBtn = el.querySelector('#probe-ev-more-btn');
    if (moreBtn) moreBtn.addEventListener('click', () => loadProbeEvents(server, el, shown));
    // кнопки блокировки/разблокировки (делегирование — только на новых строках)
    tb.querySelectorAll('[data-block]:not([data-wired])').forEach((b) => {
      b.setAttribute('data-wired', '1');
      b.addEventListener('click', async () => {
        const ip = b.dataset.block;
        if (!(await confirmDialog(T('js.g.probe_block_confirm', ip), T('js.g.probe_block')))) return;
        b.disabled = true;
        try { const r = await api.probeBlockIp(server.id, ip); if (!r.ok) { toast(r.error || 'error', 'error'); b.disabled = false; return; } toast(T('js.g.probe_block') + ': ' + ip, 'success'); loadProbeEvents(server, el, 0); }
        catch (e) { toast(e.message, 'error'); b.disabled = false; }
      });
    });
    tb.querySelectorAll('[data-unblock]:not([data-wired])').forEach((b) => {
      b.setAttribute('data-wired', '1');
      b.addEventListener('click', async () => {
        b.disabled = true;
        try { await api.probeUnblockIp(server.id, b.dataset.unblock); toast(T('js.g.probe_unblock') + ': ' + b.dataset.unblock, 'success'); loadProbeEvents(server, el, 0); }
        catch (e) { toast(e.message, 'error'); b.disabled = false; }
      });
    });
  }

  // Контекстный блок «Обход блокировок» (анти-DPI) в инспекторе — редактирует те
  // же глобальные настройки, что и страница «Настройки» (один источник правды).
  // Ключи локализации берём из settings.antidpi.* (они есть в window.T).
  async function renderAntiDpiBlock(el) {
    if (!el) return;
    let s;
    try { s = await api.antidpiGet(); } catch (e) { el.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`; return; }
    const fps = ['chrome', 'firefox', 'edge', 'safari', 'ios', 'android', 'random', 'randomized'];
    el.innerHTML = `
      <label style="font-weight:600">${T('settings.antidpi.title')}</label>
      <p class="muted" style="font-size:12px;margin:2px 0 8px">${T('settings.antidpi.desc')}</p>
      <label style="display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer"><input type="checkbox" id="adpi-frag" ${s.tls_fragment ? 'checked' : ''} style="width:auto"> ${T('settings.antidpi.fragment')}</label>
      <label style="display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer;margin-top:6px"><input type="checkbox" id="adpi-rec" ${s.record_fragment ? 'checked' : ''} style="width:auto"> ${T('settings.antidpi.record_fragment')}</label>
      <div style="margin-top:8px"><label class="muted" style="font-size:13px">${T('settings.antidpi.fragment_delay')}</label><br><input id="adpi-delay" value="${escapeHtml(s.fragment_delay || '')}" placeholder="500ms" style="width:auto;margin-top:4px"></div>
      <div style="margin-top:8px"><label class="muted" style="font-size:13px">${T('settings.antidpi.fingerprint')}</label><br>
        <select id="adpi-fp" style="width:auto;margin-top:4px">${fps.map((f) => `<option value="${f}" ${s.utls_fingerprint === f ? 'selected' : ''}>${f}</option>`).join('')}</select></div>
      <div style="margin-top:10px"><button class="secondary" id="adpi-save">${T('common.save')}</button> <span id="adpi-res" class="muted" style="font-size:12px"></span></div>`;
    el.querySelector('#adpi-save').addEventListener('click', async () => {
      const btn = el.querySelector('#adpi-save'); const res = el.querySelector('#adpi-res');
      btn.disabled = true; res.textContent = '';
      try {
        await api.antidpiSave({
          tls_fragment: el.querySelector('#adpi-frag').checked,
          record_fragment: el.querySelector('#adpi-rec').checked,
          fragment_delay: el.querySelector('#adpi-delay').value.trim(),
          utls_fingerprint: el.querySelector('#adpi-fp').value,
        });
        res.textContent = T('settings.flash.antidpi_saved');
        toast(T('settings.flash.antidpi_saved'), 'success');
      } catch (e) { res.textContent = e.message; toast(e.message, 'error'); } finally { btn.disabled = false; }
    });
  }

  // Вкладка «Журнал простоев»: инциденты недоступности узла + логи за окно простоя
  // (их снимает health-check по SSH при восстановлении — см. App\ServerIncidents).
  async function renderIncidentsTab(server, body) {
    body.innerHTML = `<p class="muted">${T('js.g.inc_desc')}</p><div id="inc-list"><span class="muted">${T('js.g.inc_loading')}</span></div>`;
    const list = body.querySelector('#inc-list');
    let rows;
    try {
      rows = await api.serverIncidents(server.id);
    } catch (e) {
      list.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
      return;
    }
    if (!rows.length) {
      list.innerHTML = `<p class="muted">${T('js.g.inc_none')}</p>`;
      return;
    }
    const fmtDur = (a, b) => {
      if (!b) return T('js.g.inc_ongoing');
      const s = Math.max(0, Math.round((new Date(b.replace(' ', 'T') + 'Z') - new Date(a.replace(' ', 'T') + 'Z')) / 1000));
      if (s < 60) return s + T('js.g.inc_sec');
      if (s < 3600) return Math.round(s / 60) + T('js.g.inc_min');
      return (s / 3600).toFixed(1) + T('js.g.inc_hour');
    };
    list.innerHTML = rows.map((r) => `
      <div class="inc-item" data-inc="${r.id}" style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">
        <div class="row" style="justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div><strong>${escapeHtml(r.down_at)}</strong> <span class="muted">UTC · ${T('js.g.inc_down_for', fmtDur(r.down_at, r.up_at))}</span></div>
          <div>${r.up_at ? `<span class="badge ok">${T('js.g.inc_recovered')}</span>` : `<span class="badge down">${T('js.g.inc_ongoing')}</span>`}</div>
        </div>
        ${r.down_detail ? `<div class="muted" style="font-size:12px;margin-top:4px">${escapeHtml(r.down_detail)}</div>` : ''}
        ${Number(r.has_log) ? `<button class="secondary" data-inc-log="${r.id}" style="margin-top:8px">${T('js.g.inc_show_log')}</button><pre class="inc-log" id="inc-log-${r.id}" style="display:none;white-space:pre-wrap;word-break:break-word;max-height:360px;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;margin-top:8px;font-size:12px"></pre>` : `<div class="muted" style="font-size:12px;margin-top:6px">${T('js.g.inc_no_log')}</div>`}
      </div>`).join('');
    list.querySelectorAll('[data-inc-log]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const incId = btn.dataset.incLog;
        const pre = body.querySelector('#inc-log-' + incId);
        if (pre.style.display !== 'none') { pre.style.display = 'none'; return; }
        if (!pre.textContent) {
          btn.disabled = true;
          try {
            const r = await api.serverIncidentLog(incId);
            pre.textContent = r.log || T('js.g.inc_no_log');
          } catch (e) { pre.textContent = e.message; } finally { btn.disabled = false; }
        }
        pre.style.display = 'block';
      });
    });
  }

  async function wireRebootSchedule(server) {
    const en = document.getElementById('rb-enabled'), fr = document.getElementById('rb-freq'),
      dw = document.getElementById('rb-dow'), tm = document.getElementById('rb-time'),
      btn = document.getElementById('rb-save'), st = document.getElementById('rb-status');
    if (!en || !btn) return;
    const syncDow = () => { dw.style.display = fr.value === 'weekly' ? '' : 'none'; };
    fr.addEventListener('change', syncDow);
    try {
      const s = await api.rebootSchedule(server.id);
      en.checked = !!s.enabled; fr.value = s.freq || 'weekly'; dw.value = String(s.dow || 0); tm.value = s.time_utc || '04:00';
      if (s.last_run_at) st.textContent = T('js.g.reboot_last', s.last_run_at);
    } catch (e) { /* дефолты */ }
    syncDow();
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api.saveRebootSchedule({ server_id: server.id, enabled: en.checked, freq: fr.value, time_utc: tm.value, dow: Number(dw.value) });
        toast(T('js.g.reboot_saved'), 'success');
      } catch (e) { toast(e.message, 'error'); } finally { btn.disabled = false; }
    });
  }

  const PROVISION_STATUS_META = {
    not_provisioned: { label: T('js.g.prov_not'), cls: 'unknown' },
    provisioning: { label: T('js.g.prov_ing'), cls: 'warn' },
    provisioned: { label: T('js.g.prov_done'), cls: 'ok' },
    failed: { label: T('js.g.prov_fail'), cls: 'down' },
  };

  function openConnectionInspector(connId) {
    const conn = state.connections.find((c) => c.id === connId);
    if (!conn) return;
    selectedServerId = null;
    selectedConnectionId = connId;
    const inspector = document.getElementById('infra-inspector');
    inspector.classList.remove('hidden');
    const relatedRoutes = state.routes.filter((r) => conn.exit_server_id && r.resolved_exit_server_id === conn.exit_server_id);
    const isExecutable = (state.executable_connection_types || []).includes(conn.type);
    const provisionMeta = PROVISION_STATUS_META[conn.provision_status] || PROVISION_STATUS_META.not_provisioned;

    inspector.innerHTML = `
      <div class="inspector-header"><strong>Connection: ${escapeHtml(conn.source_name)} → ${escapeHtml(conn.target_name)}</strong><button class="secondary" data-close>✕</button></div>
      <div class="inspector-body">
        ${conn.exit_source === 'free' ? `<div class="flash error" style="margin-bottom:10px">${T('js.g.free_exit_warn')}</div>` : ''}
        <div class="field-row"><label>${T('js.g.type_label')}</label>${escapeHtml(conn.type)}${conn.exit_source === 'free' ? ` <span class="badge unknown">${T('js.g.free_badge')}${conn.exit_country ? ' ' + escapeHtml(conn.exit_country) : ''}</span>` : ''}</div>
        <div class="field-row"><label>Label</label>${escapeHtml(conn.label || '—')}</div>
        ${isExecutable ? `
          <div class="field-row"><label>${T('js.g.provisioning_label')}</label>
            <span class="badge ${provisionMeta.cls}">${provisionMeta.label}</span>
            ${conn.last_provision_at ? `<span class="muted"> · ${escapeHtml(conn.last_provision_at)}</span>` : ''}
          </div>
          <button id="btn-provision-conn">${T('js.g.install_configure')}</button>
          ${conn.last_provision_log ? `
            <details class="provision-log"><summary>${T('js.g.last_run_log')}</summary>
              <pre>${escapeHtml(conn.last_provision_log)}</pre>
            </details>` : ''}
          ${conn.type === 'wireguard' && conn.provision_status === 'provisioned' && conn.exit_server_id ? `
            <div class="field-row" style="margin-top:10px">
              <label>${T('js.g.home_devices')}</label>
              <a class="btn secondary" href="/wg-peers.php?exit_server_id=${conn.exit_server_id}" target="_blank" rel="noopener">${T('js.g.manage_devices')}</a>
            </div>
          ` : ''}
        ` : ''}
        ${conn.type === 'vless' ? `
          <details class="provision-log" style="margin-top:10px" id="camo-box" open>
            <summary>${T('js.g.camo_title')}</summary>
            <p class="muted" style="margin:8px 0">${T('js.g.camo_desc')}</p>
            <div class="field-row"><label>${T('js.g.camo_domain')}</label><input id="camo-domain" placeholder="updates.example.com"></div>
            <div class="field-row"><label>${T('js.g.camo_front')}</label>
              <select id="camo-preset"><option value="">${T('js.g.camo_none')}</option></select>
            </div>
            <div class="field-row" id="camo-brand-row" style="display:none"><label>${T('js.g.camo_brand')}</label><input id="camo-brand" placeholder="ExampleSoft"></div>
            <div class="field-row" id="camo-html-row" style="display:none;flex-direction:column;align-items:stretch">
              <label>${T('js.g.camo_custom_html')}</label>
              <textarea id="camo-html" rows="8" style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12px" placeholder="&lt;!doctype html&gt;&lt;html&gt;&lt;body&gt;&lt;h1&gt;...&lt;/h1&gt;&lt;/body&gt;&lt;/html&gt;"></textarea>
              <span class="muted" style="font-size:12px;margin-top:4px">${T('js.g.camo_custom_html_hint')}</span>
            </div>
            <button id="btn-camo-apply" type="button">${T('js.g.camo_apply')}</button>
            <div id="camo-result" style="margin-top:8px"></div>
          </details>
        ` : ''}
        <div class="field-row" style="margin-top:10px"><label>${T('js.g.routes_via')}</label>
          ${relatedRoutes.length ? '<ul>' + relatedRoutes.map((r) => `<li>${escapeHtml(r.name)}</li>`).join('') + '</ul>' : '<p class="muted">' + T('js.g.no_routes_via') + '</p>'}
        </div>
        <button class="danger" id="btn-del-conn-insp">${T('js.g.del_conn_btn')}</button>
      </div>
    `;
    inspector.querySelector('[data-close]').addEventListener('click', closeInspector);
    inspector.querySelector('#btn-del-conn-insp').addEventListener('click', async () => {
      if (!(await confirmDialog(T('js.g.del_conn'), T('js.g.del')))) return;
      await api.deleteConnection(connId);
      markPending(1);
      toast(T('js.g.conn_deleted'), 'success');
      closeInspector();
      await loadGraph();
    });

    const camoBox = inspector.querySelector('#camo-box');
    if (camoBox) {
        const presetSel = camoBox.querySelector('#camo-preset');
        const brandRow = camoBox.querySelector('#camo-brand-row');
        api.camouflagePresets().then((presets) => {
            Object.entries(presets).forEach(([id, label]) => {
                const o = document.createElement('option');
                o.value = id; o.textContent = T('js.g.raise_prefix', label);
                presetSel.appendChild(o);
            });
        }).catch(() => {});
        const htmlRow = camoBox.querySelector('#camo-html-row');
        presetSel.addEventListener('change', () => {
            brandRow.style.display = presetSel.value ? '' : 'none';
            htmlRow.style.display = presetSel.value === 'custom' ? 'flex' : 'none';
        });
        camoBox.querySelector('#btn-camo-apply').addEventListener('click', async () => {
            const domain = camoBox.querySelector('#camo-domain').value.trim();
            const preset = presetSel.value;
            const box = camoBox.querySelector('#camo-result');
            if (!domain) { box.innerHTML = '<div class="flash error">' + T('js.g.enter_domain') + '</div>'; return; }
            const btn = camoBox.querySelector('#btn-camo-apply');
            btn.disabled = true;
            const progress = showProgress(preset ? T('js.g.camo_prog_front') : T('js.g.camo_prog_domain'), preset ? 70 : 25,
                preset ? [T('js.g.camo_step_caddy'), T('js.g.camo_step_site'), T('js.g.camo_step_cert'), T('js.g.camo_step_reality')] : [T('js.g.camo_step_reality')]);
            try {
                const r = await api.setCamouflage(connId, {
                    domain, preset,
                    brand: camoBox.querySelector('#camo-brand').value.trim() || 'Service',
                    custom_html: preset === 'custom' ? camoBox.querySelector('#camo-html').value : '',
                });
                progress.done();
                box.innerHTML = `<div class="flash ${r.ok ? 'success' : 'error'}">${escapeHtml(r.message || (r.ok ? T('js.g.done_word') : T('js.g.error_cap')))}</div>`;
                if (r.ok) { markPending(1); await loadGraph(); }
            } catch (err) {
                progress.done();
                box.innerHTML = `<div class="flash error">${escapeHtml(err.message)}</div>`;
            } finally {
                btn.disabled = false;
            }
        });
    }

    const provisionBtn = inspector.querySelector('#btn-provision-conn');
    if (provisionBtn) {
      provisionBtn.addEventListener('click', async () => {
        const confirmed = await confirmDialog(
          T('js.g.prov_conn_confirm', escapeHtml(conn.target_name), escapeHtml(conn.type)),
          T('js.g.install')
        );
        if (!confirmed) return;
        provisionBtn.disabled = true;
        provisionBtn.textContent = T('js.g.running');
        try {
          const result = await api.provisionConnection(connId);
          toast(result.ok ? T('js.g.prov_ok_toast') : T('js.g.prov_fail_toast'), result.ok ? 'success' : 'error');
        } catch (err) {
          toast(err.message, 'error');
        } finally {
          await loadGraph();
        }
      });
    }
  }

  // ------------------------------------------------------ context menu ---
  function showContextMenu(originalEvent, items) {
    originalEvent.preventDefault && originalEvent.preventDefault();
    document.querySelectorAll('.context-menu').forEach((m) => m.remove());
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    const x = originalEvent.clientX || (originalEvent.renderedPosition ? originalEvent.renderedPosition.x : 0);
    const y = originalEvent.clientY || (originalEvent.renderedPosition ? originalEvent.renderedPosition.y : 0);
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
    items.forEach((item) => {
      const btn = document.createElement('button');
      btn.textContent = item.label;
      if (item.danger) btn.classList.add('danger');
      btn.addEventListener('click', () => { menu.remove(); item.action(); });
      menu.appendChild(btn);
    });
    document.body.appendChild(menu);
    setTimeout(() => document.addEventListener('click', () => menu.remove(), { once: true }), 0);
  }

  function buildServerContextMenu(serverId) {
    const server = state.servers.find((s) => s.id === serverId);
    const items = [
      { label: T('js.g.menu_open'), action: () => openInspector(serverId) },
      { label: T('js.g.edit'), action: () => openEditServerModal(server) },
      { label: T('js.g.test_conn'), action: async () => { openInspector(serverId); } },
    ];
    if (!server.is_self) {
      items.push({ label: T('js.g.del'), danger: true, action: async () => {
        if (!(await confirmDialog(T('js.g.del_server_short', escapeHtml(server.name)), T('js.g.del')))) return;
        await api.deleteServer(serverId);
        markPending(1);
        toast(T('js.g.server_deleted'), 'success');
        await loadGraph();
      } });
    }
    return items;
  }

  function buildSetContextMenu(setId) {
    const set = state.server_sets.find((s) => s.id === setId);
    return [
      { label: T('js.g.menu_open'), action: () => openServerSetDetailModal(setId) },
      { label: T('js.g.del'), danger: true, action: async () => {
        if (!set) return;
        const choice = await confirmSetDelete(set);
        if (!choice) return;
        try {
          const res = await api.deleteServerSet(setId, choice === 'cascade');
          markPending(1);
          toast(choice === 'cascade' ? T('js.g.set_deleted_cascade', (res && res.deleted_servers) || 0) : T('js.g.set_deleted'), 'success');
          await loadGraph();
        } catch (e) { toast(e.message, 'error'); }
      } },
    ];
  }

  function buildConnectionContextMenu(connId) {
    return [
      { label: T('js.g.menu_open'), action: () => openConnectionInspector(connId) },
      { label: T('js.g.del'), danger: true, action: async () => {
        if (!(await confirmDialog(T('js.g.del_conn'), T('js.g.del')))) return;
        await api.deleteConnection(connId);
        markPending(1);
        toast(T('js.g.conn_deleted'), 'success');
        await loadGraph();
      } },
    ];
  }

  // ----------------------------------------------------------- modals ----
  function openModal(html, wide) {
    const overlay = document.getElementById('modal-overlay');
    overlay.innerHTML = `<div class="modal ${wide ? 'modal-wide' : ''}" style="position:relative">
      <button type="button" class="icon-btn ghost" data-modal-x title="${window.T('common.close')} (Esc)" aria-label="${window.T('common.close')}"
        style="position:absolute;top:14px;right:14px;width:30px;height:30px;padding:0;font-size:18px;line-height:1">✕</button>
      ${html}</div>`;
    overlay.classList.remove('hidden');
    overlay.querySelector('[data-modal-x]').addEventListener('click', closeModal);
    // onclick (а не addEventListener once): клик внутри окна не должен «съедать» закрытие по фону.
    overlay.onclick = (e) => { if (e.target === overlay) closeModal(); };
  }
  function closeModal() {
    document.getElementById('modal-overlay').classList.add('hidden');
  }

  /**
   * «Мастер маскировки» — пошаговое создание сайта-прикрытия для exit-сервера:
   * 1) домен + проверка A-записи (должна указывать на IP exit'а);
   * 2) оформление (пресет / свой HTML + бренд);
   * 3) применение (ставит Caddy, берёт сертификат Let's Encrypt, настраивает Reality);
   * 4) готово. Работает поверх существующего api.setCamouflage.
   */
  function openCamouflageWizard(connId) {
    const conn = state.connections.find((c) => c.id === connId);
    if (!conn) { toast(T('js.g.cw_no_conn'), 'error'); return; }
    const st = { step: 0, domain: '', preset: 'updates', brand: 'Service', html: '', presets: {} };

    function actions(backIdx, nextLabel, nextId) {
      return '<div class="modal-actions" style="justify-content:space-between;margin-top:18px">'
        + (backIdx !== null ? '<button type="button" class="secondary" id="cw-back">' + esc(T('js.g.cw_back')) + '</button>' : '<span></span>')
        + (nextLabel ? '<button type="button" id="' + nextId + '">' + esc(nextLabel) + '</button>' : '<span></span>')
        + '</div>';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    function render() {
      let html = '<h2 style="margin:0 0 4px">' + esc(T('js.g.cw_title')) + '</h2>'
        + '<p class="muted" style="margin:0 0 16px;font-size:13px">' + esc(T('js.g.cw_sub', conn.target_name)) + '</p>';

      if (st.step === 0) {
        html += '<label>' + esc(T('js.g.camo_domain')) + '</label>'
          + '<input id="cw-domain" placeholder="updates.example.com" value="' + esc(st.domain) + '">'
          + '<button type="button" class="secondary" id="cw-check" style="margin-top:8px">' + esc(T('js.g.cw_check_dns')) + '</button>'
          + '<div id="cw-dns" style="margin-top:10px"></div>'
          + actions(null, T('js.g.cw_next'), 'cw-next');
      } else if (st.step === 1) {
        const opts = Object.entries(st.presets).map(function (e) {
          return '<option value="' + esc(e[0]) + '"' + (st.preset === e[0] ? ' selected' : '') + '>' + esc(e[1]) + '</option>';
        }).join('');
        html += '<label>' + esc(T('js.g.cw_preset')) + '</label><select id="cw-preset">' + opts + '</select>'
          + '<div id="cw-brand-row" style="margin-top:10px"><label>' + esc(T('js.g.camo_brand')) + '</label><input id="cw-brand" value="' + esc(st.brand) + '" placeholder="SB update services"></div>'
          + '<div id="cw-html-row" style="margin-top:10px;' + (st.preset === 'custom' ? '' : 'display:none') + '"><label>' + esc(T('js.g.camo_custom_html')) + '</label>'
          + '<textarea id="cw-html" rows="7" style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12px">' + esc(st.html) + '</textarea></div>'
          + actions(0, T('js.g.cw_next'), 'cw-next');
      } else if (st.step === 2) {
        html += '<div class="field-row"><label>' + esc(T('js.g.camo_domain')) + '</label><b>' + esc(st.domain) + '</b></div>'
          + '<div class="field-row"><label>' + esc(T('js.g.cw_preset')) + '</label>' + esc(st.presets[st.preset] || st.preset) + '</div>'
          + '<p class="muted" style="font-size:12px;margin:8px 0 0">' + esc(T('js.g.cw_apply_note')) + '</p>'
          + '<div id="cw-apply-result" style="margin-top:10px"></div>'
          + '<div class="modal-actions" style="justify-content:space-between;margin-top:18px"><button type="button" class="secondary" id="cw-back">' + esc(T('js.g.cw_back')) + '</button><button type="button" id="cw-apply">' + esc(T('js.g.cw_apply_btn')) + '</button></div>';
      } else {
        html += '<div style="text-align:center;padding:10px 0"><div style="font-size:40px">✓</div>'
          + '<p style="font-weight:600;margin:6px 0">' + esc(T('js.g.cw_done')) + '</p>'
          + '<p class="muted" style="font-size:13px">' + esc(T('js.g.cw_done_msg')) + '</p>'
          + '<p style="margin-top:12px"><a class="btn secondary" href="https://' + encodeURIComponent(st.domain) + '/" target="_blank" rel="noopener">' + esc(T('js.g.cw_open_site')) + '</a></p></div>';
      }
      openModal(html);
      bind();
    }

    function bind() {
      const back = document.getElementById('cw-back');
      if (back) back.onclick = function () { st.step--; render(); };

      if (st.step === 0) {
        document.getElementById('cw-check').onclick = async function () {
          const d = document.getElementById('cw-domain').value.trim().toLowerCase();
          const box = document.getElementById('cw-dns');
          if (!d) { box.innerHTML = ''; return; }
          box.innerHTML = '<span class="muted">…</span>';
          try {
            const r = await api.camouflagePrecheck(connId, d);
            const list = r.resolved.length ? r.resolved.join(', ') : T('js.g.cw_dns_none');
            box.innerHTML = '<div class="field-row"><label>' + esc(T('js.g.cw_dns_expected')) + '</label><code>' + esc(r.expected_ip) + '</code></div>'
              + '<div class="field-row"><label>' + esc(T('js.g.cw_dns_resolved')) + '</label><code>' + esc(list) + '</code></div>'
              + '<div class="flash ' + (r.match ? 'success' : 'error') + '">' + esc(r.match ? T('js.g.cw_dns_match') : T('js.g.cw_dns_nomatch')) + '</div>';
          } catch (e) { box.innerHTML = '<div class="flash error">' + esc(e.message) + '</div>'; }
        };
        document.getElementById('cw-next').onclick = function () {
          const d = document.getElementById('cw-domain').value.trim().toLowerCase();
          if (!/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/.test(d)) { toast(T('js.g.cw_bad_domain'), 'error'); return; }
          st.domain = d; st.step = 1; render();
        };
      } else if (st.step === 1) {
        const sel = document.getElementById('cw-preset');
        sel.onchange = function () { document.getElementById('cw-html-row').style.display = sel.value === 'custom' ? '' : 'none'; };
        document.getElementById('cw-next').onclick = function () {
          st.preset = sel.value;
          st.brand = document.getElementById('cw-brand').value.trim() || 'Service';
          st.html = document.getElementById('cw-html') ? document.getElementById('cw-html').value : '';
          st.step = 2; render();
        };
      } else if (st.step === 2) {
        document.getElementById('cw-apply').onclick = async function () {
          const progress = showProgress(T('js.g.cw_applying'), 70,
            [T('js.g.camo_step_caddy'), T('js.g.camo_step_site'), T('js.g.camo_step_cert'), T('js.g.camo_step_reality')]);
          try {
            const r = await api.setCamouflage(connId, { domain: st.domain, preset: st.preset, brand: st.brand, custom_html: st.preset === 'custom' ? st.html : '' });
            progress.done();
            if (r.ok) { st.step = 3; render(); markPending(1); await loadGraph(); }
            else { document.getElementById('cw-apply-result').innerHTML = '<div class="flash error">' + esc(r.message || T('js.g.error_cap')) + '</div>'; }
          } catch (e) {
            progress.done();
            document.getElementById('cw-apply-result').innerHTML = '<div class="flash error">' + esc(e.message) + '</div>';
          }
        };
      }
    }

    api.camouflagePresets().then(function (p) { st.presets = p || {}; if (!st.presets[st.preset]) { st.preset = Object.keys(st.presets)[0] || ''; } render(); }).catch(function () { render(); });
  }

  /**
   * Замена нативного confirm() — тот блокирует автоматизацию/расширения
   * браузера (родной диалог не проксируется через CDP) и не позволяет
   * показать форматированный текст. message может содержать HTML —
   * вызывающий код сам отвечает за экранирование динамических частей.
   */
  /**
   * Диалог удаления набора: если внутри есть серверы — три исхода
   *   'cascade' (группа + серверы), 'group' (только группа), null (отмена);
   * если серверов нет — обычное подтверждение (→ 'group' или null).
   */
  function confirmSetDelete(set) {
    const memberCount = (set.members || []).length;
    if (memberCount === 0) {
      return confirmDialog(T('js.g.del_set_confirm', escapeHtml(set.name)), T('js.g.del'))
        .then((ok) => (ok ? 'group' : null));
    }
    return new Promise((resolve) => {
      openModal(`
        <p>${T('js.g.del_set_members_q', escapeHtml(set.name), memberCount)}</p>
        <div class="modal-actions" style="flex-wrap:wrap;gap:8px">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="button" class="secondary" data-group>${T('js.g.del_set_only')}</button>
          <button type="button" class="danger" data-cascade>${T('js.g.del_set_with')}</button>
        </div>
      `);
      const overlay = document.getElementById('modal-overlay');
      const finish = (result) => { closeModal(); resolve(result); };
      overlay.querySelector('[data-cancel]').addEventListener('click', () => finish(null));
      overlay.querySelector('[data-group]').addEventListener('click', () => finish('group'));
      overlay.querySelector('[data-cascade]').addEventListener('click', () => finish('cascade'));
      overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(null); }, { once: true });
    });
  }

  function confirmDialog(message, confirmLabel) {
    return new Promise((resolve) => {
      openModal(`
        <p>${message}</p>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="button" data-confirm>${escapeHtml(confirmLabel || T('js.g.confirm'))}</button>
        </div>
      `);
      const overlay = document.getElementById('modal-overlay');
      const finish = (result) => { closeModal(); resolve(result); };
      overlay.querySelector('[data-cancel]').addEventListener('click', () => finish(false));
      overlay.querySelector('[data-confirm]').addEventListener('click', () => finish(true));
      overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(false); }, { once: true });
    });
  }

  function openAddServerModal() {
    renderServerForm(null);
  }
  function openEditServerModal(server) {
    renderServerForm(server);
  }

  const ROLE_LABELS = {
    router: { label: T('js.g.role_router'), help: T('js.g.role_router_h') },
    exit: { label: T('js.g.role_exit'), help: T('js.g.role_exit_h') },
    proxy: { label: T('js.g.role_proxy'), help: T('js.g.role_proxy_h') },
    vpn: { label: T('js.g.role_vpn'), help: T('js.g.role_vpn_h') },
    gateway: { label: T('js.g.role_gateway'), help: T('js.g.role_gateway_h') },
    storage: { label: T('js.g.role_storage'), help: T('js.g.role_storage_h') },
    generic: { label: T('js.g.role_generic'), help: T('js.g.role_generic_h') },
  };

  function renderServerForm(server) {
    const roles = ['router', 'exit', 'proxy', 'vpn', 'gateway', 'storage', 'generic'];
    openModal(`
      <h3>${server ? T('js.g.edit_server') : T('js.g.add_server_modal')}</h3>
      <form id="server-form">
        <div class="field-row"><label>${T('js.g.name')}</label><input name="name" required value="${server ? escapeHtml(server.name) : ''}"></div>
        <div class="field-row"><label>${T('js.g.role_q')}</label>
          <select name="role" id="role-select">${roles.map((r) => `<option value="${r}" ${server && server.role === r ? 'selected' : ''}>${ROLE_LABELS[r].label}</option>`).join('')}</select>
          <p class="muted" id="role-help" style="margin-top:4px"></p>
        </div>
        <div class="field-row"><label>Host / IP</label><input name="host" value="${server ? escapeHtml(server.host) : ''}" placeholder="203.0.113.5"></div>
        <div class="row">
          <div class="field-row" style="flex:1"><label>${T('js.g.ssh_port')}</label><input name="ssh_port" type="number" value="${server ? server.ssh_port : 22}"></div>
          <div class="field-row" style="flex:1"><label>${T('js.g.ssh_user')}</label><input name="ssh_user" value="${server ? escapeHtml(server.ssh_user) : 'root'}"></div>
        </div>
        ${server && server.is_self ? '' : `
        <div class="field-row"><label>${T('js.g.how_login')}</label>
          <div class="row" style="gap:16px">
            <label style="font-weight:400"><input type="radio" name="auth_mode" value="password" ${server && server.has_ssh_key ? '' : 'checked'}> ${T('js.g.auth_pw')}</label>
            <label style="font-weight:400"><input type="radio" name="auth_mode" value="key" ${server && server.has_ssh_key ? 'checked' : ''}> ${T('js.g.auth_key')}</label>
          </div>
        </div>
        <div class="field-row" data-auth="password"><label>${T('js.g.ssh_pw')}${server && server.has_ssh_key ? T('js.g.pw_keep_hint') : ''}</label>
          <input type="password" name="ssh_password" autocomplete="off" placeholder="${T('js.g.pw_ph')}">
          <p class="muted" style="margin-top:4px">${T('js.g.pw_note')}</p>
        </div>
        <div class="field-row" data-auth="key"><label>${T('js.g.ssh_privkey')}${server && server.has_ssh_key ? T('js.g.key_keep_hint') : ''}</label>
          <textarea name="ssh_private_key" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
        </div>`}
        <div class="row">
          <div class="field-row" style="flex:1"><label>${T('js.g.country')}</label><input name="country" value="${server ? escapeHtml(server.country || '') : ''}"></div>
          <div class="field-row" style="flex:1"><label>${T('js.g.region')}</label><input name="region" value="${server ? escapeHtml(server.region || '') : ''}"></div>
        </div>
        <div class="field-row"><label>${T('js.g.description')}</label><textarea name="description">${server ? escapeHtml(server.description || '') : ''}</textarea></div>
        <div class="field-row"><label>${T('js.g.tags')}</label><input name="tags" value="${server && server.tags ? escapeHtml(JSON.parse(server.tags).join(', ')) : ''}"></div>
        <details ${server && server.traffic_limit_gb ? 'open' : ''} style="margin:6px 0 10px">
          <summary style="cursor:pointer;font-weight:500">${T('js.g.traffic_limit_title')}</summary>
          <p class="muted" style="margin:6px 0">${T('js.g.traffic_limit_note', 85, 95)}</p>
          <div class="row">
            <div class="field-row" style="flex:1"><label>${T('js.g.limit_gb_month')}</label><input name="traffic_limit_gb" type="number" min="0" step="1" placeholder="3000" value="${server && server.traffic_limit_gb ? escapeHtml(String(server.traffic_limit_gb)) : ''}"></div>
            <div class="field-row" style="flex:1"><label>${T('js.g.period_start_day')}</label><input name="traffic_reset_day" type="number" min="1" max="28" value="${server ? (server.traffic_reset_day || 1) : 1}"></div>
          </div>
          <div class="field-row"><label>${T('js.g.count_mode_q')}</label>
            <select name="traffic_count_mode">
              ${[['sum', T('js.g.mode_sum_full')], ['out', T('js.g.mode_out2')], ['max', T('js.g.mode_max2')]].map(([v, l]) => `<option value="${v}" ${(server ? server.traffic_count_mode : 'sum') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select>
          </div>
        </details>
        <div class="field-row"><label><input type="checkbox" name="enabled" ${!server || server.enabled ? 'checked' : ''}> ${T('js.g.active_cb')}</label></div>
        <div id="test-before-save"></div>
        <div class="modal-actions">
          <button type="button" class="secondary" id="btn-test-before-save">${T('js.g.test_conn')}</button>
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="submit">${server ? T('common.save') : T('js.g.add_btn')}</button>
        </div>
      </form>
    `);
    const form = document.getElementById('server-form');
    form.querySelector('[data-cancel]').addEventListener('click', closeModal);

    const roleSelect = document.getElementById('role-select');
    const roleHelp = document.getElementById('role-help');
    const updateRoleHelp = () => { roleHelp.textContent = ROLE_LABELS[roleSelect.value].help; };
    roleSelect.addEventListener('change', updateRoleHelp);
    updateRoleHelp();

    const authMode = () => (form.querySelector('input[name="auth_mode"]:checked') || {}).value || 'key';
    const updateAuthFields = () => {
      form.querySelectorAll('[data-auth]').forEach((el) => el.classList.toggle('hidden', el.dataset.auth !== authMode()));
    };
    form.querySelectorAll('input[name="auth_mode"]').forEach((r) => r.addEventListener('change', updateAuthFields));
    updateAuthFields();

    /** Отдельные от формы поля входа: пароль в БД не отправляется никогда, ключ — только в режиме «ключ». */
    const serverPayload = (fd) => {
      const data = Object.fromEntries(fd);
      const password = data.ssh_password || '';
      delete data.ssh_password;
      delete data.auth_mode;
      if (authMode() !== 'key') delete data.ssh_private_key;
      return { data, password };
    };

    form.querySelector('#btn-test-before-save').addEventListener('click', async () => {
      const box = document.getElementById('test-before-save');
      const fd = new FormData(form);
      if (authMode() === 'password' && fd.get('ssh_password')) {
        box.innerHTML = '<p class="muted">' + T('js.g.checking_pw') + '</p>';
        try {
          const r = await api.testPassword({
            host: fd.get('host'), ssh_port: Number(fd.get('ssh_port') || 22), ssh_user: fd.get('ssh_user') || 'root',
            password: fd.get('ssh_password'),
          });
          box.innerHTML = r.ok
            ? `<div class="flash success">${T('js.g.pw_ok', escapeHtml(r.hostname || ''), escapeHtml(r.os || ''))}</div>`
            : renderSshDiagnosis(r);
          wireUseSshPort(box, (port) => { form.querySelector('[name="ssh_port"]').value = port; box.innerHTML = `<div class="flash success">${T('js.g.port_filled_recheck', port)}</div>`; });
        } catch (e) {
          box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
        }
        return;
      }
      const key = fd.get('ssh_private_key');
      if (!key && !(server && server.has_ssh_key)) {
        box.innerHTML = '<div class="flash error">' + T('js.g.enter_key_or_save') + '</div>';
        return;
      }
      box.innerHTML = '<p class="muted">' + T('js.g.checking') + '</p>';
      try {
        // Тестируем уже сохранённые данные: если это новый сервер — сначала предложим сохранить.
        if (!server) {
          box.innerHTML = '<div class="flash error">' + T('js.g.save_first') + '</div>';
          return;
        }
        if (key) {
          const { data } = serverPayload(fd);
          data.enabled = fd.has('enabled');
          data.tags = (data.tags || '').split(',').map((t) => t.trim()).filter(Boolean);
          await api.updateServer(server.id, data);
        }
        const r = await api.testConnection(server.id);
        box.innerHTML = r.ok
          ? `<div class="flash success">✓ SSH connection successful<br>Hostname: ${escapeHtml(r.hostname)}<br>OS: ${escapeHtml(r.os)}</div>`
          : renderSshDiagnosis(r);
        wireUseSshPort(box, (port) => { form.querySelector('[name="ssh_port"]').value = port; box.innerHTML = `<div class="flash success">${T('js.g.port_filled_save', port)}</div>`; });
      } catch (e) {
        box.innerHTML = `<div class="flash error">${escapeHtml(e.message)}</div>`;
      }
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const { data, password } = serverPayload(fd);
      data.enabled = fd.has('enabled');
      data.tags = (data.tags || '').split(',').map((t) => t.trim()).filter(Boolean);
      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      try {
        let savedId;
        if (server) {
          await api.updateServer(server.id, data);
          savedId = server.id;
          toast(T('js.g.server_updated'), 'success');
        } else {
          savedId = (await api.createServer(data)).id;
          toast(T('js.g.server_added'), 'success');
        }
        if (authMode() === 'password' && password) {
          submitBtn.textContent = T('js.g.creating_key');
          const r = await api.bootstrapSsh(savedId, password);
          if (r.ok) {
            toast(T('js.g.ssh_key_ok'), 'success');
          } else {
            toast(T('js.g.saved_no_key', r.raw_error || T('js.g.error_word')), 'error');
          }
        }
        markPending(1);
        closeModal();
        await loadGraph();
      } catch (err) {
        toast(err.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = server ? T('common.save') : T('js.g.add_btn');
      }
    });
  }

  function openConnectionModal(sourceId, targetId) {
    const source = state.servers.find((s) => s.id === sourceId);
    const target = state.servers.find((s) => s.id === targetId);
    openModal(`
      <h3>${T('js.g.new_conn', escapeHtml(source.name), escapeHtml(target.name))}</h3>
      <form id="conn-form">
        <div class="field-row"><label>${T('js.g.type_label')}</label>
          <select name="type" id="conn-type">
            <option value="amneziawg">AmneziaWG</option>
            <option value="wireguard">${T('js.g.wg_plain')}</option>
            <option value="vless">VLESS (Reality / gRPC / WS)</option>
            <option value="shadowsocks">Shadowsocks / Shadowsocks-2022</option>
            <option value="hysteria2">Hysteria2</option>
            <option value="tuic">TUIC</option>
            <option value="trojan">Trojan</option>
            <option value="ssh">SSH</option>
            <option value="tcp">TCP</option>
            <option value="http">HTTP</option>
            <option value="socks">SOCKS</option>
            <option value="generic">Generic</option>
          </select>
        </div>
        <div class="field-row"><label>Label</label><input name="label" placeholder="${T('exit.f.name_ph')}"></div>
        <div id="conn-type-fields"></div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="submit">${T('js.g.create_link')}</button>
        </div>
      </form>
    `, true);

    const draftNote = '<p class="muted">' + T('js.g.draft_note') + '</p>';

    const typeFields = document.getElementById('conn-type-fields');
    function renderTypeFields(type) {
      if (type === 'amneziawg') {
        typeFields.innerHTML = `
          ${draftNote}
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="wg_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="wg_endpoint_port" type="number" value="51900"></div>
          </div>
          <div class="field-row"><label>${T('js.g.peer_pubkey_opt')}</label><input name="wg_peer_pubkey"></div>
          <div class="field-row"><label>${T('js.g.psk_opt')}</label><input name="wg_peer_psk"></div>
          <div class="row">
            <div class="field-row" style="flex:1"><label>${T('js.g.local_privkey_opt')}</label><input name="wg_local_privkey"></div>
            <div class="field-row" style="flex:1"><label>${T('js.g.local_addr_opt')}</label><input name="wg_local_address" placeholder="10.90.0.2/32"></div>
          </div>
          <div class="field-row"><label>${T('js.g.iface_opt')}</label><input name="wg_interface_name" placeholder="awg-ex1"></div>
          <details style="margin-top:4px">
            <summary style="cursor:pointer;font-weight:500">${T('js.g.amnezia_obf')}</summary>
            <p class="muted" style="font-size:12px;margin:4px 0">${T('js.g.amnezia_obf_hint')}</p>
            <div class="row" style="flex-wrap:wrap;gap:8px">
              ${['Jc:4', 'Jmin:40', 'Jmax:70', 'S1:', 'S2:', 'H1:', 'H2:', 'H3:', 'H4:'].map((p) => { const [k, ph] = p.split(':'); return `<div class="field-row" style="flex:1;min-width:64px"><label>${k}</label><input name="awg_${k}" type="number" min="0" placeholder="${ph}"></div>`; }).join('')}
            </div>
          </details>
        `;
      } else if (type === 'wireguard') {
        typeFields.innerHTML = `
          ${draftNote}
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="wg_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="wg_endpoint_port" type="number" value="51820"></div>
          </div>
          <div class="field-row"><label>${T('js.g.peer_pubkey_opt')}</label><input name="wg_peer_pubkey"></div>
          <div class="row">
            <div class="field-row" style="flex:1"><label>${T('js.g.local_privkey_opt')}</label><input name="wg_local_privkey"></div>
            <div class="field-row" style="flex:1"><label>${T('js.g.local_addr_opt')}</label><input name="wg_local_address" placeholder="10.90.0.2/32"></div>
          </div>
          <p class="muted">${T('js.g.wg_note')}</p>
        `;
      } else if (type === 'vless') {
        typeFields.innerHTML = `
          ${draftNote}
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="vless_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="vless_endpoint_port" type="number" value="443"></div>
          </div>
          <div class="field-row"><label>${T('js.g.uuid_opt')}</label><input name="vless_uuid"></div>
          <div class="field-row"><label>${T('js.g.flow_opt')}</label><input name="vless_flow" placeholder="xtls-rprx-vision"></div>
          <div class="field-row"><label>Transport</label>
            <select name="vless_transport" id="vless-transport">
              <option value="reality">Reality</option>
              <option value="ws">WebSocket + TLS</option>
              <option value="grpc">gRPC</option>
            </select>
          </div>
          <div id="vless-transport-fields"></div>
        `;
        const renderVlessTransport = () => {
          const t = document.getElementById('vless-transport').value;
          const box = document.getElementById('vless-transport-fields');
          if (t === 'reality') {
            box.innerHTML = `
              <div class="field-row"><label>${T('js.g.reality_pub_opt')}</label><input name="vless_reality_public_key"></div>
              <div class="field-row"><label>${T('js.g.reality_sid_opt')}</label><input name="vless_reality_short_id"></div>
              <div class="field-row"><label>${T('js.g.reality_sni_opt')}</label><input name="vless_reality_server_name"></div>
            `;
          } else if (t === 'ws') {
            box.innerHTML = `<div class="field-row"><label>WS path</label><input name="vless_ws_path" placeholder="/"></div>`;
          } else {
            box.innerHTML = `<div class="field-row"><label>gRPC service name</label><input name="vless_grpc_service_name"></div>`;
          }
        };
        document.getElementById('vless-transport').addEventListener('change', renderVlessTransport);
        renderVlessTransport();
      } else if (type === 'shadowsocks') {
        typeFields.innerHTML = `
          ${draftNote}
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="ss_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="ss_endpoint_port" type="number" value="8388"></div>
          </div>
          <div class="field-row"><label>${T('js.g.ss_method_opt')}</label><input name="ss_method" placeholder="chacha20-ietf-poly1305"></div>
          <div class="field-row"><label>${T('js.g.password_opt')}</label><input name="ss_password"></div>
        `;
      } else if (type === 'hysteria2') {
        typeFields.innerHTML = `
          ${draftNote}
          <p class="muted">${T('js.g.tls_note1')}</p>
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="hy2_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="hy2_endpoint_port" type="number" value="443"></div>
          </div>
          <div class="field-row"><label>${T('js.g.domain_req')}</label><input name="hy2_domain" required placeholder="hy2.example.com"></div>
          <div class="field-row"><label>${T('js.g.password_opt')}</label><input name="hy2_password"></div>
          <div class="field-row"><label>${T('js.g.obfs_opt')}</label><input name="hy2_obfs_password"></div>
        `;
      } else if (type === 'tuic') {
        typeFields.innerHTML = `
          ${draftNote}
          <p class="muted">${T('js.g.tls_note2')}</p>
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="tuic_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="tuic_endpoint_port" type="number" value="443"></div>
          </div>
          <div class="field-row"><label>${T('js.g.domain_req')}</label><input name="tuic_domain" required placeholder="tuic.example.com"></div>
          <div class="field-row"><label>${T('js.g.uuid_opt')}</label><input name="tuic_uuid"></div>
          <div class="field-row"><label>${T('js.g.password_opt')}</label><input name="tuic_password"></div>
          <div class="field-row"><label>Congestion control</label>
            <select name="tuic_congestion_control">
              <option value="bbr">bbr</option>
              <option value="cubic">cubic</option>
              <option value="new_reno">new_reno</option>
            </select>
          </div>
        `;
      } else if (type === 'trojan') {
        typeFields.innerHTML = `
          ${draftNote}
          <p class="muted">${T('js.g.tls_note2')}</p>
          <div class="row">
            <div class="field-row" style="flex:1"><label>Endpoint host</label><input name="trojan_endpoint_host" value="${escapeHtml(target.host || '')}"></div>
            <div class="field-row" style="flex:1"><label>Endpoint port</label><input name="trojan_endpoint_port" type="number" value="443"></div>
          </div>
          <div class="field-row"><label>${T('js.g.domain_req')}</label><input name="trojan_domain" required placeholder="trojan.example.com"></div>
          <div class="field-row"><label>${T('js.g.password_opt')}</label><input name="trojan_password"></div>
        `;
      } else {
        typeFields.innerHTML = `
          <div class="field-row"><label>${T('js.g.config_opt')}</label><textarea name="config" placeholder="{}"></textarea></div>
          <p class="muted">${T('js.g.visual_only')}</p>
        `;
      }
    }
    document.getElementById('conn-type').addEventListener('change', (e) => renderTypeFields(e.target.value));
    renderTypeFields('amneziawg');

    const form = document.getElementById('conn-form');
    form.querySelector('[data-cancel]').addEventListener('click', closeModal);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const type = fd.get('type');
      const payload = { source_server_id: sourceId, target_server_id: targetId, type, label: fd.get('label') || null };

      if (type === 'amneziawg' || type === 'wireguard') {
        payload.wireguard = {
          endpoint_host: fd.get('wg_endpoint_host'),
          endpoint_port: Number(fd.get('wg_endpoint_port') || 51820),
          wg_peer_pubkey: fd.get('wg_peer_pubkey') || '',
          wg_peer_psk: fd.get('wg_peer_psk') || null,
          wg_local_privkey: fd.get('wg_local_privkey') || '',
          wg_local_address: fd.get('wg_local_address') || '',
          interface_name: fd.get('wg_interface_name') || null,
          amnezia_params: (function () {
            const o = {};
            ['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'H1', 'H2', 'H3', 'H4'].forEach((k) => {
              const v = fd.get('awg_' + k);
              if (v !== null && String(v).trim() !== '') o[k] = Number(v);
            });
            return Object.keys(o).length ? JSON.stringify(o) : null; // пусто → панель генерит сама
          })(),
          status: 'active',
        };
      } else if (type === 'vless') {
        payload.vless = {
          endpoint_host: fd.get('vless_endpoint_host'),
          endpoint_port: Number(fd.get('vless_endpoint_port') || 443),
          uuid: fd.get('vless_uuid') || '',
          flow: fd.get('vless_flow') || null,
          transport: fd.get('vless_transport'),
          reality_public_key: fd.get('vless_reality_public_key') || '',
          reality_short_id: fd.get('vless_reality_short_id') || '',
          reality_server_name: fd.get('vless_reality_server_name') || '',
          ws_path: fd.get('vless_ws_path') || '/',
          grpc_service_name: fd.get('vless_grpc_service_name') || '',
        };
      } else if (type === 'shadowsocks') {
        payload.shadowsocks = {
          endpoint_host: fd.get('ss_endpoint_host'),
          endpoint_port: Number(fd.get('ss_endpoint_port') || 8388),
          method: fd.get('ss_method') || '',
          password: fd.get('ss_password') || '',
        };
      } else if (type === 'hysteria2') {
        payload.hysteria2 = {
          endpoint_host: fd.get('hy2_endpoint_host'),
          endpoint_port: Number(fd.get('hy2_endpoint_port') || 443),
          domain: fd.get('hy2_domain'),
          password: fd.get('hy2_password') || '',
          obfs_password: fd.get('hy2_obfs_password') || '',
        };
      } else if (type === 'tuic') {
        payload.tuic = {
          endpoint_host: fd.get('tuic_endpoint_host'),
          endpoint_port: Number(fd.get('tuic_endpoint_port') || 443),
          domain: fd.get('tuic_domain'),
          uuid: fd.get('tuic_uuid') || '',
          password: fd.get('tuic_password') || '',
          congestion_control: fd.get('tuic_congestion_control') || 'bbr',
        };
      } else if (type === 'trojan') {
        payload.trojan = {
          endpoint_host: fd.get('trojan_endpoint_host'),
          endpoint_port: Number(fd.get('trojan_endpoint_port') || 443),
          domain: fd.get('trojan_domain'),
          password: fd.get('trojan_password') || '',
        };
      } else {
        const raw = fd.get('config');
        if (raw) {
          try { payload.config = JSON.parse(raw); } catch (e2) { toast(T('js.g.bad_json'), 'error'); return; }
        }
      }

      try {
        await api.createConnection(payload);
        markPending(1);
        toast(T('js.g.conn_created'), 'success');
        closeModal();
        await loadGraph();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  function randomPort() {
    return 10000 + Math.floor(Math.random() * 20000);
  }

  function openPortKnockModal(server) {
    let ports = [randomPort(), randomPort(), randomPort()];

    function renderPortsList() {
      const box = document.getElementById('knock-ports-list');
      box.innerHTML = ports.map((p, i) => `
        <div class="row" style="margin-bottom:4px">
          <span class="muted" style="width:20px">${i + 1}.</span>
          <input type="number" data-knock-port="${i}" value="${p}" required style="flex:1">
          <button type="button" class="secondary" data-remove-port="${i}" ${ports.length <= 1 ? 'disabled' : ''}>✕</button>
        </div>
      `).join('');
      box.querySelectorAll('[data-knock-port]').forEach((input) => {
        input.addEventListener('input', () => { ports[Number(input.dataset.knockPort)] = Number(input.value); });
      });
      box.querySelectorAll('[data-remove-port]').forEach((btn) => {
        btn.addEventListener('click', () => {
          ports.splice(Number(btn.dataset.removePort), 1);
          renderPortsList();
        });
      });
    }

    openModal(`
      <h3>${T('js.g.pk_title', escapeHtml(server.name))}</h3>
      <p class="muted">${T('js.g.pk_desc', escapeHtml(server.host))}</p>
      <p class="flash error">${T('js.g.pk_warn')}</p>
      <form id="knock-form">
        <div class="field-row"><label>${T('js.g.pk_protected')}</label><input name="protected_port" type="number" value="22" required></div>
        <div class="field-row"><label>${T('js.g.pk_sequence')}</label>
          <div id="knock-ports-list"></div>
          <button type="button" class="secondary" id="btn-add-knock-port" style="margin-top:6px">${T('js.g.pk_add_port')}</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="submit">${T('js.g.apply_btn')}</button>
        </div>
      </form>
    `, true);
    renderPortsList();
    document.getElementById('btn-add-knock-port').addEventListener('click', () => {
      ports.push(randomPort());
      renderPortsList();
    });
    const form = document.getElementById('knock-form');
    form.querySelector('[data-cancel]').addEventListener('click', closeModal);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const payload = {
        protected_port: Number(fd.get('protected_port')),
        knock_ports: ports.map(Number),
      };
      try {
        const result = await api.hardenPortKnock(server.id, payload);
        closeModal();
        toast(result.ok ? T('js.g.pk_done', payload.knock_ports.join(' → ')) : T('js.g.script_fail'), result.ok ? 'success' : 'error');
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  const DEFAULT_DECOY_PORTS = [21, 23, 3389, 5900, 6379, 9000];

  function openPortscanBanModal(server) {
    let ports = server.portscan_decoy_ports ? JSON.parse(server.portscan_decoy_ports) : [...DEFAULT_DECOY_PORTS];

    function renderPortsList() {
      const box = document.getElementById('scanban-ports-list');
      box.innerHTML = ports.map((p, i) => `
        <div class="row" style="margin-bottom:4px">
          <input type="number" data-scanban-port="${i}" value="${p}" required style="flex:1">
          <button type="button" class="secondary" data-remove-scanban-port="${i}" ${ports.length <= 1 ? 'disabled' : ''}>✕</button>
        </div>
      `).join('');
      box.querySelectorAll('[data-scanban-port]').forEach((input) => {
        input.addEventListener('input', () => { ports[Number(input.dataset.scanbanPort)] = Number(input.value); });
      });
      box.querySelectorAll('[data-remove-scanban-port]').forEach((btn) => {
        btn.addEventListener('click', () => {
          ports.splice(Number(btn.dataset.removeScanbanPort), 1);
          renderPortsList();
        });
      });
    }

    openModal(`
      <h3>${T('js.g.ps_title', escapeHtml(server.name))}</h3>
      <p class="muted">${T('js.g.ps_desc')}</p>
      <form id="scanban-form">
        <div class="field-row"><label>${T('js.g.ps_decoys')}</label>
          <div id="scanban-ports-list"></div>
          <button type="button" class="secondary" id="btn-add-scanban-port" style="margin-top:6px">${T('js.g.pk_add_port')}</button>
        </div>
        <div class="field-row"><label>${T('js.g.ps_ban_dur')}</label><input name="ban_seconds" type="number" value="${server.portscan_ban_seconds || 86400}" required></div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="submit">${T('js.g.apply_btn')}</button>
        </div>
      </form>
    `, true);
    renderPortsList();
    document.getElementById('btn-add-scanban-port').addEventListener('click', () => {
      ports.push(randomPort());
      renderPortsList();
    });
    const form = document.getElementById('scanban-form');
    form.querySelector('[data-cancel]').addEventListener('click', closeModal);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const payload = { decoy_ports: ports.map(Number), ban_seconds: Number(fd.get('ban_seconds')) };
      try {
        const result = await api.hardenPortscanBan(server.id, payload);
        closeModal();
        toast(result.ok ? T('js.g.ps_done', payload.decoy_ports.join(', ')) : T('js.g.script_fail'), result.ok ? 'success' : 'error');
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  function openExportImportModal() {
    openModal(`
      <h3>${T('js.g.ei_title')}</h3>
      <p class="muted">${T('js.g.ei_desc')}</p>
      <div class="modal-actions" style="justify-content:flex-start">
        <a class="btn secondary" href="/api/infrastructure-export.php" target="_blank" rel="noopener">${T('js.g.ei_download')}</a>
      </div>
      <h4 style="margin:18px 0 6px">${T('js.g.ei_import_h')}</h4>
      <p class="muted" style="margin-top:-4px">${T('js.g.ei_import_note')}</p>
      <input type="file" id="import-infra-file" accept="application/json">
      <div id="import-infra-result" style="margin-top:10px"></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${window.T('common.close')}</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    overlay.querySelector('#import-infra-file').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const resultBox = document.getElementById('import-infra-result');
      resultBox.innerHTML = '<p class="muted">' + T('js.g.importing') + '</p>';
      try {
        const text = await file.text();
        const data = JSON.parse(text);
        const r = await api.importInfrastructure(data);
        const line = (label, n) => n ? `<div>${label}: <strong>${n}</strong></div>` : '';
        resultBox.innerHTML = `
          <div class="flash" style="border-color:var(--success)">
            <strong>${T('js.g.ei_done')}</strong>
            ${line(T('js.g.ei_c_servers'), r.created.servers)}
            ${line(T('js.g.ei_c_conns'), r.created.connections)}
            ${line(T('js.g.ei_c_exits'), r.created.exit_servers)}
            ${line(T('js.g.ei_c_sets'), r.created.server_sets)}
            ${line(T('js.g.ei_s_servers'), r.skipped.servers)}
            ${line(T('js.g.ei_s_conns'), r.skipped.connections)}
            ${line(T('js.g.ei_s_exits'), r.skipped.exit_servers)}
            ${line(T('js.g.ei_s_sets'), r.skipped.server_sets)}
            ${r.notes.length ? '<ul style="margin:8px 0 0;padding-left:18px">' + r.notes.map((n) => `<li>${escapeHtml(n)}</li>`).join('') + '</ul>' : ''}
          </div>
        `;
        markPending(r.created.servers + r.created.connections + r.created.exit_servers + r.created.server_sets);
        await loadGraph();
      } catch (err) {
        resultBox.innerHTML = `<div class="flash error">${escapeHtml(err.message)}</div>`;
      }
    });
  }

  function openServerSetsModal() {
    openModal(`
      <h3>${T('js.g.sets_title')}</h3>
      <div id="sets-list">${state.server_sets.map((s) => {
        const healthyCount = s.members.filter((m) => m.is_healthy).length;
        return `
        <div class="card" style="margin-bottom:8px;cursor:pointer" data-open-set="${s.id}">
          <div class="row" style="justify-content:space-between">
            <strong>${escapeHtml(s.name)}</strong>
            ${setHealthBadge(s.health_status)}
          </div>
          <div class="muted" style="margin-top:4px">${T('js.g.sets_summary', escapeHtml(s.strategy), s.members.length, s.members.length ? T('js.g.sets_healthy_suffix', healthyCount) : '')}</div>
        </div>
      `;
      }).join('') || '<p class="muted">' + T('js.g.sets_empty') + '</p>'}</div>
      <h3>${T('js.g.sets_new')}</h3>
      <form id="new-set-form">
        <div class="field-row"><label>${T('js.g.name')}</label><input name="name" required></div>
        <div class="field-row"><label>${T('js.g.strategy')}</label>
          <select name="strategy"><option value="manual">manual</option><option value="priority">priority</option><option value="failover">failover</option></select>
        </div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.close')}</button>
          <button type="submit">${T('js.g.create_btn')}</button>
        </div>
      </form>
    `);
    document.getElementById('modal-overlay').querySelectorAll('[data-open-set]').forEach((card) => {
      card.addEventListener('click', () => openServerSetDetailModal(Number(card.dataset.openSet)));
    });
    document.getElementById('new-set-form').querySelector('[data-cancel]').addEventListener('click', closeModal);
    document.getElementById('new-set-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await api.createServerSet({ name: fd.get('name'), strategy: fd.get('strategy'), enabled: true });
        markPending(1);
        toast(T('js.g.set_created'), 'success');
        closeModal();
        await loadGraph();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  function memberHealthBadge(m) {
    if (!m.exit_server_id) return '<span class="badge unknown">' + T('js.g.m_no_exit') + '</span>';
    if (m.member_status === 'maintenance') return '<span class="badge maintenance">' + T('js.g.m_maint') + '</span>';
    if (m.is_healthy) return '<span class="badge ok">' + T('js.g.m_healthy') + '</span>';
    if (m.last_health_ok_at) return '<span class="badge warn">' + T('js.g.m_stale') + '</span>';
    return '<span class="badge unknown">' + T('js.g.m_nodata') + '</span>';
  }

  /** Агрегированный статус набора — см. App\Models\ServerSet::healthStatus(). */
  function setHealthBadge(status) {
    const labels = {
      healthy: ['ok', T('js.g.h_healthy')],
      degraded: ['warn', T('js.g.h_degraded')],
      offline: ['down', T('js.g.h_offline')],
      maintenance: ['maintenance', T('js.g.h_maint')],
    };
    const [cls, label] = labels[status] || ['unknown', T('js.g.h_unknown')];
    return `<span class="badge ${cls}">${label}</span>`;
  }

  async function refreshAndReopenSetDetail(setId) {
    await loadGraph();
    if (state.server_sets.some((s) => s.id === setId)) {
      openServerSetDetailModal(setId);
    } else {
      closeModal();
    }
  }

  let setDetailTab = 'overview';

  function openServerSetDetailModal(setId) {
    const set = state.server_sets.find((s) => s.id === setId);
    if (!set) return;

    const tabs = [
      ['overview', T('js.g.set_tab_overview')],
      ['members', T('js.g.set_tab_servers')],
      ['routes', T('js.g.set_tab_routes')],
      ['advanced', T('js.g.set_tab_advanced')],
    ];
    openModal(`
      <div class="row" style="justify-content:space-between;align-items:center;gap:10px">
        <input id="set-name" value="${escapeHtml(set.name)}" style="font-size:18px;font-weight:600;flex:1;min-width:0;background:transparent;border:1px solid transparent;border-radius:8px;padding:4px 8px" title="${T('js.g.set_rename_hint')}">
        ${setHealthBadge(set.health_status)}
      </div>
      <div class="row" style="gap:2px;margin:12px 0 14px;border-bottom:1px solid var(--border)">
        ${tabs.map(([k, label]) => `<button type="button" class="set-tab" data-set-tab="${k}" style="background:none;border:none;border-bottom:2px solid transparent;color:var(--text-secondary);padding:8px 14px;cursor:pointer;font-size:14px">${label}</button>`).join('')}
      </div>
      <div id="set-tab-content" style="min-height:220px"></div>
      <div class="modal-actions" style="justify-content:space-between">
        <button type="button" class="secondary" data-back>${T('js.g.back_to_sets')}</button>
        <button type="button" data-close-set-modal>${window.T('common.close')}</button>
      </div>
    `, true);

    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-back]').addEventListener('click', openServerSetsModal);
    overlay.querySelector('[data-close-set-modal]').addEventListener('click', closeModal);

    const nameInput = overlay.querySelector('#set-name');
    nameInput.addEventListener('focus', () => { nameInput.style.borderColor = 'var(--border)'; });
    const saveName = async () => {
      const newName = nameInput.value.trim();
      nameInput.style.borderColor = 'transparent';
      if (!newName || newName === set.name) { nameInput.value = set.name; return; }
      try {
        await api.updateServerSet(setId, { name: newName, description: set.description, strategy: set.strategy, enabled: set.enabled, tags: set.tags });
        set.name = newName;
        markPending(1);
        toast(T('js.g.set_renamed'), 'success');
      } catch (e) { nameInput.value = set.name; toast(e.message, 'error'); }
    };
    nameInput.addEventListener('blur', saveName);
    nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); nameInput.blur(); } });

    overlay.querySelectorAll('[data-set-tab]').forEach((btn) => {
      if (btn.dataset.setTab === setDetailTab) { btn.style.color = 'var(--accent)'; btn.style.borderBottomColor = 'var(--accent)'; }
      btn.addEventListener('click', () => { setDetailTab = btn.dataset.setTab; renderSetTab(setId, set, overlay); overlay.querySelectorAll('[data-set-tab]').forEach((b) => { const on = b.dataset.setTab === setDetailTab; b.style.color = on ? 'var(--accent)' : 'var(--text-secondary)'; b.style.borderBottomColor = on ? 'var(--accent)' : 'transparent'; }); });
    });

    renderSetTab(setId, set, overlay);
  }

  function renderSetTab(setId, set, overlay) {
    const c = overlay.querySelector('#set-tab-content');
    const memberIds = new Set(set.members.map((m) => m.server_id));
    const availableServers = state.servers.filter((s) => !memberIds.has(s.id));
    const setRoutes = state.routes.filter((r) => r.server_set_id === setId);

    if (setDetailTab === 'overview') {
      const current = set.resolved_exit_server_id ? (set.members.find((m) => m.exit_server_id === set.resolved_exit_server_id) || null) : null;
      c.innerHTML = `
        <div class="field-row"><label>${T('js.g.strategy_label')}</label><span>${escapeHtml(set.strategy)}</span></div>
        <div class="field-row"><label>${T('js.g.set_current_exit')}</label>${current ? '● ' + escapeHtml(current.name) : '<span class="muted">—</span>'}</div>
        <div class="field-row"><label>${T('js.g.set_tab_servers')}</label>${set.members.length}</div>
        <div class="field-row"><label>${T('js.g.set_tab_routes')}</label>${setRoutes.length}</div>`;
      return;
    }

    if (setDetailTab === 'members') {
      const sorted = [...set.members].sort((a, b) => a.priority - b.priority);
      const healthy = set.members.filter((m) => m.is_healthy).length;
      c.innerHTML = `
        <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:8px">
          <span class="muted">${T('js.g.set_members_summary', set.members.length, healthy)}</span>
          <button class="secondary" id="btn-add-member">+ ${T('js.g.pick_server')}</button>
        </div>
        ${sorted.map((m, i) => `
          <div class="row" data-member-id="${m.server_id}" style="justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid var(--border);border-radius:9px;margin-bottom:6px">
            <div style="min-width:0">
              <div>${set.resolved_exit_server_id && m.exit_server_id === set.resolved_exit_server_id ? '<span class="badge ok" style="margin-right:6px">' + T('js.g.set_primary') + '</span>' : ''}${escapeHtml(m.name)}</div>
              <div class="muted" style="font-size:12px;margin-top:2px">${memberHealthBadge(m)} · ${T('js.g.priority', m.priority)}</div>
            </div>
            <div class="row" style="gap:2px">
              <button class="ghost icon-btn" data-mv-up="${m.server_id}" ${i === 0 ? 'disabled' : ''} title="↑">▲</button>
              <button class="ghost icon-btn" data-mv-down="${m.server_id}" ${i === sorted.length - 1 ? 'disabled' : ''} title="↓">▼</button>
              <button class="ghost icon-btn" data-remove-member="${m.server_id}" title="${T('js.g.remove_from_set')}">✕</button>
            </div>
          </div>`).join('') || '<div class="empty-state"><p class="muted">' + T('js.g.no_members') + '</p></div>'}`;

      c.querySelector('#btn-add-member').addEventListener('click', () => openServerPickerModal(setId, availableServers, sorted));
      c.querySelectorAll('[data-remove-member]').forEach((btn) => btn.addEventListener('click', async () => {
        try { await api.setMember(setId, Number(btn.dataset.removeMember), 'remove'); markPending(1); toast(T('js.g.member_removed'), 'success'); await refreshAndReopenSetDetail(setId); } catch (e) { toast(e.message, 'error'); }
      }));
      const reorder = async (serverId, dir) => {
        const idx = sorted.findIndex((m) => m.server_id === serverId);
        const swapIdx = idx + dir;
        if (swapIdx < 0 || swapIdx >= sorted.length) return;
        const a = sorted[idx], b = sorted[swapIdx];
        try {
          await api.setMember(setId, a.server_id, 'add', b.priority);
          await api.setMember(setId, b.server_id, 'add', a.priority);
          markPending(1); await refreshAndReopenSetDetail(setId);
        } catch (e) { toast(e.message, 'error'); }
      };
      c.querySelectorAll('[data-mv-up]').forEach((b) => b.addEventListener('click', () => reorder(Number(b.dataset.mvUp), -1)));
      c.querySelectorAll('[data-mv-down]').forEach((b) => b.addEventListener('click', () => reorder(Number(b.dataset.mvDown), 1)));
      c.querySelectorAll('[data-member-id]').forEach((row) => row.addEventListener('contextmenu', (e) => {
        const sid = Number(row.dataset.memberId);
        showContextMenu(e, [
          { label: T('js.g.mc_up'), action: () => reorder(sid, -1) },
          { label: T('js.g.mc_down'), action: () => reorder(sid, 1) },
          { label: T('js.g.mc_open_server'), action: () => { closeModal(); openInspector(sid); } },
          { label: T('js.g.remove_from_set'), danger: true, action: async () => { try { await api.setMember(setId, sid, 'remove'); markPending(1); toast(T('js.g.member_removed'), 'success'); await refreshAndReopenSetDetail(setId); } catch (err) { toast(err.message, 'error'); } } },
        ]);
      }));
      return;
    }

    if (setDetailTab === 'routes') {
      c.innerHTML = `
        <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:8px">
          <span class="muted">${T('js.g.routes_via_set', setRoutes.length)}</span>
          <span class="row" style="gap:6px">
            <button class="secondary" id="btn-add-route">${T('js.g.add_existing_route')}</button>
            <button class="secondary" id="btn-create-route-toggle">+ ${T('js.g.create_btn')}</button>
          </span>
        </div>
        <div id="set-routes" style="max-height:280px;overflow-y:auto">
          ${setRoutes.map((r) => `<div class="row" data-route-id="${r.id}" style="justify-content:space-between;padding:6px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:4px"><span>${escapeHtml(r.name)}</span><button class="ghost icon-btn" data-detach-route="${r.id}" title="${T('js.g.detach_route')}">✕</button></div>`).join('') || '<div class="empty-state"><p class="muted">' + T('js.g.no_routes_set') + '</p></div>'}
        </div>
        <div id="create-route-box" hidden style="margin-top:10px">
          <div class="row">
            <input id="new-route-name" placeholder="${T('js.g.new_route_ph')}">
            <select id="new-route-type">
              <option value="domain_suffix">${T('js.g.rt_suffix')}</option>
              <option value="domain_full">${T('js.g.rt_full')}</option>
              <option value="domain_keyword">${T('js.g.rt_keyword')}</option>
              <option value="ip_cidr">IP / CIDR</option>
              <option value="geosite">Geosite</option>
            </select>
            <button class="secondary" id="btn-create-route">${T('js.g.create_btn')}</button>
          </div>
        </div>`;
      c.querySelector('#btn-add-route').addEventListener('click', () => {
        const attachedIds = new Set(setRoutes.map((r) => r.id));
        window.RouteTreePicker.open({ title: T('js.g.add_existing_route'), excludeIds: attachedIds, onConfirm: async (ids) => {
          if (!ids.length) return;
          try { for (const rid of ids) await api.updateRoute(rid, { server_set_id: setId }); markPending(1); toast(T('js.g.route_added_set'), 'success'); await refreshAndReopenSetDetail(setId); } catch (e) { toast(e.message, 'error'); }
        } });
      });
      c.querySelector('#btn-create-route-toggle').addEventListener('click', () => { const box = c.querySelector('#create-route-box'); box.hidden = !box.hidden; });
      c.querySelector('#btn-create-route').addEventListener('click', async () => {
        const name = c.querySelector('#new-route-name').value.trim();
        if (!name) { toast(T('js.g.enter_dest'), 'error'); return; }
        try { await api.createRoute({ name, type: c.querySelector('#new-route-type').value, value: name, server_set_id: setId }); markPending(1); toast(T('js.g.route_created_set'), 'success'); await refreshAndReopenSetDetail(setId); } catch (e) { toast(e.message, 'error'); }
      });
      const detach = async (rid) => { try { await api.updateRoute(rid, { server_set_id: null }); markPending(1); toast(T('js.g.route_removed_set'), 'success'); await refreshAndReopenSetDetail(setId); } catch (e) { toast(e.message, 'error'); } };
      c.querySelectorAll('[data-detach-route]').forEach((btn) => btn.addEventListener('click', () => detach(Number(btn.dataset.detachRoute))));
      c.querySelectorAll('[data-route-id]').forEach((row) => row.addEventListener('contextmenu', (e) => {
        const rid = Number(row.dataset.routeId);
        showContextMenu(e, [
          { label: T('js.g.mc_edit_route'), action: () => { location.href = '/groups.php'; } },
          { label: T('js.g.detach_route'), danger: true, action: () => detach(rid) },
        ]);
      }));
      return;
    }

    // advanced
    c.innerHTML = `
      <div class="field-row"><label>${T('js.g.strategy_label')}</label>
        <select id="set-strategy">${['manual', 'priority', 'failover'].map((st) => `<option value="${st}" ${set.strategy === st ? 'selected' : ''}>${st}</option>`).join('')}</select>
      </div>
      <p class="muted" style="margin:16px 0 8px">${T('js.g.set_delete_note')}</p>
      <button type="button" class="danger" id="btn-delete-set">${T('js.g.del_set_btn')}</button>`;
    c.querySelector('#set-strategy').addEventListener('change', async (e) => {
      try { await api.updateServerSet(setId, { name: set.name, description: set.description, strategy: e.target.value, enabled: set.enabled, tags: set.tags }); markPending(1); toast(T('js.g.strategy_changed'), 'success'); await refreshAndReopenSetDetail(setId); } catch (err) { toast(err.message, 'error'); }
    });
    c.querySelector('#btn-delete-set').addEventListener('click', async () => {
      const choice = await confirmSetDelete(set);
      if (!choice) return;
      try {
        const res = await api.deleteServerSet(setId, choice === 'cascade');
        markPending(1);
        toast(choice === 'cascade' ? T('js.g.set_deleted_cascade', (res && res.deleted_servers) || 0) : T('js.g.set_deleted'), 'success');
        await loadGraph();
        closeModal();
      } catch (e) { toast(e.message, 'error'); }
    });
  }

  function openServerPickerModal(setId, availableServers, currentMembers) {
    const nextPriority = (currentMembers.length ? Math.max(...currentMembers.map((m) => m.priority)) : 0) + 10;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:120;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px';
    overlay.innerHTML = `<div class="card" style="max-width:520px;width:100%;margin:0">
      <div class="row" style="justify-content:space-between;align-items:center"><h3 style="margin:0">${T('js.g.pick_server')}</h3><button class="secondary" data-x>✕</button></div>
      <input type="text" data-search placeholder="${T('js.r.picker_search')}" style="margin:12px 0">
      <div data-list style="max-height:50vh;overflow:auto"></div>
    </div>`;
    document.body.appendChild(overlay);
    const listEl = overlay.querySelector('[data-list]');
    const close = () => overlay.remove();
    overlay.querySelector('[data-x]').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    let q = '';
    function render() {
      const rows = availableServers.filter((s) => !q || s.name.toLowerCase().includes(q) || (s.role || '').toLowerCase().includes(q));
      listEl.innerHTML = rows.map((s) => `<div class="row" data-pick="${s.id}" style="justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer"><span>${escapeHtml(s.name)} <span class="muted">${escapeHtml(s.role)}</span></span>${statusBadge(s.status)}</div>`).join('') || '<p class="muted">' + T('js.g.no_servers_avail') + '</p>';
      listEl.querySelectorAll('[data-pick]').forEach((row) => row.addEventListener('click', async () => {
        try { await api.setMember(setId, Number(row.dataset.pick), 'add', nextPriority); markPending(1); toast(T('js.g.member_added'), 'success'); close(); await refreshAndReopenSetDetail(setId); } catch (e) { toast(e.message, 'error'); }
      }));
    }
    overlay.querySelector('[data-search]').addEventListener('input', (e) => { q = e.target.value.toLowerCase(); render(); });
    render();
  }

  function openBulkRoutesModal() {
    openModal(`
      <h3>${T('js.g.bulk_title')}</h3>
      <form id="bulk-routes-form">
        <div class="field-row"><label>${T('js.g.bulk_dest')}</label>
          <textarea name="destinations" style="min-height:160px" placeholder="youtube.com&#10;googlevideo.com&#10;ytimg.com"></textarea>
        </div>
        <div class="field-row"><label>${T('js.g.bulk_type')}</label>
          <select name="type">
            <option value="domain_suffix">${T('js.g.rt_suffix2')}</option>
            <option value="domain_full">${T('js.g.rt_full')}</option>
            <option value="domain_keyword">${T('js.g.rt_keyword')}</option>
            <option value="ip_cidr">IP / CIDR</option>
            <option value="geosite">${T('js.g.rt_geosite')}</option>
          </select>
        </div>
        <div class="field-row"><label>Server Set</label>
          <select name="server_set_id">
            <option value="">${T('js.g.bulk_direct')}</option>
            ${state.server_sets.map((s) => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('')}
          </select>
        </div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${window.T('common.cancel')}</button>
          <button type="submit">${T('js.g.bulk_add')}</button>
        </div>
      </form>
    `);
    const form = document.getElementById('bulk-routes-form');
    form.querySelector('[data-cancel]').addEventListener('click', closeModal);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const destinations = String(fd.get('destinations') || '').split('\n').map((l) => l.trim()).filter(Boolean);
      if (!destinations.length) { toast(T('js.g.bulk_empty'), 'error'); return; }
      try {
        const r = await api.bulkRoutes({
          destinations,
          type: fd.get('type'),
          server_set_id: fd.get('server_set_id') || null,
        });
        markPending(r.created.length);
        toast(T('js.g.bulk_added', r.created.length), 'success');
        closeModal();
        await loadGraph();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  // -------------------------------------------------- preview & apply ----
  async function openPreviewModal() {
    openModal('<h3>' + T('js.g.review_title') + '</h3><p class="muted">' + T('js.g.loading2') + '</p>', true);
    try {
      const preview = await api.preview();
      const errors = preview.issues.filter((i) => i.level === 'error');
      openModal(`
        <h3>Review changes</h3>
        ${preview.issues.length ? '<ul class="issue-list">' + preview.issues.map((i) => `<li class="${i.level}">${escapeHtml(i.message)}</li>`).join('') + '</ul>' : ''}
        <ul class="diff-list">${preview.diff.map((d) => `<li class="${d.op === '+' ? 'add' : d.op === '-' ? 'remove' : d.op === '~' ? 'change' : ''}">${d.op} ${escapeHtml(d.path)} ${escapeHtml(d.note || '')}</li>`).join('')}</ul>
        <div class="field-row"><label>${T('js.g.apply_comment')}</label><input id="apply-description" placeholder="${T('js.g.apply_comment_ph')}"></div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>Cancel</button>
          <button type="submit" id="btn-apply" ${errors.length ? 'disabled title="' + T('js.g.apply_has_errors') + '"' : ''}>Apply</button>
        </div>
      `, true);
      document.querySelector('.modal [data-cancel]').addEventListener('click', closeModal);
      const applyBtn = document.getElementById('btn-apply');
      if (applyBtn) {
        applyBtn.addEventListener('click', async () => {
          applyBtn.disabled = true;
          applyBtn.textContent = T('js.g.applying');
          try {
            const desc = document.getElementById('apply-description').value || T('js.g.apply_desc_default');
            const r = await api.apply(desc);
            if (r.result.exit_code === 0) {
              toast('✓ Applied successfully', 'success');
              pendingCount = 0;
              markPending(0);
            } else {
              toast(T('js.g.apply_error', r.result.stderr || r.result.stdout), 'error');
            }
            closeModal();
            await loadGraph();
          } catch (err) {
            toast(err.message, 'error');
            applyBtn.disabled = false;
            applyBtn.textContent = 'Apply';
          }
        });
      }
    } catch (err) {
      toast(err.message, 'error');
      closeModal();
    }
  }

  /**
   * Лёгкое фоновое обновление online/offline на графе: НЕ вызывает renderGraph()
   * (та делает cy.destroy() + пересоздание — сбросило бы zoom/pan/выделение
   * каждые 30с), а точечно патчит data(status)/data(statusColor) у уже
   * существующих узлов — Cytoscape сам перекрасит border по style data(...).
   * Источник свежести — bin/health_check_servers.php по cron, не сам этот опрос.
   */
  async function refreshServerStatuses() {
    if (!cy) return;
    try {
      state = await api.infrastructure();
      state.servers.forEach((s) => {
        const node = cy.getElementById('s' + s.id);
        if (node.length) {
          node.data('status', s.status);
          node.data('statusColor', STATUS_COLOR[s.status] || STATUS_COLOR.unknown);
        }
      });
      renderKpis();
    } catch (e) {
      // Тихо игнорируем — не спамим тостами при временных сетевых сбоях фонового опроса.
    }
  }

  // ------------------------------------------------------------- utils ---
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  // -------------------------------------------------------------- init ---
  /** Сводка по всей инфраструктуре сразу (не по одному серверу за раз) — по итогам разбора статьи про Active Probing. */
  async function renderRiskBanner() {
    const banner = document.getElementById('risk-banner');
    if (!banner) return;
    try {
      const latest = await api.riskScanLatestAll();
      const risky = latest.filter((r) => r.score >= 60);
      if (!risky.length) {
        banner.classList.add('hidden');
        return;
      }
      banner.innerHTML = T('js.g.risk_banner', risky.map((r) => `<strong>${escapeHtml(r.server_name)}</strong> (${r.score}%)`).join(', '));
      banner.classList.remove('hidden');
    } catch (e) {
      banner.classList.add('hidden');
    }
  }

  async function renderUpdateBanner() {
    const banner = document.getElementById('update-banner');
    if (!banner) return;
    try {
      const s = await apiCall('/api/updates.php', 'GET');
      if (!s || !s.update_available) { banner.classList.add('hidden'); return; }
      const repo = s.repo_url
        ? ` — <a href="${escapeHtml(s.repo_url)}" target="_blank" rel="noopener">${T('js.g.update_open_repo')}</a>`
        : '';
      banner.innerHTML = T('js.g.update_available', escapeHtml(s.current), escapeHtml(s.latest || '?')) + repo;
      banner.classList.remove('hidden');
    } catch (e) {
      banner.classList.add('hidden');
    }
  }

  function init() {
    document.getElementById('btn-add-server').addEventListener('click', openAddServerModal);
    document.getElementById('btn-bulk-routes').addEventListener('click', openBulkRoutesModal);
    document.getElementById('btn-server-sets').addEventListener('click', openServerSetsModal);
    document.getElementById('btn-export-import').addEventListener('click', openExportImportModal);
    document.getElementById('btn-fit').addEventListener('click', () => cy && cy.fit(undefined, 40));

    const devBtn = document.getElementById('btn-toggle-devices');
    if (devBtn) {
      updateDevicesToggleLabel();
      devBtn.addEventListener('click', () => {
        setGraphShowDevices(!graphShowDevices());
        updateDevicesToggleLabel();
        renderGraph();          // перерисовка из уже загруженного state (без запроса)
        refreshOpenInspector();
      });
    }
    document.getElementById('btn-review-apply').addEventListener('click', openPreviewModal);
    const wizardBtn = document.getElementById('btn-wizard');
    if (wizardBtn && window.PanelMasters && window.PanelMasters.openAddServerWizard) {
      wizardBtn.addEventListener('click', () => window.PanelMasters.openAddServerWizard());
    }
    loadGraph().catch((e) => toast(T('js.g.graph_load_fail', e.message), 'error'));
    renderRiskBanner();
    renderUpdateBanner();

    // Вкладка «Конфигурация» в инспекторе — это iframe settings.php?embed=1.
    // После сохранения там он шлёт postMessage — сразу перечитываем граф/конфиг,
    // чтобы изменения Reality/протоколов подхватились без перезагрузки страницы.
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !e.data || e.data.type !== 'vr-settings-saved') return;
      loadGraph().then(() => toast(T('js.g.cfg_saved_reloaded'), 'success')).catch(() => {});
    });

    const refreshSeconds = (window.PANEL_CONFIG && window.PANEL_CONFIG.graphRefreshInterval) || 30;
    setInterval(refreshServerStatuses, Math.max(10, refreshSeconds) * 1000);
  }

  // Минимальная публичная поверхность для panel/public/assets/js/masters/*
  // (мастера — отдельные файлы, не часть этого замыкания, но переиспользуют
  // его api/toast/confirmDialog/loadGraph, чтобы не дублировать логику).
  window.Panel = {
    api, toast, confirmDialog, escapeHtml, openModal, closeModal, loadGraph, renderSshDiagnosis, wireUseSshPort, showProgress,
    getState: () => state,
  };

  document.addEventListener('DOMContentLoaded', init);
})();
