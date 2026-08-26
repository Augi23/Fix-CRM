<?php
/**
 * Pokladna — dokončení prodeje.
 * Vstup: JSON { csrf_token, payment (cash|card|invoice), customer_id?, note?,
 *               items: [{type: part|product|manual|order, id, qty, price, name?}] }
 * V JEDNÉ transakci: odpis skladu (atomické guardy proti souběhu dvou kas
 * i zápornému skladu) + doklad pos_sales/pos_sale_items + případná faktura.
 * Selže-li COKOLI, vrátí se celý košík — nesmí projít půlka prodeje.
 * Ceny přijímáme z košíku (možnost slevy na místě), názvy/kódy VŽDY z DB.
 * Výjimka je ruční položka mimo sklad: tam se ukládá jen serverem ověřený název.
 * Daňový režim položky se odvozuje ze STAVU zboží (grade), ne z typu položky —
 * viz afxGoodsIsUsedByGrade().
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/receipt58.php';   // afxEnsurePosCashColumns (Placeno/Vráceno)
require_once '../includes/pos_shift.php';   // převzetí pokladny — markovat smí jen držitel směny
ob_clean();
header('Content-Type: application/json; charset=utf-8');

/** Runtime pojistka ke sloupcům z migrace 040 (daňový režim + nákupní cena).
 *  Deploy nasadí kód dřív, než doběhne run_migrations.php — bez ní by první prodej
 *  po nasazení spadl na neznámý sloupec a zákazník by u kasy zůstal bez dokladu.
 *  POZOR: DDL nesmí běžet uvnitř transakce (v MySQL dělá implicitní COMMIT). */
if (!function_exists('afxEnsurePosGoodsTaxColumns')) {
    function afxEnsurePosGoodsTaxColumns(): void {
        global $pdo;
        static $done = false;
        if ($done || !isset($pdo)) return;
        $done = true;
        try {
            $add = [
                ['pos_sale_items', 'grade', "ALTER TABLE pos_sale_items ADD COLUMN grade VARCHAR(16) NULL DEFAULT NULL"],
                ['pos_sale_items', 'purchase_price', "ALTER TABLE pos_sale_items ADD COLUMN purchase_price DECIMAL(12,2) NULL DEFAULT NULL"],
                ['products', 'purchase_price', "ALTER TABLE products ADD COLUMN purchase_price DECIMAL(12,2) NULL DEFAULT NULL"],
                ['products', 'last_sold_at', "ALTER TABLE products ADD COLUMN last_sold_at DATETIME NULL DEFAULT NULL"],
            ];
            foreach ($add as [$table, $col, $ddl]) {
                if (!$pdo->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $col . "'")->fetch()) {
                    $pdo->exec($ddl);
                }
            }
            $col = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'item_type'")->fetch(PDO::FETCH_ASSOC);
            if ($col && (!str_contains((string)($col['Type'] ?? ''), "'vykup'") || !str_contains((string)($col['Type'] ?? ''), "'expense'"))) {
                $pdo->exec("ALTER TABLE pos_sale_items MODIFY COLUMN item_type ENUM('part','product','manual','order','vykup','expense') NOT NULL");
            }
        } catch (Throwable $e) { error_log('afxEnsurePosGoodsTaxColumns: ' . $e->getMessage()); }
    }
}

/** Je zboží s tímhle stavem (grade) POUŽITÉ? Rozhoduje o daňovém režimu položky:
 *  „Nový“ = běžný režim, ostatní stavy (Zánovní / A / B / C / D) = použité zboží.
 *  Prázdný nebo neznámý stav → BĚŽNÝ režim: kdybychom hádali „použité“, prodalo by
 *  se u plátce DPH nové zboží ve zvláštním režimu § 90 bez DPH = krácení daně.
 *  Opačná chyba (použitý kus v běžném režimu) stát nepoškodí, jen nás. */
if (!function_exists('afxGoodsIsUsedByGrade')) {
    function afxGoodsIsUsedByGrade(?string $grade, string $ctx = ''): bool {
        // v DB je uložený jen token stavu („A“), ale snese i celý label („A – jako nové“)
        $g = mb_strtolower(trim(explode(' ', trim((string)$grade))[0]));
        if ($g === '') {
            error_log('pos_checkout: prázdný stav zboží (' . $ctx . ') → běžný daňový režim');
            return false;
        }
        if (in_array($g, ['nový', 'novy', 'nové', 'nove', 'new'], true)) { return false; }
        if (in_array($g, ['zánovní', 'zanovni', 'a', 'b', 'c', 'd'], true)) { return true; }
        error_log('pos_checkout: neznámý stav zboží „' . $g . '" (' . $ctx . ') → běžný daňový režim');
        return false;
    }
}

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
if (!in_array($payment, ['cash', 'card', 'invoice', 'invoice_ico'], true)) {
    echo json_encode(['success' => false, 'message' => 'Vyber způsob platby.']); exit;
}
// „Faktura IČO" = doklad vystaví OSVČ majitele — jde to až po vyplnění a zapnutí
// identity v Účetnictví → Nastavení (jinak by vznikl doklad s prázdným výstavcem)
$isInvoicePay = in_array($payment, ['invoice', 'invoice_ico'], true);
if ($payment === 'invoice_ico' && !afxIcoSupplier()['ready']) {
    echo json_encode(['success' => false, 'message' => 'Faktura IČO zatím není aktivní — vyplň a zapni identitu OSVČ v Účetnictví → Nastavení.']); exit;
}
$customerId = (int)($in['customer_id'] ?? 0);
$note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
// Rezervace z e-shopu (platba při vyzvednutí): prodejka se sváže s objednávkou,
// rezervace se uvolní a objednávka se označí za vyzvednutou a zaplacenou.
$eshopOrderId = (int)($in['eshop_order_id'] ?? 0);

