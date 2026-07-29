/* ─────────────────────────────────────────────────────────────────────────
 * AUTO-ULOŽENÍ ROZPRACOVANÝCH FORMULÁŘŮ (draft) — sdílená knihovna.
 *
 * Formulář označený atributem  data-draft-key="<klíč>"  se PRŮBĚŽNĚ ukládá do
 * prohlížeče (localStorage, prefix afx_draft:). Když se omylem zavře/opustí,
 * po znovuotevření se rozepsané údaje OBNOVÍ (toast „Obnoveny rozpracované
 * údaje" s možností Zahodit).
 *
 * Draft se MAŽE až při ÚSPĚŠNÉM DOKONČENÍ:
 *   - POST→redirect formy: automaticky dle ?parametru na cílové stránce
 *     (orders.php?created_order=… → new-order, reklamace.php?created=… → new-complaint),
 *   - AJAX / POST-render formy: voláním  afxDraft.clear(form)  /  afxDraft.clearKey('<klíč>')
 *     v success bodě (po uložení/podpisu).
 *
 * NEUKLÁDÁ citlivá pole: heslo, PIN, one-time-kód, soubory, CSRF token,
 * ani pole s atributem  data-no-draft.
 * ──────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';
  var PREFIX = 'afx_draft:';
  var MAX_AGE = 14 * 24 * 3600 * 1000; // 14 dní — starší draft se zahodí

  function skip(el) {
    if (!el.name) return true;
    var t = (el.type || '').toLowerCase();
    if (['password', 'file', 'hidden', 'submit', 'button', 'reset', 'image'].indexOf(t) >= 0) return true;
    if (/csrf|(^|_)pin(_code)?$|password|(^|_)otp$/i.test(el.name)) return true;
    var ac = (el.getAttribute('autocomplete') || '').toLowerCase();
    if (ac === 'current-password' || ac === 'new-password' || ac === 'one-time-code') return true;
    if (el.hasAttribute('data-no-draft')) return true;
    return false;
  }
  function fieldEls(form) {
    return Array.prototype.filter.call(form.querySelectorAll('input, textarea, select'), function (el) { return !skip(el); });
  }
  function read(form) {
    var d = {};
    fieldEls(form).forEach(function (el) {
      var t = (el.type || '').toLowerCase();
      if (t === 'checkbox') { d['c:' + el.name + ':' + el.value] = el.checked ? 1 : 0; }
      else if (t === 'radio') { if (el.checked) d['r:' + el.name] = el.value; }
      else { d[el.name] = el.value; }
    });
    return d;
  }
  function write(form, d) {
    var changed = 0;
    fieldEls(form).forEach(function (el) {
      var t = (el.type || '').toLowerCase();
      if (t === 'checkbox') { var k = 'c:' + el.name + ':' + el.value; if (k in d) { var w = !!d[k]; if (el.checked !== w) { el.checked = w; changed++; } } }
      else if (t === 'radio') { var rk = 'r:' + el.name; if ((rk in d) && d[rk] === el.value && !el.checked) { el.checked = true; changed++; } }
      else if (el.name in d) {
        if (el.value !== d[el.name]) {
          el.value = d[el.name]; changed++;
          try { if (window.jQuery && jQuery(el).data('select2')) jQuery(el).trigger('change.select2'); } catch (e) {}
        }
      }
    });
    return changed;
  }
  function meaningful(d) {
    for (var k in d) { if (d.hasOwnProperty(k)) { var v = d[k]; if (typeof v === 'string' && v.trim() !== '') return true; } }
    return false;
  }
  function keyOf(form) { var k = form.getAttribute('data-draft-key'); return k ? PREFIX + k : null; }

  function save(form) {
    var key = keyOf(form); if (!key) return;
    try { var d = read(form); if (meaningful(d)) localStorage.setItem(key, JSON.stringify({ t: Date.now(), d: d })); else localStorage.removeItem(key); } catch (e) {}
  }
  function restore(form) {
    var key = keyOf(form); if (!key) return 0;
    try {
      var raw = localStorage.getItem(key); if (!raw) return 0;
      var o = JSON.parse(raw); if (!o || !o.d) return 0;
      if (o.t && (Date.now() - o.t) > MAX_AGE) { localStorage.removeItem(key); return 0; }
      var n = write(form, o.d);
      if (n && form.offsetParent !== null) toast(key);   // toast jen když je formulář vidět (ne skrytý modal)
      return n;
    } catch (e) { return 0; }
  }
  function clearKey(k) { try { localStorage.removeItem(k.indexOf(PREFIX) === 0 ? k : PREFIX + k); } catch (e) {} }
  function clearForm(form) { var k = keyOf(form); if (k) clearKey(k); }

  var _toastEl = null;
  function toast(key) {
    try {
      if (_toastEl && _toastEl.parentNode) _toastEl.parentNode.removeChild(_toastEl);
      var t = document.createElement('div'); _toastEl = t;
      t.setAttribute('role', 'status');
      t.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:99999;display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:12px;background:rgba(20,22,26,.96);color:#eef2f8;font:600 13px/1.3 -apple-system,"SF Pro Display","Segoe UI",Arial,sans-serif;box-shadow:0 10px 34px rgba(0,0,0,.42)';
      var s = document.createElement('span'); s.textContent = '↩︎ Obnoveny rozpracované údaje';
      var b = document.createElement('button'); b.type = 'button'; b.textContent = 'Zahodit';
      b.style.cssText = 'border:1px solid rgba(255,255,255,.28);background:transparent;color:#ff8a8a;border-radius:8px;padding:4px 10px;font:700 12px/1 inherit;cursor:pointer';
      b.onclick = function () { clearKey(key); try { location.reload(); } catch (e) {} };
      t.appendChild(s); t.appendChild(b); document.body.appendChild(t);
      setTimeout(function () { if (t.parentNode) { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 420); } }, 6500);
    } catch (e) {}
  }

  var timers = {};
  function attach(form) {
    if (form.__afxDraft) { return; } form.__afxDraft = true;
    restore(form);
    form.addEventListener('input', function () { var k = keyOf(form); clearTimeout(timers[k]); timers[k] = setTimeout(function () { save(form); }, 400); });
    form.addEventListener('change', function () { save(form); });
  }
  function scan(root) { try { (root || document).querySelectorAll('form[data-draft-key]').forEach(attach); } catch (e) {} }

  function autoClearByUrl() {
    try {
      var p = new URLSearchParams(location.search);
      if (p.has('created_order')) clearKey('new-order');
      if (p.has('created')) clearKey('new-complaint');
    } catch (e) {}
  }

  function init() {
    scan(); autoClearByUrl();
    // modály: při otevření znovu obnovit (kdyby se pole při zavření vyčistila) + ukázat toast
    if (window.jQuery) {
      try { jQuery(document).on('shown.bs.modal', function (e) { try { e.target.querySelectorAll('form[data-draft-key]').forEach(function (f) { attach(f); restore(f); }); } catch (x) {} }); } catch (x) {}
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.afxDraft = { scan: scan, attach: attach, save: save, restore: restore, clear: clearForm, clearKey: clearKey };
})();
