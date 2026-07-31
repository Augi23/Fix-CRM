<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';
ensureInventoryStockedSchema();
ensureStockLocationsSchema();
ensureSkladBranchSchema();

// ── Pobočka skladu ── bez ?branch = rozcestník (výběr pobočky), teprve pak sklad
$skladBranch = skladSelectedBranchId();
if ($skladBranch === 0) {
    require 'includes/sklad_branch_picker.php';
    require_once 'includes/footer.php';
    return;
}
$canModifyStock = crmCanModifyBranchStock($skladBranch);

// Pagination and Filters
$limit = 50;
$page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$f_model = trim((string)($_GET['model'] ?? ''));
$f_location = (string)($_GET['location'] ?? '');   // id umístění | 'none' = neumístěné

// Real-stock base condition: only stocked items (manually added, received, or with quantity).
$where_clauses = [inventoryStockedWhereSql()];
$params = [];

// Pobočková izolace — vždy jen zásoby vybrané pobočky.
$where_clauses[] = "inventory.branch_id = ?";
$params[] = $skladBranch;

if (!empty($search)) {
    $where_clauses[] = "(part_name LIKE ? OR sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($min_price !== '') {
    $where_clauses[] = "sale_price >= ?";
    $params[] = floatval($min_price);
}

if ($max_price !== '') {
    $where_clauses[] = "sale_price <= ?";
    $params[] = floatval($max_price);
}

if ($f_model !== '') {
    $where_clauses[] = "device_model = ?";
    $params[] = $f_model;
}

if ($f_location === 'none') {
    $where_clauses[] = "location_id IS NULL";
} elseif ($f_location !== '' && ctype_digit($f_location)) {
    $where_clauses[] = "location_id = ?";
    $params[] = (int)$f_location;
}

$where_sql = " WHERE " . implode(" AND ", $where_clauses);

// LEFT JOIN kvůli kódu umístění ve sloupci; filtry jedou přes sloupce inventory
$total_items = $pdo->prepare("SELECT COUNT(*) FROM inventory LEFT JOIN stock_locations sl ON sl.id = inventory.location_id" . $where_sql);
$total_items->execute($params);
$total_count = $total_items->fetchColumn();

$total_pages = ceil($total_count / $limit);

$stmt = $pdo->prepare("SELECT inventory.*, sl.code AS loc_code, sl.name AS loc_name FROM inventory LEFT JOIN stock_locations sl ON sl.id = inventory.location_id" . $where_sql . " ORDER BY part_name ASC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$inventory = $stmt->fetchAll();

$inventory_stats = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN quantity <= min_stock THEN 1 ELSE 0 END) as low_stock FROM inventory WHERE branch_id = " . (int)$skladBranch . " AND " . inventoryStockedWhereSql())->fetch();

