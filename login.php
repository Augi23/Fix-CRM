<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'klient/includes/auth.php';

$error = false;


// Návrat po přihlášení (sken skladového QR odhlášeným zařízením) — POVOLEN jen
// cíl sklad.php?... (žádné jiné/absolutní URL, žádný open redirect).
function loginSafeRedirectTarget(): string {
    $r = (string)($_REQUEST['redirect'] ?? '');
    if ($r !== '' && preg_match('~^sklad\.php(\?[A-Za-z0-9_=&%.-]*)?$~', $r)) {
        return $r;
    }
    return 'index.php';
}

function checkLoginAttempts($pdo) {
    if (!isset($pdo)) return true;
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() < 5;
    } catch (Exception $e) {
        return true;
    }
}

function recordLoginAttempt($pdo, $success) {
    if (!isset($pdo)) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ($success) {
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())")->execute([$ip]);
        }
    } catch (Exception $e) {
        // login_attempts table may not exist yet - ignore
    }
}

function clearStaffSession(): void {
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['full_name'],
        $_SESSION['tech_id'],
        $_SESSION['internal_role']
    );
}

function clearClientSession(): void {
    unset(
        $_SESSION['client_authenticated'],
        $_SESSION['client_customer_id'],
        $_SESSION['client_order_id'],
        $_SESSION['client_full_name'],
        $_SESSION['client_company'],
        $_SESSION['client_last_login']
    );
}

$loginQuips = [
    'Baterka v iPhonu, co drží. MacBook, co zase chladí. Servis, co neotravuje.',
    'Když Apple zavolá o pomoc, jsme první na lince.',
    'Šroubky, displeje a čistý macOS - přesně naše parketa.',
    'Rychlá oprava. Čistý stůl. Jablečný klid.',
    'Od iPhonu po MacBook: oprava s hlavou, ne s chaosem.',
    'Jablečný servis bez hluku, jen s výsledkem.',
    'Když praskne displej, přichází klid z AppleFix.',
    'Kde jiní vidí problém, my vidíme nový displej a hotovo.',
    'iPhone zpátky v kondici, MacBook zpátky v tempu.',
    'Žádný servisní drama - jen precizní Apple oprava.',
    'Vyměníme rozbitý chaos za funkční jablečný pořádek.',
    'Displeje, baterie, klávesnice - a potom zase klid.',
    'Když se Apple ozve, my už máme nářadí v ruce.',
    'MacBook bez hluku, iPhone bez prasklin, zákazník bez starostí.',
    'Malá závada, velká pozornost - přesně náš styl.',
    'Jablečný servis, který se neztratí v detailu.',
    'Neopravujeme jen zařízení. Vracíme mu druhý dech.',
    'Od nalomeného displeje k hotové zakázce během chvíle.',
    'Čistá oprava. Čistý výsledek. Čisté AppleFix.',
    'Servis, co rozumí iPhonu, MacBooku i času zákazníka.',
];
if (crm_get_language() === 'en') {
    $loginQuips = [
        'Battery that lasts. MacBook that cools. Service that just works.',
        'Fast repair, clean desk, and zero drama.',
        'From iPhone to MacBook, repairs done with care.',
        'When Apple calls for help, we are ready.',
        'No service chaos, only precise results.',
    ];
}
$loginQuip = $loginQuips[array_rand($loginQuips)];

