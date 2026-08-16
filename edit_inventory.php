<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureStockLocationsSchema();
ensureInventoryMovesTable();
ensureSkladBranchSchema();
ensureInventoryComponentsTable();

$id = $_GET['id'] ?? null;
if (!$id) die(__("inventory_id_missing"));

$stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) die(__("part_not_found"));

// Pobočková pojistka: díl smí upravit jen zaměstnanec JEHO pobočky (admin/Boss vždy).
$canEditBranch = crmCanModifyBranchStock((int)($item['branch_id'] ?? 0) ?: getDefaultBranchId());

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$canEditBranch) {
    $error = 'Tento díl patří jiné pobočce (' . e(skladBranchLabel((int)($item['branch_id'] ?? 0) ?: getDefaultBranchId())) . ') — upravit ho smí jen její zaměstnanci.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $canEditBranch) {
    $part_name = $_POST['part_name'];
    $sku = $_POST['sku'];
    // číselné vstupy normalizovat: prázdné pole = 0 (prázdný string '' by ve strict
    // MySQL shodil UPDATE na DECIMAL sloupci — SQLSTATE 22007/1366); čárku vzít jako
    // desetinnou tečku, kdyby ji prohlížeč poslal lokalizovaně
    $quantity = (int)($_POST['quantity'] ?? 0);
    $cost_price = (float)str_replace(',', '.', (string)($_POST['cost_price'] ?? '0'));
    $sale_price = (float)str_replace(',', '.', (string)($_POST['sale_price'] ?? '0'));
    $min_stock = (int)($_POST['min_stock'] ?? 0);
    $device_model = mb_substr(trim((string)($_POST['device_model'] ?? '')), 0, 64);
    $location_id = (int)($_POST['location_id'] ?? 0);
    // umístění musí patřit stejné pobočce jako díl (jinak by díl „ležel" jinde);
    // uložení se v takovém případě ZASTAVÍ — tiché vynulování by obsluhu nechalo
    // v přesvědčení, že díl někde je
    if ($location_id > 0) {
        $__itemBranch = (int)($item['branch_id'] ?? 0) ?: getDefaultBranchId();
        if (stockLocationBranchId($location_id) !== $__itemBranch) {
            $error = 'Vybrané umístění patří jiné pobočce — díl uložen nebyl. Vyber umístění ze skladu ' . e(skladBranchLabel($__itemBranch)) . '.';
            $location_id = -1;   // -1 = neukládat
        }
    }

    // chybné umístění (cizí pobočka) → neukládat nic; hláška je nastavená výše.
    // Rozepsané hodnoty se vrátí do formuláře, ať obsluha nepřijde o práci.
    if ($location_id < 0) {
        $item = array_merge($item, [
            'part_name' => $part_name, 'sku' => $sku, 'quantity' => $quantity,
            'cost_price' => $cost_price, 'sale_price' => $sale_price, 'min_stock' => $min_stock,
            'device_model' => $device_model,
        ]);
    }
    if ($location_id >= 0) {
      try {
        $update = $pdo->prepare("UPDATE inventory SET
            part_name = ?,
            sku = ?,
            quantity = ?,
            cost_price = ?,
            sale_price = ?,
            min_stock = ?,
            device_model = ?,
            location_id = ?
            WHERE id = ?");
        $update->execute([$part_name, $sku, $quantity, $cost_price, $sale_price, $min_stock,
            $device_model !== '' ? $device_model : null, $location_id > 0 ? $location_id : null, $id]);
        // ruční přepis počtu kusů = korekce → do deníku pohybů (ať inventura sedí)
        if ((int)$quantity !== (int)$item['quantity']) {
            crmLogInventoryMove((int)$id, (int)$quantity - (int)$item['quantity'], 'correction', null, 'Úprava počtu v editaci dílu');
        }
        // ── součástky uvnitř dílu (zařízení-dárce): upravit / smazat / přidat ──
        // prázdný název = řádek smazat; smazaný řádek ve formuláři se nepošle → smazat
        $postedComps = is_array($_POST['components'] ?? null) ? $_POST['components'] : [];
        $newComps = is_array($_POST['components_new'] ?? null) ? $_POST['components_new'] : [];
        $curC = $pdo->prepare("SELECT id FROM inventory_components WHERE inventory_id = ?");
        $curC->execute([(int)$id]);
        $curCompIds = array_map('intval', $curC->fetchAll(PDO::FETCH_COLUMN));
        $keepComp = [];
        foreach ($postedComps as $cid => $row) {
            $cid = (int)$cid;
            if (!in_array($cid, $curCompIds, true) || !is_array($row)) { continue; }
            $cn = mb_substr(trim((string)($row['name'] ?? '')), 0, 120);
            if ($cn === '') { continue; }
            $keepComp[] = $cid;
            $pdo->prepare("UPDATE inventory_components SET name = ?, is_used = ? WHERE id = ? AND inventory_id = ?")
                ->execute([$cn, !empty($row['used']) ? 1 : 0, $cid, (int)$id]);
        }
        $delComp = array_diff($curCompIds, $keepComp);
        if ($delComp) {
            $pdo->prepare("DELETE FROM inventory_components WHERE inventory_id = ? AND id IN (" . implode(',', array_fill(0, count($delComp), '?')) . ")")
                ->execute(array_merge([(int)$id], array_values($delComp)));
        }
        foreach ($newComps as $cn) {
            $cn = mb_substr(trim((string)$cn), 0, 120);
            if ($cn === '') { continue; }
            $pdo->prepare("INSERT INTO inventory_components (inventory_id, name) VALUES (?, ?)")->execute([(int)$id, $cn]);
        }
        $compCount = 0;
        try { $ccq = $pdo->prepare("SELECT COUNT(*) FROM inventory_components WHERE inventory_id = ?"); $ccq->execute([(int)$id]); $compCount = (int)$ccq->fetchColumn(); } catch (Throwable $e) {}
        crmAuditLog('inventory.update', [
            'entity_type' => 'inventory', 'entity_id' => (int)$id, 'entity_label' => (string)$part_name,
            'summary' => 'Upraven skladový díl „' . $part_name . '" (ks: ' . $quantity . ', nákup: ' . $cost_price . ', prodej: ' . $sale_price . ($compCount > 0 ? ', součástek uvnitř: ' . $compCount : '') . ')',
        ]);
        $success = __("inventory_updated");
        // Refresh
        $stmt->execute([$id]);
        $item = $stmt->fetch();
      } catch (Exception $e) {
        $error = __("error_prefix") . $e->getMessage();
      }
    }
}

// umístění pobočky, které díl patří (sklad se mezi provozovnami nemíchá)
$allLocations = stockLocationsAll($pdo, true, (int)($item['branch_id'] ?? 0) ?: getDefaultBranchId());
// jednotné značení R-P-B ve výběru umístění
$posAllLoc = [];
try { $posAllLoc = stockLocationPosCodes($pdo, array_merge(array_column($allLocations, 'id'), [(int)($item['location_id'] ?? 0)])); } catch (Throwable $e) {}
$modelOptions = [];
try { $modelOptions = $pdo->query("SELECT DISTINCT device_model FROM inventory WHERE device_model IS NOT NULL AND device_model <> '' ORDER BY device_model ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

// součástky uvnitř dílu (použité až na konec, ať je vidět, co v dárci zbývá)
$components = [];
try {
    $cq = $pdo->prepare("SELECT id, name, is_used FROM inventory_components WHERE inventory_id = ? ORDER BY is_used ASC, id ASC");
    $cq->execute([(int)$id]);
    $components = $cq->fetchAll();
} catch (Throwable $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo __('edit_product_title'); ?> <?php echo htmlspecialchars($item['part_name']); ?></h2>
    <a href="inventory.php" class="btn btn-outline-secondary"><?php echo __('back_to_inventory'); ?></a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-2"></i><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <script>window.afxDraft && afxDraft.clearKey('inventory-<?php echo (int)$id; ?>');</script>
<?php endif; ?>

<?php if (!empty($item['source_supplier']) || !empty($item['supplier_availability'])): ?>
    <div class="alert alert-info">
        <div class="fw-semibold">Supplier source</div>
        <div class="small mb-1">
            <?php echo !empty($item['source_supplier']) ? htmlspecialchars(supplierLabel((string)$item['source_supplier'])) : '—'; ?>
        </div>
        <?php if (!empty($item['supplier_availability'])): ?>
            <div class="small">Dostupnost: <?php echo htmlspecialchars($item['supplier_availability']); ?><?php echo isset($item['supplier_stock_qty']) && $item['supplier_stock_qty'] !== null ? ' (' . (int)$item['supplier_stock_qty'] . ' ks)' : ''; ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" data-draft-key="inventory-<?php echo (int)$id; ?>">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label"><?php echo __('part_name'); ?></label>
                    <input type="text" name="part_name" class="form-control" value="<?php echo htmlspecialchars($item['part_name']); ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Součástky uvnitř dílu <span class="text-white-75 small fw-normal">— u zařízení-dárce vypiš, co použitelného v něm je; najde se pak i hledáním („displej" najde tenhle iPhone)</span></label>
                    <div id="compRows" class="d-flex flex-column gap-2">
                        <?php foreach ($components as $c): ?>
                        <div class="input-group comp-row">
                            <span class="input-group-text"><i class="fas fa-puzzle-piece"></i></span>
                            <input type="text" name="components[<?php echo (int)$c['id']; ?>][name]" list="compList" class="form-control<?php echo (int)$c['is_used'] ? ' text-decoration-line-through' : ''; ?>" value="<?php echo htmlspecialchars($c['name']); ?>" maxlength="120">
                            <label class="input-group-text" title="Součástka už je vyjmutá / použitá — hledání ji přestane nabízet">
                                <input type="checkbox" class="form-check-input mt-0 me-2" name="components[<?php echo (int)$c['id']; ?>][used]" value="1" <?php echo (int)$c['is_used'] ? 'checked' : ''; ?>> použito
                            </label>
                            <button type="button" class="btn btn-outline-danger comp-del" title="Odebrat řádek (smaže se uložením)"><i class="fas fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="compAdd"><i class="fas fa-plus me-1"></i> Přidat součástku</button>
                    <datalist id="compList">
                        <?php foreach (['Displej', 'Baterie', 'Zadní sklo', 'Zadní kryt (housing)', 'Rámeček', 'Základní deska', 'Přední kamera', 'Zadní kamera', 'Face ID modul', 'Touch ID čtečka', 'Nabíjecí konektor', 'Reproduktor', 'Sluchátko', 'Taptic Engine', 'Flex tlačítek hlasitosti', 'Flex zapínacího tlačítka', 'SIM slot', 'Cívka bezdrátového nabíjení'] as $__cn): ?><option value="<?php echo $__cn; ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sku'); ?></label>
                    <input type="text" name="sku" class="form-control" value="<?php echo htmlspecialchars($item['sku']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('stock_quantity'); ?></label>
                    <input type="number" name="quantity" class="form-control" value="<?php echo $item['quantity']; ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('buy_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="cost_price" class="form-control" step="0.01" value="<?php echo $item['cost_price']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sell_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="sale_price" class="form-control" step="0.01" value="<?php echo $item['sale_price']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><?php echo __('min_stock_alert_limit'); ?></label>
                    <input type="number" name="min_stock" class="form-control" value="<?php echo $item['min_stock']; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Model zařízení</label>
                    <input type="text" name="device_model" list="editModelList" class="form-control" value="<?php echo htmlspecialchars((string)($item['device_model'] ?? '')); ?>" placeholder="iPhone 12, iPad Air…">
                    <datalist id="editModelList">
                        <?php foreach ($modelOptions as $m): ?><option value="<?php echo htmlspecialchars($m); ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Umístění — pozice ve skladu</label>
                    <select name="location_id" class="form-select">
                        <option value="">— bez umístění —</option>
                        <?php $curLoc = (string)(int)($item['location_id'] ?? 0);
                        // stávající umístění musí být v nabídce vždy — i když je
                        // deaktivované nebo z jiné pobočky, jinak by se při uložení
                        // tiše ztratilo a nikdo by nevěděl, kde díl leží
                        $curInList = false;
                        foreach ($allLocations as $__l) { if ((int)$__l['id'] === (int)$curLoc) { $curInList = true; break; } }
                        ?>
                        <?php if (!$curInList && (int)$curLoc > 0):
                            $__cl = null;
                            try { $__q = $pdo->prepare("SELECT l.*, p.code parent_code FROM stock_locations l LEFT JOIN stock_locations p ON p.id = l.parent_id WHERE l.id = ?"); $__q->execute([(int)$curLoc]); $__cl = $__q->fetch(); } catch (Throwable $e) {}
                            if ($__cl): ?>
                            <?php $__sameBranch = (int)($__cl['branch_id'] ?? 0) === ((int)($item['branch_id'] ?? 0) ?: getDefaultBranchId()); ?>
                            <option value="<?php echo (int)$curLoc; ?>" selected><?php echo htmlspecialchars(($posAllLoc[(int)$curLoc] ?? $__cl['code']) . (trim((string)$__cl['name']) !== '' ? ' · ' . $__cl['name'] : '')); ?> — <?php echo $__sameBranch ? 'deaktivované' : 'jiná pobočka'; ?></option>
                        <?php endif; endif; ?>
                        <?php foreach (['krabicka' => 'Krabičky', 'police' => 'Police', 'regal' => 'Regály'] as $t => $glabel): ?>
                            <?php
                            $grp = array_values(array_filter($allLocations, fn($l) => $l['type'] === $t));
                            if (!$grp) continue;
                            usort($grp, fn($a, $b) => strnatcmp((string)($posAllLoc[(int)$a['id']] ?? $a['code']), (string)($posAllLoc[(int)$b['id']] ?? $b['code'])));
                            ?>
                            <optgroup label="<?php echo $glabel; ?>">
                                <?php foreach ($grp as $l): ?>
                                    <option value="<?php echo (int)$l['id']; ?>" <?php echo $curLoc === (string)(int)$l['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(($posAllLoc[(int)$l['id']] ?? $l['code']) . (trim((string)$l['name']) !== '' ? ' · ' . $l['name'] : '')); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Vyber <b>krabičku</b>, když je díl v ní — nebo rovnou <b>polici</b> či <b>regál</b>, když leží volně. Umístění se zakládají v <a href="sklad_umisteni.php" class="text-info">Sklad → Umístění</a>.</div>
                </div>
                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// řádky součástek: + přidá prázdný, koš odebere (smazání proběhne uložením formuláře)
(function () {
    var rows = document.getElementById('compRows');
    var add = document.getElementById('compAdd');
    if (!rows || !add) return;
    add.addEventListener('click', function () {
        var d = document.createElement('div');
        d.className = 'input-group comp-row';
        d.innerHTML = '<span class="input-group-text"><i class="fas fa-puzzle-piece"></i></span>'
            + '<input type="text" name="components_new[]" list="compList" class="form-control" maxlength="120" placeholder="např. Displej">'
            + '<button type="button" class="btn btn-outline-danger comp-del" title="Odebrat řádek"><i class="fas fa-trash"></i></button>';
        rows.appendChild(d);
        d.querySelector('input').focus();
    });
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('.comp-del') : null;
        if (b) { b.closest('.comp-row').remove(); }
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
