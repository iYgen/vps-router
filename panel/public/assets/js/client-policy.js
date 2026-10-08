(function () {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

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
    profiles: () => apiCall('/api/policy-profiles.php', 'GET'),
    profile: (id) => apiCall(`/api/policy-profiles.php?id=${id}`, 'GET'),
    createProfile: (data) => apiCall('/api/policy-profiles.php', 'POST', data),
    updateProfile: (id, data) => apiCall(`/api/policy-profiles.php?id=${id}`, 'PUT', data),
    deleteProfile: (id) => apiCall(`/api/policy-profiles.php?id=${id}`, 'DELETE'),
    profileRoute: (id, ruleGroupId, op) =>
      apiCall(`/api/policy-profiles.php?id=${id}&action=routes`, 'POST', { rule_group_id: ruleGroupId, op }),
    renderProfile: (id) => apiCall(`/api/policy-profiles.php?id=${id}&action=render`, 'POST', {}),
    publishProfile: (id) => apiCall(`/api/policy-profiles.php?id=${id}&action=publish`, 'POST', {}),
    versions: (id) => apiCall(`/api/policy-profiles.php?id=${id}&action=versions`, 'GET'),

    devices: () => apiCall('/api/policy-devices.php', 'GET'),
    createDevice: (data) => apiCall('/api/policy-devices.php', 'POST', data),
    updateDevice: (id, data) => apiCall(`/api/policy-devices.php?id=${id}`, 'PUT', data),
    deleteDevice: (id) => apiCall(`/api/policy-devices.php?id=${id}`, 'DELETE'),
    syncDevice: (id) => apiCall(`/api/policy-devices.php?id=${id}&action=sync`, 'POST', {}),

    routes: () => apiCall('/api/routes.php', 'GET'),
    peers: () => apiCall('/api/exit-server-peers.php', 'GET'),
    pools: () => apiCall('/api/exit-server-pools.php', 'GET'),
  };

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function toast(message, type) {
    const stack = document.getElementById('toast-stack');
    const el = document.createElement('div');
    el.className = `toast ${type || ''}`;
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(() => el.remove(), 5000);
  }

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
          <button type="button" data-confirm>${escapeHtml(confirmLabel || T('js.cp.confirm'))}</button>
        </div>
      `);
      const overlay = document.getElementById('modal-overlay');
      const finish = (result) => { closeModal(); resolve(result); };
      overlay.querySelector('[data-cancel]').addEventListener('click', () => finish(false));
      overlay.querySelector('[data-confirm]').addEventListener('click', () => finish(true));
      overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(false); }, { once: true });
    });
  }

  const ACTION_LABELS = { direct_local: T('js.cp.act_direct'), proxy: T('js.cp.act_proxy'), block: T('js.cp.act_block') };
  const SYNC_BADGE = {
    never: ['unknown', T('js.cp.st_never')],
    syncing: ['warn', T('js.cp.st_syncing')],
    ok: ['ok', T('js.cp.st_ok')],
    failed: ['down', T('js.cp.st_failed')],
  };

  let state = { profiles: [], devices: [], routes: [], peers: [], pools: [] };

  // ------------------------------------------------------------- tabs ----
  function initTabs() {
    document.querySelectorAll('.cp-tab').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.cp-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.cp-panel').forEach((p) => p.classList.add('hidden'));
        document.getElementById('cp-' + btn.dataset.tab).classList.remove('hidden');
      });
    });
  }

  // ---------------------------------------------------------- profiles ---
  async function loadProfiles() {
    state.profiles = await api.profiles();
    renderProfilesList();
  }

  function renderProfilesList() {
    const box = document.getElementById('cp-profiles');
    box.innerHTML = `
      <div class="row" style="justify-content:flex-end;margin-bottom:10px">
        <button id="btn-new-profile">${T('js.cp.new_profile_btn')}</button>
      </div>
      ${state.profiles.map((p) => `
        <div class="cp-card" data-profile-id="${p.id}">
          <div class="cp-card-title">
            <strong>${escapeHtml(p.name)}</strong>
            <span class="badge ${p.enabled ? 'ok' : 'unknown'}">${p.enabled ? T('js.cp.enabled') : T('js.cp.disabled')}</span>
          </div>
          <div class="cp-card-meta">${T('js.cp.default_is', ACTION_LABELS[p.default_action] || p.default_action)}</div>
        </div>
      `).join('') || '<p class="muted">' + T('js.cp.no_profiles') + '</p>'}
    `;
    box.querySelectorAll('[data-profile-id]').forEach((card) => {
      card.addEventListener('click', () => openProfileDetail(Number(card.dataset.profileId)));
    });
    document.getElementById('btn-new-profile').addEventListener('click', openNewProfileModal);
  }

  function openNewProfileModal() {
    openModal(`
      <h3>${T('js.cp.new_profile')}</h3>
      <div class="field-row"><label>${T('js.cp.name')}</label><input id="np-name" required placeholder="Personal"></div>
      <div class="field-row"><label>${T('js.cp.default_action')}</label>
        <select id="np-action">
          <option value="direct_local">${T('js.cp.opt_direct')}</option>
          <option value="proxy">${T('js.cp.opt_proxy')}</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
        <button type="button" id="btn-create-profile">${T('js.cp.create')}</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);
    overlay.querySelector('#btn-create-profile').addEventListener('click', async () => {
      const name = overlay.querySelector('#np-name').value.trim();
      if (!name) { toast(T('js.cp.enter_name'), 'error'); return; }
      try {
        await api.createProfile({ name, default_action: overlay.querySelector('#np-action').value });
        toast(T('js.cp.profile_created'), 'success');
        closeModal();
        await loadProfiles();
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  async function openProfileDetail(profileId) {
    const [profile, routes] = await Promise.all([api.profile(profileId), api.routes()]);
    state.routes = routes;
    const assignedIds = new Set((profile.routes || []).map((r) => r.id));
    const available = routes.filter((r) => !assignedIds.has(r.id));

    openModal(`
      <h3>${escapeHtml(profile.name)}</h3>
      <div class="field-row">
        <label>${T('js.cp.default_action')}</label>
        <select id="pd-action">
          ${['direct_local', 'proxy'].map((a) => `<option value="${a}" ${profile.default_action === a ? 'selected' : ''}>${ACTION_LABELS[a]}</option>`).join('')}
        </select>
      </div>

      <h4 style="margin:16px 0 6px">${T('js.cp.routes_in_profile', (profile.routes || []).length)}</h4>
      <div id="pd-routes" style="max-height:180px;overflow-y:auto">
        ${(profile.routes || []).map((r) => `
          <div class="row" style="justify-content:space-between;margin-bottom:4px">
            <span>${escapeHtml(r.name)}</span>
            <button class="ghost icon-btn" data-remove-route="${r.id}" title="${T('js.cp.remove_from_profile')}">✕</button>
          </div>
        `).join('') || '<p class="muted">' + T('js.cp.no_routes') + '</p>'}
      </div>
      <div class="row" style="margin-top:8px">
        <select id="pd-add-route">
          <option value="">${T('js.cp.add_route_opt')}</option>
          ${available.map((r) => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('')}
        </select>
        <button class="secondary" id="btn-add-route">${T('js.cp.add_btn')}</button>
      </div>

      <h4 style="margin:16px 0 6px">${T('js.cp.publishing')}</h4>
      <p class="muted" style="margin-top:-4px">${T('js.cp.publishing_desc')}</p>
      <div id="pd-preview"></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('js.cp.close')}</button>
        <button type="button" class="danger" id="btn-delete-profile">${T('js.cp.delete_profile_btn')}</button>
        <button type="button" class="secondary" id="btn-preview-profile">${T('js.cp.build_preview')}</button>
        <button type="button" id="btn-publish-profile">${T('js.cp.publish')}</button>
      </div>
    `, true);

    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);

    overlay.querySelector('#pd-action').addEventListener('change', async (e) => {
      try {
        await api.updateProfile(profileId, { name: profile.name, default_action: e.target.value });
        toast(T('js.cp.saved'), 'success');
      } catch (err) { toast(err.message, 'error'); }
    });

    overlay.querySelectorAll('[data-remove-route]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api.profileRoute(profileId, Number(btn.dataset.removeRoute), 'remove');
          toast(T('js.cp.route_removed'), 'success');
          await openProfileDetail(profileId);
        } catch (err) { toast(err.message, 'error'); }
      });
    });

    overlay.querySelector('#btn-add-route').addEventListener('click', async () => {
      const routeId = Number(overlay.querySelector('#pd-add-route').value);
      if (!routeId) { toast(T('js.cp.pick_route'), 'error'); return; }
      try {
        await api.profileRoute(profileId, routeId, 'add');
        toast(T('js.cp.route_added'), 'success');
        await openProfileDetail(profileId);
      } catch (err) { toast(err.message, 'error'); }
    });

    overlay.querySelector('#btn-preview-profile').addEventListener('click', async () => {
      const box = overlay.querySelector('#pd-preview');
      box.innerHTML = '<p class="muted">' + T('js.cp.building') + '</p>';
      try {
        const r = await api.renderProfile(profileId);
        box.innerHTML = `
          <div class="flash" style="border-color:var(--border)">
            <div>${T('js.cp.version_if_publish')}<strong>${r.policy.version}</strong></div>
            <div>${T('js.cp.rules_count')}<strong>${r.policy.rules.length}</strong></div>
            ${r.warnings.length ? '<ul style="margin:8px 0 0;padding-left:18px">' + r.warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('') + '</ul>' : '<div class="muted" style="margin-top:6px">' + T('js.cp.no_warnings') + '</div>'}
          </div>
        `;
      } catch (err) {
        box.innerHTML = `<div class="flash error">${escapeHtml(err.message)}</div>`;
      }
    });

    overlay.querySelector('#btn-publish-profile').addEventListener('click', async () => {
      if (!(await confirmDialog(T('js.cp.publish_confirm', escapeHtml(profile.name)), T('js.cp.publish')))) return;
      try {
        const r = await api.publishProfile(profileId);
        toast(T('js.cp.version_published', r.policy.version), 'success');
        closeModal();
      } catch (err) { toast(err.message, 'error'); }
    });

    overlay.querySelector('#btn-delete-profile').addEventListener('click', async () => {
      if (!(await confirmDialog(T('js.cp.delete_profile_confirm', escapeHtml(profile.name)), T('js.cp.del')))) return;
      try {
        await api.deleteProfile(profileId);
        toast(T('js.cp.profile_deleted'), 'success');
        closeModal();
        await loadProfiles();
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  // ----------------------------------------------------------- devices ---
  async function loadDevices() {
    [state.devices, state.peers, state.profiles, state.pools] = await Promise.all([api.devices(), api.peers(), api.profiles(), api.pools()]);
    renderDevicesList();
  }

  function renderDevicesList() {
    const box = document.getElementById('cp-devices');
    box.innerHTML = `
      <div class="row" style="justify-content:flex-end;margin-bottom:10px">
        <button id="btn-new-device">${T('js.cp.new_router_btn')}</button>
      </div>
      ${state.devices.map((d) => {
        const [cls, label] = SYNC_BADGE[d.sync_status] || SYNC_BADGE.never;
        return `
        <div class="cp-card" data-device-id="${d.id}">
          <div class="cp-card-title">
            <strong>${escapeHtml(d.name)}</strong>
            <span class="badge ${cls}">${label}</span>
          </div>
          <div class="cp-card-meta">
            ${T('js.cp.profile_is', d.profile_name ? escapeHtml(d.profile_name) : T('js.cp.not_assigned'))}
            ${d.applied_policy_version ? T('js.cp.applied_version', d.applied_policy_version) : ''}
            ${d.last_sync_at ? T('js.cp.last_sync', escapeHtml(d.last_sync_at)) : ''}
          </div>
        </div>`;
      }).join('') || '<p class="muted">' + T('js.cp.no_routers') + '</p>'}
    `;
    box.querySelectorAll('[data-device-id]').forEach((card) => {
      card.addEventListener('click', () => openDeviceDetail(Number(card.dataset.deviceId)));
    });
    document.getElementById('btn-new-device').addEventListener('click', openNewDeviceModal);
  }

  function peerOptionsHtml(selectedId) {
    return state.peers.map((p) => `
      <option value="${p.id}" ${selectedId === p.id ? 'selected' : ''}>${escapeHtml(p.name)} (${escapeHtml(p.exit_server_name)})${p.revoked ? T('js.cp.revoked') : ''}</option>
    `).join('');
  }
  function profileOptionsHtml(selectedId) {
    return state.profiles.map((p) => `<option value="${p.id}" ${selectedId === p.id ? 'selected' : ''}>${escapeHtml(p.name)}</option>`).join('');
  }

  function poolOptionsHtml() {
    return state.pools.map((p) => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
  }

  function openNewDeviceModal() {
    if (!state.peers.length && !state.pools.length) {
      toast(T('js.cp.need_wg_peer'), 'error');
      return;
    }
    const hasPools = state.pools.length > 0;
    const hasPeers = state.peers.length > 0;
    openModal(`
      <h3>${T('js.cp.new_router')}</h3>
      <div class="field-row"><label>${T('js.cp.name')}</label><input id="nd-name" required placeholder="Home Keenetic"></div>
      <div class="field-row"><label>${T('js.cp.profile')}</label><select id="nd-profile"><option value="">${T('js.cp.not_assigned')}</option>${profileOptionsHtml()}</select></div>
      ${hasPools ? `
      <div class="field-row"><label>${T('js.cp.transport_mode')}</label>
        <select id="nd-transport-mode">
          <option value="pool">${T('js.cp.pool_auto')}</option>
          <option value="peer" ${hasPeers ? '' : 'disabled'}>${T('js.cp.peer_manual')}</option>
        </select>
      </div>
      <div class="field-row" id="nd-pool-row"><label>${T('js.cp.pool')}</label><select id="nd-pool">${poolOptionsHtml()}</select></div>
      <div class="field-row" id="nd-peer-row" style="display:none"><label>${T('js.cp.wg_transport_peer')}</label><select id="nd-peer">${peerOptionsHtml()}</select></div>
      ` : `
      <div class="field-row"><label>${T('js.cp.wg_transport_peer')}</label><select id="nd-peer">${peerOptionsHtml()}</select></div>
      `}
      <div class="field-row"><label>${T('js.cp.rci_host_full')}</label><input id="nd-host" placeholder="myrouter.keenetic.link"></div>
      <div class="field-row"><label>${T('js.cp.port')}</label><input id="nd-port" value="443"></div>
      <div class="field-row"><label>${T('js.cp.keen_admin_login')}</label><input id="nd-user" placeholder="admin"></div>
      <div class="field-row"><label>${T('js.cp.keen_admin_pass')}</label><input id="nd-pass" type="password"></div>
      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('common.cancel')}</button>
        <button type="button" id="btn-create-device">${T('js.cp.create')}</button>
      </div>
    `);
    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);

    const modeSelect = overlay.querySelector('#nd-transport-mode');
    if (modeSelect) {
      modeSelect.addEventListener('change', () => {
        overlay.querySelector('#nd-pool-row').style.display = modeSelect.value === 'pool' ? '' : 'none';
        overlay.querySelector('#nd-peer-row').style.display = modeSelect.value === 'peer' ? '' : 'none';
      });
    }

    overlay.querySelector('#btn-create-device').addEventListener('click', async () => {
      const name = overlay.querySelector('#nd-name').value.trim();
      if (!name) { toast(T('js.cp.enter_name'), 'error'); return; }
      const usePool = modeSelect ? modeSelect.value === 'pool' : false;
      try {
        await api.createDevice({
          name,
          adapter_type: 'keenetic',
          profile_id: Number(overlay.querySelector('#nd-profile').value) || null,
          exit_server_peer_id: usePool ? null : (Number(overlay.querySelector('#nd-peer').value) || null),
          pool_label: usePool ? overlay.querySelector('#nd-pool').value : null,
          rci_host: overlay.querySelector('#nd-host').value.trim(),
          rci_port: Number(overlay.querySelector('#nd-port').value) || 443,
          rci_username: overlay.querySelector('#nd-user').value.trim(),
          rci_password: overlay.querySelector('#nd-pass').value,
        });
        toast(T('js.cp.device_created'), 'success');
        closeModal();
        await loadDevices();
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  async function openDeviceDetail(deviceId) {
    const device = (await api.devices()).find((d) => d.id === deviceId);
    if (!device) return;

    openModal(`
      <h3>${escapeHtml(device.name)}</h3>
      <div class="field-row"><label>${T('js.cp.profile')}</label><select id="dd-profile">${profileOptionsHtml(device.profile_id)}</select></div>
      <div class="field-row"><label>${T('js.cp.wg_transport')}</label><select id="dd-peer">${peerOptionsHtml(device.exit_server_peer_id)}</select></div>
      <div class="field-row"><label>${T('js.cp.rci_host')}</label><input id="dd-host" value="${escapeHtml(device.rci_host || '')}"></div>
      <div class="field-row"><label>${T('js.cp.port')}</label><input id="dd-port" value="${device.rci_port}"></div>
      <div class="field-row"><label>${T('js.cp.login')}</label><input id="dd-user" value="${escapeHtml(device.rci_username || '')}"></div>
      <div class="field-row"><label>${T('js.cp.password')}</label><input id="dd-pass" type="password" placeholder="${device.has_rci_password ? T('js.cp.pass_keep') : T('js.cp.pass_unset')}"></div>
      <div class="field-row"><label>${T('js.cp.wg_iface')}</label><input id="dd-iface" value="${escapeHtml(device.wg_interface_name || '')}"></div>

      <h4 style="margin:16px 0 6px">${T('js.cp.sync')}</h4>
      <div class="cp-card-meta">${T('js.cp.status_is', escapeHtml(device.sync_status))}${device.applied_policy_version ? T('js.cp.applied_version2', device.applied_policy_version) : ''}</div>
      <pre id="dd-log" style="white-space:pre-wrap;font-size:12px;background:rgba(255,255,255,.04);padding:10px;border-radius:var(--radius-sm);max-height:160px;overflow:auto;margin-top:8px">${escapeHtml(device.last_sync_log || T('js.cp.no_syncs'))}</pre>

      <div class="modal-actions">
        <button type="button" class="secondary" data-cancel>${T('js.cp.close')}</button>
        <button type="button" class="danger" id="btn-delete-device">${T('js.cp.del')}</button>
        <button type="button" class="secondary" id="btn-save-device">${T('common.save')}</button>
        <button type="button" id="btn-sync-device">${T('js.cp.sync_now')}</button>
      </div>
    `, true);

    const overlay = document.getElementById('modal-overlay');
    overlay.querySelector('[data-cancel]').addEventListener('click', closeModal);

    overlay.querySelector('#btn-save-device').addEventListener('click', async () => {
      try {
        await api.updateDevice(deviceId, {
          name: device.name,
          adapter_type: 'keenetic',
          profile_id: Number(overlay.querySelector('#dd-profile').value) || null,
          exit_server_peer_id: Number(overlay.querySelector('#dd-peer').value) || null,
          rci_host: overlay.querySelector('#dd-host').value.trim(),
          rci_port: Number(overlay.querySelector('#dd-port').value) || 443,
          rci_username: overlay.querySelector('#dd-user').value.trim(),
          rci_password: overlay.querySelector('#dd-pass').value,
          wg_interface_name: overlay.querySelector('#dd-iface').value.trim() || null,
        });
        toast(T('js.cp.saved'), 'success');
        closeModal();
        await loadDevices();
      } catch (err) { toast(err.message, 'error'); }
    });

    overlay.querySelector('#btn-sync-device').addEventListener('click', async () => {
      const btn = overlay.querySelector('#btn-sync-device');
      btn.disabled = true;
      btn.textContent = T('js.cp.syncing_btn');
      try {
        await api.syncDevice(deviceId);
        toast(T('js.cp.sync_done'), 'success');
        await openDeviceDetail(deviceId);
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
        btn.textContent = T('js.cp.sync_now');
      }
    });

    overlay.querySelector('#btn-delete-device').addEventListener('click', async () => {
      if (!(await confirmDialog(T('js.cp.delete_device_confirm', escapeHtml(device.name)), T('js.cp.del')))) return;
      try {
        await api.deleteDevice(deviceId);
        toast(T('js.cp.device_deleted'), 'success');
        closeModal();
        await loadDevices();
      } catch (err) { toast(err.message, 'error'); }
    });
  }

  // -------------------------------------------------------------- init ---
  document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadProfiles().catch((e) => toast(T('js.cp.load_profiles_fail', e.message), 'error'));
    loadDevices().catch((e) => toast(T('js.cp.load_devices_fail', e.message), 'error'));
  });
})();
