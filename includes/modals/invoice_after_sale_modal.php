<?php
/**
 * DODATEČNÁ FAKTURA K PRODEJI (v3.71.0) — sdílené okno pro Pokladnu i pro
 * Účetnictví → Prodej. Zákazník zaplatil kartou (nebo hotově) a teprve pak si
 * řekne o fakturu: obsluha vybere klienta z CRM, nebo vyplní odběratele ručně,
 * a doklad se vystaví jako už uhrazený (api/pos_invoice_after.php).
 *
 * Použití na stránce:
 *   <?php require_once 'includes/modals/invoice_after_sale_modal.php'; ?>
 *   <button onclick="afxInvoiceAfterSale(12, 'KP2600042', '3 990 Kč', 'Kartou')">…</button>
 */

// Role účetní klientskou databázi procházet nesmí (api/search_customers.php je
// pro ni zavřený) — vidí proto rovnou ruční vyplnění odběratele.
$asiPickClient = !(function_exists('crmIsAccountant') && crmIsAccountant());
?>
<div class="modal fade" id="afterSaleInvoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark text-white border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Vystavit fakturu k prodeji</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-secondary bg-secondary bg-opacity-10 border-secondary py-2 px-3 small mb-3">
          <span id="asiSaleInfo">—</span><br>
          Doklad se vystaví jako <strong>již uhrazený</strong> — datum zdanitelného plnění i úhrady je den prodeje.
          Peníze se tím <strong>nepočítají dvakrát</strong>, prodej v kase zůstane, jak je.
        </div>

        <div class="btn-group w-100 mb-3" role="group"<?php echo $asiPickClient ? '' : ' style="display:none;"'; ?>>
          <input type="radio" class="btn-check" name="asiMode" id="asiModeClient" value="client"<?php echo $asiPickClient ? ' checked' : ''; ?>>
          <label class="btn btn-outline-info" for="asiModeClient"><i class="fas fa-user me-1"></i> Klient z CRM</label>
          <input type="radio" class="btn-check" name="asiMode" id="asiModeManual" value="manual"<?php echo $asiPickClient ? '' : ' checked'; ?>>
          <label class="btn btn-outline-info" for="asiModeManual"><i class="fas fa-pen me-1"></i> Vyplnit odběratele ručně</label>
        </div>

        <div id="asiClientWrap" class="mb-3"<?php echo $asiPickClient ? '' : ' style="display:none;"'; ?>>
          <label class="form-label small text-white-50">Odběratel</label>
          <select id="asiCustomer" class="form-select"><option value=""></option></select>
        </div>

        <div id="asiManualWrap" class="row g-2 mb-3"<?php echo $asiPickClient ? ' style="display:none;"' : ''; ?>>
          <div class="col-md-12">
            <label class="form-label small text-white-50">Název / jméno odběratele <span class="text-danger">*</span></label>
            <input type="text" id="asiName" class="form-control" placeholder="Firma s.r.o. nebo Jan Novák">
          </div>
          <div class="col-md-12">
            <label class="form-label small text-white-50">Adresa</label>
            <input type="text" id="asiAddress" class="form-control" placeholder="Ulice 1, 110 00 Praha 1">
          </div>
          <div class="col-md-4">
            <label class="form-label small text-white-50">IČO</label>
            <input type="text" id="asiIco" class="form-control" inputmode="numeric">
          </div>
          <div class="col-md-4">
            <label class="form-label small text-white-50">DIČ</label>
            <input type="text" id="asiDic" class="form-control" placeholder="CZ…">
          </div>
          <div class="col-md-4">
            <label class="form-label small text-white-50">E-mail</label>
            <input type="email" id="asiEmail" class="form-control" placeholder="kam poslat fakturu">
          </div>
        </div>

        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="asiSend" checked>
          <label class="form-check-label" for="asiSend">Odeslat fakturu e-mailem hned po vystavení</label>
        </div>
        <div id="asiSendWrap" class="mt-2">
          <input type="email" id="asiSendTo" class="form-control" placeholder="e-mail příjemce (prázdné = adresa klienta / odběratele)">
        </div>

        <div id="asiError" class="alert alert-danger py-2 px-3 mt-3 mb-0" style="display:none;"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Zrušit</button>
        <button type="button" class="btn btn-success" id="asiSubmit"><i class="fas fa-file-invoice me-1"></i> Vystavit fakturu</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    var modal = null, saleId = 0, saleNumber = '';

    function el(id) { return document.getElementById(id); }
    function chybu(text) {
        var box = el('asiError');
        if (!text) { box.style.display = 'none'; box.textContent = ''; return; }
        box.textContent = text; box.style.display = '';
    }
    function rezim() {
        var manual = el('asiModeManual').checked;
        el('asiClientWrap').style.display = manual ? 'none' : '';
        el('asiManualWrap').style.display = manual ? '' : 'none';
    }

    /** Otevře okno pro konkrétní prodej. */
    window.afxInvoiceAfterSale = function (id, number, castka, platba) {
        saleId = parseInt(id, 10) || 0;
        saleNumber = String(number || '');
        if (saleId <= 0) { return; }
        var elModal = el('afterSaleInvoiceModal');
        if (!elModal || typeof bootstrap === 'undefined') { return; }
        if (!modal) {
            modal = new bootstrap.Modal(elModal);
            // klient se hledá stejným API jako v kase a v Účetnictví; dropdownParent
            // musí být modal, jinak se nabídka schová pod jeho okraj
            if (window.jQuery && jQuery.fn.select2 && <?php echo $asiPickClient ? 'true' : 'false'; ?>) {
                jQuery('#asiCustomer').select2({
                    width: '100%', placeholder: 'Hledat klienta (jméno, firma, telefon, e-mail)…',
                    allowClear: true, minimumInputLength: 0,
                    dropdownParent: jQuery('#afterSaleInvoiceModal'),
                    language: {
                        noResults: function () { return 'Nikdo takový v CRM není'; },
                        searching: function () { return 'Hledám…'; }
                    },
                    ajax: {
                        url: 'api/search_customers.php', dataType: 'json', delay: 250,
                        data: function (p) { return { q: p.term || '', page: p.page || 1 }; },
                        processResults: function (d, p) {
                            p.page = p.page || 1;
                            return { results: d.results || [], pagination: { more: !!(d.pagination && d.pagination.more) } };
                        },
                        error: function (xhr) {
                            if (xhr && xhr.status === 401 && window.afxReauth && window.afxReauth.open) { window.afxReauth.open(); }
                        }
                    }
                });
            }
            el('asiModeClient').addEventListener('change', rezim);
            el('asiModeManual').addEventListener('change', rezim);
            el('asiSend').addEventListener('change', function () {
                el('asiSendWrap').style.display = this.checked ? '' : 'none';
            });
            el('asiSubmit').addEventListener('click', odeslat);
        }
        // čistý formulář pro každý prodej
        chybu('');
        el('asiSubmit').style.display = '';
        el('asiModeClient').checked = <?php echo $asiPickClient ? 'true' : 'false'; ?>;
        el('asiModeManual').checked = <?php echo $asiPickClient ? 'false' : 'true'; ?>;
        ['asiName', 'asiAddress', 'asiIco', 'asiDic', 'asiEmail', 'asiSendTo'].forEach(function (i) { el(i).value = ''; });
        el('asiSend').checked = true;
        el('asiSendWrap').style.display = '';
        if (window.jQuery && jQuery('#asiCustomer').data('select2')) { jQuery('#asiCustomer').val(null).trigger('change'); }
        rezim();
        el('asiSaleInfo').innerHTML = 'Prodej <strong>' + saleNumber.replace(/[<>&]/g, '') + '</strong>'
            + (castka ? ' · ' + String(castka).replace(/[<>&]/g, '') : '')
            + (platba ? ' · ' + String(platba).replace(/[<>&]/g, '') : '');
        modal.show();
    };

    function odeslat() {
        chybu('');
        var manual = el('asiModeManual').checked;
        var payload = { csrf_token: CSRF, sale_id: saleId };
        if (manual) {
            if (el('asiName').value.trim() === '') { chybu('Vyplň název odběratele.'); return; }
            payload.buyer = {
                name: el('asiName').value.trim(), address: el('asiAddress').value.trim(),
                ico: el('asiIco').value.trim(), dic: el('asiDic').value.trim(),
                email: el('asiEmail').value.trim()
            };
        } else {
            var cid = parseInt(el('asiCustomer').value, 10) || 0;
            if (cid <= 0) { chybu('Vyber klienta, nebo přepni na ruční vyplnění.'); return; }
            payload.customer_id = cid;
        }
        payload.send_email = el('asiSend').checked ? 1 : 0;
        payload.email = el('asiSendTo').value.trim();

        var btn = el('asiSubmit');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Vystavuji…';
        fetch('api/pos_invoice_after.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server odpověděl nesrozumitelně.' }; }); })
        .then(function (j) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-invoice me-1"></i> Vystavit fakturu';
            if (!j.ok) { chybu(j.error || j.message || 'Fakturu se nepodařilo vystavit.'); return; }
            if (j.email_error) {
                // faktura JE vystavená, ale klient ji nedostal — tohle si obsluha
                // musí přečíst, takže žádné zavření okna ani reload za zády
                chybu('Faktura ' + j.invoice_number + ' je vystavená, ale ODESLÁNÍ SELHALO: '
                      + j.email_error + ' Pošli ji z Účetnictví → Faktury.');
                el('asiSubmit').style.display = 'none';
                return;
            }
            var hlaska = 'Faktura ' + j.invoice_number + ' je vystavená'
                + (j.emailed ? ' a odeslaná na ' + String(j.emailed_to || '').replace(/[<>&"]/g, '') + '.' : '.');
            if (modal) { modal.hide(); }
            if (typeof showAlert === 'function') { showAlert(hlaska); } else { alert(hlaska); }
            setTimeout(function () { location.reload(); }, 1800);
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-invoice me-1"></i> Vystavit fakturu';
            chybu('Síťová chyba — faktura se možná nevystavila, obnov stránku.');
        });
    }
})();
</script>
