<?php
/* Dokument k vyplnění (výkupní list / zástavní formulář) — jednotný vizuál
   klientských dokladů. Horní lišta: zpět, přepínač jazyka dokumentu (překládá
   jen popisky, ne vyplněný obsah), Uložit / Tisk / Odeslat e-mailem.
   Tisk i e-mail dokument nejdřív automaticky uloží (číslo se přidělí samo). */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/documents.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) { header('Location: login.php'); exit; }
// Výkup i zástava jsou PROVOZNÍ doklady (vydávají hotovost z kasy) — role účetní
// sem nesmí; jinak by obešla zákaz z api/pos_cash_move.php přes výkupní list.
if (function_exists('crmIsAccountant') && crmIsAccountant()) { header('Location: accounting.php'); exit; }

$type = (string)($_GET['type'] ?? 'vykup');
if (!isset(crmDocTypes()[$type])) { $type = 'vykup'; }
$cfg = crmDocTypes()[$type];

$doc = crmGetDocument((int)($_GET['id'] ?? 0));
if ($doc && (string)$doc['doc_type'] !== $type) { $type = (string)$doc['doc_type']; $cfg = crmDocTypes()[$type]; }

$lang = crmDocLangOrDefault($_GET['lang'] ?? ($doc['lang'] ?? 'cs'));
$values = $doc['fields'] ?? [];
// nový doklad: místo a datum podpisu ví CRM samo (provozovna + dnešek)
if (!$doc) {
    foreach (crmDocDefaultValues($type) as $__k => $__v) {
        if (trim((string)($values[$__k] ?? '')) === '') { $values[$__k] = $__v; }
    }
}
$docNumber = (string)($doc['doc_number'] ?? '');
$docDate = !empty($doc['doc_date']) ? date('d.m.Y', strtotime((string)$doc['doc_date'])) : date('d.m.Y');
$docId = (int)($doc['id'] ?? 0);
$pageTitle = __($cfg['title_key'], $lang);
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(generateCsrfToken()); ?>">
    <title><?php echo e($pageTitle . ($docNumber !== '' ? ' ' . $docNumber : '')); ?></title>
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { margin: 0; background: #eceff3; padding: 74px 14px 40px;
               font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; }
        <?php echo crmDocumentSheetCss(); ?>
        /* ── horní lišta (jen na obrazovce) ── */
        .doc-toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 50; display: flex; align-items: center;
                       justify-content: space-between; gap: 12px; padding: 10px 16px; background: #0d1512; color: #eef2f8; }
        .doc-toolbar .tb-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .doc-toolbar a.back { color: rgba(255,255,255,.75); text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; }
        .doc-toolbar a.back:hover { color: #fff; }
        .doc-toolbar .tb-title { font-size: 14px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .doc-toolbar .tb-num { color: #5ac8fa; font-family: ui-monospace, Menlo, monospace; }
        .lang-seg { display: inline-flex; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.16);
                    border-radius: 10px; overflow: hidden; }
        .lang-seg button { border: 0; background: transparent; color: rgba(255,255,255,.65); font-weight: 700;
                           font-size: 12px; padding: 6px 12px; cursor: pointer; }
        .lang-seg button.on { background: #0a84ff; color: #fff; }
        .tb-actions { display: flex; gap: 8px; }
        .tb-btn { border: 1px solid rgba(255,255,255,.22); background: rgba(255,255,255,.06); color: #eef2f8;
                  border-radius: 10px; padding: 7px 13px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .tb-btn:hover { background: rgba(255,255,255,.14); }
        .tb-btn--primary { background: #0a84ff; border-color: #0a84ff; }
        .tb-btn--primary:hover { background: #0a5bd6; }
        .tb-btn:disabled { opacity: .5; cursor: default; }
        #docToast { position: fixed; right: 16px; bottom: 16px; z-index: 99; padding: 11px 16px; border-radius: 12px;
                    color: #fff; font-weight: 600; font-size: 13.5px; display: none; box-shadow: 0 8px 32px rgba(0,0,0,.35); }
        @media (max-width: 760px) {
            body { padding-top: 116px; }
            .doc-toolbar { flex-wrap: wrap; }
        }
        @media print { .doc-toolbar, #docToast { display: none !important; } body { padding: 0; } }
    </style>
</head>
<body>

<div class="doc-toolbar">
    <div class="tb-left">
        <a class="back" href="dokumenty.php?t=<?php echo e($type); ?>"><i class="fas fa-arrow-left me-1"></i> Dokumenty</a>
        <div class="tb-title"><?php echo e($pageTitle); ?> <span class="tb-num" id="tbNum"><?php echo e($docNumber); ?></span></div>
    </div>
    <div class="lang-seg" title="Jazyk dokumentu (překládá popisky, ne vyplněný obsah)">
        <?php foreach (['cs' => 'CS', 'en' => 'EN', 'ru' => 'RU'] as $lc => $ll): ?>
        <button type="button" data-lang="<?php echo $lc; ?>" class="<?php echo $lang === $lc ? 'on' : ''; ?>"><?php echo $ll; ?></button>
        <?php endforeach; ?>
    </div>
    <div class="tb-actions">
        <?php /* načtení věci z telefonu připojeného k Macu (v3.65.0) */ ?>
        <button type="button" class="tb-btn" id="btnDevice" title="Načte model, kapacitu, IMEI a kondici baterie z iPhonu/iPadu připojeného kabelem k Macu"><i class="fas fa-mobile-screen me-1"></i> Načíst zařízení</button>
        <?php /* opakovaný výkup od téhož člověka — identifikaci netlouct znovu (v3.63.0) */ ?>
        <button type="button" class="tb-btn" id="btnSeller" title="Doplní údaje o prodávajícím z jeho dřívějšího výkupu nebo zástavy"><i class="fas fa-user-clock me-1"></i> Prodávající z dřívějška</button>
        <button type="button" class="tb-btn" id="btnSave"><i class="fas fa-floppy-disk me-1"></i> Uložit</button>
        <button type="button" class="tb-btn" id="btnPrint"><i class="fas fa-print me-1"></i> Tisk</button>
        <button type="button" class="tb-btn" id="btnEmail"><i class="fas fa-envelope me-1"></i> Odeslat e-mailem</button>
        <?php if ($type === 'vykup'): ?>
        <button type="button" class="tb-btn" id="btnOnlineLink" title="Zkopíruje odkaz, přes který klient list vyplní online (stejný je i v e-mailu)"><i class="fas fa-link me-1"></i> Odkaz k vyplnění</button>
        <?php endif; ?>
        <button type="button" class="tb-btn tb-btn--primary" id="btnSign"><i class="fas fa-pen-nib me-1"></i> Podepsat na tabletu</button>
    </div>
</div>

<form id="docForm">
<?php echo crmRenderDocumentSheet($type, $values, $lang, 'form', $docNumber, $docDate, $docId); ?>
</form>

<div id="docToast"></div>

<?php /* Vyhledání dřívějšího prodávajícího. Přenáší se JEN údaje o osobě —
         věc, částka ani ověření totožnosti se nikdy nekopírují. */ ?>
<div id="sellerBox" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(6,10,16,.62);align-items:flex-start;justify-content:center;padding:80px 14px;">
  <div style="background:#fff;border-radius:16px;max-width:560px;width:100%;box-shadow:0 24px 70px rgba(0,0,0,.35);overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid #e6eaf0;display:flex;align-items:center;gap:10px;">
      <i class="fas fa-user-clock" style="color:#0a84ff;"></i>
      <strong style="flex:1;">Prodávající z dřívějšího dokladu</strong>
      <button type="button" id="sellerClose" style="border:0;background:transparent;font-size:20px;color:#8b95a6;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <input type="text" id="sellerQuery" placeholder="Jméno, telefon nebo e-mail…" autocomplete="off"
             style="width:100%;padding:10px 12px;border:1px solid #d6dbe4;border-radius:10px;font-size:15px;">
      <div style="font-size:12px;color:#8b95a6;margin-top:6px;">
        Přenesou se jen údaje o osobě (jméno, adresa, doklad totožnosti…). Vykupovanou věc,
        částku i ověření totožnosti vyplň znovu — patří k tomuhle výkupu.
      </div>
      <div id="sellerResults" style="margin-top:12px;max-height:320px;overflow:auto;"></div>
    </div>
  </div>
</div>

<?php /* Na Macu odstraní capture u file inputů — jinak appka „Designed for iPad"
         padá při pokusu otevřít fotoaparát (viz assets/js/capture-fix.js). */ ?>
<script src="assets/js/capture-fix.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/capture-fix.js'); ?>"></script>

<script>
(function () {
    var DOC_TYPE = <?php echo json_encode($type); ?>;
    var DOC_LANG = <?php echo json_encode($lang); ?>;
    var docId = <?php echo (int)$docId; ?>;
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function toast(msg, ok) {
        var t = document.getElementById('docToast');
        t.textContent = msg;
        t.style.background = ok ? 'rgba(52,199,89,.94)' : 'rgba(255,55,95,.94)';
        t.style.display = 'block';
        clearTimeout(t.__h); t.__h = setTimeout(function () { t.style.display = 'none'; }, ok ? 3200 : 6000);
    }

    function collect() {
        var out = {};
        document.querySelectorAll('#docForm .dinput').forEach(function (el) {
            if (el.name) { out[el.name] = el.value; }
        });
        return out;
    }

    // ── AUTO-ULOŽENÍ ROZPRACOVANÉHO DOKUMENTU (draft) ─────────────────────────
    // Rozepsaná data se průběžně ukládají do prohlížeče (localStorage). Když se
    // formulář omylem zavře, po znovuotevření se OBNOVÍ. Smaže se TEPRVE až je
    // dokument podepsán klientem (status==='done' níže) — ne při pouhém uložení.
    function draftKey() { return 'afx_draft:doc:' + DOC_TYPE + ':' + (docId > 0 ? docId : 'new'); }
    function saveDraft() {
        try {
            var data = collect();
            var any = Object.keys(data).some(function (k) { return String(data[k] || '').trim() !== ''; });
            if (any) { localStorage.setItem(draftKey(), JSON.stringify({ t: Date.now(), d: data })); }
            else { localStorage.removeItem(draftKey()); }
        } catch (e) {}
    }
    function restoreDraft() {
        try {
            var raw = localStorage.getItem(draftKey());
            if (!raw) { return; }
            var obj = JSON.parse(raw); var data = obj && obj.d; if (!data) { return; }
            var changed = 0;
            document.querySelectorAll('#docForm .dinput').forEach(function (el) {
                if (el.name && Object.prototype.hasOwnProperty.call(data, el.name) && el.value !== data[el.name]) {
                    el.value = data[el.name]; changed++;
                }
            });
            if (changed) { toast('↩︎ Obnoveny rozpracované údaje (neuložené).', true); }
        } catch (e) {}
    }
    function clearDraft() { try { localStorage.removeItem(draftKey()); } catch (e) {} }

    function save() {
        // výkupní částka je povinná — okamžitá kontrola už tady, server ji vynucuje taky
        if (DOC_TYPE === 'vykup') {
            var priceEl = document.querySelector('#docForm .dinput[name="item_price"]');
            if (priceEl && !priceEl.value.trim()) {
                priceEl.style.outline = '2px solid #ff375f';
                priceEl.addEventListener('input', function h() { priceEl.style.outline = ''; priceEl.removeEventListener('input', h); });
                priceEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                priceEl.focus();
                return Promise.reject(new Error('Vyplň výkupní částku — bez ní nejde list uložit.'));
            }
        }
        var fd = new FormData();
        fd.append('type', DOC_TYPE);
        fd.append('id', docId);
        fd.append('lang', DOC_LANG);
        fd.append('fields', JSON.stringify(collect()));
        fd.append('csrf_token', CSRF);
        return fetch('api/save_document.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { throw new Error(j.error || 'Uložení selhalo'); }
                // draft přesunout z klíče ':new' pod přidělené ID (data se NEmažou — jen po podpisu)
                try {
                    var oldKey = 'afx_draft:doc:' + DOC_TYPE + ':' + (docId > 0 ? docId : 'new');
                    var newKey = 'afx_draft:doc:' + DOC_TYPE + ':' + j.id;
                    if (oldKey !== newKey) {
                        var v = localStorage.getItem(oldKey);
                        if (v !== null) { localStorage.setItem(newKey, v); localStorage.removeItem(oldKey); }
                    }
                } catch (e) {}
                docId = j.id;
                var codeEl = document.querySelector('.doc-code');
                if (codeEl) { codeEl.textContent = j.doc_number; }
                var tn = document.getElementById('tbNum'); if (tn) { tn.textContent = j.doc_number; }
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.set('type', DOC_TYPE); u.searchParams.set('id', docId);
                    window.history.replaceState({}, '', u.toString());
                } catch (e) {}
                return j;
            });
    }

    document.getElementById('btnSave').onclick = function () {
        var b = this; b.disabled = true;
        save().then(function (j) { toast('💾 Uloženo — ' + j.doc_number, true); })
              .catch(function (e) { toast('⚠️ ' + e.message, false); })
              .finally(function () { b.disabled = false; });
    };

    /* Kontrola úplnosti před tiskem: výkupní list musí identifikovat prodávajícího
       i předmět obchodu (§ 31 odst. 6 zák. č. 455/1991 Sb.) a nést způsob úhrady.
       Nebráníme tisku (kabel sériové číslo nemá), jen se ptáme — tištěný a
       podepsaný neúplný doklad se opravuje mnohem hůř. */
    var DOC_REQUIRED = <?php echo json_encode($type === 'vykup' ? [
        'customer_name' => 'jméno a příjmení prodávajícího',
        'customer_address' => 'adresa prodávajícího',
        'customer_id_doc' => 'číslo dokladu totožnosti',
        'customer_id_verified' => 'kdo ověřil totožnost',
        'item_description' => 'popis zařízení',
        'item_brand' => 'výrobce / značka',
        'item_serial' => 'sériové číslo / IMEI',
        'item_price' => 'výkupní cena',
        'sign_payment' => 'způsob výplaty',
    ] : new stdClass(), JSON_UNESCAPED_UNICODE); ?>;

    function docMissingFields() {
        var missing = [];
        Object.keys(DOC_REQUIRED).forEach(function (name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (el && String(el.value || '').trim() === '') { missing.push(DOC_REQUIRED[name]); }
        });
        return missing;
    }

    document.getElementById('btnPrint').onclick = function () {
        var missing = docMissingFields();
        if (missing.length && !confirm('Doklad není kompletní — chybí:\n\n· ' + missing.join('\n· ')
                + '\n\nOpravdu tisknout takhle?')) { return; }
        var b = this; b.disabled = true;
        save().then(function () { window.print(); })
              .catch(function (e) { toast('⚠️ ' + e.message, false); })
              .finally(function () { b.disabled = false; });
    };

    var btnLink = document.getElementById('btnOnlineLink');
    if (btnLink) btnLink.onclick = function () {
        var b = this; b.disabled = true;
        save().then(function () {
            var fd = new FormData();
            fd.append('id', docId);
            fd.append('csrf_token', CSRF);
            return fetch('api/document_online_link.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'Odkaz se nepodařilo získat'); }
            // zkopírovat do schránky; když prohlížeč nedovolí, ukázat k ručnímu zkopírování
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(j.url)
                    .then(function () { toast('🔗 Odkaz pro klienta zkopírován — pošli ho SMS/WhatsAppem.', true); })
                    .catch(function () { window.prompt('Zkopíruj odkaz pro klienta:', j.url); });
            }
            window.prompt('Zkopíruj odkaz pro klienta:', j.url);
        }).catch(function (e) { toast('⚠️ ' + e.message, false); })
          .finally(function () { b.disabled = false; });
    };

    // ── částka vždy s měnou (v3.67.0) ────────────────────────────────────
    // Doplní se hned po opuštění pole, ať obsluha vidí přesně to, co bude
    // na vytištěném dokladu (server to při uložení stejně srovná znovu).
    (function () {
        var MENA = <?php echo json_encode(trim((string)get_setting('currency', 'Kč')) ?: 'Kč'); ?>;
        ['item_price', 'item_estimate', 'loan_amount'].forEach(function (name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (!el) return;
            el.addEventListener('blur', function () {
                var v = (el.value || '').trim();
                if (v === '' || !/\d/.test(v)) return;                 // „dohodou" nechat
                if (v.toLowerCase().indexOf(MENA.toLowerCase()) !== -1) return;
                var num = v.replace(/[^\d,.]/g, '').replace(/[.,]$/, '');
                if (num === '') return;
                var dec = /^(\d+)[.,](\d{1,2})$/.exec(num);
                var whole = dec ? dec[1] : num.replace(/[.,]/g, '');
                var groups = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
                el.value = groups + (dec ? ',' + (dec[2].length === 1 ? dec[2] + '0' : dec[2]) : '') + ' ' + MENA;
            });
        });
    })();

    // ── načtení vykupované věci z připojeného zařízení (v3.65.0) ─────────
    // Můstek na Macu (device-bridge) přečte telefon přes USB a pošle údaje do
    // CRM; tady se z nich vyplní popis věci, IMEI a kondice baterie. Rovnou
    // se pustí kontrola IMEI v databázi odcizených PČR — u výkupu je odcizený
    // kus to hlavní riziko a zjistit to PŘED výplatou peněz je celý smysl.
    (function () {
        var btn = document.getElementById('btnDevice');
        if (!btn) return;
        function esc(t) {
            return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }
        function f(name) { return document.querySelector('[name="' + name + '"]'); }

        function pcrCheck(imei) {
            if (!imei || imei.replace(/\D/g, '').length < 14) return;
            var fd = new FormData();
            fd.append('imei', imei); fd.append('csrf_token', CSRF);
            fetch('api/product_pcr.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) return;
                    if (d.status === 'stolen') {
                        toast('🚨 POZOR: IMEI je v databázi odcizených mobilů PČR! ' + (d.text || ''), false);
                    } else if (d.status === 'clean') {
                        toast('✅ IMEI není v databázi odcizených mobilů PČR.', true);
                    }
                })
                .catch(function () {});
        }

        btn.onclick = function () {
            btn.disabled = true;
            fetch('api/device_last.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok || !d.doc) {
                        toast('⚠️ ' + ((d && d.error) || 'Zařízení se nepodařilo přečíst.'), false);
                        return;
                    }
                    var filled = [], skipped = 0;
                    ['item_description', 'item_model', 'item_serial', 'item_state'].forEach(function (name) {
                        var el = f(name), val = (d.doc[name] || '').trim();
                        if (!el || val === '') return;
                        // co je vyplněné, se nepřepisuje — obsluha to mohla upřesnit
                        if (String(el.value || '').trim() !== '') { skipped++; return; }
                        el.value = val;
                        filled.push(name === 'item_state' ? 'stav' : (name === 'item_serial' ? 'IMEI' : (name === 'item_model' ? 'model' : 'popis')));
                    });
                    if (d.doc.model_unknown) {
                        // radši prázdný model než vymyšlený — ať to obsluha doplní
                        // a nahlásí identifikátor k doplnění do číselníku
                        toast('⚠️ Model ' + esc(d.doc.product_type || '') + ' zatím neznáme — název zařízení dopiš ručně a dej vědět, doplní se.', false);
                    } else if (!filled.length) {
                        toast('Pole o věci už jsou vyplněná — nic se nepřepisovalo.', true);
                    } else {
                        toast('📱 Načteno z ' + esc(d.station || 'Macu') + ': ' + filled.join(', ')
                            + (skipped ? ' (' + skipped + ' polí už bylo vyplněných)' : ''), true);
                    }
                    pcrCheck(d.doc.imei || (f('item_serial') ? f('item_serial').value : ''));
                })
                .catch(function () { toast('⚠️ Můstek se nepodařilo oslovit.', false); })
                .then(function () { btn.disabled = false; });
        };
    })();

    // ── prodávající z dřívějšího dokladu (v3.63.0) ───────────────────────
    // Stálí prodávající chodí opakovaně; přepisovat pokaždé rodné číslo,
    // adresu a doklad totožnosti je zdržení i zdroj překlepů. Přenáší se
    // JEN údaje o osobě — věc a částka musí být u každého výkupu vlastní.
    (function () {
        var box = document.getElementById('sellerBox');
        var input = document.getElementById('sellerQuery');
        var results = document.getElementById('sellerResults');
        var btn = document.getElementById('btnSeller');
        if (!box || !btn) return;

        function esc(t) {
            return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }
        function fieldEl(name) { return document.querySelector('[name="' + name + '"]'); }
        function open() {
            box.style.display = 'flex';
            // předvyplnit tím, co je v listu už napsané — obsluha většinou začne jménem
            var n = fieldEl('customer_name'), p = fieldEl('customer_phone');
            input.value = (n && n.value.trim()) || (p && p.value.trim()) || '';
            setTimeout(function () { input.focus(); input.select(); }, 40);
            if (input.value.trim().length >= 2) { search(); }
            else { results.innerHTML = '<div style="color:#8b95a6;font-size:13px;">Napiš aspoň dva znaky.</div>'; }
        }
        function close() { box.style.display = 'none'; }

        var timer = null;
        function search() {
            var q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            results.innerHTML = '<div style="color:#8b95a6;font-size:13px;">Hledám…</div>';
            fetch('api/doc_seller_lookup.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) { results.innerHTML = '<div style="color:#c0392b;font-size:13px;">' + esc((d && d.error) || 'Hledání selhalo.') + '</div>'; return; }
                    if (!d.people.length) { results.innerHTML = '<div style="color:#8b95a6;font-size:13px;">Nikdo takový u nás zatím nic neprodával.</div>'; return; }
                    results.innerHTML = d.people.map(function (p) {
                        var kdy = p.doc_date ? p.doc_date.split('-').reverse().join('. ') : '';
                        var kolik = p.count > 1 ? ' · ' + p.count + '× u nás' : '';
                        return '<button type="button" data-doc="' + (p.id | 0) + '" '
                            + 'style="display:block;width:100%;text-align:left;border:1px solid #e6eaf0;background:#fff;'
                            + 'border-radius:12px;padding:10px 12px;margin-bottom:8px;cursor:pointer;">'
                            + '<div style="font-weight:700;font-size:14.5px;">' + esc(p.name) + '</div>'
                            + '<div style="color:#8b95a6;font-size:12.5px;">' + esc(p.phone || p.email || '')
                            + ' · ' + esc(p.doc_type === 'zastava' ? 'zástava' : 'výkup') + ' ' + esc(p.doc_number)
                            + (kdy ? ' · ' + esc(kdy) : '') + esc(kolik) + '</div></button>';
                    }).join('');
                })
                .catch(function () { results.innerHTML = '<div style="color:#c0392b;font-size:13px;">Hledání se nepodařilo odeslat.</div>'; });
        }

        function fillFrom(docIdToUse) {
            fetch('api/doc_seller_lookup.php?id=' + (docIdToUse | 0), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) { toast('⚠️ ' + ((d && d.error) || 'Údaje se nepodařilo načíst.'), false); return; }
                    var filled = 0, skipped = 0;
                    Object.keys(d.fields || {}).forEach(function (name) {
                        var el = fieldEl(name);
                        if (!el) return;
                        // co je vyplněné, se nepřepisuje — obsluha už to mohla opravit
                        if (String(el.value || '').trim() !== '') { skipped++; return; }
                        el.value = d.fields[name];
                        filled++;
                    });
                    close();
                    if (!filled) { toast('Všechna pole už jsou vyplněná — nic se nepřepisovalo.', true); return; }
                    toast('✅ Doplněno ' + filled + ' údajů z dokladu ' + d.doc_number
                        + (skipped ? ' (' + skipped + ' už bylo vyplněných, ty zůstaly)' : '')
                        + '. Zkontroluj platnost dokladu totožnosti.', true);
                })
                .catch(function () { toast('⚠️ Načtení údajů selhalo.', false); });
        }

        btn.onclick = open;
        document.getElementById('sellerClose').onclick = close;
        box.addEventListener('click', function (e) { if (e.target === box) { close(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.style.display !== 'none') { close(); } });
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 300); });
        results.addEventListener('click', function (e) {
            var b = e.target.closest('[data-doc]');
            if (b) { fillFrom(parseInt(b.getAttribute('data-doc'), 10) || 0); }
        });

        // tichá nápověda: po zapsání telefonu/jména se zeptáme, jestli tu už byl
        ['customer_phone', 'customer_name'].forEach(function (name) {
            var el = fieldEl(name);
            if (!el) return;
            el.addEventListener('blur', function () {
                var v = el.value.trim();
                if (v.length < 4) return;
                var pid = fieldEl('customer_pid');
                if (pid && pid.value.trim() !== '') return;      // identifikace už je hotová
                fetch('api/doc_seller_lookup.php?q=' + encodeURIComponent(v), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok || !d.people.length) return;
                        var p = d.people[0];
                        toast('ℹ️ ' + p.name + ' u nás už prodával (' + p.doc_number + ') — „Prodávající z dřívějška" doplní údaje.', true);
                    })
                    .catch(function () {});
            });
        });
    })();

    document.getElementById('btnEmail').onclick = function () {
        var b = this; b.disabled = true;
        save().then(function () {
            var fd = new FormData();
            fd.append('id', docId);
            fd.append('csrf_token', CSRF);
            return fetch('api/send_document_email.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'Odeslání selhalo'); }
            toast('✉️ Kopie odeslána na ' + j.to, true);
        }).catch(function (e) { toast('⚠️ ' + e.message, false); })
          .finally(function () { b.disabled = false; });
    };

    // Podpis na tabletu: uložit → poslat požadavek na podpisovou stanici.
    // Po podpisu klientem se podpis vloží do dokumentu (stránka ho ukáže po reloadu).
    document.getElementById('btnSign').onclick = function () {
        var b = this; b.disabled = true;
        save().then(function () {
            var fd = new FormData();
            fd.append('action', 'create');
            fd.append('document_id', docId);
            fd.append('sig_type', 'dokument');
            fd.append('csrf_token', CSRF);
            return fetch('api/request_signature.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'Odeslání na tablet selhalo'); }
            toast('✍️ Odesláno na podpisový tablet — po podpisu klienta se podpis vloží do dokumentu.', true);
            // až klient podepíše, načíst dokument s podpisem
            var check = setInterval(function () {
                fetch('api/request_signature.php?check=' + j.request_id, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (s) {
                        if (s.status === 'done') { clearInterval(check); clearDraft(); window.location.reload(); }
                        if (s.status === 'cancelled' || s.status === 'missing') { clearInterval(check); }
                    }).catch(function () {});
            }, 3000);
        }).catch(function (e) { toast('⚠️ ' + e.message, false); })
          .finally(function () { b.disabled = false; });
    };

    // Fotodokumentace: výběr fotek (na telefonu rovnou foťák) → uložit dokument
    // → nahrát fotky → reload s vloženými fotkami. Křížek fotku smaže.
    var photoInput = document.getElementById('docPhotoInput');
    if (photoInput) {
        photoInput.onchange = function () {
            if (!photoInput.files || !photoInput.files.length) { return; }
            save().then(function () {
                var fd = new FormData();
                fd.append('action', 'upload');
                fd.append('document_id', docId);
                fd.append('csrf_token', CSRF);
                for (var i = 0; i < photoInput.files.length; i++) { fd.append('files[]', photoInput.files[i]); }
                return fetch('api/document_media.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }).then(function (j) {
                if (!j.ok) { throw new Error(j.error || 'Nahrání selhalo'); }
                window.location.reload();
            }).catch(function (e) { toast('⚠️ ' + e.message, false); photoInput.value = ''; });
        };
    }
    // Doklad totožnosti: dvě samostatná pole (přední/zadní strana). Nový soubor
    // nahradí předchozí stranu, aby se nehromadily kopie občanek.
    document.querySelectorAll('.idscan-input').forEach(function (inp) {
        inp.onchange = function () {
            if (!inp.files || !inp.files.length) { return; }
            save().then(function () {
                var fd = new FormData();
                fd.append('action', 'upload');
                fd.append('document_id', docId);
                fd.append('kind', inp.dataset.kind);
                fd.append('csrf_token', CSRF);
                fd.append('files[]', inp.files[0]);
                return fetch('api/document_media.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }).then(function (j) {
                if (!j.ok) { throw new Error(j.error || 'Nahrání selhalo'); }
                window.location.reload();
            }).catch(function (e) { toast('⚠️ ' + e.message, false); inp.value = ''; });
        };
    });

    document.querySelectorAll('.photo-del').forEach(function (btn) {
        btn.onclick = function () {
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('media_id', btn.dataset.mediaId);
            fd.append('csrf_token', CSRF);
            fetch('api/document_media.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { throw new Error(j.error || 'Smazání selhalo'); }
                    var item = btn.closest('.photo-item') || btn.closest('.idscan');
                    if (item) { window.location.reload(); }
                }).catch(function (e) { toast('⚠️ ' + e.message, false); });
        };
    });

    // Přepnutí jazyka: nejdřív uložit (ať se nic neztratí), pak reload s ?lang=
    document.querySelectorAll('.lang-seg button').forEach(function (btn) {
        btn.onclick = function () {
            var lg = btn.dataset.lang;
            if (lg === DOC_LANG) { return; }
            btn.disabled = true;
            save().then(function () {
                var u = new URL(window.location.href);
                u.searchParams.set('type', DOC_TYPE);
                if (docId > 0) { u.searchParams.set('id', docId); }
                u.searchParams.set('lang', lg);
                window.location.href = u.toString();
            }).catch(function (e) { toast('⚠️ ' + e.message, false); btn.disabled = false; });
        };
    });

    // Draft: po načtení obnovit neuložené údaje + průběžně ukládat při psaní.
    restoreDraft();
    (function () {
        var dt, f = document.getElementById('docForm');
        if (f) { f.addEventListener('input', function () { clearTimeout(dt); dt = setTimeout(saveDraft, 400); }); }
    })();
})();
</script>
</body>
</html>
