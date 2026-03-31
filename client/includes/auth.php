<?php
function clientCheckLoginAttempts($pdo): bool {
    if (!isset($pdo)) return true;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() < 5;
    } catch (Exception $e) {
        return true;
    }
}

function clientRecordLoginAttempt($pdo, bool $success): void {
    if (!isset($pdo)) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($success) {
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())")->execute([$ip]);
        }
    } catch (Exception $e) {
        // ignore
    }
}

function clientIsLoggedIn(): bool {
    return !empty($_SESSION['client_authenticated']) && !empty($_SESSION['client_customer_id']);
}

function clientRequireAuth(): void {
    if (!clientIsLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function clientLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
