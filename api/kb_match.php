<?php
/**
 * Banka — ruční spárování/odpárování pohybu s fakturou.
 *   action=match   tx_id + invoice_id → pohyb 'manual' + faktura PAID
 *   action=unmatch tx_id             → zruší vazbu; fakturu vrátí na 'issued'
 *                                      jen pokud byla zaplacena právě tímto párováním
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageInvoices()) {
    echo json_encode(['success' => false, 'message' => 'Jen pro vedení.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

ensureBankTables();
$action = (string)($_POST['action'] ?? 'match');
$txId = (int)($_POST['tx_id'] ?? 0);

try {
    $st = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = ?");
    $st->execute([$txId]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tx) { throw new Exception('Pohyb nenalezen.'); }

    if ($action === 'unmatch') {
        if (!empty($tx['matched_invoice_id']) && in_array((string)$tx['match_status'], ['auto', 'manual'], true)) {
            // vrátit správný nezaplacený stav: po splatnosti = overdue, jinak issued
            $pdo->prepare("UPDATE invoices
                SET status = IF(date_due < CURDATE(), 'overdue', 'issued'), payment_date = NULL
                WHERE id = ? AND status = 'paid'")
                ->execute([(int)$tx['matched_invoice_id']]);
        }
        $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = NULL, match_status = 'none' WHERE id = ?")
            ->execute([$txId]);
        crmAuditLog('banka.match', [
            'entity_type' => 'bank', 'entity_id' => $txId,
            'summary' => 'Zrušeno párování platby ' . formatMoney((float)$tx['amount']) . ' (VS ' . ($tx['vs'] ?: '—') . ')',
        ]);
        echo json_encode(['success' => true]); exit;
    }

    // párovat jde jen PŘÍCHOZÍ, dosud nespárovaný pohyb…
    if ((string)$tx['direction'] !== 'in') { throw new Exception('Spárovat jde jen příchozí platba.'); }
    if (!in_array((string)$tx['match_status'], ['none', 'review'], true)) { throw new Exception('Pohyb už je spárovaný — nejdřív zruš stávající párování.'); }

    // …a jen s nezaplacenou skutečnou fakturou (ne dobropis, ne už zaplacená)
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $iv = $pdo->prepare("SELECT id, invoice_number, total_amount, status, invoice_type FROM invoices WHERE id = ?");
    $iv->execute([$invoiceId]);
    $inv = $iv->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { throw new Exception('Faktura nenalezena.'); }
    if ((string)$inv['invoice_type'] !== 'invoice') { throw new Exception('Dobropis nelze párovat s příchozí platbou.'); }
    if ((string)$inv['status'] === 'paid') { throw new Exception('Faktura ' . $inv['invoice_number'] . ' už je zaplacená.'); }
    if ((string)$inv['status'] === 'cancelled') { throw new Exception('Faktura ' . $inv['invoice_number'] . ' je stornovaná.'); }

    $pdo->prepare("UPDATE invoices SET status = 'paid', payment_date = ? WHERE id = ?")
        ->execute([(string)$tx['booking_date'] ?: date('Y-m-d'), $invoiceId]);
    $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'manual' WHERE id = ?")
        ->execute([$invoiceId, $txId]);
    crmAuditLog('banka.match', [
        'entity_type' => 'invoice', 'entity_id' => $invoiceId, 'entity_label' => (string)$inv['invoice_number'],
        'summary' => 'Faktura ' . $inv['invoice_number'] . ' RUČNĚ spárována s platbou '
            . formatMoney((float)$tx['amount']) . ' (' . ($tx['counterparty_name'] ?: 'bez názvu') . ') a označena ZAPLACENO',
    ]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $msg = ($e instanceof PDOException) ? 'Databázová chyba.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
