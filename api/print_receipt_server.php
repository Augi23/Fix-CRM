<?php
/**
 * Serverový tisk účtenky na pokladní termotiskárnu (Xprinter XP58-IIN v USB serveru).
 * Prohlížeč → HTTPS → server → /dev/usb/lp0. Funguje i ze Safari/iPadu, bez dialogu.
 *
 * POST JSON { csrf_token, sale_id, drawer?: 1 }   → vytiskne doklad (+ otevře zásuvku)
 * POST JSON { csrf_token, test: 1 }               → zkušební účtenka
 * GET  ?preview=1[&id=N]                          → PNG náhled rastru (diagnostika, netiskne)
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/receipt58.php';
require_once '../includes/receipt_escpos.php';
require_once '../includes/pos_shift.php';
ob_clean();

if (!crmCanUsePos()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => __('unauthorized')]); exit;
}

/** Data účtenky pro prodej z kasy — STEJNÁ stavba jako print_receipt.php?format=58. */
function afxReceiptDataForSale(int $id): ?array {
    global $pdo;
    ensurePosTables();
    $st = $pdo->prepare("SELECT s.*, c.first_name, c.last_name, c.company, c.ico AS cust_ico, i.invoice_number
        FROM pos_sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN invoices i ON s.invoice_id = i.id
        WHERE s.id = ?");
    $st->execute([$id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { return null; }
    // pobočková hranice stejně jako tisková stránka
    if (!crmCanViewHistory() && (int)($sale['branch_id'] ?? 0) !== (int)getCurrentStaffBranchId()) { return null; }
    $it = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY id");
    $it->execute([$id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    // POZOR pobočková past: kontakt VŽDY z pobočky DOKLADU, ne ze session
    $bc = crmOrderBranchContact((int)($sale['branch_id'] ?? 0));
    $custName = trim((string)($sale['company'] ?? '')) ?: trim((string)($sale['first_name'] ?? '') . ' ' . (string)($sale['last_name'] ?? ''));
    $sale['customer_label'] = $custName;
    $sale['customer_ico'] = $custName !== '' ? trim((string)($sale['cust_ico'] ?? '')) : '';
    if ((string)$sale['status'] !== 'cancelled') { $sale['cancelled_at'] = null; }
    // prodej „na fakturu IČO" prodává OSVČ majitele → hlavička účtenky nese JEJÍ
    // jméno a IČO (adresa provozovny a kontakt zůstávají — prodejna je stejná)
    $ico = (string)$sale['payment_method'] === 'invoice_ico' ? afxIcoSupplier() : null;
    return crmBuildPosReceipt58($sale, $items, [
        'name' => $ico ? ($ico['name'] ?: get_setting('company_name', 'AppleFix s.r.o.')) : get_setting('company_name', 'AppleFix s.r.o.'),
        'address' => trim((string)$bc['address']),
        'ico' => $ico ? $ico['ico'] : trim((string)get_setting('company_ico', '')),
        'dic' => $ico ? $ico['dic'] : trim((string)get_setting('company_dic', '')),
        'phone' => (string)$bc['phone'],
        'web' => trim((string)get_setting('company_web', '')) ?: 'www.applefix.cz',
    ]);
}

function afxReceiptTestData(): array {
    return crmBuildPosReceipt58(
        ['sale_number' => 'TEST', 'created_at' => date('Y-m-d H:i:s'),
         'seller_name' => trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
         'payment_method' => 'cash', 'total' => 123, 'vat_rate' => 0, 'is_vat_payer' => 0,
         'cash_received' => 200, 'cash_change' => 77],
        [['item_name' => 'Zkušební tisk účtenky — ěščřžýáíéúů ĚŠČŘŽ', 'item_code' => 'TEST-58',
          'quantity' => 1, 'unit_price' => 123, 'is_used_goods' => 0]],
        ['name' => get_setting('company_name', 'AppleFix s.r.o.'), 'address' => 'Zkušební tisk',
         'ico' => trim((string)get_setting('company_ico', '')), 'dic' => '',
         'phone' => '', 'web' => 'www.applefix.cz']
    );
}

// ── GET náhled (PNG rastru přesně tak, jak půjde na hlavu) ──
if (($_GET['preview'] ?? '') === '1') {
    $data = isset($_GET['id']) ? afxReceiptDataForSale((int)$_GET['id']) : afxReceiptTestData();
    if (!$data) { http_response_code(404); die('Doklad nenalezen.'); }
    $im = crmReceiptRaster($data);
    header('Content-Type: image/png');
    imagepng($im); exit;
}

// ── POST tisk ──
header('Content-Type: application/json; charset=utf-8');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}

try {
    // lístek směny (převzetí/uzávěrka) — velkým písmem kolik má být v kase
    $slip = (string)($in['slip'] ?? '');
    if ($slip === 'shift_open' || $slip === 'shift_close') {
        $branch = (int)getCurrentStaffBranchId();
        $shift = $slip === 'shift_open' ? afxPosShiftCurrent($branch) : afxPosShiftLastClosed($branch);
        if (!$shift) { echo json_encode(['ok' => false, 'error' => 'Směna nenalezena.']); exit; }
        // u převzetí patří na lístek i předchozí držitel kasy (poslední uzavřená směna)
        $prev = $slip === 'shift_open' ? afxPosShiftLastClosed($branch) : null;
        $bytes = crmEscposReceipt(crmShiftSlipRaster($shift, $slip === 'shift_open' ? 'open' : 'close', $prev));
        echo json_encode(['ok' => true, 'b64' => base64_encode($bytes)]); exit;
    }

    $data = !empty($in['test']) ? afxReceiptTestData() : afxReceiptDataForSale((int)($in['sale_id'] ?? 0));
    if (!$data) { echo json_encode(['ok' => false, 'error' => 'Doklad nenalezen.']); exit; }

    $bytes = '';
    if (!empty($in['drawer'])) { $bytes .= crmEscposDrawerPulse(); }   // šuplík ještě před tiskem
    $bytes .= crmEscposReceipt(crmReceiptRaster($data));

    // VÝCHOZÍ REŽIM: server NIC netiskne — vrátí hotové bajty a odešle je až
    // prohlížeč POKLADNÍHO počítače na svůj lokální můstek (127.0.0.1:9101).
    // Tiskne tedy vždy jen ten počítač, který má tiskárnu v USB (přání majitele).
    if (!empty($in['bytes'])) {
        echo json_encode(['ok' => true, 'b64' => base64_encode($bytes)]); exit;
    }

    $res = crmReceiptSendBytes($bytes);
    if (!$res['ok']) { error_log('print_receipt_server: ' . $res['error']); }
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('print_receipt_server: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Tisk se nepodařil: ' . $e->getMessage()]);
}