// Přijatá hotovost (nepovinná evidence): obsluha ji zadává u platby hotově,
// účtenka z ní tiskne Placeno/Vráceno. Prázdná hodnota = nezaznamenáno (NULL).
$cashReceived = null;
if ($payment === 'cash' && isset($in['cash_received']) && $in['cash_received'] !== '' && $in['cash_received'] !== null) {
    $cashReceived = (float)str_replace(',', '.', (string)$in['cash_received']);
    if (!is_finite($cashReceived) || $cashReceived < 0 || $cashReceived > 10000000) {
        echo json_encode(['success' => false, 'message' => 'Přijatá částka je mimo rozsah.']); exit;
    }
    $cashReceived = round($cashReceived, 2);
}

$items = $in['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    echo json_encode(['success' => false, 'message' => 'Košík je prázdný.']); exit;
}
if (count($items) > 100) {
    echo json_encode(['success' => false, 'message' => 'Příliš mnoho položek.']); exit;
}

$cart = [];
$expenseSeq = 0;
foreach ($items as $it) {
    $type = (string)($it['type'] ?? '');
    $id = (int)($it['id'] ?? 0);
    $qty = (int)($it['qty'] ?? 0);
    $price = (float)($it['price'] ?? -1);
    $manualName = '';
    if ($type === 'manual' || $type === 'expense') {
        $manualName = preg_replace('/\s+/u', ' ', trim((string)($it['name'] ?? ''))) ?? '';
        $id = 0;
    }
    // is_finite: JSON 1e999 se dekóduje na INF a prošel by testem < 0
    // 'vykup' = VÝPLATNÍ řádek (platíme MY zákazníkovi za vykoupený kus):
    // qty vždy 1, id = výkupní list (crm_documents). 'expense' = VÝDAJ Z KASY
    // (záloha zaměstnanci, drobný nákup…): volný název jako 'manual', ale cena
    // PŘÍSNĚ záporná (0 by „označkovala" výdaj, který se nestal) a qty vždy 1.
    $priceOk = $type === 'vykup'
        ? (is_finite($price) && $price <= 0 && $price >= -1000000)
        : ($type === 'expense'
            ? (is_finite($price) && $price <= -0.01 && $price >= -1000000)
            : (is_finite($price) && $price >= 0 && $price <= 1000000));
    if (!in_array($type, ['part', 'product', 'manual', 'order', 'vykup', 'expense'], true)
        || (!in_array($type, ['manual', 'expense'], true) && $id <= 0)
        || (in_array($type, ['manual', 'expense'], true) && ($manualName === '' || mb_strlen($manualName) > 255))
        || (in_array($type, ['order', 'vykup', 'expense'], true) && $qty !== 1)
        || (!in_array($type, ['order', 'vykup', 'expense'], true) && ($qty < 1 || $qty > 999))
        || !$priceOk) {
        echo json_encode(['success' => false, 'message' => 'Neplatná položka v košíku.']); exit;
    }
    $price = round($price, 2);   // stejné zaokrouhlení v total i v položkách — doklad musí sedět sám se sebou
    $key = $type === 'manual'
        ? 'manual:' . mb_strtolower($manualName) . ':' . number_format($price, 2, '.', '')
        : ($type === 'expense'
            ? 'expense:' . (++$expenseSeq)   // výdaje se NIKDY neslučují (qty musí zůstat 1)
            : $type . ':' . $id);
    if (isset($cart[$key])) {   // duplicitní řádek → sloučit (guard skladu musí vidět celkové množství)
        $cart[$key]['qty'] += $qty;
        if ($cart[$key]['qty'] > 999) {
            echo json_encode(['success' => false, 'message' => 'Neplatné množství v košíku.']); exit;
        }
    } else {
        $cart[$key] = ['type' => $type, 'id' => $id, 'qty' => $qty, 'price' => $price];
        if ($type === 'manual' || $type === 'expense') { $cart[$key]['manual_name'] = $manualName; }
    }
}
$cart = array_values($cart);
// zámky skladu brát VŽDY ve stejném pořadí — dva košíky se stejnými položkami
// v opačném pořadí by se jinak vzájemně zablokovaly (deadlock 1213)
usort($cart, static fn(array $a, array $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

$orderLines = array_values(array_filter($cart, static fn(array $l): bool => $l['type'] === 'order'));
if (count($orderLines) > 1) {
    echo json_encode(['success' => false, 'message' => 'V jednom prodeji může být jen jedna zakázka.']); exit;
}
// Zakázka na fakturu: fakturu vystaví rovnou kasa (od v3.49.0 JEDINÉ místo,
// kde se platba zakázky zaznamenává). Bez vybraného zákazníka se odběratelem
// automaticky stává klient zakázky — obsluha nemusí nic dohledávat.
if ($orderLines && $isInvoicePay && $customerId <= 0 && trim((string)($in['buyer']['name'] ?? '')) === '') {
    $oc = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
    $oc->execute([(int)$orderLines[0]['id']]);
    $customerId = (int)$oc->fetchColumn();
}

$vykupLines = array_values(array_filter($cart, static fn(array $l): bool => $l['type'] === 'vykup'));
if ($vykupLines && $isInvoicePay) {
    echo json_encode(['success' => false, 'message' => 'Výplata výkupu nejde na fakturu — hotově (u protiúčtu s doplatkem zákazníka jde i karta).']); exit;
}
// Výdaj z kasy = peníze fyzicky opouštějí zásuvku → VÝHRADNĚ hotově. Karta ani
// faktura nedávají smysl (nákup drogerie kartou do kasy nepatří) a smíchání
// s kartovým prodejem by rozhodilo pokladní knihu.
$expenseLines = array_values(array_filter($cart, static fn(array $l): bool => $l['type'] === 'expense'));
if ($expenseLines && $payment !== 'cash') {
    echo json_encode(['success' => false, 'message' => 'Výdaj z kasy (záporná položka) jde jen hotově — peníze se berou ze zásuvky.']); exit;
}
// Výdaj se NESMÍ míchat s prodejem zboží (nález prověrky): netto doklad by
// zákazníkovi „slevil" o zálohu pro zaměstnance a storno by tiše vracelo
// špatnou částku. Víc výdajů najednou je v pořádku (jeden výplatní doklad).
if ($expenseLines && count($expenseLines) !== count($cart)) {
    echo json_encode(['success' => false, 'message' => 'Výdaj z kasy markuj samostatně — do jednoho dokladu nemíchej výdaj a prodej zboží.']); exit;
}

// ── rezervace z e-shopu: co se smí vyzvednout a čím zaplatit ──
// DDL pojistky MUSÍ být před prvním dotazem na eshop_* (čerstvá DB po deployi,
// než doběhne run_migrations) — jinak by PDOException vrátila HTML 500 místo JSONu.
if ($eshopOrderId > 0) { ensureEshopOrdersTable(); ensureEshopReservationSchema(); }
$eshopOrder = null;
$eshopRes = [];   // product_id => qty (co objednávka drží rezervované)
if ($eshopOrderId > 0) {
    $eo = $pdo->prepare("SELECT id, order_ref, status, customer_name, pay_id, total FROM eshop_orders WHERE id = ?");
    $eo->execute([$eshopOrderId]);
    $eshopOrder = $eo->fetch(PDO::FETCH_ASSOC);
    if ($eshopOrder && $note === '') { $note = mb_substr('Objednávka z e-shopu ' . (string)$eshopOrder['order_ref'], 0, 255); }
    if (!$eshopOrder) {
        echo json_encode(['success' => false, 'message' => 'Objednávka z e-shopu nenalezena.'], JSON_UNESCAPED_UNICODE); exit;
    }
    if ((string)$eshopOrder['status'] !== 'reserved') {
        echo json_encode(['success' => false, 'message' => 'Objednávka ' . $eshopOrder['order_ref'] . ' už není rezervovaná (stav „' . $eshopOrder['status'] . '") — nejspíš ji právě vyřídila druhá kasa.'], JSON_UNESCAPED_UNICODE); exit;
    }
    // zboží si zákazník odnáší hned → musí být zaplacené na místě (hotově/kartou)
    if ($isInvoicePay) {
        echo json_encode(['success' => false, 'message' => 'Rezervaci z e-shopu zaplať hotově nebo kartou — zboží si zákazník odnáší hned, na fakturu by odešlo nezaplacené.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $rs = $pdo->prepare("SELECT r.product_id, r.qty, COALESCE(r.unit_price, p.price) AS unit_price
        FROM eshop_order_reservations r LEFT JOIN products p ON p.id = r.product_id
        WHERE r.order_id = ? AND r.released_at IS NULL");
    $rs->execute([$eshopOrderId]);
    $eshopPrice = 0.0;   // kolik má zákazník podle objednávky zaplatit
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $q = max(1, (int)$r['qty']);
        $eshopRes[(int)$r['product_id']] = ($eshopRes[(int)$r['product_id']] ?? 0) + $q;
        $eshopPrice += round((float)$r['unit_price'], 2) * $q;
    }
    $eshopPrice = round($eshopPrice, 2);
    if (!$eshopRes) {
        echo json_encode(['success' => false, 'message' => 'Objednávka ' . $eshopOrder['order_ref'] . ' nemá žádné rezervované kusy — vyřiď ji v administraci e-shopu.'], JSON_UNESCAPED_UNICODE); exit;
    }
    // Košík musí sedět s rezervací PŘESNĚ (jinak by zůstala viset polovina
    // rezervace a kus by nikdo nemohl prodat). Ostatní zboží mimo objednávku
    // se do stejné prodejky nemíchá — účtenka i vazba mají zůstat jednoznačné.
    $cartProd = [];
    foreach ($cart as $l) {
        if ($l['type'] !== 'product') {
            echo json_encode(['success' => false, 'message' => 'S rezervací z e-shopu markuj jen její zboží — ostatní položky prodej zvlášť.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $cartProd[(int)$l['id']] = ($cartProd[(int)$l['id']] ?? 0) + (int)$l['qty'];
    }
    // Cena je autorita OBJEDNÁVKY (zákazník platí, za co objednal). UI má pole
    // readonly, ale ručně poslaný požadavek by jinak naúčtoval třeba 1 Kč.
    $cartSum = 0.0;
    foreach ($cart as $l) { $cartSum += round((float)$l['price'], 2) * (int)$l['qty']; }
    if (abs(round($cartSum, 2) - $eshopPrice) > 0.5) {
        echo json_encode(['success' => false, 'message' => 'Účtovaná částka (' . formatMoney($cartSum)
            . ') neodpovídá objednávce ' . $eshopOrder['order_ref'] . ' (' . formatMoney($eshopPrice)
            . ') — načti rezervaci znovu tlačítkem „Rezervace e-shopu".'], JSON_UNESCAPED_UNICODE); exit;
    }
    ksort($cartProd); ksort($eshopRes);
    if ($cartProd !== $eshopRes) {
        echo json_encode(['success' => false, 'message' => 'Košík neodpovídá rezervaci ' . $eshopOrder['order_ref'] . ' — načti ji znovu tlačítkem „Rezervace e-shopu" (vyzvedává se celá).'], JSON_UNESCAPED_UNICODE); exit;
    }
}

// jednorázový odběratel (v3.59.0): faktura jde vystavit i bez karty klienta —
// údaje se uloží do cust_*_override přímo na faktuře, do CRM klientů se nic nezaloží
$buyer = afxInvoiceBuyerSanitize($in['buyer'] ?? null);
if ($isInvoicePay && $buyer['error'] !== '') {
    echo json_encode(['success' => false, 'message' => $buyer['error']], JSON_UNESCAPED_UNICODE); exit;
}
if (!$isInvoicePay) { $buyer['name'] = ''; }   // u hotovosti/karty se odběratel nikam nepropisuje

if ($isInvoicePay) {
    if ($customerId <= 0 && $buyer['name'] === '') {
        echo json_encode(['success' => false, 'message' => 'Pro platbu na fakturu vyber zákazníka, nebo vyplň jednorázového odběratele.'], JSON_UNESCAPED_UNICODE); exit;
    }
    // obojí naráz = nejasné, komu doklad patří (UI to nedovolí, API to musí hlídat taky)
    if ($customerId > 0 && $buyer['name'] !== '') {
        echo json_encode(['success' => false, 'message' => 'Vyber buď zákazníka z CRM, nebo vyplň jednorázového odběratele — ne obojí.'], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($buyer['name'] !== '') {
        // kdyby ALTER neprošel, faktura by spadla až v transakci s nicneříkající hláškou
        afxEnsureInvoiceAdhocBuyer();
        $nullOk = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC);
            $nullOk = $c && strtoupper((string)$c['Null']) === 'YES';
        } catch (Throwable $e) {}
        if (!$nullOk) {
            echo json_encode(['success' => false, 'message' => 'Databáze zatím fakturu bez klienta nepovoluje — spusť aktualizaci databáze (Nastavení → Systém → Databáze), nebo vyber zákazníka.'], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    if ($customerId > 0) {
        $cust = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
        $cust->execute([$customerId]);
        if (!$cust->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Zákazník nenalezen.']); exit;
        }
    }
}

// DDL PŘED transakcí (implicitní commit)
ensurePosTables();
ensureInventoryMovesTable();
ensureProductsTable();
ensureProductsPosColumn();
afxEnsurePosGoodsTaxColumns();
ensureSkladBranchSchema();
afxEnsurePosCashColumns();
afxEnsurePosShiftTable();
afxEnsurePosPaymentEnum();       // ENUM payment_method musí znát 'invoice_ico'
afxEnsureInvoiceSupplierColumn(); // faktura nese výstavce (sro|ico)
afxEnsureInvoiceAdhocBuyer();     // faktura bez klienta v CRM (customer_id NULL + e-mail odběratele)
ensureEshopOrdersTable();
ensureEshopReservationSchema();   // reserved_qty čte i běžný prodej (guard rezervací)
if ($vykupLines) {
    require_once __DIR__ . '/../includes/documents.php';
    ensureCrmDocumentsTable();
    ensureProductsVykupColumns();
}

// názvy/kódy položek VŽDY čerstvě z DB. U dílů/produktů zůstává možnost slevy
// v košíku; u zakázky je autoritou částka uložená u zakázky.
try {
    $saleOrderId = 0;
    $orderCustomerId = 0;
    $saleOrderBranchId = 0;
    foreach ($cart as $i => $line) {
        if ($line['type'] === 'part') {
            $st = $pdo->prepare("SELECT part_name, sku, quantity FROM inventory WHERE id = ?");
            $st->execute([$line['id']]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Díl už neexistuje.']); exit; }
            if (!crmCanModifyBranchStock(crmInventoryBranchId((int)$line['id']))) {
                echo json_encode(['success' => false, 'message' => 'Díl „' . $row['part_name'] . '" je skladem na jiné pobočce — prodat ho smí jen její zaměstnanci.']); exit;
            }
            // předběžná česká kontrola; skutečný souběh chytí atomický guard v transakci
            if ((int)$row['quantity'] < $line['qty']) {
                echo json_encode(['success' => false, 'message' => 'Dílu „' . $row['part_name'] . '" je skladem jen ' . (int)$row['quantity'] . ' ks.']); exit;
            }
            $cart[$i]['name'] = (string)$row['part_name'];
            $cart[$i]['code'] = (string)($row['sku'] ?? '');
            // díly (náhradní součástky) jdou vždy v běžném režimu — neprodávají se jako použité zboží
            $cart[$i]['used'] = false;
            $cart[$i]['grade'] = null;
            $cart[$i]['purchase_price'] = null;
        } elseif ($line['type'] === 'product') {
            $st = $pdo->prepare("SELECT title, product_code, grade, purchase_price, stock_qty, COALESCE(reserved_qty, 0) AS reserved_qty FROM products WHERE id = ?");
            $st->execute([$line['id']]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Produkt už neexistuje.']); exit; }
            // Kus rezervovaný objednávkou z e-shopu (platba při vyzvednutí) se nesmí
            // prodat někomu jinému — online zákazník by přišel k prázdnému regálu.
            // Volných kusů je stock_qty − reserved_qty; rezervaci vyzvedne jen prodej
            // svázaný s TOU objednávkou (tam se počítá celý stav).
            $resQty = (int)$row['reserved_qty'];
            if ($resQty > 0) {
                $freeQty = (int)$row['stock_qty'] - $resQty;
                $mine = $eshopOrderId > 0 ? (int)($eshopRes[(int)$line['id']] ?? 0) : 0;
                if ($line['qty'] > $freeQty + $mine) {
                    $ri = afxProductReservationInfo((int)$line['id']);
                    $refTxt = $ri ? (' ' . (string)$ri['order_ref'] . ($ri['customer_name'] ? ' (' . $ri['customer_name'] . ')' : '')) : '';
                    echo json_encode(['success' => false, 'message' => '„' . $row['title'] . '" je rezervovaný objednávkou z e-shopu' . $refTxt
                        . ' — vyzvedni ji tlačítkem „Rezervace e-shopu", nebo rezervaci nejdřív zruš.'], JSON_UNESCAPED_UNICODE); exit;
                }
            }
            if (!crmCanModifyBranchStock(crmProductBranchId((int)$line['id']))) {
                echo json_encode(['success' => false, 'message' => 'Produkt „' . $row['title'] . '" je skladem na jiné pobočce — prodat ho smí jen její zaměstnanci.']); exit;
            }
            // kus z NEVYPLACENÉHO výkupu se nesmí prodat jako běžný produkt —
            // nejdřív výplata výkupu (jinak by klient „platil" za vlastní zařízení)
            try {
                $vd = $pdo->prepare("SELECT doc_number FROM crm_documents
                    WHERE doc_type = 'vykup' AND vykup_product_id = ? AND payout_sale_id IS NULL LIMIT 1");
                $vd->execute([(int)$line['id']]);
                $vdn = $vd->fetchColumn();
                if ($vdn !== false && $vdn !== null) {
                    echo json_encode(['success' => false, 'message' => 'Kus pochází z nevyplaceného výkupu ' . $vdn . ' — nejdřív výkup vyplať (najdi ho vyhledáváním nebo přes „Výplata výkupu"), pak teprve jde prodávat.']); exit;
                }
            } catch (Throwable $e) { /* bez tabulky dokumentů se kontrola přeskočí */ }
            $grade = trim((string)($row['grade'] ?? ''));
            $cart[$i]['name'] = (string)$row['title'] . ($grade !== '' ? ' (stav ' . $grade . ')' : '');
            $cart[$i]['code'] = (string)$row['product_code'];
            // režim dle stavu zboží: „Nový“ = běžný, ostatní stavy = použité zboží (§ 90 u plátce)
            $cart[$i]['grade'] = $grade !== '' ? mb_substr($grade, 0, 16) : null;
            $cart[$i]['used'] = afxGoodsIsUsedByGrade($grade, 'produkt #' . $line['id'] . ' ' . $row['product_code']);
            // nákupní cena se ukládá NA DOKLAD — u § 90 se z ní počítá daň z přirážky
            // a v products se může kdykoli přepsat (nebo kus po prodeji zmizet)
            $cart[$i]['purchase_price'] = ($row['purchase_price'] === null || $row['purchase_price'] === '')
                ? null : round((float)$row['purchase_price'], 2);
        } elseif ($line['type'] === 'order') {
            $st = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company
                FROM orders o JOIN customers c ON c.id = o.customer_id
                WHERE o.id = ? LIMIT 1");
            $st->execute([$line['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Zakázka už neexistuje.']); exit; }
            if (!canAccessOrderBranch($row)) {
                echo json_encode(['success' => false, 'message' => 'Zakázka je na jiné pobočce — účtovat ji smí jen její zaměstnanci.']); exit;
            }
            $rowStatus = (string)($row['status'] ?? '');
            if (!isOrderStatusIn($rowStatus, 'completed') && !isOrderStatusIn($rowStatus, 'uncollected')
                && $rowStatus !== 'Vydáno - čeká na platbu') {
                echo json_encode(['success' => false, 'message' => 'Do Pokladny lze vložit jen zakázku ve stavu Připraveno k převzetí, Nevyzvednuto nebo Vydáno - čeká na platbu.']); exit;
            }
            if (trim((string)($row['payment_method'] ?? '')) !== '' || crmOrderPosSale((int)$row['id'])) {
                echo json_encode(['success' => false, 'message' => 'Zakázka už má zaznamenanou platbu nebo účtenku.']); exit;
            }
            // existující faktura = pohledávka už běží (vystavená předem z detailu
            // zakázky) — druhý doklad by tržbu zdvojil; úhrada patří k té faktuře
            $iv = $pdo->prepare("SELECT invoice_number FROM invoices WHERE order_id = ? AND status <> 'cancelled' LIMIT 1");
            $iv->execute([(int)$row['id']]);
            if (($ivNum = $iv->fetchColumn()) !== false && $ivNum !== null) {
                echo json_encode(['success' => false, 'message' => 'K zakázce už existuje faktura ' . $ivNum . ' — úhradu (i hotově/kartou) k ní zapiš v Účetnictví → Banka, nebo fakturu nejdřív stornuj.']); exit;
            }
            $amount = crmOrderPosAmount($row);
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Zakázka nemá částku k úhradě.']); exit;
            }
            $cart[$i]['name'] = crmOrderPosItemName($row);
            $cart[$i]['code'] = orderDisplayCode($row);
            $cart[$i]['qty'] = 1;
            $cart[$i]['price'] = $amount;
            $cart[$i]['used'] = false;
            $cart[$i]['grade'] = null;
            $cart[$i]['purchase_price'] = null;
            $saleOrderId = (int)$row['id'];
            $orderCustomerId = (int)$row['customer_id'];
            $saleOrderBranchId = (int)($row['branch_id'] ?? 0);
        } elseif ($line['type'] === 'vykup') {
            // Výplata výkupu: autoritou je VÝKUPNÍ LIST; vyplácenou částku zadává
            // obsluha na kase (výchozí = cena z listu). Kus do skladu založil
            // už dokument (crmSyncVykupProduct) — tady se jen vyplácí.
            $st = $pdo->prepare("SELECT id, doc_number, subject, price, branch_id, payout_sale_id FROM crm_documents WHERE id = ? AND doc_type = 'vykup'");
            $st->execute([$line['id']]);
            $row = $st->fetch();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Výkupní list nenalezen.']); exit; }
            if ((int)($row['payout_sale_id'] ?? 0) > 0) {
                echo json_encode(['success' => false, 'message' => 'Výkup ' . $row['doc_number'] . ' už je vyplacený prodejkou — podruhé vyplatit nejde.']); exit;
            }
            $docBranch = (int)($row['branch_id'] ?? 0) ?: (int)getDefaultBranchId();
            if (!crmCanModifyBranchStock($docBranch)) {
                echo json_encode(['success' => false, 'message' => 'Výkup ' . $row['doc_number'] . ' patří jiné pobočce — vyplácí ho její kasa.']); exit;
            }
            // starší listy (z doby před funkcí) ještě nemusí mít produkt — doplnit
            try { crmSyncVykupProduct((int)$row['id']); } catch (Throwable $e) {}
            if (abs((float)$cart[$i]['price']) < 0.005) {
                $docAmount = crmParseAmountCzk((string)($row['price'] ?? ''));
                if ($docAmount > 0) { $cart[$i]['price'] = -round($docAmount, 2); }
            }
            // nulová výplata = zapomenutá částka (starší list bez ceny) — nesmí projít,
            // list by se označil za vyplacený a klient by nedostal nic
            if (abs((float)$cart[$i]['price']) < 0.005) {
                echo json_encode(['success' => false, 'message' => 'Výkup ' . $row['doc_number'] . ' má nulovou částku — uprav cenu v košíku, nebo doplň výkupní částku do listu.']); exit;
            }
            $subject = trim((string)($row['subject'] ?? ''));
            $cart[$i]['name'] = 'Výplata výkupu ' . $row['doc_number'] . ($subject !== '' ? ' — ' . mb_substr($subject, 0, 170) : '');
            $cart[$i]['code'] = (string)$row['doc_number'];
            $cart[$i]['qty'] = 1;
            $cart[$i]['used'] = false;
            $cart[$i]['grade'] = null;
            $cart[$i]['purchase_price'] = null;
        } else {
            // Ruční položka mimo sklad ('manual') i výdaj z kasy ('expense'):
            // neváže se na inventory/products a nijak nehýbe skladem.
            $cart[$i]['name'] = (string)$line['manual_name'];
            $cart[$i]['code'] = '';
            $cart[$i]['used'] = false;
            $cart[$i]['grade'] = null;
            $cart[$i]['purchase_price'] = null;
        }
    }
    // Klient zakázky doplní odběratele JEN když obsluha žádného nevybrala —
    // výslovně vybraný zákazník (např. firma platící opravu zaměstnance) má
    // přednost. Bezpodmínečný přepis tu do v3.49.0 tiše měnil odběratele faktury.
    // ...a nedosazuje se vůbec, když obsluha vyplnila jednorázového odběratele:
    // faktura by pak nesla jeho jméno, ale IČO a adresu klienta zakázky, objevila
    // by se klientovi v portálu a e-mailem by odešla jemu (nález prověrky 25.8.).
    if ($orderCustomerId > 0 && $customerId <= 0 && $buyer['name'] === '') { $customerId = $orderCustomerId; }

    $total = 0.0;
    foreach ($cart as $line) { $total += $line['price'] * $line['qty']; }
    $total = round($total, 2);
    if ($total > 9999999 || $total < -9999999) {
        echo json_encode(['success' => false, 'message' => 'Částka je mimo rozsah.']); exit;
    }
    // Záporný součet = fyzicky VYPLÁCÍME zákazníkovi → jde jen z hotovosti kasy.
    // (Protiúčet s kladným doplatkem může být klidně kartou.)
    if ($total < 0 && $payment !== 'cash') {
        echo json_encode(['success' => false, 'message' => 'Součet je záporný (vyplácíš zákazníkovi ' . formatMoney(abs($total)) . ') — to jde jen hotově.']); exit;
    }

    // přijatá hotovost nesmí být nižší než celkem — jinak by „Vráceno" vyšlo záporně
    // výplata / vyrovnaný protiúčet: „přijato" nedává smysl — zbytek z opuštěného
    // LCD by nafoukl Vráceno (přijato − záporný total) a obsluha by vydala víc
    if ($total <= 0) { $cashReceived = null; }
    $cashChange = null;
    if ($cashReceived !== null) {
        if ($cashReceived + 0.005 < $total) {
            echo json_encode(['success' => false, 'message' => 'Přijatá částka (' . number_format($cashReceived, 0, ',', ' ') . ' Kč) je nižší než celkem k úhradě.']); exit;
        }
        $cashChange = round($cashReceived - $total, 2);
    }

    // POKLADNU SMÍ OBSLUHOVAT JEN TEN, KDO JI MÁ PŘEVZATOU (pos_shifts) —
    // v jednu chvíli jediný pracovník na pobočku. Kontrola PŘED transakcí.
    afxPosShiftAssertMine((int)getCurrentStaffBranchId());

    // UZÁVĚRKA OBDOBÍ: doklad kasy vzniká k dnešnímu dni — když je aktuální měsíc
    // účetně uzavřený (typicky konec roku uzavřený se zpožděním), prodej nesmí
    // projít, jinak by se tržby rozešly s odevzdaným přiznáním. Kontrola PŘED
    // transakcí (žádný odpis skladu nazmar); RuntimeException doputuje do catch
    // níže a uživatel u kasy dostane srozumitelnou hlášku, ne „databázovou chybu".
    afxAccountingAssertOpen(date('Y-m-d'), 'prodej na kase');

    $pdo->beginTransaction();

    // Zakázka v košíku: zamknout řádek a znovu ověřit, že ji mezitím nezaplatila
    // druhá kasa nebo výdej z detailu zakázky.
    if ($saleOrderId > 0) {
        ensureOrderPaymentMethodColumn();
        $ol = $pdo->prepare("SELECT id, payment_method FROM orders WHERE id = ? FOR UPDATE");
        $ol->execute([$saleOrderId]);
        $lockedOrder = $ol->fetch(PDO::FETCH_ASSOC);
        if (!$lockedOrder) { throw new Exception('Zakázka už neexistuje.'); }
        if (trim((string)($lockedOrder['payment_method'] ?? '')) !== '' || crmOrderPosSale($saleOrderId)) {
            throw new Exception('Zakázka už má zaznamenanou platbu nebo účtenku.');
        }
        $ivl = $pdo->prepare("SELECT invoice_number FROM invoices WHERE order_id = ? AND status <> 'cancelled' LIMIT 1");
        $ivl->execute([$saleOrderId]);
        if (($ivlNum = $ivl->fetchColumn()) !== false && $ivlNum !== null) {
            throw new Exception('K zakázce mezitím vznikla faktura ' . $ivlNum . ' — úhradu zapiš k ní v Účetnictví.');
        }
    }

    // ── rezervace z e-shopu: zámek objednávky JAKO PRVNÍ ──
    // Ruční akce (zrušení/expedice) zamykají eshop_orders → products; kasa musí
    // držet stejné pořadí, jinak vzniká deadlock 1213 při souběhu.
    if ($eshopOrderId > 0) {
        $lk = $pdo->prepare("SELECT id, order_ref, status FROM eshop_orders WHERE id = ? FOR UPDATE");
        $lk->execute([$eshopOrderId]);
        $lockedEshop = $lk->fetch(PDO::FETCH_ASSOC);
        if (!$lockedEshop || (string)$lockedEshop['status'] !== 'reserved') {
            throw new Exception('Objednávku z e-shopu mezitím vyřídila jiná kasa nebo ji někdo zrušil — prodej neproběhl.');
        }
    }

    // ── odpis skladu (atomicky, guard proti souběhu/zápornému stavu) ──
    foreach ($cart as $line) {
        if ($line['type'] === 'part') {
            changeInventoryQuantity($line['id'], -$line['qty']);   // hází výjimku při nedostatku
        } elseif ($line['type'] === 'product') {
            // pos_sold_at = „kus je CELÝ vyprodaný přes kasu" → nastavit jen když stav
            // klesl na nulu (SET se vyhodnocuje zleva, IF už vidí odečtenou hodnotu).
            // Částečný prodej vícekusového příslušenství flag nesmí zapnout — import
            // by pak zbývající skutečné kusy vynuloval.
            // Guard počítá DOSTUPNOST (sklad − rezervace) a k ní přičítá jen tu
            // rezervaci, kterou tenhle prodej vyzvedává ($mine). Bez toho by mezi
            // předtransakční kontrolou a tímhle odpisem stihla vzniknout objednávka
            // z e-shopu a kus by se prodal dvakrát (dostupnost by šla do mínusu).
            $mineQty = $eshopOrderId > 0 ? (int)($eshopRes[(int)$line['id']] ?? 0) : 0;
            $u = $pdo->prepare("UPDATE products
                SET stock_qty = stock_qty - ?, pos_sold_at = IF(stock_qty = 0, NOW(), pos_sold_at),
                    last_sold_at = NOW()
                WHERE id = ? AND stock_qty >= ? AND stock_qty - COALESCE(reserved_qty, 0) + ? >= ?");
            $u->execute([$line['qty'], $line['id'], $line['qty'], $mineQty, $line['qty']]);
            if ($u->rowCount() === 0) {
                throw new Exception('„' . $line['name'] . '" už není volný — mezitím ho prodala druhá kasa nebo zarezervoval e-shop.');
            }
        }
    }

    // ── doklad (UNIQUE číslo + retry proti souběhu dvou kas) ──
    // $try jde do čísla jako posun: pod REPEATABLE READ čte opakovaný SELECT
    // tentýž snapshot, samotné opakování by vracelo identické (kolidující) číslo.
    $branchId = $saleOrderBranchId > 0 ? $saleOrderBranchId : (int)getCurrentStaffBranchId();
    $seller = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    // DPH snapshot dle VÝSTAVCE prodeje: „Faktura IČO" prodává OSVČ — účtenka
    // musí nést STEJNÝ režim jako faktura vystavená v téže transakci
    $isVat = $payment === 'invoice_ico'
        ? get_setting('ico_supplier_is_vat', '0') == '1'
        : get_setting('acc_is_vat_payer', '0') == '1';
    $vatRate = (float)get_setting('acc_vat_rate', '21');
    $insSale = $pdo->prepare("INSERT INTO pos_sales
            (sale_number, branch_id, seller_name, customer_id, order_id, payment_method, total, vat_rate, is_vat_payer, note,
             cash_received, cash_change)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $saleId = 0; $saleNumber = '';
    for ($try = 0; $try < 6; $try++) {
        $saleNumber = generatePosSaleNumber($pdo, $try);
        try {
            $insSale->execute([$saleNumber, $branchId ?: null, $seller,
                $customerId > 0 ? $customerId : null, $saleOrderId > 0 ? $saleOrderId : null, $payment, $total,
                $isVat ? $vatRate : 0, $isVat ? 1 : 0, $note !== '' ? $note : null,
                $cashReceived, $cashChange]);
            $saleId = (int)$pdo->lastInsertId();
            break;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        }
    }
    if ($saleId <= 0) { throw new Exception('Nepodařilo se přidělit číslo dokladu — zkus to znovu.'); }

    $insItem = $pdo->prepare("INSERT INTO pos_sale_items
            (sale_id, item_type, item_id, item_name, item_code, quantity, unit_price, is_used_goods,
             grade, purchase_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($cart as $line) {
        $insItem->execute([$saleId, $line['type'], $line['id'], mb_substr($line['name'], 0, 255),
            mb_substr($line['code'], 0, 64) ?: null, $line['qty'], round($line['price'], 2), $line['used'] ? 1 : 0,
            $line['grade'], $line['purchase_price']]);
    }

    // ── výplata výkupu: provázat výkupní list s prodejkou (blokuje dvojí
    // výplatu a vypíná dokumentový výdej z kasy — peníze teď drží prodejka) ──
    foreach ($cart as $line) {
        if ($line['type'] !== 'vykup') { continue; }
        $lk = $pdo->prepare("UPDATE crm_documents SET payout_sale_id = ? WHERE id = ? AND doc_type = 'vykup' AND payout_sale_id IS NULL");
        $lk->execute([$saleId, $line['id']]);
        if ($lk->rowCount() === 0) {
            throw new Exception('Výkup ' . $line['code'] . ' právě vyplatila jiná kasa — prodej neproběhl.');
        }
    }

    // ── rezervace z e-shopu je vyzvednutá a zaplacená ──
    // Sklad odečetl běžný produktový průchod výš; tady se jen uvolní rezervace
    // (reserved_qty zpět dolů) a objednávka se sváže s prodejkou.
    if ($eshopOrderId > 0) {
        $rel = $pdo->prepare("UPDATE products SET reserved_qty = GREATEST(COALESCE(reserved_qty, 0) - ?, 0) WHERE id = ?");
        foreach ($eshopRes as $pid => $q) { $rel->execute([$q, $pid]); }
        $pdo->prepare("UPDATE eshop_order_reservations SET released_at = NOW() WHERE order_id = ? AND released_at IS NULL")
            ->execute([$eshopOrderId]);
        $finEshop = $pdo->prepare("UPDATE eshop_orders SET status = 'collected', pos_sale_id = ?, collected_at = NOW()
            WHERE id = ? AND status = 'reserved'");
        $finEshop->execute([$saleId, $eshopOrderId]);
        if ($finEshop->rowCount() === 0) {
            throw new Exception('Objednávku z e-shopu se nepodařilo označit za vyzvednutou — prodej neproběhl.');
        }
    }

    // ── platba na fakturu → rovnou vystavit fakturu (stejná transakce) ──
    $invoiceId = null;
    if ($isInvoicePay) {
        $invItems = array_map(static fn($l) => ['name' => $l['name'], 'qty' => $l['qty'],
            'unit_price' => $l['price'], 'used' => $l['used']], $cart);
        $invoiceId = crmPosCreateInvoice($pdo, $customerId, $saleNumber, $invItems, $total, $saleOrderId > 0 ? $saleOrderId : null, $payment === 'invoice_ico' ? 'ico' : 'sro', $buyer);
        $pdo->prepare("UPDATE pos_sales SET invoice_id = ? WHERE id = ?")->execute([$invoiceId, $saleId]);
    }

    if ($saleOrderId > 0) {
        $orderPayment = ['card' => 'card', 'invoice' => 'transfer', 'invoice_ico' => 'transfer'][$payment] ?? 'cash';
        $up = $pdo->prepare("UPDATE orders SET payment_method = ? WHERE id = ? AND (payment_method IS NULL OR payment_method = '')");
        $up->execute([$orderPayment, $saleOrderId]);
        if ($up->rowCount() === 0) {
            throw new Exception('Zakázka už má mezitím zaznamenanou platbu.');
        }
        // doplatek po výdeji: zaplacením se „Vydáno - čeká na platbu" dorovná na plné Vydáno
        $fin = $pdo->prepare("UPDATE orders SET status = 'Vydáno', updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'Vydáno - čeká na platbu'");
        $fin->execute([$saleOrderId]);
        if ($fin->rowCount() > 0) {
            logOrderStatusChange($saleOrderId, 'Vydáno - čeká na platbu', 'Vydáno');
        }
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
    } elseif ($line['type'] === 'product') {
        $hasProduct = true;
    }
}
// výplaty výkupů: audit + přepočet dokumentových pohybů kasy (payout_sale_id je
// nastavené → případný starší „výdej z kasy" od dokumentu se stáhne, peníze
// teď oficiálně drží tahle prodejka)
foreach ($cart as $line) {
    if ($line['type'] !== 'vykup') { continue; }
    crmAuditLog('kasa.vykup_payout', [
        'entity_type' => 'pos_sale', 'entity_id' => $saleId, 'entity_label' => $saleNumber,
        'summary' => 'Vyplacen výkup ' . $line['code'] . ': ' . formatMoney(abs((float)$line['price'])) . ' (doklad ' . $saleNumber . ')',
        'branch_id' => $branchId,
    ]);
    try { crmSyncVykupCashMovement((int)$line['id']); }
    catch (Throwable $e) { error_log('pos_checkout vykup sync: ' . $e->getMessage()); }
}
// výdaje z kasy: každý zvlášť do auditu — u peněz ze zásuvky musí být dohledatelné
// kdo, kdy, kolik a na co (záloha zaměstnanci, drobný nákup…)
foreach ($cart as $line) {
    if ($line['type'] !== 'expense') { continue; }
    crmAuditLog('kasa.expense', [
        'entity_type' => 'pos_sale', 'entity_id' => $saleId, 'entity_label' => $saleNumber,
        'summary' => 'Výdaj z kasy: ' . $line['name'] . ' — ' . formatMoney(abs((float)$line['price'])) . ' (doklad ' . $saleNumber . ')',
        'branch_id' => $branchId,
    ]);
}
if ($eshopOrderId > 0 && $eshopOrder) {
    crmAuditLog('kasa.eshop_pickup', [
        'entity_type' => 'pos_sale', 'entity_id' => $saleId, 'entity_label' => $saleNumber,
        'summary' => 'Vyzvednuta a zaplacena objednávka z e-shopu ' . $eshopOrder['order_ref']
            . ' (účtováno ' . formatMoney($total) . ', objednáno ' . formatMoney((float)($eshopOrder['total'] ?? 0))
            . ', doklad ' . $saleNumber . ')',
        'branch_id' => $branchId,
    ]);
}
$payLabel = ['cash' => 'hotově', 'card' => 'kartou', 'invoice' => 'na fakturu (s.r.o.)', 'invoice_ico' => 'na fakturu (IČO)'][$payment];
crmAuditLog('kasa.sale', [
    'entity_type' => 'pos_sale', 'entity_id' => $saleId, 'entity_label' => $saleNumber,
    'summary' => 'Prodej ' . count($cart) . ' pol. za ' . formatMoney($total) . ' (' . $payLabel . ')'
        . ($invoiceId ? ' — faktura ' . (string)($pdo->query("SELECT invoice_number FROM invoices WHERE id = " . (int)$invoiceId)->fetchColumn() ?: $invoiceId) : '')
        . ($buyer['name'] !== '' ? ', odběratel ' . $buyer['name'] . ($buyer['ico'] !== '' ? ' (IČO ' . $buyer['ico'] . ')' : '') : ''),
    'branch_id' => $branchId,
]);
if (!empty($saleOrderId)) {
    crmAuditLog('order.payment_set', [
        'entity_type' => 'order', 'entity_id' => (int)$saleOrderId,
        'summary' => 'Zakázka uhrazena přes Pokladnu dokladem ' . $saleNumber . ' (' . $payLabel . ', ' . formatMoney($total) . ')',
        'branch_id' => $branchId,
    ]);
}

// má faktura kam odejít? UI podle toho poradí tisk místo mailu
$invoiceMail = '';
if ($invoiceId) {
    $invoiceMail = $buyer['name'] !== '' ? trim((string)$buyer['email']) : '';
    if ($invoiceMail === '' && $customerId > 0) {
        try {
            $em = $pdo->prepare("SELECT email FROM customers WHERE id = ?");
            $em->execute([$customerId]);
            $invoiceMail = trim((string)$em->fetchColumn());
        } catch (Throwable $e) { $invoiceMail = ''; }
    }
    if (!filter_var($invoiceMail, FILTER_VALIDATE_EMAIL)) { $invoiceMail = ''; }
}

echo json_encode([
    'success' => true,
    'sale_id' => $saleId,
    'sale_number' => $saleNumber,
    'order_id' => !empty($saleOrderId) ? (int)$saleOrderId : null,
    'invoice_id' => $invoiceId,
    'invoice_mail' => $invoiceMail,   // '' = faktura nemá kam odejít → nabídnout tisk
    'total' => round($total, 2),
    'cash_change' => $cashChange,   // kolik vrátit zákazníkovi (jen hotově s evidencí)
    'has_product' => $hasProduct,   // UI připomene vyřadit kus v naskladňovací appce
]);
