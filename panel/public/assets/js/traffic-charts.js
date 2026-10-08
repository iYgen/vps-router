/* Графики трафика (страница «Трафик»). Чистый SVG, без внешних библиотек:
   - линейные/area-графики по дням (вход/исход всего, и по серверам);
   - топ ресурсов полосами;
   - выбор периода (7/30/90 дней);
   - наведение показывает значения за день (как в Яндекс.Метрике). */
(function () {
  'use strict';
  var T = window.T || function (k) { return k; };
  var PALETTE = ['#2f6fed', '#35d07f', '#ff4f87', '#f5b942', '#8b7cff', '#22c1c3', '#e86a5c', '#9ccc65', '#c98a00', '#5b9cff'];

  function fmtBytes(n) {
    n = Number(n) || 0;
    var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function svgEl(tag, attrs) {
    var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (var k in attrs) { e.setAttribute(k, attrs[k]); }
    return e;
  }

  /* cfg: { labels:[], series:[{name,color,data:[]}], fill:bool } */
  function renderLineChart(el, cfg) {
    el.innerHTML = '';
    var W = Math.max(320, el.clientWidth || 640), H = 240;
    var padL = 56, padR = 12, padT = 12, padB = 26;
    var iw = W - padL - padR, ih = H - padT - padB;
    var labels = cfg.labels, series = cfg.series;
    var n = labels.length;

    var max = 0;
    series.forEach(function (s) { s.data.forEach(function (v) { if (v > max) max = v; }); });
    if (max <= 0) max = 1;
    // «Красивый» потолок.
    var pow = Math.pow(1024, Math.floor(Math.log(max) / Math.log(1024)));
    var top = Math.ceil(max / pow) * pow; if (top <= 0) top = max;

    var svg = svgEl('svg', { width: '100%', viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', style: 'display:block' });
    function x(i) { return padL + (n <= 1 ? iw / 2 : iw * i / (n - 1)); }
    function y(v) { return padT + ih - ih * (v / top); }

    // Горизонтальные линии сетки + подписи Y.
    var GRID = 4;
    for (var g = 0; g <= GRID; g++) {
      var gy = padT + ih * g / GRID;
      svg.appendChild(svgEl('line', { x1: padL, y1: gy, x2: W - padR, y2: gy, stroke: 'var(--line,#e4e8ee)', 'stroke-width': 1 }));
      var val = top * (1 - g / GRID);
      var tl = svgEl('text', { x: padL - 8, y: gy + 4, 'text-anchor': 'end', 'font-size': 10.5, fill: 'var(--text-muted,#6b7684)' });
      tl.textContent = fmtBytes(val); svg.appendChild(tl);
    }
    // Подписи X (разрежённо).
    function xLabel(lb) {
      if (lb.length <= 4) return lb;          // YYYY (год)
      if (lb.length === 7) return lb;          // YYYY-MM (месяц)
      return lb.slice(5);                      // MM-DD (день/неделя)
    }
    var step = Math.max(1, Math.ceil(n / 7));
    for (var i = 0; i < n; i += step) {
      var tx = svgEl('text', { x: x(i), y: H - 8, 'text-anchor': 'middle', 'font-size': 10, fill: 'var(--text-muted,#6b7684)' });
      tx.textContent = xLabel(labels[i]);
      svg.appendChild(tx);
    }

    series.forEach(function (s) {
      var pts = s.data.map(function (v, i) { return x(i) + ',' + y(v); }).join(' ');
      if (cfg.fill && series.length <= 2) {
        var area = padL + ',' + (padT + ih) + ' ' + pts + ' ' + (W - padR) + ',' + (padT + ih);
        svg.appendChild(svgEl('polygon', { points: area, fill: s.color, 'fill-opacity': 0.08, stroke: 'none' }));
      }
      svg.appendChild(svgEl('polyline', { points: pts, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round' }));
    });

    // Курсор + точки при наведении.
    var cursor = svgEl('line', { x1: 0, y1: padT, x2: 0, y2: padT + ih, stroke: 'var(--text-muted,#6b7684)', 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0 });
    svg.appendChild(cursor);
    var dots = series.map(function (s) { var c = svgEl('circle', { r: 3.5, fill: s.color, opacity: 0 }); svg.appendChild(c); return c; });
    var hit = svgEl('rect', { x: padL, y: padT, width: iw, height: ih, fill: 'transparent' });
    svg.appendChild(hit);

    var tip = document.createElement('div');
    tip.style.cssText = 'position:absolute;pointer-events:none;background:var(--surface-elevated,#1d1d22);border:1px solid var(--line,#e4e8ee);border-radius:8px;padding:7px 10px;font-size:12px;box-shadow:0 6px 24px rgba(0,0,0,.25);display:none;z-index:5;min-width:120px';
    el.style.position = 'relative';
    el.appendChild(tip);

    function toIndex(clientX) {
      var r = svg.getBoundingClientRect();
      var px = (clientX - r.left) * (W / r.width);
      var i = Math.round((px - padL) / (iw / Math.max(1, n - 1)));
      return Math.max(0, Math.min(n - 1, i));
    }
    hit.addEventListener('mousemove', function (ev) {
      var i = toIndex(ev.clientX);
      var cx = x(i);
      cursor.setAttribute('x1', cx); cursor.setAttribute('x2', cx); cursor.setAttribute('opacity', 1);
      dots.forEach(function (c, si) { c.setAttribute('cx', cx); c.setAttribute('cy', y(series[si].data[i])); c.setAttribute('opacity', 1); });
      var rows = series.map(function (s) {
        return '<div style="display:flex;align-items:center;gap:6px;margin-top:3px"><span style="width:9px;height:9px;border-radius:2px;background:' + s.color + '"></span>'
          + esc(s.name) + ': <b>' + fmtBytes(s.data[i]) + '</b></div>';
      }).join('');
      tip.innerHTML = '<div style="color:var(--text-muted,#6b7684)">' + esc(labels[i]) + '</div>' + rows;
      tip.style.display = 'block';
      var r = svg.getBoundingClientRect();
      var leftPx = (cx / W) * r.width;
      tip.style.left = Math.min(r.width - tip.offsetWidth - 6, Math.max(0, leftPx + 10)) + 'px';
      tip.style.top = '6px';
    });
    hit.addEventListener('mouseleave', function () {
      cursor.setAttribute('opacity', 0); dots.forEach(function (c) { c.setAttribute('opacity', 0); }); tip.style.display = 'none';
    });

    el.appendChild(svg);

    // Легенда.
    if (cfg.legend !== false) {
      var lg = document.createElement('div');
      lg.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:12px;color:var(--text-secondary,#3a4552)';
      lg.innerHTML = series.map(function (s) {
        return '<span style="display:inline-flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:2px;background:' + s.color + '"></span>' + esc(s.name) + '</span>';
      }).join('');
      el.appendChild(lg);
    }
  }

  function renderHostBars(el, hosts) {
    el.innerHTML = '';
    if (!hosts.length) { el.innerHTML = '<p class="muted" style="font-size:13px">' + esc(T('traffic.no_hosts')) + '</p>'; return; }
    var max = hosts[0].total || 1;
    hosts.forEach(function (h) {
      var row = document.createElement('div');
      row.style.cssText = 'display:grid;grid-template-columns:220px 1fr 110px;gap:10px;align-items:center;padding:5px 0';
      var pct = Math.max(2, Math.round(100 * h.total / max));
      row.innerHTML =
        '<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px" title="' + esc(h.host) + '">' + esc(h.host) + '</div>'
        + '<div style="background:var(--line,#e4e8ee);border-radius:6px;height:16px"><div style="width:' + pct + '%;height:100%;border-radius:6px;background:#2f6fed"></div></div>'
        + '<div style="text-align:right;font-size:12.5px;color:var(--text-secondary,#3a4552)">↓' + fmtBytes(h.down) + ' ↑' + fmtBytes(h.up) + '</div>';
      el.appendChild(row);
    });
  }

  function initTrafficCharts() {
    var root = document.getElementById('tr-charts');
    if (!root) return;
    var fromEl = document.getElementById('tr-from');
    var toEl = document.getElementById('tr-to');
    var granEl = document.getElementById('tr-gran');
    // query: либо {days} (быстрый период), либо {from,to} (календарь); + gran.
    var state = { query: 'days=30', data: null };

    async function load() {
      try {
        var gran = granEl && granEl.value ? '&gran=' + encodeURIComponent(granEl.value) : '';
        var r = await fetch('/api/traffic-series.php?' + state.query + gran, { headers: { Accept: 'application/json' } });
        var d = await r.json();
        if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
        state.data = d;
        // Отразим фактический период в календаре.
        if (fromEl && d.from) fromEl.value = d.from;
        if (toEl && d.to) toEl.value = d.to;
        draw();
      } catch (e) {
        root.querySelector('#tr-chart-totals').innerHTML = '<p class="muted">' + esc(T('traffic.charts_err')) + ' ' + esc(e.message) + '</p>';
      }
    }
    function draw() {
      var d = state.data;
      var labels = d.labels || d.days || [];
      // Заголовок под выбранную гранулярность («по дням/неделям/месяцам/годам»).
      var phrase = { day: T('traffic.per_day'), week: T('traffic.per_week'), month: T('traffic.per_month'), year: T('traffic.per_year') }[d.granularity] || '';
      var tt = document.getElementById('tr-totals-title');
      if (tt) tt.textContent = T('traffic.chart_totals', phrase);
      renderLineChart(root.querySelector('#tr-chart-totals'), {
        labels: labels, fill: true,
        series: [
          { name: T('traffic.incoming'), color: '#35d07f', data: d.totals.rx },
          { name: T('traffic.outgoing'), color: '#2f6fed', data: d.totals.tx }
        ]
      });
      var perSrv = d.servers.map(function (s, i) {
        return { name: s.name, color: PALETTE[i % PALETTE.length], data: s.rx.map(function (v, j) { return v + s.tx[j]; }) };
      });
      var srvEl = root.querySelector('#tr-chart-servers');
      if (perSrv.length) renderLineChart(srvEl, { labels: labels, series: perSrv, fill: false });
      else srvEl.innerHTML = '<p class="muted" style="font-size:13px">' + esc(T('traffic.no_server_data')) + '</p>';
      renderHostBars(root.querySelector('#tr-hosts'), d.hosts);
    }

    // Быстрые периоды (7/30/90/1г/2г/Всё).
    root.querySelectorAll('[data-range]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.query = 'days=' + encodeURIComponent(b.dataset.range);
        root.querySelectorAll('[data-range]').forEach(function (x) { x.classList.toggle('active', x === b); });
        load();
      });
    });
    // Календарь: авто-обновление при изменении дат (без кнопки «Применить»).
    function applyDates() {
      if (!fromEl.value || !toEl.value) return;
      state.query = 'from=' + encodeURIComponent(fromEl.value) + '&to=' + encodeURIComponent(toEl.value);
      root.querySelectorAll('[data-range]').forEach(function (x) { x.classList.remove('active'); });
      load();
    }
    if (fromEl) fromEl.addEventListener('change', applyDates);
    if (toEl) toEl.addEventListener('change', applyDates);
    // Смена гранулярности — сразу перезапрос с текущим периодом.
    if (granEl) granEl.addEventListener('change', load);

    var ro; try { ro = new ResizeObserver(function () { if (state.data) draw(); }); ro.observe(root); } catch (e) {
      window.addEventListener('resize', function () { if (state.data) draw(); });
    }
    load();
  }

  if (document.readyState !== 'loading') initTrafficCharts();
  else document.addEventListener('DOMContentLoaded', initTrafficCharts);
})();
