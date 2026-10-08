(function () {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
  const EXPANDED_KEY = 'vr_routes_expanded';

  // ---------------------------------------------------------------- API --
  async function apiCall(url, method, body) {
    const opts = { method, headers: { 'X-CSRF-Token': csrfToken } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data = null;
    try { data = await res.json(); } catch (e) { /* empty body */ }
    if (!res.ok) {
      throw new Error((data && data.error) || `HTTP ${res.status}`);
    }
    return data;
  }

  const api = {
    list: () => apiCall('/api/routes.php', 'GET'),
    get: (id) => apiCall(`/api/routes.php?id=${id}`, 'GET'),
    create: (data) => apiCall('/api/routes.php', 'POST', data),
    update: (id, data) => apiCall(`/api/routes.php?id=${id}`, 'PUT', data),
    remove: (id) => apiCall(`/api/routes.php?id=${id}`, 'DELETE'),
    addRule: (groupId, type, value) => apiCall(`/api/routes.php?action=add-rule&id=${groupId}`, 'POST', { type, value }),
    deleteRule: (groupId, ruleId) => apiCall(`/api/routes.php?action=delete-rule&id=${groupId}`, 'POST', { rule_id: ruleId }),
    sync: () => apiCall('/api/routes.php?action=sync', 'POST', {}),
    bulk: (data) => apiCall('/api/routes.php?action=bulk', 'POST', data),
    serverSets: () => apiCall('/api/server-sets.php', 'GET'),
    exitServers: () => apiCall('/api/exit-servers.php', 'GET'),
    checkBlocked: (domains) => apiCall('/api/block-checker.php', 'POST', { domains }),
  };

  // -------------------------------------------------------------- utils --
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function highlight(text, query) {
    const safe = escapeHtml(text);
    if (!query) return safe;
    const idx = text.toLowerCase().indexOf(query.toLowerCase());
    if (idx === -1) return safe;
    const before = escapeHtml(text.slice(0, idx));
    const match = escapeHtml(text.slice(idx, idx + query.length));
    const after = escapeHtml(text.slice(idx + query.length));
    return `${before}<mark>${match}</mark>${after}`;
  }

  const RULE_TYPE_LABELS = {
    domain_suffix: T('js.r.t_suffix'), domain_full: T('js.r.t_full'),
    domain_keyword: T('js.r.t_keyword'), ip_cidr: 'IP / CIDR', geosite: 'Geosite',
  };

  const ICON = {
    chevron: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>',
    folder: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>',
    route: '<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="12"/></svg>',
    imported: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>',
    warning: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
  };

  // ----------------------------------------------------------- toasts ----
  function toast(message, type) {
    const stack = document.getElementById('toast-stack');
    const el = document.createElement('div');
    el.className = `toast ${type || ''}`;
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(() => el.remove(), 5000);
  }

  // ----------------------------------------------------------- modals ----
  function openModal(html, wide) {
    const overlay = document.getElementById('modal-overlay');
    overlay.innerHTML = `<div class="modal ${wide ? 'modal-wide' : ''}">${html}</div>`;
    overlay.classList.remove('hidden');
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); }, { once: true });
  }
  function closeModal() {
    document.getElementById('modal-overlay').classList.add('hidden');
  }
  function confirmDialog(message, confirmLabel) {
    return new Promise((resolve) => {
      openModal(`
        <p>${message}</p>
        <div class="modal-actions">
          <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
          <button type="button" data-confirm>${escapeHtml(confirmLabel || T('js.r.confirm'))}</button>
        </div>
      `);
      const overlay = document.getElementById('modal-overlay');
      const finish = (result) => { closeModal(); resolve(result); };
      overlay.querySelector('[data-cancel]').addEventListener('click', () => finish(false));
      overlay.querySelector('[data-confirm]').addEventListener('click', () => finish(true));
      overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(false); }, { once: true });
    });
  }

  // ------------------------------------------------------ context menu ---
  function showContextMenu(x, y, items) {
    document.querySelectorAll('.context-menu').forEach((m) => m.remove());
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    items.forEach((item) => {
      if (item.separator) {
        const hr = document.createElement('div');
        hr.style.cssText = 'height:1px;background:var(--border);margin:4px 2px';
        menu.appendChild(hr);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.label;
      if (item.danger) btn.className = 'danger';
      btn.addEventListener('click', () => { menu.remove(); item.action(); });
      menu.appendChild(btn);
    });
    document.body.appendChild(menu);
    const vw = window.innerWidth, vh = window.innerHeight;
    const rect = menu.getBoundingClientRect();
    menu.style.left = Math.min(x, vw - rect.width - 8) + 'px';
    menu.style.top = Math.min(y, vh - rect.height - 8) + 'px';
    setTimeout(() => {
      document.addEventListener('click', function onDocClick() {
        menu.remove();
        document.removeEventListener('click', onDocClick);
      }, { once: true });
    }, 0);
  }

  // -------------------------------------------------------------- state --
  let state = { rows: [], byId: {}, roots: [] };
  let serverSets = [];
  let exitServers = [];
  let expanded = loadExpanded();
  let selectedId = null;
  let multiSelected = new Set();
  let searchQuery = '';
  let searchKeep = null; // Set of ids to keep visible while searching
  let pendingInlineCreate = null; // parentId (or 'root') awaiting inline group-name input
  let pendingRenameId = null;

  function loadExpanded() {
    try {
      const raw = localStorage.getItem(EXPANDED_KEY);
      return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch (e) { return new Set(); }
  }
  function saveExpanded() {
    try { localStorage.setItem(EXPANDED_KEY, JSON.stringify([...expanded])); } catch (e) { /* ignore */ }
  }

  function rebuildIndex() {
    state.byId = {};
    state.rows.forEach((r) => { r.children_ids = []; state.byId[r.id] = r; });
    state.rows.forEach((r) => {
      if (r.parent_id && state.byId[r.parent_id]) state.byId[r.parent_id].children_ids.push(r.id);
    });
    state.roots = state.rows.filter((r) => !r.parent_id || !state.byId[r.parent_id]).map((r) => r.id);
    // Верхний уровень открыт по умолчанию при первой загрузке (нет сохранённого состояния вообще).
    if (!localStorage.getItem(EXPANDED_KEY)) {
      state.roots.forEach((id) => expanded.add(id));
    }
  }

  function collectDescendants(id) {
    const node = state.byId[id];
    if (!node) return [];
    let out = [];
    node.children_ids.forEach((cid) => { out.push(cid); out = out.concat(collectDescendants(cid)); });
    return out;
  }
  function isDescendant(ancestorId, candidateId) {
    return collectDescendants(ancestorId).includes(candidateId);
  }
  function descendantLeafCount(id) {
    const node = state.byId[id];
    if (!node || !node.children_ids.length) return 1;
    return node.children_ids.reduce((sum, cid) => sum + descendantLeafCount(cid), 0);
  }
  /**
   * Узел без правил — это ещё не настроенный маршрут-лист, а скорее "папка
   * в процессе наполнения" (так создаются группы через "+ Создать группу" —
   * children_ids пока 0). Как только у узла появляется хотя бы одно правило,
   * он однозначно ведёт себя как Route: контекстное меню и "+ Добавить"
   * перестают предлагать создание вложенных элементов в нём.
   */
  function isContainer(node) {
    return node.children_ids.length > 0 || !(node.rules && node.rules.length);
  }

  // ------------------------------------------------------------- load ----
  async function loadAll() {
    // Единый индикатор (кольцо) в панели дерева на время загрузки; render() ниже
    // заменит содержимое. hideOnDone=false — панель не прячем, её перерисует render().
    var pane = document.getElementById('routes-tree-pane');
    var loader = (window.PanelLoader && pane) ? window.PanelLoader.inline(pane, (window.T ? T('common.loading') : ''), 2, false) : null;
    try {
      const [rows, sets, exits] = await Promise.all([api.list(), api.serverSets(), api.exitServers()]);
      state.rows = rows;
      serverSets = sets;
      exitServers = exits;
      rebuildIndex();
      render();
      updateSummary();
    } finally {
      if (loader) loader.done();
    }
    if (selectedId && state.byId[selectedId]) {
      renderInspector(selectedId);
    } else if (selectedId) {
      selectedId = null;
      document.getElementById('routes-inspector').classList.add('hidden');
    }
  }

  function updateSummary() {
    const enabled = state.rows.filter((r) => r.enabled).length;
    const disabled = state.rows.length - enabled;
    document.getElementById('routes-summary').textContent = state.rows.length
      ? `${enabled} active · ${disabled} disabled` : '';
  }

  // ------------------------------------------------------------ render ---
  function targetOptionsHtml(selectedValue) {
    const setOpts = serverSets.map((s) =>
      `<option value="set:${s.id}" ${selectedValue === `set:${s.id}` ? 'selected' : ''}>${escapeHtml(s.name)} (${escapeHtml(s.strategy)})</option>`
    ).join('');
    const exitOpts = exitServers.map((s) =>
      `<option value="server:${s.id}" ${selectedValue === `server:${s.id}` ? 'selected' : ''}>${escapeHtml(s.name)} [${escapeHtml(s.protocol)}]</option>`
    ).join('');
    return `
      <option value="" ${!selectedValue ? 'selected' : ''}>${T('js.r.direct_ru')}</option>
      ${setOpts ? `<optgroup label="${T('js.r.grp_sets')}">${setOpts}</optgroup>` : ''}
      ${exitOpts ? `<optgroup label="${T('js.r.grp_exits')}">${exitOpts}</optgroup>` : ''}
    `;
  }
  function targetValue(node) {
    if (node.server_set_id) return `set:${node.server_set_id}`;
    if (node.exit_server_id) return `server:${node.exit_server_id}`;
    return '';
  }
  // Бейдж чувствительности — показываем на КАЖДОМ листовом маршруте (есть
  // правила), чтобы было видно, где безопасно гонять через чужие ноды, а где нет.
  // 🟢 safe (видео/CDN) · 🟡 neutral · 🔴 sensitive (банки/почта/госуслуги).
  // Через free-exit добавляем ⚠ и повышенный %.
  function sensBadge(node) {
    const s = node.sensitivity;
    if (!s || !node.rules || !node.rules.length) return '';
    const warn = s.via_untrusted ? ' ⚠' : '';
    const title = T('js.r.sens_title', s.score) + (s.via_untrusted ? ' · ' + T('js.r.sens_via_free') : '');
    if (s.level === 'sensitive') {
      return `<span class="tree-badge" style="color:var(--danger)" title="${title}">🔴 ${T('js.r.sens_sensitive')}${warn}</span>`;
    }
    if (s.level === 'neutral') {
      return `<span class="tree-badge" style="color:var(--warning)" title="${title}">🟡${warn}</span>`;
    }
    return `<span class="tree-badge" style="color:var(--success)" title="${title}">🟢${warn}</span>`;
  }

  function badgeFor(node) {
    if ((node.exit_server_id && !node.exit_name) || (node.server_set_id && !node.server_set_name)) {
      return '<span class="tree-badge" style="color:var(--warning)">⚠ Invalid target</span>';
    }
    if (!node.enabled) return '<span class="tree-badge">Disabled</span>';
    if (node.server_set_name) return `<span class="tree-badge proxy">Proxy · ${escapeHtml(node.server_set_name)}</span>`;
    if (node.exit_name) return `<span class="tree-badge proxy">Proxy · ${escapeHtml(node.exit_name)}</span>`;
    return '<span class="tree-badge">Direct (local)</span>';
  }

  function renderRow(id, depth) {
    const node = state.byId[id];
    if (!node) return '';
    const hasChildren = node.children_ids.length > 0;
    const looksLikeFolder = isContainer(node);
    const isExpanded = expanded.has(id);
    const invalidTarget = (node.exit_server_id && !node.exit_name) || (node.server_set_id && !node.server_set_name);
    const iconCls = [
      invalidTarget ? 'error' : (node.origin === 'imported' ? 'imported' : (looksLikeFolder ? 'folder' : 'route')),
      !node.enabled ? 'muted' : '',
    ].filter(Boolean).join(' ');
    const icon = invalidTarget ? ICON.warning : (node.origin === 'imported' ? ICON.imported : (looksLikeFolder ? ICON.folder : ICON.route));
    const selectedCls = selectedId === id ? 'selected' : '';
    const multiCls = multiSelected.has(id) ? 'multi-selected' : '';
    const disabledCls = node.enabled ? '' : 'disabled-row';
    const hidden = searchKeep && !searchKeep.has(id) ? 'search-hidden' : '';
    const label = highlight(node.name, searchQuery);
    const count = hasChildren ? `<span class="tree-count">${descendantLeafCount(id)}</span>` : '';

    let labelHtml = pendingRenameId === id
      ? `<input type="text" class="tree-rename-input" data-rename-input="${id}" value="${escapeHtml(node.name)}" style="width:100%">`
      : `<span class="tree-label">${label}</span>`;

    let row = `
      <div class="tree-row ${selectedCls} ${multiCls} ${disabledCls} ${hidden}" data-id="${id}" data-depth="${depth}" draggable="true">
        <span class="tree-indent" style="width:${depth * 18}px"></span>
        <span class="tree-checkbox-slot"><input type="checkbox" data-multi="${id}" ${multiSelected.has(id) ? 'checked' : ''}></span>
        <button type="button" class="tree-toggle ${looksLikeFolder ? '' : 'spacer-only'}" data-toggle="${id}" style="transform:${isExpanded && looksLikeFolder ? 'rotate(90deg)' : 'none'}">${looksLikeFolder ? ICON.chevron : ''}</button>
        <span class="tree-icon ${iconCls}">${icon}</span>
        ${labelHtml}
        <span class="tree-meta">${sensBadge(node)}${badgeFor(node)}${count}</span>
      </div>
    `;
    if (isExpanded && looksLikeFolder) {
      if (hasChildren) {
        row += node.children_ids.map((cid) => renderRow(cid, depth + 1)).join('');
      } else if (pendingInlineCreate !== id) {
        row += emptyGroupRowHtml(depth + 1, id);
      }
      if (pendingInlineCreate === id) row += inlineCreateRowHtml(depth + 1);
    }
    return row;
  }

  function emptyGroupRowHtml(depth, parentId) {
    return `
      <div class="tree-empty-hint" style="padding-left:${depth * 18 + 30}px">
        <span class="muted">This group is empty</span>
        <button type="button" class="ghost" data-empty-add-route="${parentId}">+ Add route</button>
        <button type="button" class="ghost" data-empty-add-group="${parentId}">+ Add subgroup</button>
      </div>
    `;
  }

  function inlineCreateRowHtml(depth) {
    return `
      <div class="inline-create-row" style="padding-left:${depth * 18 + 30}px">
        <input type="text" id="inline-create-input" placeholder="${T('js.r.group_name_ph')}">
        <button type="button" class="secondary icon-btn" data-inline-confirm>✓</button>
      </div>
    `;
  }

  function render() {
    const pane = document.getElementById('routes-tree-pane');
    if (!state.rows.length && pendingInlineCreate !== 'root') {
      pane.innerHTML = `
        <div class="empty-state">
          <div class="empty-state-icon">${ICON.folder}</div>
          <h3>No routes yet</h3>
          <p class="muted">${T('js.r.empty_hint')}</p>
          <button id="empty-add-route">+ Add route</button>
        </div>`;
      pane.querySelector('#empty-add-route').addEventListener('click', () => openCreateRouteForm(null));
      return;
    }

    let html = '';
    if (multiSelected.size > 0) {
      html += `
        <div class="bulk-toolbar">
          <strong>${multiSelected.size} selected</strong>
          <div class="spacer"></div>
          <button class="secondary" data-bulk="move">Move</button>
          <button class="secondary" data-bulk="enable">Enable</button>
          <button class="secondary" data-bulk="disable">Disable</button>
          <button class="secondary" data-bulk="target">Change target</button>
          <button class="danger" data-bulk="delete">Delete</button>
          <button class="ghost icon-btn" data-bulk="clear" title="${T('js.r.clear_selection')}">✕</button>
        </div>
      `;
    }
    html += state.roots.map((id) => renderRow(id, 0)).join('');
    if (pendingInlineCreate === 'root') html += inlineCreateRowHtml(0);
    pane.innerHTML = html || '<div class="empty-state"><p class="muted">' + T('js.r.empty') + '</p></div>';

    wireInlineCreate();
    wireRename();
    wireBulkToolbar();
  }

  // ------------------------------------------------------------ events ---
  function selectNode(id) {
    selectedId = id;
    multiSelected.clear();
    render();
    renderInspector(id);
  }
  function closeInspector() {
    selectedId = null;
    document.getElementById('routes-inspector').classList.add('hidden');
    render();
  }

  document.getElementById('routes-tree-pane').addEventListener('click', (e) => {
    const checkbox = e.target.closest('[data-multi]');
    if (checkbox) {
      const id = Number(checkbox.dataset.multi);
      if (multiSelected.has(id)) multiSelected.delete(id); else multiSelected.add(id);
      render();
      return;
    }
    const toggle = e.target.closest('[data-toggle]');
    if (toggle) {
      const id = Number(toggle.dataset.toggle);
      if (expanded.has(id)) expanded.delete(id); else expanded.add(id);
      saveExpanded();
      render();
      return;
    }
    const inlineConfirm = e.target.closest('[data-inline-confirm]');
    if (inlineConfirm) { confirmInlineCreate(); return; }
    const emptyAddRoute = e.target.closest('[data-empty-add-route]');
    if (emptyAddRoute) { openCreateRouteForm(Number(emptyAddRoute.dataset.emptyAddRoute)); return; }
    const emptyAddGroup = e.target.closest('[data-empty-add-group]');
    if (emptyAddGroup) {
      const parentId = Number(emptyAddGroup.dataset.emptyAddGroup);
      pendingInlineCreate = parentId;
      render();
      return;
    }
    const row = e.target.closest('.tree-row');
    if (!row) return;
    const id = Number(row.dataset.id);
    if (pendingRenameId === id) return; // не мешаем печатать
    if (e.ctrlKey || e.metaKey) {
      if (multiSelected.has(id)) multiSelected.delete(id); else multiSelected.add(id);
      render();
      return;
    }
    if (multiSelected.size > 0) { multiSelected.clear(); }
    selectNode(id);
  });

  document.getElementById('routes-tree-pane').addEventListener('dblclick', (e) => {
    const row = e.target.closest('.tree-row');
    if (!row) return;
    const id = Number(row.dataset.id);
    const node = state.byId[id];
    if (!node) return;
    if (isContainer(node)) {
      if (expanded.has(id)) expanded.delete(id); else expanded.add(id);
      saveExpanded();
      render();
    } else {
      selectNode(id);
      const insp = document.getElementById('routes-inspector');
      const nameInput = insp.querySelector('[data-edit-name]');
      if (nameInput) nameInput.focus();
    }
  });

  document.getElementById('routes-tree-pane').addEventListener('contextmenu', (e) => {
    const row = e.target.closest('.tree-row');
    if (!row) return;
    e.preventDefault();
    const id = Number(row.dataset.id);
    if (!multiSelected.has(id)) { selectNode(id); }
    showContextMenu(e.clientX, e.clientY, contextMenuFor(id));
  });

  // drag & drop
  let dragId = null;
  document.getElementById('routes-tree-pane').addEventListener('dragstart', (e) => {
    const row = e.target.closest('.tree-row');
    if (!row) return;
    dragId = Number(row.dataset.id);
    e.dataTransfer.effectAllowed = 'move';
  });
  document.getElementById('routes-tree-pane').addEventListener('dragover', (e) => {
    const row = e.target.closest('.tree-row');
    if (!row || dragId === null) return;
    e.preventDefault();
    row.classList.add('dragover');
  });
  document.getElementById('routes-tree-pane').addEventListener('dragleave', (e) => {
    const row = e.target.closest('.tree-row');
    if (row) row.classList.remove('dragover');
  });
  document.getElementById('routes-tree-pane').addEventListener('drop', async (e) => {
    const row = e.target.closest('.tree-row');
    if (!row || dragId === null) return;
    e.preventDefault();
    row.classList.remove('dragover');
    const targetId = Number(row.dataset.id);
    const draggedId = dragId;
    dragId = null;
    if (targetId === draggedId || isDescendant(draggedId, targetId)) {
      toast(T('js.r.cant_move_into_self'), 'error');
      return;
    }
    try {
      await api.update(draggedId, { parent_id: targetId });
      expanded.add(targetId);
      saveExpanded();
      toast(T('js.r.moved'), 'success');
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  });

  function wireInlineCreate() {
    const input = document.getElementById('inline-create-input');
    if (!input) return;
    input.focus();
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') confirmInlineCreate();
      if (e.key === 'Escape') { pendingInlineCreate = null; render(); }
    });
  }
  async function confirmInlineCreate() {
    const input = document.getElementById('inline-create-input');
    const name = input ? input.value.trim() : '';
    const parentId = pendingInlineCreate;
    pendingInlineCreate = null;
    if (!name) { render(); return; }
    try {
      const parentIdValue = parentId === 'root' ? null : parentId;
      await api.create({ name, parent_id: parentIdValue });
      if (parentIdValue) { expanded.add(parentIdValue); saveExpanded(); }
      toast(T('js.r.group_created'), 'success');
      await loadAll();
    } catch (err) { toast(err.message, 'error'); render(); }
  }

  function wireRename() {
    const input = document.querySelector('[data-rename-input]');
    if (!input) return;
    input.focus();
    input.select();
    const commit = async () => {
      const id = pendingRenameId;
      const name = input.value.trim();
      pendingRenameId = null;
      if (!name || !id) { render(); return; }
      try {
        await api.update(id, { name });
        await loadAll();
      } catch (err) { toast(err.message, 'error'); render(); }
    };
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') commit();
      if (e.key === 'Escape') { pendingRenameId = null; render(); }
    });
    input.addEventListener('blur', () => { if (pendingRenameId !== null) commit(); });
  }

  function wireBulkToolbar() {
    const bar = document.querySelector('.bulk-toolbar');
    if (!bar) return;
    bar.querySelectorAll('[data-bulk]').forEach((btn) => {
      btn.addEventListener('click', () => handleBulkAction(btn.dataset.bulk));
    });
  }

  async function handleBulkAction(action) {
    const ids = [...multiSelected];
    if (action === 'clear') { multiSelected.clear(); render(); return; }
    if (action === 'move') { openMovePicker(ids); return; }
    if (action === 'target') { openBulkTargetForm(ids); return; }
    if (action === 'enable' || action === 'disable') {
      try {
        for (const id of ids) await api.update(id, { enabled: action === 'enable' });
        toast(action === 'enable' ? T('js.r.enabled') : T('js.r.disabled'), 'success');
        multiSelected.clear();
        await loadAll();
      } catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (action === 'delete') {
      const totalDescendants = ids.reduce((sum, id) => sum + collectDescendants(id).length, 0);
      const msg = T('js.r.del_selected', ids.length, totalDescendants ? T('js.r.with_nested', totalDescendants) : '');
      if (!(await confirmDialog(msg, T('js.r.del')))) return;
      try {
        const all = new Set();
        ids.forEach((id) => { collectDescendants(id).forEach((d) => all.add(d)); all.add(id); });
        const ordered = [...all].sort((a, b) => depthOf(b) - depthOf(a));
        for (const id of ordered) await api.remove(id);
        toast(T('js.r.deleted'), 'success');
        multiSelected.clear();
        await loadAll();
      } catch (err) { toast(err.message, 'error'); }
    }
  }
  function depthOf(id) {
    let d = 0, node = state.byId[id];
    while (node && node.parent_id && state.byId[node.parent_id]) { d++; node = state.byId[node.parent_id]; }
    return d;
  }

  // ------------------------------------------------------- context menu --
  function contextMenuFor(id) {
    const node = state.byId[id];
    const container = isContainer(node);

    if (node.origin === 'imported') {
      const items = [
        { label: T('js.r.m_open_list'), action: () => selectNode(id) },
        { label: T('js.r.m_sync'), action: () => triggerSync() },
        { label: T('js.r.m_edit'), action: () => { selectNode(id); pendingRenameId = id; render(); } },
        { separator: true },
        { label: T('js.r.m_add_to_group'), action: () => openMovePicker([id]) },
      ];
      if (!container) items.push({ label: T('js.r.m_duplicate'), action: () => duplicateNode(id) });
      items.push({ separator: true });
      items.push({ label: node.enabled ? T('js.r.m_disable') : T('js.r.m_enable'), action: () => (container ? handleBulkAction2(id, !node.enabled) : toggleEnabled(id)) });
      items.push({ separator: true });
      items.push({ label: T('js.r.m_delete'), danger: true, action: () => deleteNode(id) });
      return items;
    }

    const items = [];
    if (container) {
      items.push({ label: T('js.r.m_add_route'), action: () => openCreateRouteForm(id) });
      items.push({ label: T('js.r.m_create_group'), action: () => { expanded.add(id); saveExpanded(); pendingInlineCreate = id; render(); } });
      items.push({ label: T('js.r.m_import_file'), action: () => openFileImportModal(id) });
      items.push({ separator: true });
    }
    items.push({ label: T('js.r.m_rename'), action: () => { pendingRenameId = id; render(); } });
    if (!container) items.push({ label: T('js.r.m_duplicate'), action: () => duplicateNode(id) });
    items.push({ label: T('js.r.m_move'), action: () => openMovePicker([id]) });
    items.push({ separator: true });
    if (container) {
      items.push({ label: T('js.r.m_enable_all'), action: () => handleBulkAction2(id, true) });
      items.push({ label: T('js.r.m_disable_all'), action: () => handleBulkAction2(id, false) });
    } else {
      items.push({ label: node.enabled ? T('js.r.m_disable') : T('js.r.m_enable'), action: () => toggleEnabled(id) });
    }
    if (node.parent_id) items.push({ label: T('js.r.m_open_parent'), action: () => selectNode(node.parent_id) });
    items.push({ separator: true });
    items.push({ label: T('js.r.m_delete'), danger: true, action: () => deleteNode(id) });
    return items;
  }

  async function handleBulkAction2(id, enabled) {
    try {
      const ids = [id, ...collectDescendants(id)];
      for (const cid of ids) await api.update(cid, { enabled });
      toast(enabled ? T('js.r.enabled') : T('js.r.disabled'), 'success');
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  }
  async function toggleEnabled(id) {
    const node = state.byId[id];
    try {
      await api.update(id, { enabled: !node.enabled });
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  }

  async function deleteNode(id) {
    const node = state.byId[id];
    const descendants = collectDescendants(id);
    const msg = descendants.length
      ? T('js.r.del_group_nested', escapeHtml(node.name), descendants.length)
      : T('js.r.del_one', escapeHtml(node.name));
    if (!(await confirmDialog(msg, T('js.r.del')))) return;
    try {
      const ordered = [...descendants].sort((a, b) => depthOf(b) - depthOf(a));
      for (const cid of ordered) await api.remove(cid);
      await api.remove(id);
      toast(T('js.r.deleted'), 'success');
      if (selectedId === id || descendants.includes(selectedId)) closeInspector();
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  }

  async function duplicateNode(id) {
    const node = state.byId[id];
    try {
      const full = await api.get(id);
      const created = await api.create({
        name: node.name + T('js.r.copy_suffix'),
        parent_id: node.parent_id || null,
        server_set_id: node.server_set_id || null,
        exit_server_id: node.exit_server_id || null,
        comment: node.comment || null,
      });
      for (const r of (full.rules || [])) {
        await api.addRule(created.id, r.type, r.value);
      }
      toast(T('js.r.duplicated'), 'success');
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  }

  async function triggerSync() {
    toast(T('js.r.sync_started'), '');
    try {
      const result = await api.sync();
      toast(T('js.r.sync_result', result.imported, result.skipped, result.errors && result.errors.length ? T('js.r.sync_errors', result.errors.length) : ''), result.errors && result.errors.length ? 'error' : 'success');
      await loadAll();
    } catch (err) { toast(err.message, 'error'); }
  }

  // ------------------------------------------------------- move picker ---
  function openMovePicker(ids) {
    const excluded = new Set(ids);
    ids.forEach((id) => collectDescendants(id).forEach((d) => excluded.add(d)));
    function renderPickerRows(list, depth) {
      return list.map((id) => {
        const node = state.byId[id];
        const disabled = excluded.has(id);
        const html = `
          <div class="tree-row ${disabled ? 'disabled-row' : ''}" style="cursor:${disabled ? 'not-allowed' : 'pointer'}" ${disabled ? '' : `data-move-target="${id}"`}>
            <span class="tree-indent" style="width:${depth * 18}px"></span>
            <span class="tree-icon folder">${ICON.folder}</span>
            <span class="tree-label">${escapeHtml(node.name)}</span>
          </div>
        `;
        return html + (node.children_ids.length ? renderPickerRows(node.children_ids, depth + 1) : '');
      }).join('');
    }
    const label = ids.length > 1 ? T('js.r.n_items', ids.length) : `«${escapeHtml(state.byId[ids[0]].name)}»`;
    openModal(`
      <h3>${T('js.r.move_title', label)}</h3>
      <p class="muted" style="margin-top:-6px">${T('js.r.move_hint')}</p>
      <div style="max-height:340px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:4px">
        <div class="tree-row" data-move-target="root"><span class="tree-icon folder">${ICON.folder}</span><span class="tree-label">${T('js.r.to_root')}</span></div>
        ${renderPickerRows(state.roots, 0)}
      </div>
      <div class="modal-actions"><button type="button" class="secondary" data-cancel>${T('common.cancel')}</button></div>
    `, true);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    overlay.querySelectorAll('[data-move-target]').forEach((row) => {
      row.addEventListener('click', async () => {
        const target = row.dataset.moveTarget;
        const parentId = target === 'root' ? null : Number(target);
        closeModal();
        try {
          for (const id of ids) await api.update(id, { parent_id: parentId });
          if (parentId) { expanded.add(parentId); saveExpanded(); }
          multiSelected.clear();
          toast(T('js.r.moved'), 'success');
          await loadAll();
        } catch (err) { toast(err.message, 'error'); }
      });
    });
  }

  // -------------------------------------------------- create route form --
  function openCreateRouteForm(parentId) {
    openModal(`
      <h3>${T('js.r.new_route')}</h3>
      <div class="field-row"><label>Destination</label><input id="f-dest" placeholder="youtube.com"></div>
      <div class="field-row"><label>${T('js.r.type')}</label>
        <select id="f-type">
          ${Object.entries(RULE_TYPE_LABELS).map(([v, l]) => `<option value="${v}">${escapeHtml(l)}</option>`).join('')}
        </select>
      </div>
      <div class="field-row"><label>Exit target</label><select id="f-target">${targetOptionsHtml('')}</select></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
        <button type="button" data-create>${T('js.r.create')}</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    overlay.querySelector('#f-dest').focus();
    overlay.querySelector('[data-create]').addEventListener('click', async () => {
      const dest = overlay.querySelector('#f-dest').value.trim();
      if (!dest) { toast(T('js.r.enter_dest'), 'error'); return; }
      const type = overlay.querySelector('#f-type').value;
      const targetVal = overlay.querySelector('#f-target').value;
      const body = { name: dest, type, value: dest, parent_id: parentId || null };
      if (targetVal.startsWith('set:')) body.server_set_id = Number(targetVal.slice(4));
      else if (targetVal.startsWith('server:')) body.exit_server_id = Number(targetVal.slice(7));
      closeModal();
      try {
        const created = await api.create(body);
        if (parentId) { expanded.add(parentId); saveExpanded(); }
        toast(T('js.r.route_created'), 'success');
        await loadAll();
        selectNode(created.id);
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  function openBulkTargetForm(ids) {
    openModal(`
      <h3>${ids.length} routes selected</h3>
      <div class="field-row"><label>Exit target</label><select id="f-bulk-target">${targetOptionsHtml('')}</select></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
        <button type="button" data-apply>Apply to ${ids.length} routes</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    overlay.querySelector('[data-apply]').addEventListener('click', async () => {
      const targetVal = overlay.querySelector('#f-bulk-target').value;
      const body = { server_set_id: null, exit_server_id: null };
      if (targetVal.startsWith('set:')) { body.server_set_id = Number(targetVal.slice(4)); delete body.exit_server_id; }
      else if (targetVal.startsWith('server:')) { body.exit_server_id = Number(targetVal.slice(7)); delete body.server_set_id; }
      closeModal();
      try {
        for (const id of ids) await api.update(id, body);
        multiSelected.clear();
        toast(T('js.r.applied'), 'success');
        await loadAll();
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  // -------------------------------------------------------------- "+" ----
  document.getElementById('btn-routes-add').addEventListener('click', (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const anchorId = selectedId;
    const anchorNode = anchorId ? state.byId[anchorId] : null;
    const parentForNew = anchorNode ? (isContainer(anchorNode) ? anchorId : (anchorNode.parent_id || null)) : null;
    showContextMenu(rect.left, rect.bottom + 4, [
      { label: '+ Route', action: () => openCreateRouteForm(parentForNew) },
      { label: '+ Group', action: () => { if (parentForNew) { expanded.add(parentForNew); saveExpanded(); } pendingInlineCreate = parentForNew || 'root'; render(); } },
      { label: T('js.r.import_file_csv'), action: () => openFileImportModal(parentForNew) },
      { label: '⟳ Sync GitHub list (RockBlack-VPN)', action: () => triggerSync() },
    ]);
  });
  document.getElementById('btn-routes-sync').addEventListener('click', triggerSync);

  function flatGroupOptionsHtml() {
    let html = '<option value="">' + T('js.r.to_root') + '</option>';
    (function walk(ids, depth) {
      ids.forEach((id) => {
        const node = state.byId[id];
        html += `<option value="${id}">${'　'.repeat(depth)}${escapeHtml(node.name)}</option>`;
        walk(node.children_ids, depth + 1);
      });
    })(state.roots, 0);
    return html;
  }

  function openFileImportModal(initialParentId) {
    let destinations = [];
    openModal(`
      <h3>${T('js.r.import_title')}</h3>
      <p class="muted">${T('js.r.import_desc')}</p>
      <div class="field-row"><label>${T('js.r.file')}</label><input type="file" id="import-file" accept=".txt,.csv,text/plain,text/csv"></div>
      <div id="import-preview" class="muted" style="margin-bottom:12px">${T('js.r.no_file')}</div>
      <div class="field-row"><label>${T('js.r.type_for_all')}</label>
        <select id="import-type">
          ${Object.entries(RULE_TYPE_LABELS).map(([v, l]) => `<option value="${v}">${escapeHtml(l)}</option>`).join('')}
        </select>
      </div>
      <div class="field-row"><label>${T('js.r.parent_group')}</label><select id="import-parent">${flatGroupOptionsHtml()}</select></div>
      <div class="field-row"><label>Exit target</label><select id="import-target">${targetOptionsHtml('')}</select></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
        <button type="button" id="btn-do-import" disabled>${T('js.r.import_btn')}</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    if (initialParentId) overlay.querySelector('#import-parent').value = String(initialParentId);

    const fileInput = overlay.querySelector('#import-file');
    const preview = overlay.querySelector('#import-preview');
    const importBtn = overlay.querySelector('#btn-do-import');
    fileInput.addEventListener('change', () => {
      const file = fileInput.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        destinations = String(reader.result)
          .split(/\r?\n/)
          .map((line) => line.split(',')[0].trim())
          .filter(Boolean);
        preview.textContent = destinations.length
          ? T('js.r.found_addrs', destinations.length, destinations.slice(0, 3).join(', ') + (destinations.length > 3 ? '…' : ''))
          : T('js.r.no_addrs');
        importBtn.disabled = destinations.length === 0;
      };
      reader.onerror = () => { preview.textContent = T('js.r.read_fail'); };
      reader.readAsText(file);
    });

    importBtn.addEventListener('click', async () => {
      const type = overlay.querySelector('#import-type').value;
      const parentVal = overlay.querySelector('#import-parent').value;
      const targetVal = overlay.querySelector('#import-target').value;
      const body = { destinations, type, parent_id: parentVal ? Number(parentVal) : null };
      if (targetVal.startsWith('set:')) body.server_set_id = Number(targetVal.slice(4));
      else if (targetVal.startsWith('server:')) body.exit_server_id = Number(targetVal.slice(7));
      importBtn.disabled = true;
      importBtn.textContent = T('js.r.importing');
      try {
        const r = await api.bulk(body);
        toast(T('js.r.routes_imported', r.created.length), 'success');
        if (parentVal) { expanded.add(Number(parentVal)); saveExpanded(); }
        closeModal();
        await loadAll();
      } catch (err) {
        toast(err.message, 'error');
        importBtn.disabled = false;
        importBtn.textContent = T('js.r.import_btn');
      }
    });
  }

  // ------------------------------------------------------------ search --
  const searchInput = document.getElementById('routes-search');
  let searchDebounce = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => applySearch(searchInput.value), 150);
  });
  function applySearch(query) {
    searchQuery = query.trim();
    if (!searchQuery) { searchKeep = null; render(); return; }
    const q = searchQuery.toLowerCase();
    const matches = new Set();
    state.rows.forEach((r) => {
      const nameMatch = r.name.toLowerCase().includes(q);
      const ruleMatch = (r.rules || []).some((ru) => ru.value.toLowerCase().includes(q));
      if (nameMatch || ruleMatch) matches.add(r.id);
    });
    const keep = new Set();
    matches.forEach((id) => {
      keep.add(id);
      let p = state.byId[id] && state.byId[id].parent_id;
      while (p && state.byId[p]) {
        keep.add(p);
        expanded.add(p);
        p = state.byId[p].parent_id;
      }
    });
    searchKeep = keep;
    render();
  }

  // -------------------------------------------------------- inspector ----
  function renderInspector(id) {
    const node = state.byId[id];
    const inspector = document.getElementById('routes-inspector');
    if (!node) { inspector.classList.add('hidden'); return; }
    inspector.classList.remove('hidden');
    const hasChildren = node.children_ids.length > 0;
    const kind = node.origin === 'imported' ? 'IMPORTED LIST' : (isContainer(node) ? 'GROUP' : 'ROUTE');

    let statsHtml = '';
    if (hasChildren && node.origin !== 'imported') {
      const subgroups = node.children_ids.filter((cid) => state.byId[cid].children_ids.length > 0).length;
      statsHtml = `<div class="field-row"><label>${T('js.r.composition')}</label>${descendantLeafCount(id)} routes${subgroups ? `, ${subgroups} subgroups` : ''}</div>`;
    }
    if (node.origin === 'imported') {
      const importedCount = hasChildren ? descendantLeafCount(id) : ((node.rules || []).length);
      statsHtml += `<div class="field-row"><label>${T('js.r.imported')}</label>${importedCount} destinations</div>`;
      statsHtml += `<div class="field-row"><label>${T('js.r.source')}</label><span class="muted" style="word-break:break-all">${escapeHtml(node.import_source_path || '—')}</span></div>`;
      statsHtml += `<div class="field-row"><label>${T('js.r.status')}</label><span class="badge ok">${T('js.r.synced')}</span></div>`;
    }

    let rulesHtml = '';
    if (!hasChildren) {
      const rules = node.rules || [];
      rulesHtml = `
        <div class="field-row"><label>${T('js.r.rules_n', rules.length)}</label>
          <ul class="inspector-rule-list" id="insp-rules">
            ${rules.map((r) => `
              <li data-rule="${r.id}">
                <span><span class="rule-type">${escapeHtml(RULE_TYPE_LABELS[r.type] || r.type)}</span><br>${escapeHtml(r.value)}</span>
                <button type="button" class="ghost icon-btn" data-del-rule="${r.id}" title="${T('js.r.del_rule')}">✕</button>
              </li>
            `).join('') || '<li class="muted">' + T('js.r.no_rules') + '</li>'}
          </ul>
        </div>
        <div class="row" style="margin-bottom:14px">
          <select id="insp-new-rule-type" style="flex:0 0 auto">
            ${Object.entries(RULE_TYPE_LABELS).map(([v, l]) => `<option value="${v}">${escapeHtml(l)}</option>`).join('')}
          </select>
          <input id="insp-new-rule-value" placeholder="${T('js.r.value_ph')}" style="flex:1;min-width:0">
          <button type="button" class="secondary" id="insp-add-rule">+</button>
        </div>
        ${rules.some((r) => r.type.startsWith('domain')) ? `
          <div class="row" style="margin-bottom:8px">
            <button type="button" class="secondary" id="insp-check-blocked">${T('js.r.check_blocked')}</button>
          </div>
          <div id="insp-blocked-result"></div>
        ` : ''}
      `;
    }

    inspector.innerHTML = `
      <div class="inspector-header">
        <strong>${escapeHtml(node.name)}</strong>
        <button class="secondary icon-btn" data-close>✕</button>
      </div>
      <div class="inspector-body">
        <p class="muted" style="margin-top:-8px">${kind}</p>
        <div class="field-row"><label>${T('js.r.name')}</label><input data-edit-name value="${escapeHtml(node.name)}"></div>
        ${statsHtml}
        <div class="field-row"><label>Exit target</label><select id="insp-target">${targetOptionsHtml(targetValue(node))}</select></div>
        <div class="field-row">
          <label>${T('js.r.status')}</label>
          <label class="vr-checkbox"><input type="checkbox" id="insp-enabled" ${node.enabled ? 'checked' : ''}><span class="box"></span> ${T('js.r.enabled_cb')}</label>
        </div>
        ${rulesHtml}
        ${node.parent_id ? `<div class="field-row"><label>${T('js.r.parent')}</label><a href="#" id="insp-open-parent">${escapeHtml((state.byId[node.parent_id] || {}).name || '—')}</a></div>` : ''}
        <div class="row">
          ${node.origin === 'imported' ? '<button class="secondary" id="insp-sync">' + T('js.r.sync_now') + '</button>' : ''}
          <button class="danger" id="insp-delete">${T('js.r.del')}</button>
        </div>
      </div>
    `;

    const syncBtn = inspector.querySelector('#insp-sync');
    if (syncBtn) syncBtn.addEventListener('click', triggerSync);
    inspector.querySelector('[data-close]').addEventListener('click', closeInspector);
    inspector.querySelector('[data-edit-name]').addEventListener('change', async (e) => {
      try { await api.update(id, { name: e.target.value.trim() || node.name }); await loadAll(); }
      catch (err) { toast(err.message, 'error'); }
    });
    inspector.querySelector('#insp-target').addEventListener('change', async (e) => {
      const val = e.target.value;
      const body = {};
      if (val.startsWith('set:')) body.server_set_id = Number(val.slice(4));
      else if (val.startsWith('server:')) body.exit_server_id = Number(val.slice(7));
      else body.server_set_id = null;
      try { await api.update(id, body); await loadAll(); }
      catch (err) { toast(err.message, 'error'); }
    });
    inspector.querySelector('#insp-enabled').addEventListener('change', async (e) => {
      try { await api.update(id, { enabled: e.target.checked }); await loadAll(); }
      catch (err) { toast(err.message, 'error'); }
    });
    const delBtn = inspector.querySelector('#insp-delete');
    if (delBtn) delBtn.addEventListener('click', () => deleteNode(id));
    const parentLink = inspector.querySelector('#insp-open-parent');
    if (parentLink) parentLink.addEventListener('click', (e) => { e.preventDefault(); selectNode(node.parent_id); });

    const addRuleBtn = inspector.querySelector('#insp-add-rule');
    if (addRuleBtn) {
      addRuleBtn.addEventListener('click', async () => {
        const type = inspector.querySelector('#insp-new-rule-type').value;
        const value = inspector.querySelector('#insp-new-rule-value').value.trim();
        if (!value) return;
        try { await api.addRule(id, type, value); await loadAll(); }
        catch (err) { toast(err.message, 'error'); }
      });
    }
    inspector.querySelectorAll('[data-del-rule]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try { await api.deleteRule(id, Number(btn.dataset.delRule)); await loadAll(); }
        catch (err) { toast(err.message, 'error'); }
      });
    });

    const checkBlockedBtn = inspector.querySelector('#insp-check-blocked');
    if (checkBlockedBtn) {
      checkBlockedBtn.addEventListener('click', async () => {
        const domains = (node.rules || []).filter((r) => r.type.startsWith('domain')).map((r) => r.value);
        const box = inspector.querySelector('#insp-blocked-result');
        checkBlockedBtn.disabled = true;
        checkBlockedBtn.textContent = T('js.r.checking');
        box.innerHTML = '<p class="muted">' + T('js.r.checking_desc') + '</p>';
        try {
          const results = await api.checkBlocked(domains);
          const cls = { ok: 'ok', blocked: 'down', down: 'warn' };
          box.innerHTML = `<ul style="padding:0;margin:8px 0 0;list-style:none">${results.map((r) => `
            <li style="margin-bottom:6px">
              <span class="badge ${cls[r.verdict] || 'unknown'}">${escapeHtml(r.verdict)}</span>
              ${escapeHtml(r.domain)}
              <div class="muted" style="font-size:12px">${escapeHtml(r.note)}</div>
            </li>
          `).join('')}</ul>`;
        } catch (err) {
          box.innerHTML = `<div class="flash error">${escapeHtml(err.message)}</div>`;
        } finally {
          checkBlockedBtn.disabled = false;
          checkBlockedBtn.textContent = T('js.r.check_blocked');
        }
      });
    }
  }

  // -------------------------------------------------------- shortcuts ----
  document.addEventListener('keydown', (e) => {
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';

    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      searchInput.focus();
      return;
    }
    if (e.key === 'Escape') {
      if (pendingInlineCreate !== null) { pendingInlineCreate = null; render(); return; }
      if (pendingRenameId !== null) { pendingRenameId = null; render(); return; }
      if (multiSelected.size) { multiSelected.clear(); render(); return; }
      if (!document.getElementById('modal-overlay').classList.contains('hidden')) closeModal();
      return;
    }
    if (typing) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
      e.preventDefault();
      const node = selectedId ? state.byId[selectedId] : null;
      const siblings = node && node.parent_id ? (state.byId[node.parent_id].children_ids) : state.roots;
      multiSelected = new Set(siblings);
      render();
      return;
    }
    if (!selectedId) return;
    if (e.key === ' ') { e.preventDefault(); toggleEnabled(selectedId); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') { deleteNode(selectedId); return; }
    if (e.key === 'Enter') {
      const insp = document.getElementById('routes-inspector');
      const nameInput = insp.querySelector('[data-edit-name]');
      if (nameInput) nameInput.focus();
    }
  });

  // -------------------------------------------------------------- init ---
  loadAll().catch((e) => toast(T('js.r.load_fail', e.message), 'error'));
})();
