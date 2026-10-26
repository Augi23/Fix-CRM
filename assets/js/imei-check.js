/* Kontrola IMEI (v4.1.0) — sdílené pro horní lištu (každá stránka) i kartu na Nástěnce
   (mobil). Inicializuje každý form[data-imei-check]; výsledek jde do prvku, na který
   ukazuje data-result. V liště je výsledek v plovoucím panelu pod polem.
   Texty: window.IMEI_I18N (vypisuje includes/header.php). Dřív inline v index.php. */
(function () {
    function esc(x) { return (window.escapeHtml ? window.escapeHtml(String(x == null ? '' : x))
        : String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; })); }
    var T = window.IMEI_I18N || {};

    function alertBox(type, inner, extraCls) {
        var cls = type === 'success' ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : type === 'danger' ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
            : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';
        return '<div class="' + cls + ' py-2 ' + (extraCls || 'mb-0') + '">' + inner + '</div>';
    }
    function simple(type, message, details) {
        var icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        return alertBox(type, '<i class="fas fa-' + icon + ' me-2"></i>' + esc(message) + (details ? '<div class="small mt-1 opacity-75">' + esc(details) + '</div>' : ''));
    }
    function ifreeicloud(result) {
        if (!result) { return ''; }
        var ok = !!result.success;
        var icon = ok ? 'check-circle' : 'circle-exclamation';
        var headline = esc(result.headline || (ok ? (result.model || 'OK') : (result.message || '')));
        var detailsHtml = '';
        if (result.details && typeof result.details === 'object') {
            var rows = Object.keys(result.details).map(function (k) {
                return '<div class="d-flex justify-content-between small py-1 border-bottom border-opacity-10"><span class="opacity-75">'
                    + esc(k) + '</span><span class="fw-semibold ms-3 text-end">' + esc(String(result.details[k])) + '</span></div>';
            }).join('');
            if (rows) { detailsHtml = '<div class="mt-2">' + rows + '</div>'; }
        }
        var imageHtml = result.image ? '<div class="mt-2 text-center"><img src="' + esc(result.image) + '" alt="" style="max-height:120px;border-radius:8px;"></div>' : '';
        var meta = result.service_id !== undefined ? '<div class="small mt-2 opacity-50">' + esc(T.serviceId) + ': ' + esc(String(result.service_id)) + (result.http_code ? ' · HTTP ' + esc(String(result.http_code)) : '') + '</div>' : '';
        var note = (!detailsHtml && !imageHtml && result.summary) ? '<pre class="small mt-2 mb-0 p-2 rounded border border-opacity-25 bg-dark bg-opacity-25 text-white-75" style="white-space:pre-wrap;">' + esc(result.summary) + '</pre>' : '';
        return alertBox(ok ? 'success' : 'warning', '<div class="fw-semibold mb-1"><i class="fas fa-sim-card me-2"></i>iFreeiCloud</div><div><i class="fas fa-' + icon + ' me-2"></i>' + headline + '</div>' + imageHtml + detailsHtml + note + meta);
    }
    function police(res) {
        if (!res || !res.success) {
            var msg = res && res.police && res.police.message ? res.police.message : (res && res.message ? res.message : T.checkCouldNotComplete);
            return alertBox('warning', '<div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>' + esc(T.policeDb) + '</div><div><i class="fas fa-circle-exclamation me-2"></i>' + esc(msg) + '</div>', 'mb-2');
        }
        var policeMessage = res.police && res.police.message ? res.police.message : (res.message || '');
        var type = 'warning', headline = T.resultUnknown;
        if (res.status === 'found') { type = 'danger'; headline = T.resultFound; }
        else if (res.status === 'not_found') { type = 'success'; headline = T.resultNotFound; }
        else if (policeMessage) { headline = policeMessage; }
        var icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        return alertBox(type, '<div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>' + esc(T.policeDb) + '</div><div><i class="fas fa-' + icon + ' me-2"></i>' + esc(headline) + '</div>'
            + (policeMessage ? '<div class="small mt-1 opacity-75">' + esc(policeMessage) + '</div>' : ''), 'mb-2');
    }

    function init(form) {
        var input = form.querySelector('input[type="text"]');
        var btn = form.querySelector('button[type="submit"]');
        var result = document.querySelector(form.getAttribute('data-result') || '');
        if (!input || !btn || !result) { return; }
        var pop = result.classList.contains('afx-imei-pop');   // plovoucí panel v liště
        function show(html) { result.innerHTML = html; if (pop) { result.hidden = false; } }
        function hide() { if (pop) { result.hidden = true; } }
        input.addEventListener('input', function () { this.value = this.value.replace(/\D+/g, '').slice(0, 15); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var imei = (input.value || '').replace(/\D+/g, '').slice(0, 15);
            if (imei.length < 14) { show(simple('warning', T.imeiMinDigits)); return; }
            var old = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            var fd = new FormData(); fd.append('imei', imei); fd.append('csrf_token', window.afxCsrf ? window.afxCsrf() : '');
            fetch('api/check_imei.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false; btn.innerHTML = old;
                    var head = pop ? '<div class="afx-imei-pop-h"><b>' + esc(T.title) + ' · ' + esc(imei) + '</b><button type="button" class="btn-close btn-close-white" data-imei-close aria-label="Zavřít"></button></div>' : '';
                    show(head + police(res) + ifreeicloud(res && res.ifreeicloud));
                })
                .catch(function () { btn.disabled = false; btn.innerHTML = old; show(simple('warning', T.checkFailed)); });
        });
        if (pop) {
            result.addEventListener('click', function (e) { if (e.target.closest('[data-imei-close]')) { hide(); } });
            document.addEventListener('click', function (e) { if (!result.hidden && !result.contains(e.target) && !form.contains(e.target)) { hide(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !result.hidden) { hide(); } });
            input.addEventListener('focus', function () { if (result.innerHTML.trim() !== '') { result.hidden = false; } });
        }
    }
    function boot() { document.querySelectorAll('form[data-imei-check]').forEach(init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
