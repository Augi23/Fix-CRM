/**
 * Liquid Glass — reaktivní vrstva (Fix-CRM redesign 2026)
 * Podpis Liquid Glass: materiál reaguje na pohyb.
 * - pointer nad kartou => posun specular odlesku (CSS var --lg-mx/--lg-my)
 * - rAF throttling, passive listenery, respektuje prefers-reduced-motion
 */
(function () {
  'use strict';

  /* ---------- Přepínač světlý/tmavý režim ---------- */
  var THEME_KEY = 'lg-theme';

  function currentTheme() {
    try { return localStorage.getItem(THEME_KEY) || 'dark'; } catch (e) { return 'dark'; }
  }

  function applyTheme(t) {
    document.documentElement.setAttribute('data-lg-theme', t);
    document.documentElement.setAttribute('data-bs-theme', t);
    var icons = document.querySelectorAll('.lg-theme-toggle i');
    for (var i = 0; i < icons.length; i++) {
      icons[i].className = (t === 'light') ? 'fas fa-moon' : 'fas fa-sun';
    }
  }

  function initTheme() {
    applyTheme(currentTheme());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.lg-theme-toggle') : null;
      if (!btn) return;
      var next = currentTheme() === 'light' ? 'dark' : 'light';
      try { localStorage.setItem(THEME_KEY, next); } catch (err) {}
      applyTheme(next);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme);
  } else {
    initTheme();
  }

  /* ---------- Reaktivní odlesky ---------- */
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  var SHEEN_SELECTOR = '.card, .glass-card, .glass-panel, .crm-queue-card, .crm-revenue-card, .modal-content';
  var raf = null;

  function attachSheen(root) {
    var els = (root || document).querySelectorAll(SHEEN_SELECTOR);
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.__lgSheen) continue;
      el.__lgSheen = true;
      el.classList.add('lg-sheen');
      el.addEventListener('pointermove', onMove, { passive: true });
    }
  }

  function onMove(e) {
    var el = e.currentTarget;
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = null;
      var r = el.getBoundingClientRect();
      var x = ((e.clientX - r.left) / Math.max(r.width, 1)) * 100;
      var y = ((e.clientY - r.top) / Math.max(r.height, 1)) * 100;
      el.style.setProperty('--lg-mx', x.toFixed(1) + '%');
      el.style.setProperty('--lg-my', y.toFixed(1) + '%');
    });
  }

  function init() {
    attachSheen(document);
    // dynamicky přidané karty (modaly, AJAX obsah)
    if ('MutationObserver' in window) {
      var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes.length) { attachSheen(document); break; }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
