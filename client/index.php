<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$error = false;

if (clientIsLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$clientQuips = [
    'Zakázka je přijatá, my už máme šroubováky v ruce.',
    'Prasklý displej? To je u nás jen začátek příběhu.',
    'Když iPhone mlčí, my víme, co s ním.',
    'MacBook se seká? V servisu se to rovná, ne přehlíží.',
    'Baterka dolů, servis nahoru.',
    'Od přijetí po vyzvednutí bez servisního chaosu.',
    'Čeká na díly? My už hlídáme pořadník.',
    'Rychle přijatá zakázka, pečlivě hotová oprava.',
    'Když se Apple tváří nedostupně, my už hledáme řešení.',
    'Nejprve diagnostika, pak klid. To je celý trik.',
    'Jeden telefon přijatý, jeden zákazník méně nervózní.',
    'MacBook bez chlazení? To je přesně náš druh pondělí.',
    'Servis se vším všudy — bez omáčky, ale s výsledkem.',
    'Závada je nahlášená, řešení je na cestě.',
    'Opravy děláme tak, aby se na ně dalo spolehnout i po půlnoci.',
    'Když je zakázka v procesu, my ji nepouštíme z očí.',
    'AppleFix: přijato, rozebráno, opraveno, vydáno.',
    'Jablečný servis, co drží slovo i šroubky.',
    'Až bude připraveno k vyzvednutí, dáme vědět bez zbytečných řečí.',
    'Ticho na stole často znamená, že oprava běží přesně jak má.',
];
$clientQuip = $clientQuips[array_rand($clientQuips)];

function clientRenderLoginError($message) {
    return '<div class="alert alert-danger small mb-4">' . e($message) . '</div>';
}

if (isset($_POST['login'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!clientCheckLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $pinCode = trim((string)($_POST['pin_code'] ?? ''));

        if ($orderId <= 0 || $pinCode === '') {
            $error = 'Vyplň číslo zakázky i PIN kód.';
        } elseif (isset($pdo)) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT o.*, c.first_name, c.last_name, c.phone, c.email, c.company, c.customer_type
                     FROM orders o
                     INNER JOIN customers c ON c.id = o.customer_id
                     WHERE o.id = ?
                     LIMIT 1"
                );
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();

                if ($order && hash_equals(trim((string)($order['pin_code'] ?? '')), $pinCode)) {
                    session_regenerate_id(true);
                    $_SESSION['client_authenticated'] = true;
                    $_SESSION['client_customer_id'] = (int)$order['customer_id'];
                    $_SESSION['client_order_id'] = (int)$order['id'];
                    $_SESSION['client_full_name'] = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                    $_SESSION['client_company'] = $order['company'] ?? '';
                    $_SESSION['client_last_login'] = time();
                    clientRecordLoginAttempt($pdo, true);
                    header('Location: dashboard.php');
                    exit;
                }

                clientRecordLoginAttempt($pdo, false);
                $error = 'Neplatné číslo zakázky nebo PIN kód.';
            } catch (Exception $e) {
                clientRecordLoginAttempt($pdo, false);
                $error = 'Přihlášení se nezdařilo. Zkus to prosím znovu.';
            }
        } else {
            $error = __('login_error_db');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['lang'] ?? 'cs'); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klientská sekce - AppleFix</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    <style>
        .client-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(243,247,255,0.9);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-scene">
        <section class="login-hero">
            <div class="login-brandline">
                <img src="../assets/img/applefix-logo.png" alt="AppleFix logo" class="login-hero-logo">
            </div>

            <h1 class="login-headline">Klientská<br>sekce.</h1>

            <p class="login-copy">
                <?php echo e($clientQuip); ?>
            </p>

            <div class="login-points">
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-circle-check"></i></span>
                    <span>Stav opravy v reálném čase</span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-coins"></i></span>
                    <span>Přehled ceny opravy nebo hotové částky</span>
                </div>
                <div class="login-point">
                    <span class="login-point-icon"><i class="fas fa-box-open"></i></span>
                    <span>Informace, kdy je zařízení připravené k vyzvednutí</span>
                </div>
            </div>
        </section>

        <section class="login-panel glass-card shadow-lg">
            <div class="login-panel-inner">
                <div class="login-panel-head d-flex justify-content-between align-items-start">
                    <div>
                        <span class="login-panel-kicker">Client Access</span>
                        <h2>AppleFix klient</h2>
                    </div>
                    <span class="client-badge"><i class="fas fa-shield-halved"></i> oddělený login</span>
                </div>

                <?php if ($error): ?>
                    <?php echo clientRenderLoginError($error); ?>
                <?php endif; ?>

                <form method="POST" class="login-form">
                    <?php echo csrfField(); ?>
                    <div class="mb-3">
                        <label class="form-label">Číslo zakázky</label>
                        <input type="number" name="order_id" class="form-control" required autofocus autocomplete="off" placeholder="Např. 1234">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">PIN kód z protokolu</label>
                        <input type="text" name="pin_code" class="form-control" required autocomplete="off" placeholder="PIN kód">
                    </div>
                    <div class="d-grid">
                        <button type="submit" name="login" class="btn btn-primary">Přihlásit se</button>
                    </div>
                </form>

                <div class="login-note">Data jsou čtena přímo z CRM. Klient vidí jen své zakázky.</div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
