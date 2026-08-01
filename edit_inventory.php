<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureStockLocationsSchema();
ensureInventoryMovesTable();
ensureSkladBranchSchema();

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
    $quantity = $_POST['quantity'];
    $cost_price = $_POST['cost_price'];
    $sale_price = $_POST['sale_price'];
    $min_stock = $_POST['min_stock'];
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
        crmAuditLog('inventory.update', [
            'entity_type' => 'inventory', 'entity_id' => (int)$id, 'entity_label' => (string)$part_name,
            'summary' => 'Upraven skladový díl „' . $part_name . '" (ks: ' . $quantity . ', nákup: ' . $cost_price . ', prodej: ' . $sale_price . ')',
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
$modelOptions = [];
try { $modelOptions = $pdo->query("SELECT DISTINCT device_model FROM inventory WHERE device_model IS NOT NULL AND device_model <> '' ORDER BY device_model ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
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
                            <option value="<?php echo (int)$curLoc; ?>" selected><?php echo htmlspecialchars(stockLocationFullLabel($__cl)); ?> — <?php echo $__sameBranch ? 'deaktivované' : 'jiná pobočka'; ?></option>
                        <?php endif; endif; ?>
                        <?php foreach (['krabicka' => 'Krabičky', 'police' => 'Police', 'regal' => 'Regály'] as $t => $glabel): ?>
                            <?php $grp = array_filter($allLocations, fn($l) => $l['type'] === $t); if (!$grp) continue; ?>
                            <optgroup label="<?php echo $glabel; ?>">
                                <?php foreach ($grp as $l): ?>
                                    <option value="<?php echo (int)$l['id']; ?>" <?php echo $curLoc === (string)(int)$l['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(stockLocationFullLabel($l)); ?></option>
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

<?php require_once 'includes/footer.php'; ?>
