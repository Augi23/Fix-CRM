<?php
/**
 * PŘESUNY ZBOŽÍ MEZI POBOČKAMI (31.7.2026)
 * ─────────────────────────────────────────────────────────────────────────────
 * Zboží (skladové díly i e-shop produkty/příslušenství) se dá zařadit do
 * „seznamu přesunu" na druhou pobočku. Seznam je ROZPRACOVANÝ (draft), dokud ho
 * NEPOTVRDÍ zaměstnanec ZDROJOVÉ pobočky (od které se přesouvá) — teprve potom se
 * zásoba fyzicky přesune (odečte ze zdroje, přičte/vytvoří na cíli SE STEJNÝM
 * obrázkem) a přesun se uloží do historie s datem/časem/jménem potvrzujícího.
 *
 * Model přesunu:
 *   • díl (inventory) — množstevní: přesune se zvolený počet; na cíli se sloučí se
 *     stejným dílem (part_name+sku), jinak se vytvoří kopie s image_path zdroje.
 *   • produkt/příslušenství (products) — kus je unikátní (product_code UNIQUE), takže
 *     se přesouvá CELÝ (přepnutí branch_id + stock_key), i s fotkou (řádek se nemění).
 *
 * Vyžaduje includes/functions.php (getBranches, getDefaultBranchId, crmCanModifyBranchStock,
 * isBranchGlobalViewer, getCurrentStaffBranchId, crmAuditLog, crmLogInventoryMove,
 * skladBranchIdForStockKey, productImageDisplayUrl…).
 */

