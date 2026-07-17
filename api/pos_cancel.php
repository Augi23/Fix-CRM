<?php
/**
 * Storno prodeje na kase — JEN vedení (admin/Boss). Plná vratka celého dokladu:
 * vrátí zboží na sklad, označí doklad jako stornovaný, případnou fakturu zruší.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
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

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM pos_sales WHERE id = ? FOR UPDATE");
    $st->execute([$id]);
    $sale = $st->fetch();
    if (!$sale) { throw new Exception('Doklad nenalezen.'); }
    if ((string)$sale['status'] === 'cancelled') { throw new Exception('Doklad už je stornovaný.'); }

    // zaplacenou fakturu storno kasy rušit nesmí — na to je dobropis v Účetnictví
    if (!empty($sale['invoice_id'])) {
        $ist = $pdo->prepare("SELECT status FROM invoices WHERE id = ?");
        $ist->execute([(int)$sale['invoice_id']]);
        if ((string)$ist->fetchColumn() === 'paid') {
            throw new Exception('Faktura k prodeji je už zaplacená — vyřeš dobropisem v Účetnictví, pak teprve storno.');
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
        } else {
            // vrácení produktu: zpět skladem, flag kasy pryč (kus je zase v appce pravdivě skladem)
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ?, pos_sold_at = NULL WHERE id = ?")
                ->execute([(int)$line['quantity'], (int)$line['item_id']]);
        }
    }

    $who = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $pdo->prepare("UPDATE pos_sales SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ? WHERE id = ?")
        ->execute([$who !== '' ? $who : null, $id]);

    if (!empty($sale['invoice_id'])) {
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([(int)$sale['invoice_id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('pos_cancel: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Databázová chyba — storno neproběhlo, zkus to znovu.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg]); exit;
}

foreach ($items as $line) {
    if ((string)$line['item_type'] === 'part') {
        crmLogInventoryMove((int)$line['item_id'], (int)$line['quantity'], 'sale_cancel', null, 'Storno ' . $sale['sale_number']);
    }
}
crmAuditLog('kasa.cancel', [
    'entity_type' => 'pos_sale', 'entity_id' => $id, 'entity_label' => (string)$sale['sale_number'],
    'summary' => 'Storno prodeje ' . $sale['sale_number'] . ' za ' . formatMoney((float)$sale['total'])
        . (!empty($sale['invoice_id']) ? ' (zrušena i faktura)' : '')
        . ($missingParts ? ' — díl „' . implode('“, „', $missingParts) . '“ už není ve skladu, kusy se nevrátily' : ''),
    'branch_id' => (int)getCurrentStaffBranchId(),
]);

echo json_encode(['success' => true]);