if (isset($_POST['login'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!checkLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = __('login_fill_user_pass');
        } elseif (isset($pdo)) {
            $loginSucceeded = false;
            // Na klientské doméně (applefix.help) se zaměstnanci NEpřihlásí —
            // zaměstnanecké větve se přeskočí, funguje jen klientský lookup níže.
            $allowStaffLogin = !crmIsClientDomain();

            // 1) Staff / admin login
            if ($allowStaffLogin) {
            ensureUsersBranchColumn();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // dočasná blokace PER ÚČET po 10 špatných heslech na kase — platí
            // pro odemčení i login té konkrétní osoby, ostatní se přihlásí normálně
            if ($user && ($__posBlk = crmPosUnlockBlockRemaining('u' . (int)$user['id'])) > 0) {
                $__msg = 'Účet je dočasně zablokovaný (' . (int)ceil($__posBlk / 60) . ' min) po opakovaně špatném heslu na kase.';
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => $__msg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $error = $__msg;
                $user = false;   // nepustit ani ke kontrole hesla
            }

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                clearClientSession();
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = 'admin';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['tech_id']   = null;
                // Admin má globální výhled (řídí se rolí), ale výchozí pobočku Karlín —
                // aby se všude zobrazovala a předvybírala. Viz ensureUsersBranchColumn().
                $_SESSION['branch_id'] = (int)($user['branch_id'] ?? 0) ?: getDefaultBranchId();
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                crmAuditLog('auth.login', ['entity_type' => 'auth', 'summary' => 'Přihlášení do systému (administrátor)']);
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'redirect' => loginSafeRedirectTarget(),
                        'greeting' => loginGreetingUrl((string)$user['username']),
                        'name' => (string)($user['full_name'] ?: $user['username'])], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                header('Location: ' . loginSafeRedirectTarget());
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM technicians WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $tech = $stmt->fetch();

            if ($tech && ($__posBlkT = crmPosUnlockBlockRemaining('t' . (int)$tech['id'])) > 0) {
                $__msg = 'Účet je dočasně zablokovaný (' . (int)ceil($__posBlkT / 60) . ' min) po opakovaně špatném heslu na kase.';
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => $__msg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $error = $__msg;
                $tech = false;
            }

            if ($tech && password_verify($password, $tech['password'])) {
                session_regenerate_id(true);
                clearClientSession();
                $_SESSION['user_id']   = 't' . $tech['id'];
                $_SESSION['username']  = $tech['username'];
                $_SESSION['role']      = (($tech['role'] ?? 'engineer') === 'admin') ? 'admin' : 'technician';
                $_SESSION['full_name'] = $tech['name'];
                $_SESSION['tech_id']   = $tech['id'];
                $_SESSION['branch_id'] = $tech['branch_id'] ?? null;
                if ($_SESSION['role'] === 'technician') {
                    $_SESSION['internal_role'] = $tech['role'] ?? 'engineer';
                }
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                crmAuditLog('auth.login', ['entity_type' => 'auth', 'summary' => 'Přihlášení do systému']);
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'redirect' => loginSafeRedirectTarget(),
                        'greeting' => loginGreetingUrl((string)$tech['username']),
                        'name' => (string)($tech['name'] ?: $tech['username'])], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                header('Location: ' . loginSafeRedirectTarget());
                exit;
            }
            } // konec zaměstnaneckých větví (jen mimo klientskou doménu)

            // 2) Client login - same portal, different dashboard
            $lookup = clientLookupCustomerAndOrders($pdo, $username);
            $customer = $lookup['customer'];
            $orders = $lookup['orders'];
            $matchedOrder = null;

            foreach ($orders as $order) {
                if (hash_equals(trim((string)($order['pin_code'] ?? '')), $password)) {
                    $matchedOrder = $order;
                    break;
                }
            }

            if ($customer && $matchedOrder) {
                session_regenerate_id(true);
                clearStaffSession();
                $_SESSION['client_authenticated'] = true;
                $_SESSION['client_customer_id'] = (int)$customer['id'];
                $_SESSION['client_order_id'] = (int)$matchedOrder['id'];
                $_SESSION['client_full_name'] = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                $_SESSION['client_company'] = $customer['company'] ?? '';
                $_SESSION['client_last_login'] = time();
                // Klientský portál poběží v jazyce klienta (customers.preferred_language:
                // cs/en, uk→en přes crmCustomerDocLang). Ukládáme do klientského klíče,
                // který NEovlivní zaměstnanecké UI ($_SESSION['lang'] / cookie neměníme).
                $_SESSION['client_lang'] = crmCustomerDocLang($customer['preferred_language'] ?? 'cs');
                recordLoginAttempt($pdo, true);
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'redirect' => 'klient/dashboard.php', 'greeting' => null, 'name' => ''], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                header('Location: klient/dashboard.php');
                exit;
            }

            recordLoginAttempt($pdo, false);
            $error = __('login_invalid_credentials');
        } else {
            $error = __('login_error_db');
        }
    }
}

