<?php
/* Požadavky pro podpisovou stanici:
   POST action=create  (order_id, sig_type)      → nový požadavek pro pobočku zakázky
   POST action=create  (complaint_id, sig_type=reklamace) → podpis reklamačního protokolu
                                                    (řádek má order_id = 0)
   GET  ?check=<id>                              → stav požadavku (pending/done/cancelled;
                                                    prošlý požadavek hlásí 'cancelled')
   POST action=cancel  (request_id)              → zrušení (stanice „Přeskočit" / rozmyšlení) */
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
    // Platnost se vyhodnocuje i tady: stav 'expired' přepisuje jen poll stanice,
    // takže bez zapnutého iPadu by detail zakázky čekal navždy.
    $st = $pdo->prepare("SELECT status,
            (COALESCE(shown_at, created_at) < (NOW() - INTERVAL 30 MINUTE)) AS je_prosly
        FROM signature_requests WHERE id = ? LIMIT 1");
    try {
        $st->execute([(int)$_GET['check']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {   // starší DB bez shown_at
        $st = $pdo->prepare("SELECT status, (created_at < (NOW() - INTERVAL 30 MINUTE)) AS je_prosly
                             FROM signature_requests WHERE id = ? LIMIT 1");
        $st->execute([(int)$_GET['check']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    $status = (string)($row['status'] ?? 'missing');
    if ($status === 'pending' && !empty($row['je_prosly'])) { $status = 'expired'; }
    // Prošlý požadavek (stanice ho po čase pustila) je pro pult totéž co zrušený —
    // ať čekající detail zakázky nestojí donekonečna na „Odesláno na tablet".
    if ($status === 'expired') { $status = 'cancelled'; }
    echo json_encode(['ok' => true, 'status' => $status]);
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
$complaintId = (int)($_POST['complaint_id'] ?? 0);
$documentId = (int)($_POST['document_id'] ?? 0);
$sigType = (string)($_POST['sig_type'] ?? '');

// ── Dokumenty (výkupní list / zástavní formulář): podpis na stanici ──
if ($documentId > 0 && $sigType === 'dokument') {
    require_once __DIR__ . '/../includes/documents.php';
    ensureDocumentSignatureSupport();
    $doc = crmGetDocument($documentId);
    if (!$doc) {
        echo json_encode(['ok' => false, 'error' => 'Dokument nenalezen']); exit;
    }
    try {
        $pdo->prepare("UPDATE signature_requests SET status = 'cancelled' WHERE document_id = ? AND status = 'pending'")->execute([$documentId]);
        $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $branch = (int)getCurrentStaffBranchId();
        $pdo->prepare("INSERT INTO signature_requests (order_id, document_id, sig_type, branch_id, requested_by, email_after) VALUES (0, ?, 'dokument', ?, ?, 0)")
            ->execute([$documentId, $branch ?: null, $by !== '' ? mb_substr($by, 0, 100) : null]);
        echo json_encode(['ok' => true, 'request_id' => (int)$pdo->lastInsertId(), 'notice' => '', 'email' => '']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
    }
    exit;
}

// ── Reklamace: podpis reklamačního protokolu (stejná stanice jako zakázky) ──
if ($complaintId > 0 && $sigType === 'reklamace') {
    ensureComplaintSignatureSupport();
    $st = $pdo->prepare("SELECT id FROM complaints WHERE id = ? LIMIT 1");
    $st->execute([$complaintId]);
    if (!$st->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Reklamace nenalezena']); exit;
    }
    try {
        $pdo->prepare("UPDATE signature_requests SET status = 'cancelled' WHERE complaint_id = ? AND status = 'pending'")->execute([$complaintId]);
        $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $branch = (int)getCurrentStaffBranchId();
        $pdo->prepare("INSERT INTO signature_requests (order_id, complaint_id, sig_type, branch_id, requested_by, email_after) VALUES (0, ?, 'reklamace', ?, ?, 0)")
            ->execute([$complaintId, $branch ?: null, $by !== '' ? mb_substr($by, 0, 100) : null]);
        echo json_encode(['ok' => true, 'request_id' => (int)$pdo->lastInsertId(), 'notice' => '', 'email' => '']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
    }
    exit;
}

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

    // e-mail klienta (kam po podpisu půjde list) — ať to UI může rovnou ukázat
    $emailForUi = (isset($cliEmail) && filter_var($cliEmail, FILTER_VALIDATE_EMAIL)) ? $cliEmail : '';
    echo json_encode(['ok' => true, 'request_id' => (int)$pdo->lastInsertId(), 'notice' => $notice, 'email' => $emailForUi]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
}
