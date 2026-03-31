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

function clientNormalizePhone(string $value): string {
    return preg_replace('/\D+/', '', $value) ?: '';
}

function clientNormalizeEmail(string $value): string {
    return strtolower(trim($value));
}

function clientLookupCustomerAndOrders($pdo, string $identifier): array {
    $identifier = trim($identifier);
    $result = [
        'customer' => null,
        'orders' => [],
        'matched_order' => null,
    ];

    if (!isset($pdo) || $identifier === '') {
        return $result;
    }

    $customer = null;

    try {
        // 1) Order number first, if the identifier is numeric and matches an existing order.
        if (ctype_digit($identifier)) {
            $stmt = $pdo->prepare(
                "SELECT o.id as order_id, o.*, c.first_name, c.last_name, c.phone, c.email, c.company, c.customer_type
                 FROM orders o
                 INNER JOIN customers c ON c.id = o.customer_id
                 WHERE o.id = ?
                 LIMIT 1"
            );
            $stmt->execute([(int)$identifier]);
            $row = $stmt->fetch();
            if ($row) {
                $customer = [
                    'id' => (int)$row['customer_id'],
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'phone' => $row['phone'] ?? '',
                    'email' => $row['email'] ?? '',
                    'company' => $row['company'] ?? '',
                    'customer_type' => $row['customer_type'] ?? 'private',
                ];
                $result['matched_order'] = $row;
            }
        }

        // 2) Email lookup.
        if (!$customer && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare(
                "SELECT id, first_name, last_name, phone, email, company, customer_type
                 FROM customers
                 WHERE LOWER(email) = LOWER(?)
                 LIMIT 1"
            );
            $stmt->execute([$identifier]);
            $customer = $stmt->fetch() ?: null;
        }

        // 3) Phone lookup (normalized digits).
        if (!$customer) {
            $needleDigits = clientNormalizePhone($identifier);
            if ($needleDigits !== '') {
                $stmt = $pdo->query(
                    "SELECT id, first_name, last_name, phone, email, company, customer_type
                     FROM customers
                     WHERE phone IS NOT NULL AND phone <> ''"
                );
                while ($row = $stmt->fetch()) {
                    if (clientNormalizePhone((string)($row['phone'] ?? '')) === $needleDigits) {
                        $customer = $row;
                        break;
                    }
                }
            }
        }

        if ($customer) {
            $customerId = (int)$customer['id'];
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM orders
                 WHERE customer_id = ?
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$customerId]);
            $orders = $stmt->fetchAll();
            $result['customer'] = $customer;
            $result['orders'] = $orders;

            // If the initial identifier was an order number, keep that exact order as the match.
            if (!$result['matched_order'] && !empty($orders)) {
                $result['matched_order'] = $orders[0];
            }
        }

        return $result;
    } catch (Exception $e) {
        return $result;
    }
}
