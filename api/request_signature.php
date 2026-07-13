<?php
/* Požadavky pro podpisovou stanici:
   POST action=create  (order_id, sig_type)  → nový požadavek pro pobočku zakázky
   GET  ?check=<id>                          → stav požadavku (pending/done/cancelled)
   POST action=cancel  (request_id)          → zrušení (stanice „Přeskočit" / rozmyšlení) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}

ensureSignatureRequestsTable();

// stav požadavku (poll z detailu zakázky, čeká na stanici)
if (isset($_GET['check'])) {
    $st = $pdo->prepare("SELECT status FROM signature_requests WHERE id = ? LIMIT 1");
    $st->execute([(int)$_GET['check']]);
    echo json_encode(['ok' => true, 'status' => (string)($st->fetchColumn() ?: 'missing')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}

$action = (string)($_POST['action'] ?? 'create');

if ($action === 'cancel') {
    $id = (int)($_POST['request_id'] ?? 0);
    $pdo->prepare("UPDATE signature_requests SET status = 'cancelled' WHERE id = ? AND status = 'pending'")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$sigType = (string)($_POST['sig_type'] ?? '');
if ($orderId <= 0 || !in_array($sigType, ['prijem', 'vydej'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Chybné parametry']); exit;
}

$st = $pdo->prepare("SELECT id, branch_id FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order || !canAccessOrderBranch($order)) {
    echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit;
}

try {
    // starší čekající požadavky téže zakázky zrušit (ať stanice neukazuje duplicity)
    $pdo->prepare("UPDATE signature_requests SET status = 'cancelled' WHERE order_id = ? AND status = 'pending'")->execute([$orderId]);

    // volitelně: po podpisu automaticky poslat zakázkový list e-mailem
    $emailAfter = (int)($_POST['email_after'] ?? 0) === 1;
    $notice = '';
    if ($emailAfter) {
        $ce = $pdo->prepare("SELECT c.email FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?");
        $ce->execute([$orderId]);
        $cliEmail = trim((string)$ce->fetchColumn());
        if (!filter_var($cliEmail, FILTER_VALIDATE_EMAIL)) {
            $emailAfter = false;
            $notice = 'no_email';
        }
    }

    $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $pdo->prepare("INSERT INTO signature_requests (order_id, sig_type, branch_id, requested_by, email_after) VALUES (?, ?, ?, ?, ?)")
        ->execute([$orderId, $sigType, (int)($order['branch_id'] ?? 0) ?: null, $by !== '' ? mb_substr($by, 0, 100) : null, $emailAfter ? 1 : 0]);

    echo json_encode(['ok' => true, 'request_id' => (int)$pdo->lastInsertId(), 'notice' => $notice]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
}
