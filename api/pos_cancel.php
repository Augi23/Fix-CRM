<?php
/**
 * Storno prodeje na kase — JEN vedení (admin/Boss). Plná vratka celého dokladu:
 * vrátí zboží na sklad, označí doklad jako stornovaný, případnou fakturu zruší.
 *
 * POKLADNÍ KNIHA: u hotovostního prodeje se NEsmí historie přepsat zpětně —
 * peníze v den prodeje v zásuvce byly (uzavřený den knihy musí dál sedět)
 * a fyzicky odcházejí až teď, při vrácení zákazníkovi. Proto se v den storna
 * založí VÝDAJOVÝ pohyb (purpose 'storno', vazba na prodej) + VPD; kniha pak
 * prodej dál počítá jako příjem v den prodeje a výdej ukáže v den storna.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/cash_book.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!crmCanUsePos()) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanCancelPosSale()) {
    echo json_encode(['success' => false, 'message' => 'Storno smí jen vedení (admin, Boss).']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Chybí doklad.']); exit;
}

ensurePosTables();
ensureInventoryMovesTable();
ensureProductsPosColumn();
ensureEshopOrdersTable();
ensureEshopReservationSchema();
// DDL pojistky PŘED transakcí — uvnitř by CREATE/ALTER udělal implicitní COMMIT.
ensurePosCashMovementsTable();
afxEnsureCashBookTables();

// UZÁVĚRKA: storno mění tržby dne PRODEJE (sestava Tržby z pokladny čte stav
// dokladu) a zakládá kompenzační pohyb k DNEŠKU — oba měsíce musí být otevřené.
if (function_exists('afxAccountingAssertOpen')) {
    try {
        $dv = $pdo->prepare("SELECT DATE(created_at) FROM pos_sales WHERE id = ?");
        $dv->execute([$id]);
        $dvDate = (string)$dv->fetchColumn();
        if ($dvDate !== '') { afxAccountingAssertOpen($dvDate, 'prodej z pokladny'); }
        afxAccountingAssertOpen(date('Y-m-d'), 'storno prodeje');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
    }
}

$stornoMoveId = 0;
try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM pos_sales WHERE id = ? FOR UPDATE");
    $st->execute([$id]);
    $sale = $st->fetch();
    if (!$sale) { throw new Exception('Doklad nenalezen.'); }
    if ((string)$sale['status'] === 'cancelled') { throw new Exception('Doklad už je stornovaný.'); }

    // Zaplacenou fakturu storno kasy rušit nesmí — na to je dobropis v Účetnictví.
    // Když ale dobropis UŽ existuje, storno se pustit musí: od v3.71.0 vzniká
    // k prodeji kartou dodatečná faktura rovnou jako zaplacená, takže bez téhle
    // výjimky by takový prodej nešlo stornovat ani po vrácení peněz.
    if (!empty($sale['invoice_id'])) {
        $ist = $pdo->prepare("SELECT status FROM invoices WHERE id = ?");
        $ist->execute([(int)$sale['invoice_id']]);
        if ((string)$ist->fetchColumn() === 'paid') {
            $dob = $pdo->prepare("SELECT COUNT(*) FROM invoices
                WHERE parent_id = ? AND COALESCE(invoice_type, 'invoice') = 'credit_note'
                  AND status <> 'cancelled'");
            $dob->execute([(int)$sale['invoice_id']]);
            if ((int)$dob->fetchColumn() === 0) {
                throw new Exception('Faktura k prodeji je už zaplacená — vystav k ní nejdřív dobropis v Účetnictví, pak teprve storno.');
            }
        }
    }

    $it = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ?");
    $it->execute([$id]);
    $items = $it->fetchAll();

    $missingParts = [];
    foreach ($items as $line) {
        if ((string)$line['item_type'] === 'part') {
            // díl mohl být mezitím smazán ze skladu — storno kvůli tomu nesmí navždy zamrznout
            $chk = $pdo->prepare("SELECT id FROM inventory WHERE id = ?");
            $chk->execute([(int)$line['item_id']]);
            if ($chk->fetch()) {
                changeInventoryQuantity((int)$line['item_id'], (int)$line['quantity']);
            } else {
                $missingParts[] = (string)$line['item_name'];
            }
        } elseif ((string)$line['item_type'] === 'product') {
            // vrácení produktu: zpět skladem, flag kasy pryč (kus je zase v appce pravdivě skladem)
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ?, pos_sold_at = NULL WHERE id = ?")
                ->execute([(int)$line['quantity'], (int)$line['item_id']]);
        } elseif ((string)$line['item_type'] === 'vykup') {
            // storno výplaty výkupu: odvázat výkupní list (jde pak vyplatit znovu);
            // vrácenou hotovost řeší kompenzační pohyb níže
            try {
                $pdo->prepare("UPDATE crm_documents SET payout_sale_id = NULL WHERE id = ? AND payout_sale_id = ?")
                    ->execute([(int)$line['item_id'], $id]);
            } catch (Throwable $e) { error_log('pos_cancel vykup unlink: ' . $e->getMessage()); }
        }
    }

    // Storno prodejky, kterou se vyzvedla objednávka z e-shopu: zboží je zpátky
    // na skladě (výš), takže se musí vrátit i REZERVACE — jinak by kus visel
    // volný, ačkoli ho pořád drží nevyřízená online objednávka.
    // BEZ vlastního try/catch: chyba tady MUSÍ shodit celé storno (vnější catch
    // rollbackne). Spolknutá výjimka by commitla půlku — prodejka stornovaná,
    // ale objednávka dál 'collected' a kus zablokovaný napůl vrácenou rezervací.
    $eshopBack = '';
    $eo = $pdo->prepare("SELECT id, order_ref FROM eshop_orders WHERE pos_sale_id = ? AND status = 'collected' FOR UPDATE");
    $eo->execute([$id]);
    if ($eoRow = $eo->fetch(PDO::FETCH_ASSOC)) {
        // jen řádky, které tenhle prodej uvolnil (released_at vyplněné) — až přibude
        // částečné vyzvedávání, nesmí storno navýšit rezervaci dvakrát
        $rs = $pdo->prepare("SELECT product_id, qty FROM eshop_order_reservations WHERE order_id = ? AND released_at IS NOT NULL");
        $rs->execute([(int)$eoRow['id']]);
        $back = $pdo->prepare("UPDATE products SET reserved_qty = COALESCE(reserved_qty, 0) + ? WHERE id = ?");
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) { $back->execute([max(1, (int)$r['qty']), (int)$r['product_id']]); }
        $pdo->prepare("UPDATE eshop_order_reservations SET released_at = NULL WHERE order_id = ?")->execute([(int)$eoRow['id']]);
        $pdo->prepare("UPDATE eshop_orders SET status = 'reserved', pos_sale_id = NULL, collected_at = NULL WHERE id = ?")
            ->execute([(int)$eoRow['id']]);
        $eshopBack = (string)$eoRow['order_ref'];
    }

    $who = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $pdo->prepare("UPDATE pos_sales SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ? WHERE id = ?")
        ->execute([$who !== '' ? $who : null, $id]);

    if (!empty($sale['invoice_id'])) {
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([(int)$sale['invoice_id']]);
    }
    if (!empty($sale['order_id'])) {
        ensureOrderPaymentMethodColumn();
        $pdo->prepare("UPDATE orders SET payment_method = NULL WHERE id = ? AND payment_method IN ('cash','card')")
            ->execute([(int)$sale['order_id']]);
    }

    // Hotovostní prodej: výdej peněz zapsat do pokladního deníku ke DNI STORNA
    // (v jedné transakci se stornem — buď se povede obojí, nebo nic). Kniha
    // stornovaný prodej ponechává v příjmech dne prodeje PRÁVĚ podle existence
    // tohoto pohybu (afxCashSums), takže se historie zpětně nemění.
    $stornoDir = ((float)$sale['total']) >= 0 ? 'out' : 'in';   // záporná prodejka (výplata výkupu): peníze se vracejí DO kasy
    $stornoAbs = round(abs((float)$sale['total']), 2);
    if ((string)$sale['payment_method'] === 'cash' && $stornoAbs > 0.004) {
        $pdo->prepare("INSERT INTO pos_cash_movements
                (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
            VALUES (?, ?, ?, 'storno', 'pos_sale', ?, ?, ?, ?)")
            ->execute([
                ((int)($sale['branch_id'] ?? 0) > 0 ? (int)$sale['branch_id'] : null),
                $stornoDir, $stornoAbs, $id,
                mb_substr((string)$sale['sale_number'], 0, 40),
                mb_substr(($stornoDir === 'out' ? 'Vratka hotovosti — storno prodeje ' : 'Vrácená výplata (výkup / výdaj z kasy) — storno prodejky ') . (string)$sale['sale_number'], 0, 255),
                $who !== '' ? mb_substr($who, 0, 100) : null,
            ]);
        $stornoMoveId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('pos_cancel: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Databázová chyba — storno neproběhlo, zkus to znovu.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg]); exit;
}

// VPD ke kompenzačnímu pohybu — vystavuje se až PO commitu (rollback by v řadě
// nechal díru, viz afxCashDocIssue). Vazba ref_type='cash_movement' = jen papír
// k pohybu, který v knize už je; selhání dokladu nesmí shodit hotové storno.
$stornoDocNumber = '';
if ($stornoMoveId > 0) {
    $doc = afxCashDocIssue([
        'branch_id' => (int)($sale['branch_id'] ?? 0),
        'type' => $stornoDir === 'out' ? 'expense' : 'income',
        'amount' => $stornoAbs,
        'date' => date('Y-m-d'),
        'purpose' => ($stornoDir === 'out' ? 'Vratka hotovosti — storno prodeje ' : 'Vrácená výplata (výkup / výdaj z kasy) — storno prodejky ') . (string)$sale['sale_number'],
        'issued_by' => $who,
        'ref_type' => 'cash_movement',
        'ref_id' => $stornoMoveId,
        'note' => 'Storno prodeje ' . (string)$sale['sale_number'],
    ]);
    if ($doc['ok']) { $stornoDocNumber = (string)$doc['number']; }
    else { error_log('pos_cancel: VPD ke stornu se nepodařilo vystavit — ' . (string)$doc['error']); }
}

foreach ($items as $line) {
    if ((string)$line['item_type'] === 'part') {
        crmLogInventoryMove((int)$line['item_id'], (int)$line['quantity'], 'sale_cancel', null, 'Storno ' . $sale['sale_number']);
    }
}
crmAuditLog('kasa.cancel', [
    'entity_type' => 'pos_sale', 'entity_id' => $id, 'entity_label' => (string)$sale['sale_number'],
    'summary' => 'Storno prodeje ' . $sale['sale_number'] . ' za ' . formatMoney((float)$sale['total'])
        . ($eshopBack !== '' ? ' — objednávka z e-shopu ' . $eshopBack . ' je zase rezervovaná' : '')
        . (!empty($sale['invoice_id']) ? ' (zrušena i faktura)' : '')
        . ($stornoMoveId > 0 ? ' — výdej hotovosti zapsán do pokladního deníku' . ($stornoDocNumber !== '' ? ' (' . $stornoDocNumber . ')' : '') : '')
        . ($missingParts ? ' — díl „' . implode('“, „', $missingParts) . '“ už není ve skladu, kusy se nevrátily' : ''),
    'branch_id' => (int)getCurrentStaffBranchId(),
]);

echo json_encode(['success' => true, 'storno_doc' => $stornoDocNumber]);
