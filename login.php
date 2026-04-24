<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'klient/includes/auth.php';

$error = false;

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

            // 1) Staff / admin login
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                clearClientSession();
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = 'admin';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['tech_id']   = null;
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                header('Location: index.php');
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM technicians WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $tech = $stmt->fetch();

            if ($tech && password_verify($password, $tech['password'])) {
                session_regenerate_id(true);
                clearClientSession();
                $_SESSION['user_id']   = 't' . $tech['id'];
                $_SESSION['username']  = $tech['username'];
                $_SESSION['role']      = (($tech['role'] ?? 'engineer') === 'admin') ? 'admin' : 'technician';
                $_SESSION['full_name'] = $tech['name'];
                $_SESSION['tech_id']   = $tech['id'];
                if ($_SESSION['role'] === 'technician') {
                    $_SESSION['internal_role'] = $tech['role'] ?? 'engineer';
                }
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                header('Location: index.php');
                exit;
            }

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
                recordLoginAttempt($pdo, true);
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
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__('login_title')); ?> - Repair CRM</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
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
<?php $currentLang = crm_get_language(); ?>
<div class="login-lang-switcher d-flex gap-1" title="<?php echo e(__('language_switch')); ?>">
    <a class="btn btn-sm <?php echo $currentLang === 'cs' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">CS</a>
    <a class="btn btn-sm <?php echo $currentLang === 'en' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">EN</a>
    <a class="btn btn-sm <?php echo $currentLang === 'ru' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'login.php'); ?>">RU</a>
</div>

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
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('username_label'); ?></label>
                        <input type="text" name="username" class="form-control" required autofocus autocomplete="username" placeholder="<?php echo e(__('login_username_placeholder')); ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?php echo __('password'); ?></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="<?php echo e(__('login_password_placeholder')); ?>">
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

</body>
</html>