// nabídky pro filtry / hromadné akce / modal nového dílu (jen vybraná pobočka)
$modelOptions = [];
try { $modelOptions = $pdo->query("SELECT DISTINCT device_model FROM inventory WHERE branch_id = " . (int)$skladBranch . " AND device_model IS NOT NULL AND device_model <> '' ORDER BY device_model ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$allLocations = stockLocationsAll($pdo);
$unplacedCount = 0;
try { $unplacedCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE location_id IS NULL AND branch_id = " . (int)$skladBranch . " AND " . inventoryStockedWhereSql())->fetchColumn(); } catch (Throwable $e) {}

/** <option> seznam umístění seskupený podle typu (vybraná hodnota = id) */
function invLocationOptionsHtml(array $allLocations, string $selected): string {
    $groups = ['krabicka' => 'Krabičky', 'police' => 'Police', 'regal' => 'Regály'];
    $html = '';
    foreach ($groups as $t => $glabel) {
        $items = array_filter($allLocations, fn($l) => $l['type'] === $t);
        if (!$items) continue;
        $html .= '<optgroup label="' . e($glabel) . '">';
        foreach ($items as $l) {
            $html .= '<option value="' . (int)$l['id'] . '"' . ($selected === (string)(int)$l['id'] ? ' selected' : '') . '>'
                . e(stockLocationFullLabel($l)) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
        <small class="text-muted"><?php echo __('total_items'); ?>: <?php echo $total_count; ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <?php if (!$canModifyStock): ?>
            <span class="badge bg-secondary me-2" title="Do skladu jiné pobočky můžeš jen nahlížet"><i class="fas fa-eye me-1"></i>jen prohlížení</span>
        <?php endif; ?>
        <?php if($inventory_stats['low_stock'] > 0): ?>
            <span class="badge bg-warning text-dark me-2"><?php echo __('low_stock_alert'); ?>: <?php echo $inventory_stats['low_stock']; ?></span>
        <?php endif; ?>
        <a href="inventory_qr_label.php?all=1&branch=<?php echo (int)$skladBranch; ?>" target="_blank" class="btn btn-outline-info" title="Vytiskne arch QR štítků všech naskladněných dílů — nalep na regály">
            <i class="fas fa-qrcode me-2"></i> QR štítky
        </a>
        <button class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#filterPanel">
            <i class="fas fa-filter me-2"></i> <?php echo __('filters'); ?>
        </button>
        <?php if ($canModifyStock): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPartModal">
            <i class="fas fa-plus me-2"></i> <?php echo __('stock_new_part'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<div class="collapse mb-4 <?php echo (!empty($search) || !empty($min_price) || !empty($max_price) || $f_model !== '' || $f_location !== '') ? 'show' : ''; ?>" id="filterPanel">
    <div class="card card-body shadow-sm">
        <form action="inventory.php" method="GET" class="row g-3">
            <input type="hidden" name="branch" value="<?php echo (int)$skladBranch; ?>">
            <div class="col-md-4">
                <label class="form-label small"><?php echo __('search_sku_placeholder'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo __('name_or_sku'); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Model zařízení</label>
                <select name="model" class="form-select form-select-sm">
                    <option value="">— vše —</option>
                    <?php foreach ($modelOptions as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $f_model === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Umístění</label>
                <select name="location" class="form-select form-select-sm">
                    <option value="">— vše —</option>
                    <option value="none" <?php echo $f_location === 'none' ? 'selected' : ''; ?>>Bez umístění<?php echo $unplacedCount > 0 ? ' (' . $unplacedCount . ')' : ''; ?></option>
                    <?php echo invLocationOptionsHtml($allLocations, $f_location); ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small"><?php echo __('price_from'); ?></label>
                <input type="number" name="min_price" class="form-control form-control-sm" value="<?php echo htmlspecialchars($min_price); ?>" step="0.01">
            </div>
            <div class="col-md-1">
                <label class="form-label small"><?php echo __('price_to'); ?></label>
                <input type="number" name="max_price" class="form-control form-control-sm" value="<?php echo htmlspecialchars($max_price); ?>" step="0.01">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?php echo __('apply_btn'); ?></button>
                <a href="inventory.php?branch=<?php echo (int)$skladBranch; ?>" class="btn btn-sm btn-outline-secondary"><?php echo __('reset_btn'); ?></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-4" style="width:34px;"><input type="checkbox" class="form-check-input" id="invCheckAll" title="Vybrat vše na stránce"></th>
                                <th><?php echo __('photo_col'); ?></th>
                                <th><?php echo __('part_name'); ?></th>
                                <th><?php echo __('sku'); ?></th>
                                <th>Umístění</th>
                                <th><?php echo __('supplier_col'); ?></th>
                                <th><?php echo __('availability_col'); ?></th>
                                <th><?php echo __('our_stock_col'); ?></th>
                                <th><?php echo __('buy_price'); ?></th>
                                <th><?php echo __('sell_price'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="text-end pe-4"><?php echo __('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory)): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5 text-muted">
                                        <i class="fas fa-boxes fa-3x mb-3 d-block opacity-25"></i>
                                        <?php echo __('stock_real_empty'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory as $item): ?>
                                <tr>
                                    <td class="ps-4"><input type="checkbox" class="form-check-input inv-check" value="<?php echo (int)$item['id']; ?>"></td>
                                    <td>
                                        <?php if(!empty($item['image_path'] ?? '')): ?>
                                            <a href="<?php echo e($item['image_path']); ?>" data-fancybox="inventory">
                                                <img src="<?php echo e($item['image_path']); ?>" class="rounded shadow-sm" style="width: 40px; height: 40px; object-fit: cover;">
                                            </a>
                                        <?php else: ?>
                                            <div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center shadow-sm border border-secondary" style="width: 40px; height: 40px;">
                                                <i class="fas fa-image text-muted opacity-25"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($item['part_name']); ?></div>
                                        <?php if (trim((string)($item['device_model'] ?? '')) !== ''): ?>
                                            <div class="small text-white-75"><?php echo htmlspecialchars($item['device_model']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                    <td>
                                        <?php if (!empty($item['loc_code'])): ?>
                                            <a href="inventory.php?branch=<?php echo (int)$skladBranch; ?>&location=<?php echo (int)$item['location_id']; ?>" class="text-decoration-none" title="<?php echo htmlspecialchars((string)($item['loc_name'] ?? '')); ?>">
                                                <span class="badge bg-info text-dark"><?php echo htmlspecialchars($item['loc_code']); ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $supplierKey = (string)($item['source_supplier'] ?? ''); ?>
                                        <?php $supplierUrl = trim((string)($item['source_url'] ?? '')); ?>
                                        <?php if ($supplierKey !== ''): ?>
                                            <div class="d-flex flex-column gap-1">
                                                <span class="badge bg-info text-dark d-inline-block"><?php echo htmlspecialchars(supplierLabel($supplierKey)); ?></span>
                                                <?php if ($supplierUrl !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($supplierUrl); ?>" target="_blank" rel="noopener noreferrer" class="small text-decoration-none">
                                                        <i class="fas fa-external-link-alt me-1"></i>Katalog
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $availabilityText = trim((string)($item['supplier_availability'] ?? '')); ?>
                                        <?php $availabilityQty = isset($item['supplier_stock_qty']) && $item['supplier_stock_qty'] !== null && $item['supplier_stock_qty'] !== '' ? (int)$item['supplier_stock_qty'] : null; ?>
                                        <?php if ($availabilityText !== ''): ?>
                                            <div class="fw-medium"><?php echo htmlspecialchars($availabilityText); ?></div>
                                            <?php if ($availabilityQty !== null): ?>
                                                <div class="small text-white-75"><?php echo $availabilityQty; ?> <?php echo __('pcs_short'); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-medium <?php echo $item['quantity'] <= $item['min_stock'] ? 'text-danger' : ''; ?>">
                                            <?php echo $item['quantity']; ?> <?php echo __('pcs_short'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatMoney($item['cost_price']); ?></td>
                                    <td class="fw-bold text-primary"><?php echo formatMoney($item['sale_price']); ?></td>
                                    <td>
                                        <?php if ($item['quantity'] <= 0): ?>
                                            <span class="badge bg-danger"><?php echo __('status_no'); ?></span>
                                        <?php elseif ($item['quantity'] <= $item['min_stock']): ?>
                                            <span class="badge bg-warning text-dark"><?php echo __('status_low'); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo __('status_ok'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm afx-row-actions">
                                            <?php if ($canModifyStock): ?>
                                            <button type="button" class="btn btn-white border text-success restock-btn" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['part_name']); ?>" title="Naskladnit (příjem kusů)"><i class="fas fa-truck-loading"></i></button>
                                            <?php endif; ?>
                                            <a href="inventory_qr_label.php?id=<?php echo $item['id']; ?>" target="_blank" class="btn btn-white border text-info" title="QR štítek na regál"><i class="fas fa-qrcode"></i></a>
                                            <?php if ($canModifyStock): ?>
                                            <a href="edit_inventory.php?id=<?php echo $item['id']; ?>" class="btn btn-white border" title="<?php echo __('edit'); ?>"><i class="fas fa-edit text-warning"></i></a>
                                            <button type="button" class="btn btn-white border text-success assign-order-btn" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['part_name']); ?>" title="<?php echo __('use_in_repair'); ?>"><i class="fas fa-link"></i></button>
                                            <button type="button" class="btn btn-white border text-danger" onclick="deletePart(<?php echo $item['id']; ?>)" title="<?php echo __('delete'); ?>"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <datalist id="modelList">
            <?php foreach ($modelOptions as $m): ?><option value="<?php echo htmlspecialchars($m); ?>"></option><?php endforeach; ?>
        </datalist>

        <!-- Hromadné akce nad zaškrtnutými díly (třídění skladu do krabiček) — jen zaměstnanec pobočky -->
        <?php if ($canModifyStock): ?>
        <div id="bulkBar" class="card shadow-lg border-secondary position-fixed start-50 translate-middle-x p-2 px-3" style="display:none; bottom: 18px; z-index: 1040; max-width: 96vw;">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="fw-semibold text-nowrap"><span id="bulkCount">0</span> vybráno</span>
                <select id="bulkLocation" class="form-select form-select-sm" style="width:auto; max-width:280px;">
                    <option value="0">— odebrat umístění —</option>
                    <?php echo invLocationOptionsHtml($allLocations, ''); ?>
                </select>
                <button type="button" id="bulkAssign" class="btn btn-sm btn-primary text-nowrap">Přiřadit umístění</button>
                <input type="text" id="bulkModel" list="modelList" class="form-control form-control-sm" style="width:160px;" placeholder="Model (iPhone 12…)">
                <button type="button" id="bulkSetModel" class="btn btn-sm btn-outline-primary text-nowrap">Nastavit model</button>
                <a href="#" id="bulkLabels" target="_blank" class="btn btn-sm btn-outline-info text-nowrap"><i class="fas fa-qrcode me-1"></i>Štítky</a>
                <button type="button" id="bulkClear" class="btn btn-sm btn-outline-secondary">Zrušit</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <?php
            $params_p = $_GET;
            unset($params_p['p']);
            $qs = http_build_query($params_p);
            $url_pre = $qs ? "&$qs" : "";

            $pagination_window = 10;
            if ($total_pages <= $pagination_window) {
                $start_page = 1;
                $end_page = $total_pages;
            } else {
                $half_window = (int)floor($pagination_window / 2);
                $start_page = max(1, $page - $half_window);
                $end_page = $start_page + $pagination_window - 1;

                if ($end_page > $total_pages) {
                    $end_page = $total_pages;
                    $start_page = max(1, $end_page - $pagination_window + 1);
                }
            }
        ?>
        <nav class="mt-4">
            <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo max(1, $page - 1) . $url_pre; ?>" aria-label="Previous">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>

                <?php if ($start_page > 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>

                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i . $url_pre; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($end_page < $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>

                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo min($total_pages, $page + 1) . $url_pre; ?>" aria-label="Next">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="newPartModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="api/add_inventory.php" method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="branch_id" value="<?php echo (int)$skladBranch; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-truck-loading me-2 text-success"></i><?php echo __('stock_new_part'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?php echo __('part_name'); ?></label>
                            <input type="text" name="part_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('sku'); ?></label>
                            <input type="text" name="sku" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('quantity'); ?></label>
                            <input type="number" name="quantity" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('buy_price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="cost_price" class="form-control" step="0.01">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('sell_price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="sale_price" class="form-control" step="0.01">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('min_stock_label'); ?></label>
                            <input type="number" name="min_stock" class="form-control" value="5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model zařízení</label>
                            <input type="text" name="device_model" list="modelList" class="form-control" placeholder="iPhone 12, iPad Air…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Umístění</label>
                            <select name="location_id" class="form-select">
                                <option value="">— bez umístění —</option>
                                <?php echo invLocationOptionsHtml($allLocations, ''); ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="assignToOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="assignToOrderForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="inventory_id" id="assignInventoryId">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('use_in_repair'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 mb-3">
                        <div class="small text-muted mb-1"><?php echo __('proc_sel_part'); ?></div>
                        <div class="fw-semibold" id="assignInventoryName"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('order'); ?></label>
                        <select name="order_id" id="assignOrderSelect" class="form-select" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" class="form-control" id="assignQuantity" value="1" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><?php echo __('assign_to_order'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const assignModalEl = document.getElementById('assignToOrderModal');
    const assignModal = assignModalEl ? new bootstrap.Modal(assignModalEl) : null;

    $('#assignOrderSelect').select2({
        dropdownParent: $('#assignToOrderModal'),
        width: '100%',
        placeholder: '<?php echo __('search_order_placeholder'); ?>',
        ajax: {
            url: 'api/search_orders.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term || '', limit: 20 };
            },
            processResults: function(data) {
                return { results: (data.results || []) };
            }
        }
    });

    $('.assign-order-btn').on('click', function() {
        $('#assignInventoryId').val($(this).data('id'));
        $('#assignInventoryName').text($(this).data('name'));
        $('#assignQuantity').val(1);
        $('#assignOrderSelect').val(null).trigger('change');
        if (assignModal) assignModal.show();
    });

    $('#assignToOrderForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/add_order_item.php', $(this).serialize(), function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error_prefix'); ?>' + res.message);
            }
        });
    });
});

function deletePart(id) {
    showConfirm('<?php echo __('confirm_delete_inventory'); ?>', function() {
        $.post('api/delete_inventory.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error_prefix'); ?>' + res.message);
            }
        });
    });
}

// ── hromadný výběr: přiřazení do umístění / model / tisk štítků vybraných ──
(function () {
    var bar = document.getElementById('bulkBar');
    if (!bar) return;
    function selected() {
        return Array.prototype.map.call(document.querySelectorAll('.inv-check:checked'), function (c) { return c.value; });
    }
    function refresh() {
        var ids = selected();
        bar.style.display = ids.length > 0 ? '' : 'none';
        document.getElementById('bulkCount').textContent = ids.length;
        document.getElementById('bulkLabels').href = 'inventory_qr_label.php?ids=' + ids.join(',');
    }
    document.addEventListener('change', function (e) {
        if (e.target.id === 'invCheckAll') {
            document.querySelectorAll('.inv-check').forEach(function (c) { c.checked = e.target.checked; });
        }
        if (e.target.classList && (e.target.classList.contains('inv-check') || e.target.id === 'invCheckAll')) refresh();
    });
    document.getElementById('bulkClear').addEventListener('click', function () {
        document.querySelectorAll('.inv-check, #invCheckAll').forEach(function (c) { c.checked = false; });
        refresh();
    });
    function bulkPost(data) {
        var ids = selected();
        if (!ids.length) return;
        data.inventory_ids = ids.join(',');
        data.csrf_token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fetch('api/stock_locations.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) { location.reload(); } else { showAlert(d.message || 'Chyba'); } })
            .catch(function () { showAlert('Síťová chyba.'); });
    }
    document.getElementById('bulkAssign').addEventListener('click', function () {
        bulkPost({op: 'assign', location_id: document.getElementById('bulkLocation').value || 0});
    });
    document.getElementById('bulkSetModel').addEventListener('click', function () {
        var m = document.getElementById('bulkModel').value.trim();
        if (m === '' && !confirm('Model je prázdný — opravdu ho vybraným dílům smazat?')) return;
        bulkPost({op: 'set_model', device_model: m});
    });
}());

// Rychlé naskladnění z řádku (desktop) — stejné API jako QR sken na regálu
$(document).on('click', '.restock-btn', function () {
    var id = this.dataset.id, name = this.dataset.name || '';
    var qty = prompt('Naskladnit „' + name + '" — kolik kusů přijímáš?', '1');
    if (qty === null) return;
    qty = parseInt(qty, 10);
    if (!qty || qty < 1) { alert('Zadej kladný počet kusů.'); return; }
    var fd = new FormData();
    fd.append('op', 'restock'); fd.append('inventory_id', id); fd.append('qty', qty);
    fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    fetch('api/inventory_move.php', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Chyba'); }
        })
        .catch(function () { alert('Síťová chyba.'); });
});
</script>



<?php require_once 'includes/footer.php'; ?>