/** Vytvoří tabulky přesunů (idempotentně). Migrace se na serveru nespouští → runtime. */
function ensureStockTransfersSchema(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_branch_id INT NOT NULL,
            to_branch_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by_name VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_by_name VARCHAR(120) DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            KEY idx_tr_route (from_branch_id, to_branch_id, status),
            KEY idx_tr_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfer_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_id INT NOT NULL,
            item_type VARCHAR(16) NOT NULL,            -- 'inventory' | 'product'
            source_id INT NOT NULL,                    -- inventory.id / products.id
            name VARCHAR(255) DEFAULT NULL,
            image_url VARCHAR(500) DEFAULT NULL,
            qty INT NOT NULL DEFAULT 1,
            added_by_name VARCHAR(120) DEFAULT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tri_transfer (transfer_id),
            KEY idx_tri_source (item_type, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ensureStockTransfersSchema: ' . $e->getMessage()); }
}

/** Jméno přihlášeného (pro záznam kdo přidal/potvrdil). */
function afxTransferActorName(): string {
    $n = trim((string)($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? $_SESSION['tech_name'] ?? $_SESSION['username'] ?? ''));
    return $n !== '' ? mb_substr($n, 0, 120) : 'Neznámý';
}

/** Otevřená (draft) dávka přesunu z→na; vytvoří, pokud neexistuje. Vrací id. */
function afxTransferOpenDraft(int $from, int $to): int {
    global $pdo;
    ensureStockTransfersSchema();
    $st = $pdo->prepare("SELECT id FROM stock_transfers WHERE from_branch_id = ? AND to_branch_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1");
    $st->execute([$from, $to]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;
    $ins = $pdo->prepare("INSERT INTO stock_transfers (from_branch_id, to_branch_id, status, created_by_name) VALUES (?, ?, 'draft', ?)");
    $ins->execute([$from, $to, afxTransferActorName()]);
    return (int)$pdo->lastInsertId();
}

/** Přidá položku do draftu přesunu ze zdrojové pobočky $from na druhou pobočku.
 *  Vrací ['ok'=>bool,'message'=>..., 'transfer_id'=>int, 'count'=>int(položek v draftu)]. */
function afxTransferAddItem(int $from, string $type, int $sourceId, int $qty): array {
    global $pdo;
    ensureStockTransfersSchema();
    $type = $type === 'product' ? 'product' : 'inventory';
    $qty  = max(1, $qty);

    // cílová pobočka = ta DRUHÁ (jen 2 pobočky). Když by jich bylo víc, bralo by se z POST.
    $branches = getBranches(true);
    $to = 0;
    foreach ($branches as $b) { if ((int)$b['id'] !== $from) { $to = (int)$b['id']; break; } }
    if ($to <= 0) return ['ok' => false, 'message' => 'Není kam přesouvat (druhá pobočka nenalezena).'];

    // právo: přesouvat ZE zdrojové pobočky smí jen její zaměstnanec (admin/Boss vždy)
    if (!crmCanModifyBranchStock($from)) {
        return ['ok' => false, 'message' => 'Přesouvat z této pobočky smí jen její zaměstnanci.'];
    }

    if ($type === 'inventory') {
        $it = $pdo->prepare("SELECT id, part_name, quantity, image_path, branch_id FROM inventory WHERE id = ?");
        $it->execute([$sourceId]);
        $row = $it->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'message' => 'Díl nenalezen.'];
        if ((int)($row['branch_id'] ?? 0) !== $from) return ['ok' => false, 'message' => 'Tento díl není na této pobočce.'];
        if ($qty > (int)$row['quantity']) return ['ok' => false, 'message' => 'Skladem je jen ' . (int)$row['quantity'] . ' ks.'];
        $name = (string)$row['part_name'];
        $img  = (string)($row['image_path'] ?? '');
    } else {
        $it = $pdo->prepare("SELECT id, title, stock_qty, image_url, studio_image_url, branch_id FROM products WHERE id = ?");
        $it->execute([$sourceId]);
        $row = $it->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'message' => 'Produkt nenalezen.'];
        if ((int)($row['branch_id'] ?? 0) !== $from) return ['ok' => false, 'message' => 'Tento produkt není na této pobočce.'];
        // produkt se přesouvá celý (unikátní kus) → qty = celý stav (min 1)
        $qty  = max(1, (int)$row['stock_qty']);
        $name = (string)$row['title'];
        $img  = (string)($row['studio_image_url'] ?? '') !== '' ? (string)$row['studio_image_url'] : (string)($row['image_url'] ?? '');
    }

    $transferId = afxTransferOpenDraft($from, $to);

    // stejná položka už v draftu? u dílu navýšíme počet (do stropu skladu), produkt jen jednou
    $ex = $pdo->prepare("SELECT id, qty FROM stock_transfer_items WHERE transfer_id = ? AND item_type = ? AND source_id = ? LIMIT 1");
    $ex->execute([$transferId, $type, $sourceId]);
    $exist = $ex->fetch(PDO::FETCH_ASSOC);
    if ($exist) {
        if ($type === 'inventory') {
            // nový počet = min(sklad, staré+nové), ať nepřesáhne dostupné
            $newQty = min((int)$row['quantity'], (int)$exist['qty'] + $qty);
            $pdo->prepare("UPDATE stock_transfer_items SET qty = ? WHERE id = ?")->execute([$newQty, (int)$exist['id']]);
        }
        // produkt už tam je → nic (je celý)
    } else {
        $pdo->prepare("INSERT INTO stock_transfer_items (transfer_id, item_type, source_id, name, image_url, qty, added_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$transferId, $type, $sourceId, mb_substr($name, 0, 255), mb_substr($img, 0, 500) ?: null, $qty, afxTransferActorName()]);
    }

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id = " . (int)$transferId)->fetchColumn();
    return ['ok' => true, 'message' => 'Přidáno do přesunu na ' . skladBranchLabel($to) . '.', 'transfer_id' => $transferId, 'to' => $to, 'count' => $cnt];
}

/** Položky dané dávky přesunu. */
function afxTransferItems(int $transferId): array {
    global $pdo;
    ensureStockTransfersSchema();
    $st = $pdo->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id = ? ORDER BY id ASC");
    $st->execute([$transferId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Načte dávku přesunu. */
function afxTransferGet(int $transferId): ?array {
    global $pdo;
    ensureStockTransfersSchema();
    $st = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
    $st->execute([$transferId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Smí přihlášený POTVRDIT tento přesun? Jen zaměstnanec ZDROJOVÉ pobočky (admin/Boss vždy). */
function afxCanConfirmTransfer(array $transfer): bool {
    return crmCanModifyBranchStock((int)$transfer['from_branch_id']);
}

/** Odebere položku z draftu (jen ze zdrojové pobočky). */
function afxTransferRemoveItem(int $itemId): array {
    global $pdo;
    ensureStockTransfersSchema();
    $st = $pdo->prepare("SELECT ti.id, ti.transfer_id, t.from_branch_id, t.status FROM stock_transfer_items ti JOIN stock_transfers t ON t.id = ti.transfer_id WHERE ti.id = ?");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'message' => 'Položka nenalezena.'];
    if (($row['status'] ?? '') !== 'draft') return ['ok' => false, 'message' => 'Tento přesun už je uzavřený.'];
    if (!crmCanModifyBranchStock((int)$row['from_branch_id'])) return ['ok' => false, 'message' => 'Upravovat přesun smí jen zaměstnanci zdrojové pobočky.'];
    $pdo->prepare("DELETE FROM stock_transfer_items WHERE id = ?")->execute([$itemId]);
    // prázdný draft rovnou zrušíme
    $left = (int)$pdo->query("SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id = " . (int)$row['transfer_id'])->fetchColumn();
    if ($left === 0) { $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE id = ? AND status = 'draft'")->execute([(int)$row['transfer_id']]); }
    return ['ok' => true, 'message' => 'Odebráno z přesunu.', 'count' => $left];
}

/** POTVRDÍ a PROVEDE přesun: odečte ze zdroje, přičte/vytvoří na cíli (se stejným obrázkem),
 *  označí dávku confirmed + zapíše kdo/kdy. Vrací ['ok'=>bool,'message'=>...]. */
function afxTransferConfirm(int $transferId): array {
    global $pdo;
    ensureStockTransfersSchema();
    $t = afxTransferGet($transferId);
    if (!$t) return ['ok' => false, 'message' => 'Přesun nenalezen.'];
    if (($t['status'] ?? '') !== 'draft') return ['ok' => false, 'message' => 'Tento přesun už byl vyřízen.'];
    if (!afxCanConfirmTransfer($t)) return ['ok' => false, 'message' => 'Potvrdit přesun smí jen zaměstnanec zdrojové pobočky (' . skladBranchLabel((int)$t['from_branch_id']) . ').'];

    $from = (int)$t['from_branch_id'];
    $to   = (int)$t['to_branch_id'];
    $items = afxTransferItems($transferId);
    if (!$items) return ['ok' => false, 'message' => 'Seznam přesunu je prázdný.'];

    try {
        $pdo->beginTransaction();
        foreach ($items as $it) {
            $qty = max(1, (int)$it['qty']);
            if ($it['item_type'] === 'inventory') {
                $src = $pdo->prepare("SELECT * FROM inventory WHERE id = ? FOR UPDATE");
                $src->execute([(int)$it['source_id']]);
                $s = $src->fetch(PDO::FETCH_ASSOC);
                if (!$s || (int)$s['branch_id'] !== $from) { throw new Exception('Díl „' . $it['name'] . '" už není na zdrojové pobočce.'); }
                if ((int)$s['quantity'] < $qty) { throw new Exception('Dílu „' . $s['part_name'] . '" už je skladem jen ' . (int)$s['quantity'] . ' ks.'); }
                // odečti ze zdroje
                $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?")->execute([$qty, (int)$s['id']]);
                // cíl: stejný díl (part_name+sku) na cílové pobočce → přičti, jinak vytvoř kopii se stejným obrázkem
                $dstFind = $pdo->prepare("SELECT id FROM inventory WHERE branch_id = ? AND part_name = ? AND COALESCE(sku,'') = COALESCE(?, '') LIMIT 1");
                $dstFind->execute([$to, $s['part_name'], $s['sku']]);
                $dstId = (int)$dstFind->fetchColumn();
                if ($dstId > 0) {
                    $pdo->prepare("UPDATE inventory SET quantity = quantity + ?, is_stocked = 1 WHERE id = ?")->execute([$qty, $dstId]);
                } else {
                    $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock, is_stocked, device_model, image_path, branch_id)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)")
                        ->execute([$s['part_name'], $s['sku'], $qty, $s['cost_price'], $s['sale_price'], $s['min_stock'],
                                   $s['device_model'] ?: null, $s['image_path'] ?: null, $to]);
                    $dstId = (int)$pdo->lastInsertId();
                }
                if (function_exists('crmLogInventoryMove')) {
                    try {
                        crmLogInventoryMove((int)$s['id'], -$qty, 'transfer_out', null, 'Přesun na ' . skladBranchLabel($to));
                        crmLogInventoryMove($dstId, $qty, 'transfer_in', null, 'Přesun z ' . skladBranchLabel($from));
                    } catch (Throwable $e) {}
                }
            } else {
                // produkt: přesun celého kusu = přepnutí pobočky (i s fotkou v řádku)
                $src = $pdo->prepare("SELECT id, title, branch_id FROM products WHERE id = ? FOR UPDATE");
                $src->execute([(int)$it['source_id']]);
                $s = $src->fetch(PDO::FETCH_ASSOC);
                if (!$s || (int)$s['branch_id'] !== $from) { throw new Exception('Produkt „' . $it['name'] . '" už není na zdrojové pobočce.'); }
                $toStockKey = (skladBranchCode($to) === 'prikope') ? 'vaclavak' : 'karlin';
                $pdo->prepare("UPDATE products SET branch_id = ?, stock_key = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$to, $toStockKey, (int)$s['id']]);
            }
        }
        $confirmer = afxTransferActorName();
        $pdo->prepare("UPDATE stock_transfers SET status = 'confirmed', confirmed_by_name = ?, confirmed_at = NOW() WHERE id = ?")
            ->execute([$confirmer, $transferId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    if (function_exists('crmAuditLog')) {
        crmAuditLog('inventory.transfer', [
            'entity_type' => 'stock_transfer', 'entity_id' => $transferId,
            'summary' => 'Přesun zboží ' . skladBranchLabel($from) . ' → ' . skladBranchLabel($to)
                . ' potvrzen (' . count($items) . ' položek)',
        ]);
    }
    return ['ok' => true, 'message' => 'Přesun na ' . skladBranchLabel($to) . ' proveden (' . count($items) . ' položek).'];
}

/** Historie POTVRZENÝCH přesunů (nejnovější první), volitelně jen pro danou pobočku (z NEBO na). */
function afxTransferHistory(int $branch = 0, int $limit = 100): array {
    global $pdo;
    ensureStockTransfersSchema();
    $where = "status = 'confirmed'";
    $params = [];
    if ($branch > 0) { $where .= " AND (from_branch_id = ? OR to_branch_id = ?)"; $params[] = $branch; $params[] = $branch; }
    $st = $pdo->prepare("SELECT * FROM stock_transfers WHERE $where ORDER BY confirmed_at DESC, id DESC LIMIT " . (int)$limit);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Počet položek v otevřeném draftu přesunu z dané pobočky (pro badge). */
function afxTransferDraftCount(int $from): int {
    global $pdo;
    ensureStockTransfersSchema();
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM stock_transfer_items ti JOIN stock_transfers t ON t.id = ti.transfer_id
            WHERE t.from_branch_id = " . (int)$from . " AND t.status = 'draft'")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
