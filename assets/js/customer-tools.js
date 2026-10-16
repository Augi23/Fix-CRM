/* ═══════════════════════════════════════════════════════════════════════════
   KLIENTI — sloučení duplicit a upozornění na existujícího klienta (v3.93.0)
   • afxMergeCustomers(keepId, dropId, text) — vše z dropId přejde na keepId
   • při psaní telefonu nového klienta se zeptá, jestli už v CRM není
     (stejný telefon = posledních 9 číslic, nebo e-mail) a nabídne ho použít
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }

    window.afxMergeCustomers = function (keepId, dropId, text, after) {
        if (!confirm(text || 'Sloučit klienty? Zakázky, reklamace, faktury, účtenky i body přejdou na ponechaného klienta a druhý záznam se smaže. Nejde vrátit.')) { return; }
        var fd = new FormData();
        fd.append('keep_id', keepId); fd.append('drop_id', dropId); fd.append('csrf_token', csrf());
        fetch('api/customer_merge.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    var m = d.moved || {};
                    alert('Sloučeno. Převedeno: zakázky ' + (m.orders || 0) + ', reklamace ' + (m.complaints || 0) + ', faktury ' + (m.invoices || 0) + ', účtenky ' + (m.pos_sales || 0) + '.');
                    if (after) { location.href = after; } else { location.reload(); }
                } else { alert((d && d.error) || 'Sloučení se nepovedlo.'); }
            })
            .catch(function () { alert('Síťová chyba — nic se nezměnilo.'); });
    };

    /* ── upozornění na duplicitu při zakládání klienta ── */
    var SEL = '#inline_phone, #newCustomerModal input[name="phone"], form[data-new-customer] input[name="phone"]';
    var timer = null;
    function box(input) {
        var b = input.parentNode.querySelector('.afx-dupe-hint');
        if (!b) {
            b = document.createElement('div');
            b.className = 'afx-dupe-hint small mt-1';
            b.style.cssText = 'color:#ffd479;line-height:1.35';
            input.parentNode.appendChild(b);
        }
        return b;
    }
    function useCustomer(input, c) {
        // v průvodci nové zakázky vybere klienta do pole Zákazník (select2)
        var modal = input.closest('#newOrderModal');
        var sel = modal && window.jQuery ? window.jQuery(modal).find('select[name="customer_id"]') : null;
        if (sel && sel.length) {
            if (!sel.find('option[value="' + c.id + '"]').length) { sel.append(new Option(c.name + (c.phone ? ' (' + c.phone + ')' : ''), c.id, true, true)); }
            sel.val(String(c.id)).trigger('change');
            box(input).innerHTML = '<span style="color:#7ee2a8"><i class="fas fa-check me-1"></i>Vybrán existující klient ' + esc(c.name) + ' — nového nezakládej.</span>';
            return;
        }
        location.href = 'edit_customer.php?id=' + encodeURIComponent(c.id);
    }
    function check(input) {
        var phone = input.value.replace(/\D+/g, '');
        var b = box(input);
        if (phone.length < 9) { b.innerHTML = ''; return; }
        fetch('api/customer_dupe_check.php?phone=' + encodeURIComponent(input.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.matches || !d.matches.length) { b.innerHTML = ''; return; }
                b.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>Klient s tímto telefonem už v CRM je: '
                    + d.matches.map(function (c, i) {
                        return '<a href="#" data-dupe-i="' + i + '" style="color:#5ab0ff;font-weight:600">' + esc(c.name) + '</a>'
                            + ' <span style="opacity:.7">(' + (c.orders || 0) + ' zak.)</span>';
                    }).join(', ') + ' — klikni pro použití.';
                b.querySelectorAll('[data-dupe-i]').forEach(function (a) {
                    a.addEventListener('click', function (e) { e.preventDefault(); useCustomer(input, d.matches[+a.getAttribute('data-dupe-i')]); });
                });
            })
            .catch(function () {});
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.matches || !t.matches(SEL)) { return; }
        clearTimeout(timer);
        timer = setTimeout(function () { check(t); }, 450);
    });
})();
