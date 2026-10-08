/* Единый индикатор загрузки для всех долгих действий панели (красивое кольцо с %).
   window.PanelLoader.progress(title, expectedSec, steps) -> { step(text), done() }
   Один источник правды, чтобы анимация была одинаковой везде. */
(function () {
  'use strict';
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function T(k) { var f = window.T; var s = f ? f.apply(null, arguments) : k; return s; }

  function ensureStyle() {
    if (document.getElementById('pg-style')) return;
    var st = document.createElement('style');
    st.id = 'pg-style';
    st.textContent = '@keyframes pg-spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  var R = 52, C = 2 * Math.PI * R;

  /** HTML кольца прогресса (переиспользуется и оверлеем графа). */
  function ringMarkup(sizeOuter) {
    var s = sizeOuter || 132;
    return ''
      + '<div style="position:relative;width:' + s + 'px;height:' + s + 'px;margin:0 auto 16px">'
      + '<svg width="' + s + '" height="' + s + '" viewBox="0 0 132 132" style="position:absolute;inset:0">'
      + '<circle cx="66" cy="66" r="' + R + '" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="9"/>'
      + '<circle class="pg-ring" cx="66" cy="66" r="' + R + '" fill="none" stroke="var(--accent)" stroke-width="9" stroke-linecap="round"'
      + ' stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '" transform="rotate(-90 66 66)" style="transition:stroke-dashoffset .4s ease"/>'
      + '</svg>'
      + '<svg width="' + s + '" height="' + s + '" viewBox="0 0 132 132" style="position:absolute;inset:0;animation:pg-spin 1.1s linear infinite">'
      + '<circle cx="66" cy="66" r="38" fill="none" stroke="var(--accent-purple)" stroke-width="3" stroke-linecap="round" stroke-dasharray="60 180"/>'
      + '</svg>'
      + '<div class="pg-pct" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:' + Math.round(s / 5) + 'px;font-weight:700">0%</div>'
      + '</div>';
  }

  function setRing(el, p) {
    var ring = el.querySelector('.pg-ring'), pct = el.querySelector('.pg-pct');
    if (ring) ring.setAttribute('stroke-dashoffset', String(C * (1 - p / 100)));
    if (pct) pct.textContent = Math.floor(p) + '%';
  }

  function progress(title, expectedSec, steps) {
    ensureStyle();
    expectedSec = expectedSec || 3;
    var host = document.getElementById('progress-overlay');
    if (!host) {
      host = document.createElement('div');
      host.id = 'progress-overlay';
      host.style.cssText = 'position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)';
      document.body.appendChild(host);
    }
    host.innerHTML =
      '<div style="background:var(--surface-elevated,#1d1d22);border:1px solid var(--border);border-radius:16px;padding:28px 34px;text-align:center;min-width:300px;max-width:420px;box-shadow:var(--shadow-modal,0 24px 80px rgba(0,0,0,.45))">'
      + ringMarkup(132)
      + '<div style="font-weight:600;font-size:15px">' + esc(title) + '</div>'
      + '<div class="pg-step muted" style="margin-top:6px;font-size:12.5px;min-height:18px"></div>'
      + '<div class="pg-eta muted" style="margin-top:4px;font-size:12px"></div>'
      + '</div>';
    host.style.display = 'flex';
    var stepEl = host.querySelector('.pg-step'), etaEl = host.querySelector('.pg-eta');
    var start = Date.now(), pct = 0;
    function render(p) { pct = p; setRing(host, p); }
    function tick() {
      var t = (Date.now() - start) / 1000;
      var p = t <= expectedSec ? (t / expectedSec) * 90 : 90 + 9 * (1 - Math.exp(-(t - expectedSec) / expectedSec));
      render(Math.max(pct, Math.min(99, p)));
      var left = Math.max(0, Math.round(expectedSec - t));
      etaEl.textContent = left > 0 ? T('js.g.eta_left', left) : T('js.g.eta_more', Math.round(t));
      if (steps && steps.length) {
        var i = Math.min(steps.length - 1, Math.floor((t / expectedSec) * steps.length));
        stepEl.textContent = steps[i];
      }
    }
    tick();
    var timer = setInterval(tick, 250);
    return {
      step: function (text) { steps = null; if (stepEl) stepEl.textContent = text; },
      done: function () {
        clearInterval(timer); render(100);
        if (etaEl) etaEl.textContent = T('js.g.eta_done', Math.round((Date.now() - start) / 1000));
        setTimeout(function () { host.style.display = 'none'; }, 450);
      }
    };
  }

  /**
   * Неблокирующий вариант: рисует кольцо в переданный контейнер и ПЛАВНО
   * анимирует прогресс сам (без внешних значений) к ~90% за expectedSec, затем
   * done() доводит до 100% и прячет. Для оверлеев (загрузка графа и т.п.).
   */
  function inline(el, title, expectedSec, hideOnDone) {
    if (!el) return { done: function () {} };
    if (hideOnDone === undefined) hideOnDone = true; // оверлей скрывается; для контент-панелей false
    ensureStyle();
    expectedSec = expectedSec || 3;
    el.innerHTML = '<div class="graph-loading-card" style="padding:26px 0">' + ringMarkup(88)
      + (title ? '<div class="graph-loading-label">' + esc(title) + '</div>' : '') + '</div>';
    if (hideOnDone) el.classList.remove('hidden');
    var start = Date.now(), pct = 0;
    function tick() {
      var t = (Date.now() - start) / 1000;
      var p = t <= expectedSec ? (t / expectedSec) * 90 : 90 + 9 * (1 - Math.exp(-(t - expectedSec) / expectedSec));
      pct = Math.max(pct, Math.min(99, p));
      setRing(el, pct);
    }
    tick();
    var timer = setInterval(tick, 80); // частые тики + CSS-transition дают плавность
    return {
      done: function () {
        clearInterval(timer);
        setRing(el, 100);
        // Оверлей — прячем; контент-панель перерисует вызывающий код (render()).
        if (hideOnDone) setTimeout(function () { el.classList.add('hidden'); }, 300);
      }
    };
  }

  window.PanelLoader = { progress: progress, inline: inline, ringMarkup: ringMarkup, setRing: setRing };
})();
