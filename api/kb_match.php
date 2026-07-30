<?php
/**
 * Banka — ruční spárování/odpárování pohybu s fakturou.
 *   action=match   tx_id + invoice_id → zapíše platbu k faktuře (i ČÁSTEČNOU: faktura
 *                                       se označí zaplacená až když je uhrazená celá)
 *   action=unmatch tx_id             → smaže evidenci platby a přepočítá stav faktury;
 *                                       platbu vyřadí z automatického párování
 *   action=reset   tx_id             → vrátí vyřazenou platbu do automatického párování
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
afxEnsureInvoicePayments();
$action = (string)($_POST['action'] ?? 'match');
$txId = (int)($_POST['tx_id'] ?? 0);

try {
    $st = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = ?");
    $st->execute([$txId]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tx) { throw new Exception('Pohyb nenalezen.'); }

    if ($action === 'unmatch') {
        // Smazání evidence platby stav faktury přepočítá samo: když ji krylo víc plateb
        // a zůstávají další, faktura zaplacená ZŮSTANE (jen se zmenší uhrazená částka);
        // bez ostatních plateb se vrátí mezi nezaplacené (po splatnosti = overdue).
        afxInvoiceRemoveBankPayment($txId);
        if (!empty($tx['matched_invoice_id'])) { afxInvoiceRecalcPaid((int)$tx['matched_invoice_id'], true); }
        // KRITICKÉ: stav 'ignored', NE 'none'. Do 'none' sahá automatické párování —
        // odpárovaná platba by se při nejbližší synchronizaci sama zase spárovala
        // a přebila rozhodnutí účetní. Zpět do automatu ji vrátí až člověk (action=reset).
        $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = NULL, match_status = 'ignored',
            matched_at = NULL, match_note = 'Ručně odpárováno — vyřazeno z automatického párování' WHERE id = ?")
            ->execute([$txId]);
        crmAuditLog('banka.match', [
            'entity_type' => 'bank', 'entity_id' => $txId,
            'summary' => 'Zrušeno párování platby ' . formatMoney((float)$tx['amount']) . ' (VS ' . ($tx['vs'] ?: '—')
                . ') — platba vyřazena z automatického párování',
        ]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'reset') {
        // vrácení vyřazené platby zpět do automatického párování
        if ((string)$tx['match_status'] !== 'ignored') { throw new Exception('Do automatického párování jde vrátit jen vyřazená platba.'); }
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'none', matched_invoice_id = NULL,
            match_note = NULL WHERE id = ?")->execute([$txId]);
        crmAuditLog('banka.match', [
            'entity_type' => 'bank', 'entity_id' => $txId,
            'summary' => 'Platba ' . formatMoney((float)$tx['amount']) . ' (VS ' . ($tx['vs'] ?: '—')
                . ') vrácena do automatického párování',
        ]);
        echo json_encode(['success' => true]); exit;
    }

    // párovat jde jen PŘÍCHOZÍ, dosud nespárovaný pohyb…
    if ((string)$tx['direction'] !== 'in') { throw new Exception('Spárovat jde jen příchozí platba.'); }
    if (!empty($tx['is_reversal'])) { throw new Exception('Storno platby nelze párovat s fakturou.'); }
    if (!in_array((string)$tx['match_status'], ['none', 'review', 'ignored'], true)) { throw new Exception('Pohyb už je spárovaný — nejdřív zruš stávající párování.'); }
    // …a jen pohyb z právě napojeného účtu a prostředí (sandbox nesmí platit ostré faktury)
    if ((string)$tx['env'] !== kbApiEnv() || (string)$tx['account_id'] !== (string)get_setting('kb_account_id', '')) {
        throw new Exception('Pohyb patří k jinému účtu nebo prostředí, než je teď napojené.');
    }
    if (strtoupper((string)$tx['currency']) !== 'CZK') { throw new Exception('Platbu v cizí měně nelze párovat s korunovou fakturou — vyřeš ručně v účetnictví.'); }

    // …a jen se skutečnou fakturou (ne dobropis, ne stornovaná), která ještě není celá uhrazená
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $iv = $pdo->prepare("SELECT id, invoice_number, total_amount, paid_amount, status, invoice_type FROM invoices WHERE id = ?");
    $iv->execute([$invoiceId]);
    $inv = $iv->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { throw new Exception('Faktura nenalezena.'); }
    if ((string)$inv['invoice_type'] !== 'invoice') { throw new Exception('Dobropis nelze párovat s příchozí platbou.'); }
    if ((string)$inv['status'] === 'cancelled') { throw new Exception('Faktura ' . $inv['invoice_number'] . ' je stornovaná.'); }

    $info = afxInvoicePaymentInfo($inv);
    if ($info['remaining'] <= 0) {
        throw new Exception('Faktura ' . $inv['invoice_number'] . ' je už celá uhrazená (' . formatMoney($info['paid']) . ').');
    }
    $amount = (float)$tx['amount'];
    // přeplatek se ručně navázat nedá — takovou platbu je potřeba rozdělit nebo vrátit,
    // jinak by v evidenci vznikla faktura „zaplacená" víc, než kolik měla být
    if ($amount > $info['remaining'] + afxPayTolerance($info['total'])) {
        throw new Exception('Platba ' . formatMoney($amount) . ' je vyšší než zbytek k úhradě ('
            . formatMoney($info['remaining']) . ') na faktuře ' . $inv['invoice_number'] . '.');
    }

    if (!afxInvoiceAddPayment($invoiceId, $amount, 'bank', (string)$tx['booking_date'] ?: date('Y-m-d'), $txId, 'Ručně spárováno')) {
        throw new Exception('Tato platba už je k faktuře navázaná.');
    }
    $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'manual',
        matched_at = NOW(), match_note = 'Ručně spárováno' WHERE id = ?")
        ->execute([$invoiceId, $txId]);

    $after = afxInvoiceRecalcPaid($invoiceId);
    $fullyPaid = $after['remaining'] <= 0;
    crmAuditLog('banka.match', [
        'entity_type' => 'invoice', 'entity_id' => $invoiceId, 'entity_label' => (string)$inv['invoice_number'],
        'summary' => 'Faktura ' . $inv['invoice_number'] . ' RUČNĚ spárována s platbou '
            . formatMoney($amount) . ' (' . ($tx['counterparty_name'] ?: 'bez názvu') . ') — '
            . ($fullyPaid ? 'označena ZAPLACENO' : 'částečná platba, zbývá ' . formatMoney($after['remaining'])),
    ]);
    echo json_encode(['success' => true, 'paid' => $after['paid'], 'remaining' => $after['remaining'],
        'message' => $fullyPaid ? 'Faktura je zaplacená.' : 'Zaplaceno ' . formatMoney($after['paid'])
            . ' z ' . formatMoney($after['total']) . ' — zbývá ' . formatMoney($after['remaining']) . '.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $msg = ($e instanceof PDOException) ? 'Databázová chyba.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
