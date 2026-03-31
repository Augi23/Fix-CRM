<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'klient/includes/auth.php';

$error = false;

// ── Rate limiting ─────────────────────────────────────────────────────────────
function checkLoginAttempts($pdo) {
    if (!isset($pdo)) return true; // if DB down, allow (handled below)
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
        // login_attempts table may not exist yet — ignore
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

// ── Login form handler ────────────────────────────────────────────────────────
$disabledDemoAdminUsername = 'admin';
$disabledDemoAdminPasswordHash = '$2y$10$qafwiLAk9Osoxr.4UX/YCuO6m6TejA377VwyxMP1zakKWOIdV89Ay';

if (isset($_POST['login_employee'])) {
    // CSRF validation
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!checkLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (isset($pdo)) {
            // 1. Try Admin (users table)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $isDisabledDemoAdmin =
                    ($user['username'] === $disabledDemoAdminUsername)
                    && hash_equals($disabledDemoAdminPasswordHash, (string)$user['password']);

                if ($isDisabledDemoAdmin) {
                    recordLoginAttempt($pdo, false);
                    $error = __('login_error_auth');
                } else {
                    session_regenerate_id(true); // Session Fixation protection
                    clearClientSession();
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = 'admin';
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['tech_id']   = null;
                    invalidatePermissionsCache();
                    recordLoginAttempt($pdo, true);
                    header("Location: index.php");
                    exit;
                }
            }

            // 2. Try Technician (technicians table)
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
                header("Location: index.php");
                exit;
            }

            recordLoginAttempt($pdo, false);
            $error = __('login_error_auth');
        } else {
            $error = __('login_error_db');
        }
    }
} elseif (isset($_POST['login_client'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!checkLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $loginIdentifier = trim((string)($_POST['login_identifier'] ?? ''));
        $pinCode = trim((string)($_POST['pin_code'] ?? ''));

        if ($loginIdentifier === '' || $pinCode === '') {
            $error = 'Vyplň telefon, e-mail nebo číslo zakázky a PIN kód.';
        } elseif (isset($pdo)) {
            try {
                $lookup = clientLookupCustomerAndOrders($pdo, $loginIdentifier);
                $customer = $lookup['customer'];
                $orders = $lookup['orders'];
                $matchedOrder = null;

                foreach ($orders as $order) {
                    if (hash_equals(trim((string)($order['pin_code'] ?? '')), $pinCode)) {
                        $matchedOrder = $order;
                        break;
                    }
                }

                if ($matchedOrder && $customer) {
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
                $error = 'Neplatný telefon, e-mail, číslo zakázky nebo PIN kód.';
            } catch (Exception $e) {
                recordLoginAttempt($pdo, false);
                $error = 'Přihlášení se nezdařilo. Zkus to prosím znovu.';
            }
        } else {
            $error = __('login_error_db');
        }
    }
}

// Redirect if already logged in
if (clientIsLoggedIn()) {
    header('Location: klient/dashboard.php');
    exit;
}
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$loginQuips = [
    'Baterka v iPhonu, co drží. MacBook, co zase chladí. Servis, co neotravuje.',
    'Když Apple zavolá o pomoc, jsme první na lince.',
    'Šroubky, displeje a čistý macOS — přesně naše parketa.',
    'Rychlá oprava. Čistý stůl. Jablečný klid.',
    'Od iPhonu po MacBook: oprava s hlavou, ne s chaosem.',
    'Jablečný servis bez hluku, jen s výsledkem.',
    'Když praskne displej, přichází klid z AppleFix.',
    'Kde jiní vidí problém, my vidíme nový displej a hotovo.',
    'iPhone zpátky v kondici, MacBook zpátky v tempu.',
    'Žádný servisní drama — jen precizní Apple oprava.',
    'Vyměníme rozbitý chaos za funkční jablečný pořádek.',
    'Displeje, baterie, klávesnice — a potom zase klid.',
    'Když se Apple ozve, my už máme nářadí v ruce.',
    'MacBook bez hluku, iPhone bez prasklin, zákazník bez starostí.',
    'Malá závada, velká pozornost — přesně náš styl.',
    'Jablečný servis, který se neztratí v detailu.',
    'Neopravujeme jen zařízení. Vracíme mu druhý dech.',
    'Od nalomeného displeje k hotové zakázce během chvíle.',
    'Čistá oprava. Čistý výsledek. Čisté AppleFix.',
    'Servis, co rozumí iPhonu, MacBooku i času zákazníka.',
];
$loginQuip = $loginQuips[array_rand($loginQuips)];
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['lang'] ?? 'ru'); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__('login_title')); ?> - Repair CRM</title>
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <style>
        .login-section + .login-section {
            margin-top: 22px;
            padding-top: 22px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .login-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            font-size: 0.9rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(240, 245, 255, 0.82);
        }

        .login-section-note {
            margin-bottom: 14px;
            color: rgba(243, 247, 255, 0.72);
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .login-separator {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 20px 0 18px;
            color: rgba(243, 247, 255, 0.6);
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .login-separator::before,
        .login-separator::after {
            content: '';
            height: 1px;
            flex: 1;
            background: rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body>

<div class="login-page">
    <div class="login-scene">
        <section class="login-hero">
            <div class="login-brandline">
                <img src="assets/img/applefix-logo.png" alt="AppleFix logo" class="login-hero-logo">
            </div>

            <h1 class="login-headline">...servis<br>se vším všudy.</h1>

            <p class="login-copy">
                <?php echo e($loginQuip); ?>
            </p>

            <div class="login-points">
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-magnifying-glass"></i></span>
                    <span>Jedna brána pro zaměstnance i klienty</span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-layer-group"></i></span>
                    <span>Oddělený přístup bez míchání oprávnění</span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-window-restore"></i></span>
                    <span>Klient vidí jen svou opravu a stav zakázky</span>
                </div>
            </div>
        </section>

        <section class="login-panel glass-card shadow-lg">
            <div class="login-panel-inner">
                <div class="login-panel-head">
                    <div>
                        <span class="login-panel-kicker">Secure Access</span>
                        <h2><?php echo e(__('login_title')); ?></h2>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger small mb-4"><?php echo e($error); ?></div>
                <?php endif; ?>

                <div class="login-section">
                    <div class="login-section-title"><i class="fas fa-user-tie"></i><span>Zaměstnanec / technik</span></div>
                    <form method="POST" class="login-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="login_employee" value="1">
                        <div class="mb-3">
                            <label class="form-label"><?php echo e(__('username_label')); ?></label>
                            <input type="text" name="username" class="form-control" required autofocus autocomplete="username">
                        </div>
                        <div class="mb-4">
                            <label class="form-label"><?php echo e(__('password')); ?></label>
                            <input type="password" name="password" class="form-control" value="" required autocomplete="current-password">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary"><?php echo e(__('login_btn')); ?></button>
                        </div>
                    </form>
                </div>

                <div class="login-separator">nebo</div>

                <div class="login-section">
                    <div class="login-section-title"><i class="fas fa-user-shield"></i><span>Klient</span></div>
                    <div class="login-section-note">Přihlášení funguje přes telefon, e-mail nebo číslo zakázky + PIN z protokolu.</div>
                    <form method="POST" class="login-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="login_client" value="1">
                        <div class="mb-3">
                            <label class="form-label">Telefon, e-mail nebo číslo zakázky</label>
                            <input type="text" name="login_identifier" class="form-control" required autocomplete="username" placeholder="Např. 777 123 456 nebo jmeno@email.cz">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">PIN kód z protokolu</label>
                            <input type="text" name="pin_code" class="form-control" required autocomplete="off" placeholder="PIN kód">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Přihlásit se</button>
                        </div>
                    </form>
                </div>

                <div class="login-note">Jeden login portal pro servis i klienty. Klient po přihlášení vidí jen svoji sekci.</div>
            </div>
        </section>
    </div>
</div>

</body>
</html>
