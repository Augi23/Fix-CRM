<?php
require_once __DIR__ . '/functions.php';

// Klientská doména (applefix.help) NIKDY nezobrazuje admin CRM — každou admin
// stránku přesměruje na přihlášení klientského portálu. Admin doména beze změny.
if (function_exists('crmIsClientDomain') && crmIsClientDomain()) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/../klient/includes/auth.php';

// Keep client users inside the client portal
if (clientIsLoggedIn()) {
    header('Location: klient/dashboard.php');
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    // Sken regálového QR odhlášeným telefonem nesmí cíl ztratit: u skladu
    // předáme návratovou adresu (login.php ji po přihlášení použije).
    if (basename($_SERVER['PHP_SELF']) === 'sklad.php') {
        header("Location: login.php?redirect=" . rawurlencode('sklad.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }
    header("Location: login.php");
    exit;
}

// Access Control based on permissions
$page = basename($_SERVER['PHP_SELF']);

// Pages that require specific permissions
$permission_pages = [
    'customers.php' => 'edit_customers',
    'edit_customer.php' => 'edit_customers',
    'inventory.php' => 'manage_inventory',
    'edit_inventory.php' => 'manage_inventory',
    'products.php' => 'manage_inventory',
    // 'reports.php' => 'admin_access', // Handled specially below
];

if ($page == 'reports.php') {
    if (!hasPermission('view_reports_all') && !hasPermission('admin_access') && (($_SESSION['role'] ?? '') != 'technician')) {
        header("Location: index.php");
        exit;
    }
} elseif (isset($permission_pages[$page]) && !hasPermission($permission_pages[$page])) {
    header("Location: index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$search_action = 'index.php';
$search_placeholder = __('search_placeholder');
$show_search = true;

if ($current_page == 'orders.php') {
    $search_action = 'orders.php';
    $search_placeholder = __('orders') . ' (ID, ' . __('client') . ', ' . __('device_model') . '...)';
} elseif ($current_page == 'customers.php') {
    $search_action = 'customers.php';
    $search_placeholder = __('customers') . ' (ID, ' . __('client') . ', ' . __('phone') . ', ' . __('ico') . '...)';
} elseif ($current_page == 'inventory.php') {
    $search_action = 'inventory.php';
    $search_placeholder = __('inventory') . ' (ID, ' . __('part_name') . ', ' . __('sku') . '...)';
} elseif ($current_page == 'products.php') {
    $search_action = 'products.php';
    $search_placeholder = 'Produkty (název, kód, model...)';
} elseif ($current_page == 'pokladna.php') {
    $show_search = false;   // kasa má vlastní velké vyhledávání
} elseif ($current_page == 'settings.php') {
    if (($_SESSION['role'] ?? '') == 'admin') {
        $search_action = 'settings.php';
        $search_placeholder = __('technicians') . '...';
    } else {
        $show_search = false;
    }
}

$pageTitleMap = [
    'index.php' => __('dashboard'),
    'orders.php' => __('orders'),
    'customers.php' => __('customers'),
    'inventory.php' => __('inventory'),
    'products.php' => __('inventory'),
    'pokladna.php' => 'Pokladna',
    'reports.php' => __('reports'),
    'accounting.php' => __('accounting'),
    'banka.php' => __('accounting'),
    'settings.php' => __('settings'),
    'edit_order.php' => __('orders'),
    'view_order.php' => __('orders'),
];
$topbarTitle = $pageTitleMap[$current_page] ?? get_setting('company_name', 'Repair CRM');

$roleLabel = match (getCurrentStaffRole()) {
    'admin' => __('role_admin'),
    'manager' => __('role_manager'),
    'engineer' => __('role_engineer'),
    'brigadnik' => 'Brigádník',
    default => ucfirst((string)getCurrentStaffRole()),
};

$currentLang = crm_get_language();
$langRedirect = $_SERVER['REQUEST_URI'] ?? basename($_SERVER['PHP_SELF']);

$ordersBadgeCount = 0;
try {
    $ordersBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM orders WHERE status IN (" . orderStatusSqlIn($pdo, 'active') . ")" . orderBranchScopeSql('branch_id'))->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $ordersBadgeCount = 0;
}

// Počet aktivních reklamací (badge v menu) — vše mimo Vyřízeno/Zamítnuto
$complaintsBadgeCount = 0;
try {
    $complaintsBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM complaints WHERE complaint_status NOT IN ('Vyřízeno','Zamítnuto')")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $complaintsBadgeCount = 0;
}

// Počet aktivních položek nákupů (badge v menu) — čeká na objednání nebo doručení
$procurementBadgeCount = 0;
try {
    $procurementBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('pending','ordered')")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $procurementBadgeCount = 0;
}

// Počet čekajících položek nákupního seznamu (badge v menu)
$shoppingListBadgeCount = 0;
try {
    $shoppingListBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $shoppingListBadgeCount = 0;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <?php /* viewport-fit=cover: zpřístupní env(safe-area-inset-*) pro iPhony
             s dynamic island / home indicatorem (spodní lišta, sheet) */ ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo e(get_setting('company_name', 'Repair CRM')); ?> - <?php echo e(__('dashboard')); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0d1512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fix-CRM">
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }</script>
    <meta name="csrf-token" content="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
    <script>(function(){try{var t=localStorage.getItem('lg-theme')||'dark';document.documentElement.setAttribute('data-lg-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/sf-pro.css'); ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/fix-crm-v2.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/fix-crm-v2.css'); ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/liquid-glass.css'); ?>">
    <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>">
    <link rel="stylesheet" href="assets/css/crm-shell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/crm-shell.css'); ?>">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
    <script src="assets/js/liquid-glass.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass.js'); ?>" defer></script>
    <script src="assets/js/liquid-glass-engine.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass-engine.js'); ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.umd.js"></script>
    <?php if (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])): ?>
    <script>
    // aktuální CSRF token VŽDY čteme z meta tagu (ne z closure) — po obnovení
    // přihlášení se meta aktualizuje a všechny další požadavky jedou s novým tokenem
    window.afxCsrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; };
    window.afxCurrentUser = <?php echo json_encode((string)($_SESSION['username'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    window.afxCurrentName = <?php echo json_encode((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;

    $(function() {
        $.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if (settings.type === 'POST' || settings.type === 'post') {
                    var tok = window.afxCsrf();
                    if (typeof settings.data === 'string') {
                        // přepiš případný starý token, jinak přidej
                        if (/(^|&)csrf_token=/.test(settings.data)) {
                            settings.data = settings.data.replace(/(^|&)csrf_token=[^&]*/, '$1csrf_token=' + encodeURIComponent(tok));
                        } else {
                            settings.data += '&csrf_token=' + encodeURIComponent(tok);
                        }
                    } else if (settings.data instanceof FormData) {
                        settings.data.set('csrf_token', tok);
                    }
                }
            }
        });
        // $.post/$.ajax chyby na CSRF → okno se znovupřihlášením
        $(document).ajaxError(function (e, xhr) {
            if (window.afxLooksLikeCsrf(xhr.status, xhr.responseText)) { window.afxReauth.open(); }
        });
    });

    // detekce „neplatný token" napříč jazyky (CZ/EN/RU) — JEN v CHYBOVÝCH odpovědích.
    // KRITICKÉ: nesmí skenovat úspěšná data (chat, vyhledávání, notify_poll vrací text
    // se slovy token/csrf a vracejí "ok":true/"success":true → jinak by okno vyskakovalo
    // donekonečna). Chybová = HTTP >= 400 nebo tělo s "success":false / "ok":false.
    window.afxLooksLikeCsrf = function (status, text) {
        text = String(text || '');
        var isError = status >= 400 || /"(success|ok)"\s*:\s*false/.test(text);
        if (!isError) return false;
        return /csrf|neplatný (bezpečnostní )?token|invalid (security |csrf )?token|токен/i.test(text);
    };

    // Fetch wrapper: (1) přepíše csrf_token na aktuální z meta (auto-heal i u akcí se
    // zapečeným starým tokenem), (2) při CSRF chybě v odpovědi otevře okno reauth.
    window.afxRawFetch = window.fetch.bind(window);   // původní fetch pro reauth (mimo wrapper)
    (function () {
        var _fetch = window.afxRawFetch;
        window.fetch = function (input, init) {
            init = init || {};
            var tok = window.afxCsrf();
            try {
                if (init.body instanceof FormData && init.body.has('csrf_token')) {
                    init.body.set('csrf_token', tok);
                } else if (typeof init.body === 'string' && init.body.indexOf('csrf_token') !== -1) {
                    // JSON tělo
                    if (init.body.charAt(0) === '{') {
                        var o = JSON.parse(init.body);
                        if (o && Object.prototype.hasOwnProperty.call(o, 'csrf_token')) { o.csrf_token = tok; init.body = JSON.stringify(o); }
                    } else if (/(^|&)csrf_token=/.test(init.body)) {
                        init.body = init.body.replace(/(^|&)csrf_token=[^&]*/, '$1csrf_token=' + encodeURIComponent(tok));
                    }
                }
            } catch (err) { /* tělo nechme být */ }
            return _fetch(input, init).then(function (resp) {
                try {
                    // některé endpointy hlásí CSRF chybu i s HTTP 200 + "success":false,
                    // proto peekneme i 200; ROZHODUJE ale afxLooksLikeCsrf (isError guard),
                    // který úspěšná data ("ok":true / "success":true) NIKDY nechytne
                    if (resp.status === 200 || resp.status >= 400) {
                        resp.clone().text().then(function (t) {
                            if (window.afxLooksLikeCsrf(resp.status, t)) { window.afxReauth.open(); }
                        }).catch(function () {});
                    }
                } catch (err) {}
                return resp;
            });
        };
    })();

    // ── Okno „Obnovit přihlášení" ──
    window.afxReauth = (function () {
        var open = false, pending = false, elModal, elPass, elErr, elName;
        function ensure() {
            if (elModal) return;
            var html =
                '<div class="afx-reauth-ov" id="afxReauthOv">' +
                  '<div class="afx-reauth-card">' +
                    '<div class="afx-reauth-ic"><i class="fas fa-user-lock"></i></div>' +
                    '<h4>Přihlášení vypršelo</h4>' +
                    '<div class="afx-reauth-who">Zadej heslo a pokračuj — nic se neztratí.</div>' +
                    '<div class="afx-reauth-name" id="afxReauthName"></div>' +
                    '<input type="password" id="afxReauthPass" class="form-control" placeholder="Heslo" autocomplete="current-password">' +
                    '<div class="afx-reauth-err" id="afxReauthErr"></div>' +
                    '<button type="button" class="afx-reauth-btn" id="afxReauthBtn"><i class="fas fa-unlock me-2"></i>Obnovit přihlášení</button>' +
                    '<a href="login.php" class="afx-reauth-other">Přihlásit jiného zaměstnance →</a>' +
                  '</div>' +
                '</div>';
            var wrap = document.createElement('div');
            wrap.innerHTML = html;
            document.body.appendChild(wrap.firstChild);
            elModal = document.getElementById('afxReauthOv');
            elPass = document.getElementById('afxReauthPass');
            elErr = document.getElementById('afxReauthErr');
            elName = document.getElementById('afxReauthName');
            document.getElementById('afxReauthBtn').addEventListener('click', submit);
            elPass.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
        }
        function submit() {
            if (pending) return;
            var pass = elPass.value;
            if (!pass) { elErr.textContent = 'Zadej heslo.'; return; }
            pending = true;
            elErr.textContent = 'Ověřuji…';
            var fd = new FormData();
            fd.append('username', window.afxCurrentUser || '');
            fd.append('password', pass);
            _fetchRaw('api/reauth.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pending = false;
                    if (!d.success) { elErr.textContent = d.message || 'Nepodařilo se.'; elPass.value = ''; elPass.focus(); return; }
                    // propíšeme nový token všude (meta + skrytá pole formulářů)
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.setAttribute('content', d.csrf_token);
                    document.querySelectorAll('input[name="csrf_token"]').forEach(function (i) { i.value = d.csrf_token; });
                    close();
                    if (window.showAlert) showAlert('Přihlášení obnoveno. Zopakuj prosím poslední akci.');
                })
                .catch(function () { pending = false; elErr.textContent = 'Síťová chyba — zkus to znovu.'; });
        }
        function show() {
            if (open) return;
            ensure();
            open = true;
            elErr.textContent = '';
            elPass.value = '';
            elName.textContent = window.afxCurrentName ? ('Přihlášen: ' + window.afxCurrentName) : '';
            elModal.classList.add('show');
            setTimeout(function () { elPass.focus(); }, 60);
        }
        function close() { open = false; if (elModal) elModal.classList.remove('show'); }
        // reauth samotný jede přes původní fetch (mimo wrapper) — jinak by CSRF-detekce
        // nad jeho vlastní odpovědí mohla okno otevřít donekonečna
        var _fetchRaw = window.afxRawFetch;
        return { open: show, close: close };
    })();
    </script>
    <style>
    .afx-reauth-ov { position: fixed; inset: 0; z-index: 13000; display: none; align-items: center; justify-content: center;
      background: rgba(5,8,14,.72); backdrop-filter: blur(16px) saturate(1.2); -webkit-backdrop-filter: blur(16px) saturate(1.2); }
    .afx-reauth-ov.show { display: flex; }
    .afx-reauth-card { width: min(390px, 92vw); background: rgba(14,18,26,.94); border: 1px solid rgba(255,255,255,.12);
      border-radius: 22px; padding: 28px 26px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,.5); }
    .afx-reauth-ic { font-size: 32px; color: #5fd2ff; margin-bottom: 10px; }
    .afx-reauth-card h4 { font-weight: 700; margin-bottom: 4px; color: #fff; }
    .afx-reauth-who { color: rgba(255,255,255,.6); font-size: 13.5px; margin-bottom: 4px; }
    .afx-reauth-name { color: #fff; font-weight: 600; margin-bottom: 14px; font-size: 13.5px; }
    .afx-reauth-card input[type=password] { font-size: 18px; text-align: center; padding: 11px; letter-spacing: .1em; }
    .afx-reauth-err { color: #ff7a7a; font-size: 13px; min-height: 19px; margin-top: 9px; }
    .afx-reauth-btn { width: 100%; margin-top: 8px; padding: 13px; border: 0; border-radius: 14px; font-size: 16px; font-weight: 700;
      color: #eaf6ff; background: linear-gradient(135deg, rgba(0,163,255,.34), rgba(90,200,250,.22));
      box-shadow: inset 0 0 0 1px rgba(0,163,255,.5); cursor: pointer; }
    .afx-reauth-btn:hover { filter: brightness(1.15); }
    .afx-reauth-other { display: inline-block; margin-top: 13px; font-size: 12.5px; color: rgba(255,255,255,.5); text-decoration: none; }
    .afx-reauth-other:hover { color: #5fd2ff; }
    </style>
    <?php endif; ?>
    <script>
    window.LANG_NOTICE = '<?php echo __("notice_title"); ?>';
    window.LANG_CONFIRM = '<?php echo __("confirm_title"); ?>';
    window.LANG_PREVIEW = '<?php echo __("preview_btn"); ?>';
    window.LANG_HIGH = '<?php echo __("high"); ?>';
    window.LANG_NORMAL = '<?php echo __("normal"); ?>';
    window.LANG_PRIORITY_LOW = '<?php echo __("priority_low"); ?>';
    window.LANG_PRIORITY_NORMAL = '<?php echo __("priority_normal"); ?>';
    window.LANG_PRIORITY_HIGH = '<?php echo __("priority_high"); ?>';
    window.LANG_SEARCH_CLIENT = '<?php echo __("search_client_placeholder"); ?>';
    window.LANG_BRAND = '<?php echo __("brand"); ?>';
    window.LANG_MODEL = '<?php echo __("model_placeholder"); ?>';
    window.LANG_SCAN_NOT_FOUND = '<?php echo __("scan_not_found"); ?>';
    window.LANG_SCAN_CAMERA_ERROR = '<?php echo __("scan_camera_error"); ?>';
    window.LANG_SCAN_CAMERA_DENIED = '<?php echo __("scan_camera_denied"); ?>';
    </script>
</head>
<body>
<?php require __DIR__ . '/liquid_glass_svg.php'; ?>

<?php
// Iniciály přihlášeného uživatele (avatar v servisním řádku)
$afxFn = trim((string)($_SESSION['full_name'] ?? 'U'));
$afxFp = array_values(array_filter(explode(' ', $afxFn)));
$afxInitials = mb_strtoupper(count($afxFp) >= 2 ? mb_substr($afxFp[0],0,1).mb_substr($afxFp[1],0,1) : mb_substr($afxFn,0,2));
$afxIsManager = hasPermission('admin_access') || in_array(getCurrentStaffRole(), ['manager', 'boss'], true);
?>
<!-- Pozadí: futuristické halftone vlny (tečkové víry, navy/cyan) — GENERUJE se
     canvasem přesně na míru oknu a při každé změně velikosti/měřítka se překreslí,
     takže se nikdy neořezává ani nedeformuje (dřívější SVG slice problém). -->
<canvas class="crm-bgfx" id="crmBgWaves" aria-hidden="true"></canvas>
<script>
(function(){
  var cv = document.getElementById('crmBgWaves');
  if (!cv || !cv.getContext) return;
  function lerp(a,b,t){ return a+(b-a)*t; }
  function dotColor(bright){   // deep blue → azure → cyan
    var r = Math.round(lerp(28, 90, bright*bright));
    var g = Math.round(lerp(60, 205, bright));
    var b = Math.round(lerp(150, 255, bright));
    return [r,g,b];
  }
  function vortex(ctx, cx, cy, R, o){
    for (var i=2;i<o.rings;i++){
      var tR = i/(o.rings-1);
      var r = R * Math.pow(tR, 1.25);
      var n = Math.max(24, Math.round(r*0.85));
      for (var j=0;j<n;j++){
        var th = j/n*6.2832 + i*o.twist;
        var rr = r*(1 + 0.14*Math.sin(3*th + i*0.33 + o.seed) + 0.06*Math.sin(7*th - i*0.21 + o.seed*2));
        var x = cx + Math.cos(th+o.rot)*rr, y = cy + Math.sin(th+o.rot)*rr*o.squash;
        var bright = Math.max(0, 0.5 + 0.5*Math.sin(th + i*0.30 + o.seed*3));
        bright *= (0.35 + 0.65*Math.sin(3.1416*tR));
        if (bright < 0.05) continue;
        var rad = (0.5 + 2.0*bright) * (0.45 + 0.65*tR);
        var c = dotColor(bright);
        var a = o.alpha * (0.10 + 0.80*bright);
        ctx.beginPath(); ctx.arc(x,y,rad,0,6.2832);
        ctx.fillStyle = 'rgba('+c[0]+','+c[1]+','+c[2]+','+a.toFixed(3)+')';
        ctx.fill();
        if (bright > 0.82){
          ctx.beginPath(); ctx.arc(x,y,rad*2.6,0,6.2832);
          ctx.fillStyle = 'rgba('+c[0]+','+c[1]+','+c[2]+','+(a*0.16).toFixed(3)+')';
          ctx.fill();
        }
      }
    }
  }
  function waveBand(ctx, w, h, yBase, o){
    for (var li=0; li<o.lines; li++){
      var tL = li/(o.lines-1);
      var yOff = (tL-0.5)*o.spread;
      for (var x=-10; x<=w+10; x+=7){
        var u = x/w;
        var y = yBase - (u-0.5)*h*0.10 + yOff*(1+0.5*Math.sin(6.2832*u+o.seed))
              + 26*Math.sin(6.2832*(1.35*u)+o.seed+tL*0.9) + 12*Math.sin(6.2832*(3.1*u)-o.seed*2+tL*1.7);
        var bright = Math.max(0, 0.5+0.5*Math.sin(6.2832*u*2.2+tL*2.2+o.seed*4)) * (1-Math.abs(tL-0.5)*1.6);
        if (bright < 0.06) continue;
        var c = dotColor(bright*0.85);
        ctx.beginPath(); ctx.arc(x,y,0.5+1.5*bright,0,6.2832);
        ctx.fillStyle = 'rgba('+c[0]+','+c[1]+','+c[2]+','+(o.alpha*(0.08+0.5*bright)).toFixed(3)+')';
        ctx.fill();
      }
    }
  }
  function sprinkle(ctx, w, h, count, seed){
    var s = seed;
    function rnd(){ s = (s*9301+49297)%233280; return s/233280; }
    for (var i=0;i<count;i++){
      var x = rnd()*w, y = rnd()*h, b = rnd();
      var c = dotColor(b*0.6);
      ctx.beginPath(); ctx.arc(x,y,0.5+b*0.9,0,6.2832);
      ctx.fillStyle = 'rgba('+c[0]+','+c[1]+','+c[2]+','+(0.03+b*0.07).toFixed(3)+')';
      ctx.fill();
    }
  }
  function draw(){
    var dpr = Math.min(window.devicePixelRatio||1,2), w=innerWidth, h=innerHeight;
    cv.width=w*dpr; cv.height=h*dpr; cv.style.width=w+'px'; cv.style.height=h+'px';
    var ctx = cv.getContext('2d'); ctx.setTransform(dpr,0,0,dpr,0,0);
    var g = ctx.createLinearGradient(0,0,w,h);
    g.addColorStop(0,'#04070d'); g.addColorStop(0.45,'#060d1d'); g.addColorStop(1,'#03060b');
    ctx.fillStyle=g; ctx.fillRect(0,0,w,h);
    var halo = ctx.createRadialGradient(w*0.88,h*0.10,0,w*0.88,h*0.10,Math.max(w,h)*0.55);
    halo.addColorStop(0,'rgba(40,120,210,0.10)'); halo.addColorStop(1,'rgba(0,0,0,0)');
    ctx.fillStyle=halo; ctx.fillRect(0,0,w,h);
    var D = Math.max(w,h);
    sprinkle(ctx, w, h, Math.round(w*h/9000), 77);
    vortex(ctx, w*0.86, h*0.10, D*0.50, {rings:42, twist:0.15, seed:1.7, alpha:0.95, squash:0.93, rot:0.4});
    vortex(ctx, w*0.05, h*0.96, D*0.38, {rings:32, twist:-0.13, seed:4.2, alpha:0.62, squash:0.95, rot:2.1});
    waveBand(ctx, w, h, h*0.84, {lines:16, spread:h*0.15, alpha:0.5, seed:2.6});
  }
  var tm; window.addEventListener('resize', function(){ clearTimeout(tm); tm=setTimeout(draw,120); });
  draw();
})();
</script>

<!-- Tenký servisní řádek -->
<header class="afx-utility">
    <a class="afx-brand" href="index.php">
        <img src="assets/img/logo-black.png" alt="AppleFix">
    </a>
    <span class="afx-page-title"><?php echo e($topbarTitle); ?></span>
    <span class="afx-utility-sep"></span>

    <?php if ($show_search): ?>
    <form action="<?php echo $search_action; ?>" method="GET" class="afx-search crm-navbar-search">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
            <span class="input-group-text crm-kbd-hint">⌘K</span>
        </div>
    </form>
    <?php endif; ?>

    <button class="btn btn-sm crm-v2-icon-btn" type="button" id="scanOrderBtn" title="<?php echo e(__('scan_order_title')); ?>" aria-label="<?php echo e(__('scan_order_title')); ?>">
        <i class="fas fa-qrcode"></i>
    </button>
    <button class="btn btn-sm crm-v2-icon-btn" type="button" id="notificationsToggle" aria-label="<?php echo e(__('notifications')); ?>">
        <i class="fas fa-bell"></i>
        <span class="crm-v2-alert-dot"></span>
    </button>
    <a class="btn btn-sm crm-v2-icon-btn" href="sign_station.php" title="<?php echo e(__('sign_station_link')); ?>" aria-label="<?php echo e(__('sign_station_link')); ?>"><i class="fas fa-pen-nib"></i></a>
    <button class="btn btn-sm crm-v2-icon-btn lg-theme-toggle afx-hide-m" type="button" title="<?php echo e(__('theme_toggle')); ?>" aria-label="<?php echo e(__('theme_toggle')); ?>">
        <i class="fas fa-sun"></i>
    </button>
    <div class="dropdown afx-hide-m">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle crm-lang-switch" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(__('language_switch')); ?>">
            <i class="fas fa-language me-1"></i><?php echo strtoupper($currentLang); ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item <?php echo $currentLang === 'cs' ? 'active' : ''; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_cs'); ?> (CS)</a></li>
            <li><a class="dropdown-item <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_en'); ?> (EN)</a></li>
            <li><a class="dropdown-item <?php echo $currentLang === 'ru' ? 'active' : ''; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_ru'); ?> (RU)</a></li>
        </ul>
    </div>
    <a href="logout.php" class="btn btn-outline-danger btn-sm afx-logout afx-hide-m" title="<?php echo __('logout'); ?>"><i class="fas fa-sign-out-alt"></i></a>
    <span class="afx-user" title="<?php echo e($afxFn); ?> · <?php echo e($roleLabel ?: __('role_employee')); ?>">
        <span class="afx-avatar"><?php echo e($afxInitials); ?></span>
        <b><?php echo e($afxFp[0] ?? $afxFn); ?></b>
    </span>
</header>

<!-- Plovoucí dok z buněk (Liquid Glass) -->
<div class="afx-dockwrap">
    <nav class="afx-dock" id="afxDock" data-afx-glass="dock">
        <a class="afx-cell <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-home"></i><small><?php echo __('dashboard'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
            <?php if ($ordersBadgeCount > 0): ?><span class="afx-badge"><?php echo $ordersBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-tools"></i><small><?php echo __('orders'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php">
            <?php if ($complaintsBadgeCount > 0): ?><span class="afx-badge afx-badge--warn"><?php echo $complaintsBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-rotate-left"></i><small><?php echo __('complaints'); ?></small>
        </a>
        <?php /* Pořadí: Klienti PŘED Nákupy (prohozeno 16.7.2026) */ ?>
        <?php if (hasPermission('edit_customers')): ?>
        <a class="afx-cell <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
            <i class="fas fa-users"></i><small><?php echo __('customers'); ?></small>
        </a>
        <?php endif; ?>
        <?php /* Logické skupiny (17.7.2026): zboží (Sklad→Nákupy→Pokladna) → peníze
                 (Účetnictví = Faktury|Banka) → analýza (Přehledy) → ostatní */ ?>
        <?php if (hasPermission('manage_inventory')): ?>
        <a class="afx-cell <?php echo in_array($current_page, ['inventory.php', 'products.php'], true) ? 'active' : ''; ?>" href="inventory.php">
            <i class="fas fa-boxes"></i><small><?php echo __('inventory'); ?></small>
        </a>
        <?php endif; ?>
        <a class="afx-cell <?php echo $current_page == 'procurement.php' ? 'active' : ''; ?>" href="procurement.php">
            <?php if ($procurementBadgeCount > 0): ?><span class="afx-badge"><?php echo $procurementBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-truck-loading"></i><small><?php echo __('procurement'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'pokladna.php' ? 'active' : ''; ?>" href="pokladna.php">
            <i class="fas fa-cash-register"></i><small>Pokladna</small>
        </a>
        <?php if ($afxIsManager): ?>
        <a class="afx-cell <?php echo in_array($current_page, ['accounting.php', 'banka.php'], true) ? 'active' : ''; ?>" href="accounting.php">
            <i class="fas fa-file-invoice-dollar"></i><small><?php echo __('accounting'); ?></small>
        </a>
        <?php endif; ?>
        <a class="afx-cell <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
            <i class="fas fa-chart-line"></i><small><?php echo __('reports'); ?></small>
        </a>
        <?php /* Chat vidí VŠICHNI zaměstnanci (dřív omylem jen vedení) */ ?>
        <a class="afx-cell <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>" href="chat.php">
            <i class="fas fa-comments"></i><small>Chat</small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'navody.php' ? 'active' : ''; ?>" href="navody.php">
            <i class="fas fa-graduation-cap"></i><small>Návody</small>
        </a>
        <?php /* Historie: všichni zaměstnanci kromě techniků vedlejších poboček */ ?>
        <?php if (crmCanViewHistory()): ?>
        <a class="afx-cell <?php echo $current_page == 'history.php' ? 'active' : ''; ?>" href="history.php">
            <i class="fas fa-clock-rotate-left"></i><small>Historie</small>
        </a>
        <?php endif; ?>
        <a class="afx-cell <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="fas <?php echo $afxIsManager ? 'fa-cog' : 'fa-user-circle'; ?>"></i><small><?php echo __('settings'); ?></small>
        </a>
        <span class="afx-div"></span>
        <a class="afx-cell act-blue" href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal">
            <i class="fas fa-plus"></i><small><?php echo __('new_order'); ?></small>
        </a>
        <a class="afx-cell act-orange" href="#" data-bs-toggle="modal" data-bs-target="#newComplaintModal">
            <i class="fas fa-rotate-left"></i><small><?php echo __('complaints'); ?></small>
        </a>
        <a class="afx-cell act-green <?php echo $current_page == 'nakupni-seznam.php' ? 'active' : ''; ?>" href="nakupni-seznam.php">
            <?php if (!empty($shoppingListBadgeCount) && $shoppingListBadgeCount > 0): ?><span class="afx-badge"><?php echo (int)$shoppingListBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-cart-shopping"></i><small><?php echo __('shopping_list'); ?></small>
        </a>
        <a class="afx-cell act-purple <?php echo $current_page == 'pokladna.php' ? 'active' : ''; ?>" href="pokladna.php">
            <i class="fas fa-cash-register"></i><small>Pokladna</small>
        </a>
    </nav>
</div>

<!-- Mobil/tablet: spodní tab bar -->
<nav class="afx-tabbar" aria-label="<?php echo e(__('mobile_navigation')); ?>">
    <a class="afx-tb <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home"></i><?php echo __('dashboard'); ?></a>
    <a class="afx-tb <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
        <?php if ($ordersBadgeCount > 0): ?><span class="afx-badge"><?php echo $ordersBadgeCount; ?></span><?php endif; ?>
        <i class="fas fa-tools"></i><?php echo __('orders'); ?>
    </a>
    <button class="afx-tb-plus" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal" aria-label="<?php echo __('new_order'); ?>"><i class="fas fa-plus"></i></button>
    <a class="afx-tb <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
    <button class="afx-tb" type="button" id="afxSheetOpen"><i class="fas fa-bars"></i><?php echo __('menu'); ?></button>
</nav>

<!-- Mobil/tablet: sheet menu s hledáním -->
<div class="afx-sheet" id="afxSheet" aria-hidden="true">
    <div class="afx-scrim" id="afxSheetScrim"></div>
    <div class="afx-panel">
        <div class="afx-grab"></div>
        <?php if ($show_search): ?>
        <form action="<?php echo $search_action; ?>" method="GET" class="afx-sheet-search">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
            </div>
        </form>
        <?php endif; ?>
        <div class="afx-sheet-acts">
            <a class="afx-sheet-act b" href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus"></i><?php echo __('new_order'); ?></a>
            <a class="afx-sheet-act o" href="#" data-bs-toggle="modal" data-bs-target="#newComplaintModal"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
            <a class="afx-sheet-act" href="sign_station.php"><i class="fas fa-pen-nib"></i><?php echo __('sign_station_link'); ?></a>
            <a class="afx-sheet-act g <?php echo $current_page == 'nakupni-seznam.php' ? 'active' : ''; ?>" href="nakupni-seznam.php">
                <?php if (!empty($shoppingListBadgeCount) && $shoppingListBadgeCount > 0): ?><span class="afx-badge"><?php echo (int)$shoppingListBadgeCount; ?></span><?php endif; ?>
                <i class="fas fa-cart-shopping"></i><?php echo __('shopping_list'); ?>
            </a>
            <a class="afx-sheet-act p <?php echo $current_page == 'pokladna.php' ? 'active' : ''; ?>" href="pokladna.php"><i class="fas fa-cash-register"></i>Pokladna</a>
        </div>
        <div class="afx-sheet-grid">
            <a class="afx-sheet-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home"></i><?php echo __('dashboard'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php"><i class="fas fa-tools"></i><?php echo __('orders'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
            <?php if (hasPermission('edit_customers')): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users"></i><?php echo __('customers'); ?></a>
            <?php endif; ?>
            <?php if (hasPermission('manage_inventory')): ?>
            <a class="afx-sheet-link <?php echo in_array($current_page, ['inventory.php', 'products.php'], true) ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes"></i><?php echo __('inventory'); ?></a>
            <?php endif; ?>
            <a class="afx-sheet-link <?php echo $current_page == 'procurement.php' ? 'active' : ''; ?>" href="procurement.php"><i class="fas fa-truck-loading"></i><?php echo __('procurement'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'pokladna.php' ? 'active' : ''; ?>" href="pokladna.php"><i class="fas fa-cash-register"></i>Pokladna</a>
            <?php if ($afxIsManager): ?>
            <a class="afx-sheet-link <?php echo in_array($current_page, ['accounting.php', 'banka.php'], true) ? 'active' : ''; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar"></i><?php echo __('accounting'); ?></a>
            <?php endif; ?>
            <a class="afx-sheet-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-line"></i><?php echo __('reports'); ?></a>
            <?php /* Chat vidí VŠICHNI zaměstnanci (dřív omylem jen vedení) */ ?>
            <a class="afx-sheet-link <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>" href="chat.php"><i class="fas fa-comments"></i>Chat</a>
            <a class="afx-sheet-link <?php echo $current_page == 'navody.php' ? 'active' : ''; ?>" href="navody.php"><i class="fas fa-graduation-cap"></i>Návody</a>
            <?php if (crmCanViewHistory()): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'history.php' ? 'active' : ''; ?>" href="history.php"><i class="fas fa-clock-rotate-left"></i>Historie</a>
            <?php endif; ?>
            <a class="afx-sheet-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas <?php echo $afxIsManager ? 'fa-cog' : 'fa-user-circle'; ?>"></i><?php echo __('settings'); ?></a>
        </div>
        <div class="afx-sheet-user">
            <span class="afx-avatar"><?php echo e($afxInitials); ?></span>
            <span><?php echo e($afxFn); ?><small><?php echo e($roleLabel ?: __('role_employee')); ?></small></span>
        </div>
        <div class="afx-sheet-foot">
            <button class="btn btn-outline-secondary btn-sm lg-theme-toggle" type="button"><i class="fas fa-sun me-1"></i><?php echo __('theme'); ?></button>
            <span class="afx-sheet-langs">
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'cs' ? 'active' : ''; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">CS</a>
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">EN</a>
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'ru' ? 'active' : ''; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">RU</a>
            </span>
            <a class="btn btn-outline-danger btn-sm" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i><?php echo __('logout'); ?></a>
        </div>
    </div>
</div>
<script>
(function () {
    var sheet = document.getElementById('afxSheet');
    var openBtn = document.getElementById('afxSheetOpen');
    var scrim = document.getElementById('afxSheetScrim');
    if (!sheet || !openBtn) return;
    function close() { document.body.classList.remove('afx-sheet-open'); sheet.setAttribute('aria-hidden', 'true'); }
    openBtn.addEventListener('click', function () {
        document.body.classList.add('afx-sheet-open');
        sheet.setAttribute('aria-hidden', 'false');
    });
    if (scrim) scrim.addEventListener('click', close);
    sheet.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('a') : null;
        if (t) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>

<div id="content" class="crm-v2-content">
    <div class="crm-main-content">
