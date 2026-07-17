<?php
/**
 * Pokladna — dokončení prodeje.
 * Vstup: JSON { csrf_token, payment (cash|card|invoice), customer_id?, note?,
 *               items: [{type: part|product, id, qty, price}] }
 * V JEDNÉ transakci: odpis skladu (atomické guardy proti souběhu dvou kas
 * i zápornému skladu) + doklad pos_sales/pos_sale_items + případná faktura.
 * Selže-li COKOLI, vrátí se celý košík — nesmí projít půlka prodeje.
 * Ceny přijímáme z košíku (možnost slevy na místě), názvy/kódy VŽDY z DB.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!crmCanUsePos()) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'message' => 'Neplatný požadavek.']); exit;
}
if (!validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$payment = (string)($in['payment'] ?? '');
if (!in_array($payment, ['cash', 'card', 'invoice'], true)) {
    echo json_encode(['success' => false, 'message' => 'Vyber způsob platby.']); exit;
}
$customerId = (int)($in['customer_id'] ?? 0);
$note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);

$items = $in['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    echo json_encode(['success' => false, 'message' => 'Košík je prázdný.']); exit;
}
if (count($items) > 100) {
    echo json_encode(['success' => false, 'message' => 'Příliš mnoho položek.']); exit;
}

$cart = [];
foreach ($items as $it) {
    $type = (string)($it['type'] ?? '');
    $id = (int)($it['id'] ?? 0);
    $qty = (int)($it['qty'] ?? 0);
    $price = (float)($it['price'] ?? -1);
    // is_finite: JSON 1e999 se dekóduje na INF a prošel by testem < 0
    if (!in_array($type, ['part', 'product'], true) || $id <= 0 || $qty < 1 || $qty > 999
        || !is_finite($price) || $price < 0 || $price > 1000000) {
        echo json_encode(['success' => false, 'message' => 'Neplatná položka v košíku.']); exit;
    }
    $price = round($price, 2);   // stejné zaokrouhlení v total i v položkách — doklad musí sedět sám se sebou
    $key = $type . ':' . $id;
    if (isset($cart[$key])) {   // duplicitní řádek → sloučit (guard skladu musí vidět celkové množství)
        $cart[$key]['qty'] += $qty;
    } else {
        $cart[$key] = ['type' => $type, 'id' => $id, 'qty' => $qty, 'price' => $price];
    }
}
$cart = array_values($cart);
// zámky skladu brát VŽDY ve stejném pořadí — dva košíky se stejnými položkami
// v opačném pořadí by se jinak vzájemně zablokovaly (deadlock 1213)
usort($cart, static fn(array $a, array $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

if ($payment === 'invoice') {
    if ($customerId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Pro platbu na fakturu vyber zákazníka.']); exit;
    }
    $cust = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
    $cust->execute([$customerId]);
    if (!$cust->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Zákazník nenalezen.']); exit;
    }
}

// DDL PŘED transakcí (implicitní commit)
ensurePosTables();
ensureInventoryMovesTable();
ensureProductsTable();
ensureProductsPosColumn();

// názvy/kódy položek VŽDY čerstvě z DB — klientovi nevěříme nic než id/qty/cenu
try {
    foreach ($cart as $i => $line) {
        if ($line['type'] === 'part') {
            $st = $pdo->prepare("SELECT part_name, sku, quantity FROM inventory WHERE id = ?");
            $st->execute([$line['id']]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Díl už neexistuje.']); exit; }
            // předběžná česká kontrola; skutečný souběh chytí atomický guard v transakci
            if ((int)$row['quantity'] < $line['qty']) {
                echo json_encode(['success' => false, 'message' => 'Dílu „' . $row['part_name'] . '" je skladem jen ' . (int)$row['quantity'] . ' ks.']); exit;
            }
            $cart[$i]['name'] = (string)$row['part_name'];
            $cart[$i]['code'] = (string)($row['sku'] ?? '');
            $cart[$i]['used'] = false;
        } else {
            $st = $pdo->prepare("SELECT title, product_code, grade FROM products WHERE id = ?");
            $st->execute([$line['id']]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Produkt už neexistuje.']); exit; }
            $cart[$i]['name'] = (string)$row['title'] . (!empty($row['grade']) ? ' (stav ' . $row['grade'] . ')' : '');
            $cart[$i]['code'] = (string)$row['product_code'];
            $cart[$i]['used'] = true;   // §90 — použité zboží
        }
    }

    $total = 0.0;
    foreach ($cart as $line) { $total += $line['price'] * $line['qty']; }
    $total = round($total, 2);
    if ($total > 9999999) {
        echo json_encode(['success' => false, 'message' => 'Částka je mimo rozsah.']); exit;
    }

    $pdo->beginTransaction();

    // ── odpis skladu (atomicky, guard proti souběhu/zápornému stavu) ──
    foreach ($cart as $line) {
        if ($line['type'] === 'part') {
            changeInventoryQuantity($line['id'], -$line['qty']);   // hází výjimku při nedostatku
        } else {
            // pos_sold_at = „kus je CELÝ vyprodaný přes kasu" → nastavit jen když stav
            // klesl na nulu (SET se vyhodnocuje zleva, IF už vidí odečtenou hodnotu).
            // Částečný prodej vícekusového příslušenství flag nesmí zapnout — import
            // by pak zbývající skutečné kusy vynuloval.
            $u = $pdo->prepare("UPDATE products
                SET stock_qty = stock_qty - ?, pos_sold_at = IF(stock_qty = 0, NOW(), pos_sold_at)
                WHERE id = ? AND stock_qty >= ?");
            $u->execute([$line['qty'], $line['id'], $line['qty']]);
            if ($u->rowCount() === 0) {
                throw new Exception('„' . $line['name'] . '" už není skladem — nejspíš ho právě prodala druhá kasa.');
            }
        }
    }

    // ── doklad (UNIQUE číslo + retry proti souběhu dvou kas) ──
    // $try jde do čísla jako posun: pod REPEATABLE READ čte opakovaný SELECT
    // tentýž snapshot, samotné opakování by vracelo identické (kolidující) číslo.
    $branchId = (int)getCurrentStaffBranchId();
    $seller = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $isVat = get_setting('acc_is_vat_payer', '0') == '1';
    $vatRate = (float)get_setting('acc_vat_rate', '21');
    $insSale = $pdo->prepare("INSERT INTO pos_sales
            (sale_number, branch_id, seller_name, customer_id, payment_method, total, vat_rate, is_vat_payer, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $saleId = 0; $saleNumber = '';
    for ($try = 0; $try < 6; $try++) {
        $saleNumber = generatePosSaleNumber($pdo, $try);
        try {
            $insSale->execute([$saleNumber, $branchId ?: null, $seller,
                $customerId > 0 ? $customerId : null, $payment, $total,
                $isVat ? $vatRate : 0, $isVat ? 1 : 0, $note !== '' ? $note : null]);
            $saleId = (int)$pdo->lastInsertId();
            break;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        }
    }
    if ($saleId <= 0) { throw new Exception('Nepodařilo se přidělit číslo dokladu — zkus to znovu.'); }

    $insItem = $pdo->prepare("INSERT INTO pos_sale_items
            (sale_id, item_type, item_id, item_name, item_code, quantity, unit_price, is_used_goods)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($cart as $line) {
        $insItem->execute([$saleId, $line['type'], $line['id'], mb_substr($line['name'], 0, 255),
            mb_substr($line['code'], 0, 64) ?: null, $line['qty'], round($line['price'], 2), $line['used'] ? 1 : 0]);
    }

    // ── platba na fakturu → rovnou vystavit fakturu (stejná transakce) ──
    $invoiceId = null;
    if ($payment === 'invoice') {
        $invItems = array_map(static fn($l) => ['name' => $l['name'], 'qty' => $l['qty'],
            'unit_price' => $l['price'], 'used' => $l['used']], $cart);
        $invoiceId = crmPosCreateInvoice($pdo, $customerId, $saleNumber, $invItems, $total);
        $pdo->prepare("UPDATE pos_sales SET invoice_id = ? WHERE id = ?")->execute([$invoiceId, $saleId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('pos_checkout: ' . $e->getMessage());
    // klientovi jen srozumitelnou češtinu — syrové PDO texty vynášejí SQL detaily
    $msg = $e->getMessage();
    if ($e instanceof PDOException) {
        $msg = (int)($e->errorInfo[1] ?? 0) === 1213
            ? 'Dvě kasy se potkaly na stejném zboží — zkus prodej znovu.'
            : 'Databázová chyba — prodej neproběhl, zkus to znovu.';
    } elseif (str_starts_with($msg, 'Not enough stock')) {
        $msg = 'Zboží mezitím došlo — obnov vyhledávání a zkus to znovu.';
    }
    echo json_encode(['success' => false, 'message' => $msg]); exit;
}

// ── po commitu: skladový deník + audit (best-effort, prodej už neshodí) ──
$hasProduct = false;
foreach ($cart as $line) {
    if ($line['type'] === 'part') {
        crmLogInventoryMove($line['id'], -$line['qty'], 'sale', null, 'Kasa ' . $saleNumber);
    } else {
        $hasProduct = true;
    }
}
$payLabel = ['cash' => 'hotově', 'card' => 'kartou', 'invoice' => 'na fakturu'][$payment];
crmAuditLog('kasa.sale', [
    'entity_type' => 'pos_sale', 'entity_id' => $saleId, 'entity_label' => $saleNumber,
    'summary' => 'Prodej ' . count($cart) . ' pol. za ' . formatMoney($total) . ' (' . $payLabel . ')',
    'branch_id' => $branchId,
]);

echo json_encode([
    'success' => true,
    'sale_id' => $saleId,
    'sale_number' => $saleNumber,
    'invoice_id' => $invoiceId,
    'total' => round($total, 2),
    'has_product' => $hasProduct,   // UI připomene vyřadit kus v naskladňovací appce
]);
