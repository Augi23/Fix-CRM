<?php
/* PODPISOVÁ STANICE — celoobrazovková stránka pro iPad na pultu.
   Přihlášený zaměstnanec ji otevře (a přidá na plochu jako PWA), stanice pak
   sama zobrazuje podpisové požadavky poslané z detailu zakázky. */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$branchLabel = getBranchLabel((int)getCurrentStaffBranchId()) ?: get_setting('company_name', 'AppleFix');
?>
<!DOCTYPE html>
<html lang="cs" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Podpisová stanice — AppleFix</title>
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0d1512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <link rel="stylesheet" href="assets/css/crm-shell.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/crm-shell.css'); ?>">
    <style>
        html, body { height: 100%; background: #070a09 !important; }
        body { font-family: 'SF Pro Display', -apple-system, sans-serif; margin: 0; overflow: hidden;
               display: flex; align-items: center; justify-content: center; color: #eef2f8; }
        .station-idle { text-align: center; padding: 24px; }
        .station-idle img { height: 54px; filter: invert(1); opacity: .9; }
        .station-idle h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 26px 0 6px; }
        .station-idle .sub { color: rgba(255,255,255,.45); font-size: 14px; font-weight: 300; }
        .station-idle .pulse {
            width: 10px; height: 10px; border-radius: 50%; background: #3be8a8; margin: 26px auto 0;
            animation: stationPulse 2.2s ease-in-out infinite;
        }
        @keyframes stationPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59,232,168,.45); }
            55%      { box-shadow: 0 0 0 16px rgba(59,232,168,0); }
        }
        .station-branch { position: fixed; bottom: 18px; left: 0; right: 0; text-align: center;
                          color: rgba(255,255,255,.3); font-size: 12px; font-weight: 300; }
    </style>
</head>
<body>

<div class="station-idle">
    <img src="assets/img/logo-black.png" alt="AppleFix">
    <h1>Podpisová stanice</h1>
    <div class="sub">Čekám na podpis — požadavek pošlete z detailu zakázky.</div>
    <div class="pulse"></div>