if (clientIsLoggedIn()) {
    header('Location: klient/dashboard.php');
    exit;
}

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<?php
if (!empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => (string)($error ?: __('login_invalid_credentials'))], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__('login_title')); ?> - Repair CRM</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0d1512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fix-CRM">
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }</script>
    <script>(function(){try{var t=localStorage.getItem('lg-theme')||'dark';document.documentElement.setAttribute('data-lg-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/login.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/login.css'); ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/liquid-glass.css'); ?>">
    <script src="assets/js/liquid-glass.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/liquid-glass.js'); ?>" defer></script>
    <style>
        .login-copy {
            margin-top: 18px;
            color: rgba(243, 247, 255, 0.76);
            font-size: 0.98rem;
            line-height: 1.6;
        }

        .login-note {
            margin-top: 18px;
            color: rgba(243, 247, 255, 0.7);
            font-size: 0.92rem;
            line-height: 1.55;
        }

        .login-section-note {
            margin-bottom: 18px;
            color: rgba(243, 247, 255, 0.74);
            font-size: 0.93rem;
            line-height: 1.5;
        }

        .login-lang-switcher {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 50;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/liquid_glass_svg.php'; ?>
<?php $currentLang = crm_get_language(); ?>
<div class="login-lang-switcher d-flex gap-1" title="<?php echo e(__('language_switch')); ?>">
    <button type="button" class="btn btn-sm btn-outline-light lg-theme-toggle" title="Light / Dark" aria-label="Light / Dark"><i class="fas fa-sun"></i></button>
    <a class="btn btn-sm <?php echo $currentLang === 'cs' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">CS</a>
    <a class="btn btn-sm <?php echo $currentLang === 'en' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">EN</a>
    <a class="btn btn-sm <?php echo $currentLang === 'ru' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">RU</a>
</div>

<?php $isClientDom = crmIsClientDomain(); ?>
<div class="login-page">
    <div class="login-scene">
        <section class="login-hero">
            <div class="login-brandline">
                <img src="assets/img/applefix-logo.png" alt="AppleFix logo" class="login-hero-logo">
            </div>

            <h1 class="login-headline"><?php echo __('login_headline'); ?></h1>

            <p class="login-copy"><?php echo e($loginQuip); ?></p>

            <div class="login-points">
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-magnifying-glass"></i></span>
                    <span><?php echo __('login_point_staff_client'); ?></span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-layer-group"></i></span>
                    <span><?php echo __('login_point_dashboard'); ?></span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-window-restore"></i></span>
                    <span><?php echo __('login_point_scope'); ?></span>
                </div>
            </div>
        </section>

        <section class="login-panel glass-card shadow-lg">
            <div class="login-panel-inner">
                <div class="login-panel-head">
                    <div>
                        <span class="login-panel-kicker"><?php echo __('login_panel_secure_access'); ?></span>
                        <h2><?php echo __('login_title'); ?></h2>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger small mb-4"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="POST" class="login-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="login" value="1">
                    <?php /* návrat na sklad po skenu QR odhlášeným zařízením (sanitizováno) */ ?>
                    <?php $__lr = loginSafeRedirectTarget(); if ($__lr !== 'index.php'): ?>
                        <input type="hidden" name="redirect" value="<?php echo e($__lr); ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $isClientDom ? e(__('cl_login_email_or_phone')) : __('username_label'); ?></label>
                        <input type="text" name="username" class="form-control" required autofocus autocomplete="username" placeholder="<?php echo $isClientDom ? e(__('cl_login_email_or_phone_ph')) : e(__('login_username_placeholder')); ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?php echo $isClientDom ? e(__('cl_login_pin')) : __('password'); ?></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="<?php echo $isClientDom ? e(__('cl_login_pin_ph')) : e(__('login_password_placeholder')); ?>">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary"><?php echo __('login_btn'); ?></button>
                    </div>
                </form>

                <div class="login-section-note mt-3">
                    <?php echo __('login_shared_form_note'); ?>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="greetOverlay" style="display:none; position:fixed; inset:0; z-index:99999; background:#000;">
    <canvas id="greetWave" role="status" aria-label="Přihlašování" style="position:absolute; inset:0; width:100%; height:100%; display:block;"></canvas>
</div>
<script>
/* Uvítací loading „Xiaomi boost": pole jantarových teček přes CELOU obrazovku,
   široká zlato-oranžová vlna projede ze středu k okrajům, pak černá pauza
   stejně dlouhá jako vlna a další puls. Opakuje se, dokud neproběhne
   přihlášení (redirect do dashboardu overlay ukončí). Responzivní, střed
   efektu = střed obrazovky. Respektuje prefers-reduced-motion. */
var afxGreetWave = (function () {
    var cv = null, ctx = null, raf = null, t0 = 0, W = 0, H = 0, dpr = 1;
    var TRAVEL = 3.0, PAUSE = 0.5, CYCLE = TRAVEL + PAUSE;   // krátká pauza, ať stihne víc pulzů
    var STOPS = [
        [0.00, [ 12,   5,   0]],
        [0.30, [140,  58,   8]],
        [0.58, [235, 122,  20]],
        [0.82, [255, 176,  52]],
        [1.00, [255, 208, 104]]
    ];
    function ramp(b) {
        b = b < 0 ? 0 : b > 1 ? 1 : b;
        for (var i = 1; i < STOPS.length; i++) {
            if (b <= STOPS[i][0]) {
                var a = STOPS[i - 1], c = STOPS[i];
                var f = (b - a[0]) / ((c[0] - a[0]) || 1);
                return [
                    (a[1][0] + (c[1][0] - a[1][0]) * f) | 0,
                    (a[1][1] + (c[1][1] - a[1][1]) * f) | 0,
                    (a[1][2] + (c[1][2] - a[1][2]) * f) | 0
                ];
            }
        }
        return STOPS[STOPS.length - 1][1];
    }
    function smooth(a, b, x) { var t = (x - a) / (b - a); if (t < 0) t = 0; if (t > 1) t = 1; return t * t * (3 - 2 * t); }
    function resize() {
        if (!cv) return;
        dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
        var r = cv.getBoundingClientRect();
        W = Math.round(r.width); H = Math.round(r.height);
        cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function draw(t) {
        var tc = t % CYCLE;
        var p = tc / TRAVEL;                       // p > 1 → černá pauza
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, W, H);
        if (p > 1) return;
        var pe = (p < 0.5) ? (2 * p * p) : (1 - Math.pow(-2 * p + 2, 2) / 2);  // easeInOutQuad — plynulý nástup
        var fade = smooth(0, 0.10, p) * (1 - smooth(0.92, 1, p));              // měkký začátek i konec pulsu
        if (fade <= 0) return;
        var cx = W / 2, cy = H / 2;
        var M = Math.min(W, H);
        var GAP = Math.max(12, M / 30);            // rozteč mřížky (responzivní)
        var rHole = M * 0.10;                      // tmavý střed
        var Rmax = Math.sqrt(cx * cx + cy * cy);   // až do rohů obrazovky
        var wF = M * 0.055, wT = M * 0.24;         // ostré čelo + široký zářící ohon
        var front = pe * (Rmax + wT * 2.2);        // vlna celá odjede za okraj
        for (var y = GAP / 2; y < H + GAP / 2; y += GAP) {
            for (var x = GAP / 2; x < W + GAP / 2; x += GAP) {
                var dx = x - cx, dy = y - cy;
                var r = Math.sqrt(dx * dx + dy * dy);
                var d = r - front;
                var b = (d > 0)
                    ? Math.exp(-(d * d) / (2 * wF * wF))    // před vlnou: černá
                    : Math.exp(-(d * d) / (2 * wT * wT));   // za vlnou: dohasne do černé
                b *= smooth(rHole * 0.8, rHole * 1.7, r) * fade;
                if (b < 0.025) continue;                    // mimo vlnu se nekreslí nic
                if (b > 1) b = 1;
                var col = ramp(b);
                var rad = 0.8 + (GAP * 0.40) * b;           // tečka roste s jasem vlny
                ctx.globalAlpha = Math.min(1, 0.10 + 0.92 * b);
                ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
                ctx.beginPath();
                ctx.arc(x, y, rad, 0, 6.2832);
                ctx.fill();
            }
        }
        ctx.globalAlpha = 1;
    }
    function frame(now) {
        if (!t0) t0 = now;
        draw((now - t0) / 1000);
        raf = requestAnimationFrame(frame);        // pulzuje, dokud stránka nepřejde do CRM
    }
    function start() {
        cv = document.getElementById('greetWave');
        if (!cv || !cv.getContext) return;
        ctx = cv.getContext('2d');
        resize();
        window.addEventListener('resize', resize);
        window.addEventListener('orientationchange', resize);
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            draw(TRAVEL * 0.55);                   // statický snímek vlny
            return;
        }
        t0 = 0;
        raf = requestAnimationFrame(frame);
    }
    return { start: start };
})();
</script>
<script>
(function () {
    var form = document.querySelector('.login-form');
    if (!form || !window.fetch) { return; }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        var fd = new FormData(form);
        fd.append('ajax', '1');
        fetch('login.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) {
                    btn.disabled = false;
                    var al = document.getElementById('loginAjaxError');
                    if (!al) {
                        al = document.createElement('div');
                        al.id = 'loginAjaxError';
                        al.className = 'alert alert-danger';
                        form.parentNode.insertBefore(al, form);
                    }
                    al.textContent = d.error || 'Přihlášení selhalo';
                    return;
                }
                var go = function () { window.location.href = d.redirect; };
                if (d.greeting) {
                    var ov = document.getElementById('greetOverlay');
                    ov.style.display = 'block';
                    if (window.afxGreetWave) { afxGreetWave.start(); }
                    var done = false;
                    var finish = function () { if (!done) { done = true; go(); } };
                    try {
                        var audio = new Audio(d.greeting);
                        audio.addEventListener('ended', finish);
                        audio.addEventListener('error', finish);
                        audio.play().then(function () { setTimeout(finish, 8000); }).catch(finish);
                    } catch (err) { finish(); }
                } else {
                    go();
                }
            })
            .catch(function () { form.removeEventListener('submit', arguments.callee); form.submit(); });
    });
})();
</script>
</body>
</html>
