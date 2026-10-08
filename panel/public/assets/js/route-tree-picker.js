/**
 * Reusable checkable route-tree picker (модалка). Единый UX выбора маршрутов
 * для: окна Server Set и мастера добавления сервера — вместо select/textarea.
 * Работает поверх существующего /api/routes.php (rule_groups/rules), без нового
 * бэкенда. Дерево строится по parent_id; чекбокс группы каскадит на потомков,
 * поддерживается indeterminate; поиск раскрывает совпадения.
 *
 * API:
 *   window.RouteTreePicker.open({
 *     title, confirmLabel,
 *     excludeIds: Set|Array,   // скрыть уже привязанные
 *     preselect: Array,        // изначально отмеченные id
 *     onConfirm: (ids) => {}   // выбранные id rule_group (включая раскрытые группы)
 *   })
 */
(function () {
  'use strict';
  var T = window.T || function (k) { return k; };
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  async function fetchRoutes() {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var r = await fetch('/api/routes.php', { headers: { 'X-CSRF-Token': csrf } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }

  function buildTree(rows, excludeIds) {
    var byId = {}, roots = [];
    rows.forEach(function (g) {
      if (excludeIds.has(g.id)) return;
      g._children = []; byId[g.id] = g;
    });
    rows.forEach(function (g) {
      if (!byId[g.id]) return;
      if (g.parent_id && byId[g.parent_id]) byId[g.parent_id]._children.push(g);
      else roots.push(g);
    });
    return { byId: byId, roots: roots };
  }

  function open(opts) {
    opts = opts || {};
    var excludeIds = new Set(Array.from(opts.excludeIds || []));
    var selected = new Set(opts.preselect || []);
    var expanded = new Set();
    var query = '';
    var tree = { byId: {}, roots: [] };

    var overlay = document.createElement('div');
    overlay.className = 'rtp-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1200;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px';
    overlay.innerHTML =
      '<div class="card" style="max-width:640px;width:100%;margin:0;display:flex;flex-direction:column;max-height:80vh">' +
        '<div class="row" style="justify-content:space-between;align-items:center">' +
          '<h3 style="margin:0">' + esc(opts.title || T('js.r.picker_title')) + '</h3>' +
          '<button type="button" class="secondary" data-x>✕</button>' +
        '</div>' +
        '<input type="text" data-search placeholder="' + esc(T('js.r.picker_search')) + '" style="margin:12px 0">' +
        '<div data-tree style="flex:1;overflow:auto;min-height:120px"></div>' +
        '<div class="modal-actions" style="justify-content:space-between;align-items:center;margin-top:12px">' +
          '<span class="muted" data-count></span>' +
          '<span class="row" style="gap:8px">' +
            '<button type="button" class="secondary" data-cancel>' + esc(T('common.cancel')) + '</button>' +
            '<button type="button" data-confirm></button>' +
          '</span>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    var treeEl = overlay.querySelector('[data-tree]');
    var countEl = overlay.querySelector('[data-count]');
    var confirmBtn = overlay.querySelector('[data-confirm]');

    function close() { overlay.remove(); }
    overlay.querySelector('[data-x]').addEventListener('click', close);
    overlay.querySelector('[data-cancel]').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('[data-search]').addEventListener('input', function (e) { query = e.target.value.toLowerCase(); render(); });

    // Все потомки узла (id).
    function descendants(node, acc) {
      (node._children || []).forEach(function (c) { acc.push(c.id); descendants(c, acc); });
      return acc;
    }
    function matches(node) {
      if (!query) return true;
      if ((node.name || '').toLowerCase().indexOf(query) !== -1) return true;
      if ((node.rules || []).some(function (r) { return (r.value || '').toLowerCase().indexOf(query) !== -1; })) return true;
      return (node._children || []).some(matches);
    }
    function checkState(node) {
      var kids = node._children || [];
      if (!kids.length) return selected.has(node.id) ? 'on' : 'off';
      var childStates = kids.map(checkState);
      var allOn = childStates.every(function (s) { return s === 'on'; }) && selected.has(node.id);
      var anyOn = selected.has(node.id) || childStates.some(function (s) { return s !== 'off'; });
      if (allOn) return 'on';
      return anyOn ? 'ind' : 'off';
    }
    function toggle(node, on) {
      if (on) selected.add(node.id); else selected.delete(node.id);
      descendants(node, []).forEach(function (id) { if (on) selected.add(id); else selected.delete(id); });
    }

    function rowHtml(node, depth) {
      if (!matches(node)) return '';
      var kids = node._children || [];
      var st = checkState(node);
      var isOpen = expanded.has(node.id) || (query && matches(node));
      var icon = kids.length ? (isOpen ? '▾' : '▸') : '●';
      var meta = kids.length ? `<span class="muted" style="margin-left:6px">${descendants(node, []).length}</span>` : '';
      var tgt = node.server_set_name ? `<span class="muted" style="margin-left:6px">· ${esc(node.server_set_name)}</span>`
              : (node.exit_name ? `<span class="muted" style="margin-left:6px">· ${esc(node.exit_name)}</span>` : '');
      var html = `<div class="rtp-row" style="display:flex;align-items:center;gap:8px;padding:5px 4px;padding-left:${8 + depth * 18}px">
        <input type="checkbox" data-check="${node.id}" ${st === 'on' ? 'checked' : ''} ${st === 'ind' ? 'data-ind="1"' : ''}>
        <span data-toggle="${node.id}" style="cursor:${kids.length ? 'pointer' : 'default'};flex:1;min-width:0">${icon} ${esc(node.name)}${meta}${tgt}</span>
      </div>`;
      if (kids.length && isOpen) {
        html += kids.map(function (c) { return rowHtml(c, depth + 1); }).join('');
      }
      return html;
    }

    function render() {
      treeEl.innerHTML = tree.roots.map(function (n) { return rowHtml(n, 0); }).join('') ||
        '<p class="muted">' + esc(T('js.r.picker_empty')) + '</p>';
      // indeterminate можно выставить только через JS-свойство
      treeEl.querySelectorAll('input[data-ind="1"]').forEach(function (cb) { cb.indeterminate = true; });
      countEl.textContent = T('js.r.picker_selected', selected.size);
      confirmBtn.textContent = (opts.confirmLabel || T('js.r.picker_add')) + (selected.size ? ' (' + selected.size + ')' : '');
      confirmBtn.disabled = selected.size === 0;
      treeEl.querySelectorAll('[data-check]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          toggle(tree.byId[Number(cb.dataset.check)], cb.checked); render();
        });
      });
      treeEl.querySelectorAll('[data-toggle]').forEach(function (el) {
        el.addEventListener('click', function () {
          var id = Number(el.dataset.toggle);
          if (expanded.has(id)) expanded.delete(id); else expanded.add(id);
          render();
        });
      });
    }

    confirmBtn.addEventListener('click', function () {
      var ids = Array.from(selected);
      close();
      if (opts.onConfirm) opts.onConfirm(ids);
    });

    treeEl.innerHTML = '<p class="muted">' + esc(T('js.r.picker_loading')) + '</p>';
    fetchRoutes().then(function (rows) {
      tree = buildTree(rows, excludeIds);
      render();
    }).catch(function (e) {
      treeEl.innerHTML = '<div class="flash error">' + esc(e.message) + '</div>';
    });
  }

  window.RouteTreePicker = { open: open };
})();