</div>
<div class="station-branch"><?php echo e($branchLabel); ?> · přihlášen: <?php echo e($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></div>

<!-- Výběr z fronty (víc čekajících podpisů = klient si vybere SVOU zakázku) -->
<div id="queueChooser" style="display:none; position:fixed; inset:0; z-index:3050; background:#070a09; flex-direction:column; align-items:center; justify-content:center; padding:24px;">
    <div style="color:#eef2f8; font-size:22px; font-weight:800; margin-bottom:6px;">Kdo jde podepsat?</div>
    <div style="color:rgba(255,255,255,.45); font-size:13px; font-weight:300; margin-bottom:22px;">Klepněte na svou zakázku — zkontrolujte jméno a zařízení.</div>
    <div id="queueList" style="display:flex; flex-direction:column; gap:12px; width:min(560px,92vw);"></div>
</div>

<!-- Náhled zakázkového listu k podpisu -->
<div id="docView" style="display:none; position:fixed; inset:0; z-index:3100; background:#eceff3; flex-direction:column;">
    <div style="flex:0 0 auto; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; background:#0d1512; color:#eef2f8;">
        <div>
            <div style="font-size:15px; font-weight:800;" id="docViewTitle">Zakázkový list</div>
            <div style="font-size:12px; font-weight:300; color:rgba(255,255,255,.55);" id="docViewSub"></div>
        </div>
        <div style="display:flex; gap:10px;">
            <button type="button" class="btn btn-outline-light" onclick="stationDocCancel()">Teď ne</button>
            <button type="button" class="btn btn-success btn-lg px-4" onclick="stationDocSign()"><i class="fas fa-pen-nib me-2"></i>Podepsat</button>
        </div>
    </div>
    <iframe id="docViewFrame" style="flex:1 1 auto; width:100%; border:0; background:#eceff3;"></iframe>
</div>

<!-- Potvrzení po podpisu -->
<div id="doneFlash" style="display:none; position:fixed; inset:0; z-index:3300; background:rgba(6,10,9,.9); backdrop-filter:blur(6px); align-items:center; justify-content:center;">
    <div style="text-align:center; color:#eef2f8;">
        <div style="width:84px; height:84px; margin:0 auto 20px; border-radius:50%; background:rgba(59,232,168,.16); border:2px solid #3be8a8; display:flex; align-items:center; justify-content:center;">
            <i class="fas fa-check" style="font-size:36px; color:#3be8a8;"></i>
        </div>
        <div style="font-size:24px; font-weight:800;">Podepsáno</div>
        <div id="doneFlashSub" style="font-size:14px; font-weight:300; color:rgba(255,255,255,.55); margin-top:6px;"></div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/main.js'); ?>"></script>
<script>
window.AFX_SIGN_L10N = { clear: 'Smazat', cancel: 'Teď ne', save: 'Uložit podpis' };
(function () {
    var busy = false;
    var current = null;
    var CSRF = '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>';
    var docView = document.getElementById('docView');
    var doneFlash = document.getElementById('doneFlash');

    var chooser = document.getElementById('queueChooser');

    function poll() {
        if (busy) return;
        fetch('api/sign_station.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (busy || !d || !d.ok) return;
                var reqs = d.requests || (d.request ? [d.request] : []);
                if (!reqs.length) return;
                busy = true;
                if (reqs.length === 1) {
                    current = reqs[0];
                    showDocument(reqs[0]);
                } else {
                    showChooser(reqs);   // víc čekajících → klient si vybere SVOU zakázku
                }
            })
            .catch(function () {});
    }

    // Výběr z fronty: karta = jméno klienta (velké) + zařízení + kdo poslal
    function showChooser(reqs) {
        var list = document.getElementById('queueList');
        list.innerHTML = '';
        reqs.forEach(function (r) {
            var b = document.createElement('button');
            b.type = 'button';
            b.style.cssText = 'display:block;width:100%;text-align:left;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:16px 18px;color:#eef2f8;';
            b.innerHTML = '<div style="font-size:19px;font-weight:800;">' + esc(r.customer) + '</div>' +
                '<div style="font-size:13px;font-weight:300;color:rgba(255,255,255,.55);margin-top:3px;">' +
                esc(r.order_code) + ' · ' + esc(r.device) + (r.amount ? ' · ' + esc(r.amount) : '') +
                (r.requested_by ? ' · obsluhuje ' + esc(r.requested_by) : '') + '</div>';
            b.addEventListener('click', function () {
                chooser.style.display = 'none';
                current = r;
                showDocument(r);
            });
            list.appendChild(b);
        });
        chooser.style.display = 'flex';
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = String(t == null ? '' : t); return d.innerHTML; }

    // 1) Klientovi se ukáže CELÝ zakázkový list (v jeho jazyce, bez ovládání)
    function showDocument(req) {
        document.getElementById('docViewTitle').textContent =
            req.sig_type === 'vydej' ? 'Zakázkový list — převzetí hotové zakázky' : 'Zakázkový list — převzetí do opravy';
        document.getElementById('docViewSub').textContent =
            req.order_code + ' · ' + req.customer + ' · ' + req.device + (req.amount ? ' · ' + req.amount : '');
        document.getElementById('docViewFrame').src = 'print_order.php?id=' + encodeURIComponent(req.order_id) + '&plain=1';
        docView.style.display = 'flex';
    }
    function hideDocument() {
        docView.style.display = 'none';
        chooser.style.display = 'none';
        document.getElementById('docViewFrame').src = 'about:blank';
    }

    window.stationDocCancel = function () {
        if (!current) return;
        var fd = new FormData();
        fd.append('action', 'cancel');
        fd.append('request_id', current.id);
        fd.append('csrf_token', CSRF);
        fetch('api/request_signature.php', { method: 'POST', body: fd })
            .finally(function () { hideDocument(); current = null; busy = false; });
    };

    // 2) Podpisové plátno; zrušení vrací na dokument (požadavek žije dál)
    window.stationDocSign = function () {
        if (!current) return;
        var req = current;
        var terms = {
            prijem: 'Podpisem potvrzuji souhlas s podmínkami opravy uvedenými na zakázkovém listu (dostupné též na applefix.cz).',
            vydej: 'Podpisem potvrzuji převzetí zařízení z opravy.'
        };
        afxSignaturePad({
            title: 'Podepisuje: ' + req.customer,
            subtitle: (req.sig_type === 'vydej' ? 'Převzetí hotové zakázky' : 'Převzetí do opravy') + ' · ' + req.order_code + ' · ' + req.device + (req.amount ? ' · ' + req.amount : ''),
            terms: terms[req.sig_type] || '',
            onSave: function (dataUrl) {
                var fd = new FormData();
                fd.append('order_id', req.order_id);
                fd.append('sig_type', req.sig_type);
                fd.append('image', dataUrl);
                fd.append('request_id', req.id);
                fd.append('csrf_token', CSRF);
                fetch('api/save_signature.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        hideDocument();
                        // 3) potvrzení: dokument uložen k zakázce (+ e-mail, pokud odešel)
                        document.getElementById('doneFlashSub').textContent = (j && j.emailed)
                            ? 'Podepsaný zakázkový list byl uložen a odeslán na e-mail.'
                            : 'Podepsaný zakázkový list byl uložen k zakázce.';
                        doneFlash.style.display = 'flex';
                        setTimeout(function () { doneFlash.style.display = 'none'; current = null; busy = false; }, 3500);
                    })
                    .catch(function () { hideDocument(); current = null; busy = false; });
            },
            onCancel: function () { /* zpět na dokument, požadavek trvá */ }
        });
    };

    setInterval(poll, 3000);
    poll();
})();
</script>
</body>
</html>
