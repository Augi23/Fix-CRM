<?php
/**
 * DODATEČNÁ FAKTURA K JIŽ PROBĚHLÉMU PRODEJI (v3.71.0).
 *
 * Zákazník zaplatil na pokladně kartou (nebo hotově) a až potom si řekne
 * o fakturu — třeba na firmu. Tenhle endpoint k hotovému prodeji vystaví
 * fakturu na vybraného klienta z CRM, nebo na ručně vyplněného odběratele,
 * a volitelně ji rovnou pošle e-mailem.
 *
 * Doklad se vystaví jako JIŽ UHRAZENÝ: peníze reálně došly v den prodeje,
 * takže DUZP, splatnost i datum úhrady = datum prodeje, stav „zaplaceno"
 * a evidovaná platba (karta/hotovost). Prodej v kase se NEPŘEPISUJE — jen se
 * k němu doklad připojí (pos_sales.invoice_id), aby dvojice šla dohledat.
 * Aby se tržba nezapočítala dvakrát, Přehledy platby k takovým fakturám
 * vynechávají (reports.php — částka už je v tržbě kasy).
 *
 * POST JSON {
 *   csrf_token, sale_id,
 *   customer_id?      — klient z CRM (0/chybí = ruční odběratel)
 *   buyer? { name, address, ico, dic, email }
 *   send_email?       — 1 = po vystavení rovnou odeslat
 *   email?            — kam poslat (jinak e-mail odběratele / karta klienta)
 * }
 * → { ok, invoice_id, invoice_number, emailed, emailed_to, email_error }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function paiFail(string $err, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { paiFail('Nepřihlášeno', 401); }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    paiFail(__('csrf_token_invalid'), 403);
}

$saleId = (int)($in['sale_id'] ?? 0);
if ($saleId <= 0) { paiFail('Chybí prodej.'); }

// schéma (DDL) VŽDY před transakcí — uvnitř dělá implicitní COMMIT
afxEnsureInvoiceAdhocBuyer();
afxEnsureInvoiceEmailColumns();
afxEnsureInvoiceBranch();
afxEnsureInvoicePayments();

$ss = $pdo->prepare("SELECT * FROM pos_sales WHERE id = ? LIMIT 1");
$ss->execute([$saleId]);
$sale = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sale) { paiFail('Prodej nenalezen.', 404); }

$saleBranch = $sale['branch_id'] !== null ? (int)$sale['branch_id'] : 0;
$saleDay = date('Y-m-d', strtotime((string)$sale['created_at']));
$dnesniProdej = $saleDay === date('Y-m-d');

// ── kdo smí ──────────────────────────────────────────────────────────────
// Vystavit fakturu je účetní úkon: vedení, pobočkový manažer a účetní.
// Obsluze kasy (crmCanUsePos = prakticky každý přihlášený) se povoluje jen
// DNEŠNÍ prodej z JEJÍ prodejny — tam si zákazník řekne o fakturu hned u pultu.
// Stejné pravidlo jako u api/invoice_email.php, ať nejde jednou cestou obejít
// to, co druhá hlídá.
$mineBranch = isBranchGlobalViewer()
    || (function_exists('crmIsAccountant') && crmIsAccountant())
    || $saleBranch <= 0
    || $saleBranch === (int)getCurrentStaffBranchId();
if (!$mineBranch) { paiFail('Prodej patří jiné provozovně.', 403); }
if (!crmCanUseInvoices()) {
    if (!(function_exists('crmCanUsePos') && crmCanUsePos()) || !$dnesniProdej) {
        paiFail('Fakturu ke staršímu prodeji vystaví vedení v Účetnictví → Prodej.', 403);
    }
}

// ── co fakturovat nejde ──────────────────────────────────────────────────
if ((string)$sale['status'] !== 'completed') { paiFail('Ke stornovanému prodeji fakturu vystavit nelze.'); }
if ((float)$sale['total'] <= 0) { paiFail('Výdejní doklad (výkup, výdaj z kasy) se nefakturuje.'); }
if (in_array((string)$sale['payment_method'], ['invoice', 'invoice_ico'], true)) {
    paiFail('Tenhle prodej se rovnou fakturoval — druhý doklad k němu nepatří.');
}
if (!empty($sale['invoice_id'])) {
    $iv = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
    $iv->execute([(int)$sale['invoice_id']]);
    $existing = (string)($iv->fetchColumn() ?: '');
    if ($existing !== '') {
        paiFail('K tomuhle prodeji už faktura existuje (' . $existing . ').', 409);
    }
    // faktura byla mezitím smazaná → vazba je mrtvá, uvolnit ji a pokračovat
    $pdo->prepare("UPDATE pos_sales SET invoice_id = NULL WHERE id = ?")->execute([$saleId]);
    $sale['invoice_id'] = null;
}
// zakázka nesmí dostat druhou fakturu — Přehledy sčítají vyfakturováno per zakázku
if (!empty($sale['order_id'])) {
    $oi = $pdo->prepare("SELECT invoice_number FROM invoices
                         WHERE order_id = ? AND status <> 'cancelled'
                           AND COALESCE(invoice_type, 'invoice') = 'invoice' LIMIT 1");
    $oi->execute([(int)$sale['order_id']]);
    $onum = (string)($oi->fetchColumn() ?: '');
    if ($onum !== '') {
        paiFail('K zakázce #' . (int)$sale['order_id'] . ' už faktura existuje (' . $onum . ').', 409);
    }
}

// ── režim DPH musí odpovídat DNI PRODEJE ─────────────────────────────────
// crmPosCreateInvoice počítá podle AKTUÁLNÍHO nastavení; kdyby se mezitím
// změnilo plátcovství nebo sazba, daňový doklad by o starém plnění tvrdil něco
// jiného než účtenka, kterou zákazník dostal.
$dnesPlatce = get_setting('acc_is_vat_payer', '0') == '1';
$dnesSazba = (float)get_setting('acc_vat_rate', '21');
if ((bool)$sale['is_vat_payer'] !== $dnesPlatce
    || ((bool)$sale['is_vat_payer'] && abs((float)$sale['vat_rate'] - $dnesSazba) > 0.001)) {
    paiFail('Prodej vznikl v jiném režimu DPH (' . ((bool)$sale['is_vat_payer'] ? 'plátce ' . (float)$sale['vat_rate'] . ' %' : 'neplátce')
        . '), než je nastavený dnes. Fakturu k němu vystav ručně v Účetnictví.', 409);
}

// ── odběratel: klient z CRM, nebo ručně vyplněný ─────────────────────────
$customerId = (int)($in['customer_id'] ?? 0);
$buyer = afxInvoiceBuyerSanitize($in['buyer'] ?? []);
if (($buyer['error'] ?? '') !== '') { paiFail((string)$buyer['error']); }
$buyerName = trim((string)($buyer['name'] ?? ''));
if ($customerId > 0 && $buyerName !== '') {
    // obojí naráz = doklad by byl v evidenci na klienta X, ale vytištěný na Y
    paiFail('Vyber buď klienta z CRM, nebo vyplň odběratele ručně — ne obojí.');
}
if ($customerId > 0) {
    if (function_exists('crmIsAccountant') && crmIsAccountant()) {
        // účetní klientskou databázi procházet nesmí (viz whitelist v accounting_role.php)
        paiFail('Účetní vyplní odběratele ručně.', 403);
    }
    $cc = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
    $cc->execute([$customerId]);
    if (!$cc->fetchColumn()) { paiFail('Vybraný klient neexistuje.'); }
} elseif ($buyerName === '') {
    paiFail('Vyber klienta, nebo vyplň název odběratele.');
}

// ── uzávěrka: doklad se datuje dneškem, ale DUZP a úhrada dnem prodeje ───
try {
    afxAccountingAssertOpen(date('Y-m-d'), 'fakturu');
    afxAccountingAssertOpen($saleDay, 'fakturu (DUZP a úhrada ke dni prodeje)');
} catch (Throwable $e) { paiFail($e->getMessage(), 409); }

// ── položky prodeje → položky faktury ────────────────────────────────────
$its = $pdo->prepare("SELECT item_name, quantity, unit_price, is_used_goods FROM pos_sale_items WHERE sale_id = ? ORDER BY id ASC");
$its->execute([$saleId]);
$items = [];
foreach ($its->fetchAll(PDO::FETCH_ASSOC) as $it) {
    if ((float)$it['unit_price'] < 0) {
        // výkup/výdaj uvnitř prodeje není sleva — takový doklad ať se fakturuje ručně
        paiFail('Prodej obsahuje výdejovou položku (výkup) — fakturu k němu vystav ručně v Účetnictví.');
    }
    $items[] = [
        'name' => (string)$it['item_name'],
        'qty' => max(1, (int)$it['quantity']),
        'unit_price' => (float)$it['unit_price'],
        'used' => !empty($it['is_used_goods']),
    ];
}
if (!$items) {
    // starší prodej bez rozepsaných položek — ať se dá vyfakturovat aspoň jednou položkou
    $items[] = ['name' => 'Prodej ' . (string)$sale['sale_number'], 'qty' => 1,
                'unit_price' => (float)$sale['total'], 'used' => false];
}

$payMethod = (string)$sale['payment_method'];          // 'card' | 'cash'
$payKind = $payMethod === 'cash' ? 'cash' : 'card';
$payWord = $payMethod === 'cash' ? 'hotově' : 'kartou';

$invoiceId = 0;
try {
    $pdo->beginTransaction();

    // Zámek na prodej: bez něj by dva souběžné požadavky (dvě záložky, dva lidé)
    // prošly kontrolami nad sebou a vyrobily DVĚ očíslované zaplacené faktury —
    // nebo by mezitím proběhlo storno a doklad by visel na stornovaném prodeji.
    $lock = $pdo->prepare("SELECT status, invoice_id, total FROM pos_sales WHERE id = ? FOR UPDATE");
    $lock->execute([$saleId]);
    $now = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$now) { throw new RuntimeException('Prodej nenalezen.'); }
    if ((string)$now['status'] !== 'completed') { throw new RuntimeException('Prodej byl mezitím stornován.'); }
    if (!empty($now['invoice_id'])) { throw new RuntimeException('K prodeji už mezitím vznikla faktura.'); }

    $invoiceId = crmPosCreateInvoice(
        $pdo, $customerId, (string)$sale['sale_number'], $items, (float)$sale['total'],
        !empty($sale['order_id']) ? (int)$sale['order_id'] : null, 'sro', $buyer
    );
    if ($invoiceId <= 0) { throw new RuntimeException('Fakturu se nepodařilo vystavit.'); }

    // doklad k JIŽ PŘIJATÉ platbě: DUZP, splatnost i úhrada ke dni prodeje.
    // Pobočka se bere z PRODEJE (ne z přihlášeného) — jinak by doklad k prodeji
    // druhé prodejny skončil pod tou mojí a manažerovi by zmizel z Účetnictví.
    $pdo->prepare("UPDATE invoices
            SET date_tax = ?, date_due = ?, payment_method = ?, payment_date = ?, status = 'paid',
                branch_id = ?, notes = CONCAT(COALESCE(notes, ''), ?)
            WHERE id = ?")
        ->execute([
            $saleDay, $saleDay, $payKind, $saleDay,
            $saleBranch > 0 ? $saleBranch : null,
            ' Uhrazeno ' . $payWord . ' na pokladně dne ' . date('j. n. Y', strtotime($saleDay))
                . ', doklad ' . (string)$sale['sale_number'] . '.',
            $invoiceId,
        ]);

    // prodej a faktura patří k sobě (v Prodeji i v kase je pak vidět odkaz)
    $link = $pdo->prepare("UPDATE pos_sales SET invoice_id = ? WHERE id = ? AND invoice_id IS NULL");
    $link->execute([$invoiceId, $saleId]);
    if ($link->rowCount() !== 1) { throw new RuntimeException('K prodeji už mezitím vznikla faktura.'); }

    // Evidovaná úhrada ke dni prodeje — jinak by doklad visel v pohledávkách.
    // Platí se částka FAKTURY (přepočet položek se může o haléř lišit od kasy),
    // jinak by přepočet stavu doklad zase „odzaplatil".
    $sumQ = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
    $sumQ->execute([$invoiceId]);
    $invTotal = (float)$sumQ->fetchColumn();
    afxInvoiceAddPayment($invoiceId, $invTotal, $payKind, $saleDay, null,
        'Zaplaceno ' . $payWord . ' na pokladně (' . (string)$sale['sale_number'] . ')');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('pos_invoice_after: ' . $e->getMessage());
    $hlaska = $e instanceof RuntimeException && !($e instanceof PDOException)
        ? $e->getMessage()
        : 'Fakturu se nepodařilo vystavit (chyba databáze). Zkus to znovu.';
    paiFail($hlaska, 409);
}

$numQ = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
$numQ->execute([$invoiceId]);
$invoiceNumber = (string)($numQ->fetchColumn() ?: ('#' . $invoiceId));

crmAuditLog('invoice.create', [
    'entity_type' => 'invoice', 'entity_id' => $invoiceId, 'entity_label' => $invoiceNumber,
    'summary' => 'Dodatečná faktura ' . $invoiceNumber . ' k prodeji ' . (string)$sale['sale_number']
        . ' (' . formatMoney((float)$sale['total']) . ', ' . $payWord . ')',
]);

// ── volitelné odeslání ───────────────────────────────────────────────────
$emailed = false; $emailError = ''; $emailedTo = '';
if (!empty($in['send_email'])) {
    $to = trim((string)($in['email'] ?? ''));
    if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $emailError = 'Zadaný e-mail nemá platný tvar — faktura je vystavená, jen neodešla.';
    } else {
        [$ok, $msg, $sentTo] = crmSendInvoiceEmail($invoiceId, $to !== '' ? $to : null);
        $emailed = (bool)$ok;
        $emailedTo = (string)$sentTo;
        if (!$ok) { $emailError = (string)$msg; }
    }
}

echo json_encode([
    'ok' => true,
    'invoice_id' => $invoiceId,
    'invoice_number' => $invoiceNumber,
    'emailed' => $emailed,
    'emailed_to' => $emailedTo,
    'email_error' => $emailError,
], JSON_UNESCAPED_UNICODE);
