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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/main.js'); ?>"></script>
<script>
window.AFX_SIGN_L10N = { clear: 'Smazat', cancel: 'Teď ne', save: 'Uložit podpis' };
(function () {
    var busy = false;
    var CSRF = '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>';

    function poll() {
        if (busy) return;
        fetch('api/sign_station.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (busy || !d || !d.ok || !d.request) return;
                busy = true;
                show(d.request);
            })
            .catch(function () {});
    }

    function show(req) {
        var titles = { prijem: 'Podpis — převzetí zařízení do opravy', vydej: 'Podpis — převzetí hotové zakázky' };
        var terms = {
            prijem: 'Podpisem potvrzuji souhlas s podmínkami opravy uvedenými na zakázkovém listu (dostupné též na applefix.cz).',
            vydej: 'Podpisem potvrzuji převzetí zařízení z opravy.'
        };
        afxSignaturePad({
            title: titles[req.sig_type] || 'Podpis klienta',
            subtitle: req.order_code + ' · ' + req.customer + ' · ' + req.device + (req.amount ? ' · ' + req.amount : ''),
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
                    .finally(function () { busy = false; });
            },
            onCancel: function () {
                var fd = new FormData();
                fd.append('action', 'cancel');
                fd.append('request_id', req.id);
                fd.append('csrf_token', CSRF);
                fetch('api/request_signature.php', { method: 'POST', body: fd })
                    .finally(function () { busy = false; });
            }
        });
    }

    setInterval(poll, 3000);
    poll();
})();
</script>
</body>
</html>
