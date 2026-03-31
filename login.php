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

if (isset($_POST['login'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!checkLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Vyplň uživatele a heslo.';
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

            // 2) Client login — same portal, different dashboard
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
            $error = 'Neplatný uživatel nebo heslo.';
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
<html lang="<?php echo e($_SESSION['lang'] ?? 'cs'); ?>" data-bs-theme="dark">
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

            <p class="login-copy"><?php echo e($loginQuip); ?></p>

            <div class="login-points">
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-magnifying-glass"></i></span>
                    <span>Jeden login portal pro zaměstnance i klienty</span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-layer-group"></i></span>
                    <span>Správný dashboard podle přihlášené role</span>
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
                        <h2>Přihlášení</h2>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger small mb-4"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="POST" class="login-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="login" value="1">
                    <div class="mb-3">
                        <label class="form-label">Uživatel</label>
                        <input type="text" name="username" class="form-control" required autofocus autocomplete="username" placeholder="E-mail, telefon, číslo zakázky nebo uživatelské jméno">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Heslo</label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="Heslo nebo PIN">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Přihlásit se</button>
                    </div>
                </form>

                <div class="login-section-note mt-3">
                    Zaměstnanec se přihlásí svými údaji, klient telefonem / e-mailem / číslem zakázky a PINem. Formulář je společný, systém po přihlášení sám pošle každého do správného prostoru.
                </div>

                <div class="login-note">Všechno vypadá stejně jako dřív u admin sekce, jen už bez rozdělených vstupů.</div>
            </div>
        </section>
    </div>
</div>

</body>
</html>
