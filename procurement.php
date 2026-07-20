<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureProcurementSchema();
ensureInventoryStockedSchema();

$suppliers = getSupplierCatalogs();
$selectedOrderId = max(0, (int)($_GET['order_id'] ?? 0));
$selectedSupplier = trim((string)($_GET['supplier'] ?? ''));

// Předvyplnění zakázky v modalu „Objednat díl": z URL, jinak naposledy otevřená zakázka.
$presetOrderId = $selectedOrderId > 0 ? $selectedOrderId : max(0, (int)($_SESSION['last_order_id'] ?? 0));
$presetOrderLabel = '';
if ($presetOrderId > 0 && isset($pdo)) {
    try {
        $__ps = $pdo->prepare("SELECT o.id, o.order_code, o.device_brand, o.device_model, c.first_name, c.last_name
                               FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ? LIMIT 1");
        $__ps->execute([$presetOrderId]);
        if ($__pr = $__ps->fetch()) {
            $__code = trim((string)($__pr['order_code'] ?? '')) !== '' ? (string)$__pr['order_code'] : ('#' . (int)$__pr['id']);
            $__dev  = trim(((string)($__pr['device_brand'] ?? '')) . ' ' . ((string)($__pr['device_model'] ?? '')));
            $__cli  = trim(((string)($__pr['first_name'] ?? '')) . ' ' . ((string)($__pr['last_name'] ?? '')));
            $presetOrderLabel = trim($__code . ($__dev !== '' ? ' ' . $__dev : '') . ($__cli !== '' ? ' · ' . $__cli : ''));
        } else {
            $presetOrderId = 0;
        }
    } catch (Throwable $e) { $presetOrderId = 0; }
}

// Manager/admin gate for ordering parts (the add action requires it on the server).
$can_order = hasPermission('procurement_manage') || hasPermission('admin_access');

// ── Catalog (orderable supplier parts) — paginated, styled like Sklad ──────────
$catalogLimit = 50;
$catalogPage = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($catalogPage < 1) $catalogPage = 1;
$catalogOffset = ($catalogPage - 1) * $catalogLimit;

$catalogSearch = trim((string)($_GET['search'] ?? ''));

$catalogWhere = ["source_supplier IS NOT NULL", "source_supplier <> ''"];
$catalogParams = [];

if ($catalogSearch !== '') {
    $catalogWhere[] = "(part_name LIKE ? OR sku LIKE ?)";
    $catalogParams[] = "%$catalogSearch%";
    $catalogParams[] = "%$catalogSearch%";
}

if ($selectedSupplier !== '' && isset($suppliers[$selectedSupplier])) {
    $catalogWhere[] = "source_supplier = ?";
    $catalogParams[] = $selectedSupplier;
}

$catalogWhereSql = ' WHERE ' . implode(' AND ', $catalogWhere);

$catalog = [];
$catalogTotal = 0;
$catalogPages = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory" . $catalogWhereSql);
    $countStmt->execute($catalogParams);
    $catalogTotal = (int)$countStmt->fetchColumn();
    $catalogPages = (int)ceil($catalogTotal / $catalogLimit);

    $catalogStmt = $pdo->prepare("SELECT * FROM inventory" . $catalogWhereSql . " ORDER BY part_name ASC LIMIT $catalogLimit OFFSET $catalogOffset");
    $catalogStmt->execute($catalogParams);
    $catalog = $catalogStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $catalog = [];
}

$pendingOrders = [];
$requests = [];
try {
    $requestWhere = '';
    $requestParams = [];
    if (!isBranchGlobalViewer()) {
        $requestWhere = ' WHERE o.branch_id = ?';
        $requestParams[] = getCurrentStaffBranchId();
    }
    $stmt = $pdo->prepare(
        "SELECT pr.*, o.device_brand, o.device_model, o.status AS order_status, o.technician_id, o.branch_id, t.name AS tech_name
         FROM purchase_requests pr
         LEFT JOIN orders o ON o.id = pr.order_id
         LEFT JOIN technicians t ON t.id = o.technician_id" . $requestWhere . "
         ORDER BY FIELD(pr.status, 'pending', 'ordered', 'received', 'cancelled'), FIELD(pr.priority, 'today', 'this_week', 'later'), pr.created_at DESC"
    );
    $stmt->execute($requestParams);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $requests = [];
}

try {
    if ($selectedOrderId > 0) {
        $where = ['o.id = ?'];
        $params = [$selectedOrderId];
        if (!isBranchGlobalViewer()) {
            $where[] = 'o.branch_id = ?';
            $params[] = getCurrentStaffBranchId();
        }
        $stmt = $pdo->prepare('SELECT o.id, o.device_brand, o.device_model, c.first_name, c.last_name FROM orders o JOIN customers c ON c.id = o.customer_id WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        $pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $pendingOrders = [];
}

$stats = [];
foreach ($suppliers as $supplierKey => $supplier) {
    $stats[$supplierKey] = [
        'pending' => 0,
        'ordered' => 0,
        'received' => 0,
        'cancelled' => 0,
        'total_qty' => 0,
    ];
}

$openRequests = [];
foreach ($requests as $request) {
    $supplierKey = (string)($request['supplier_key'] ?? '');
    if (!isset($stats[$supplierKey])) {
        $stats[$supplierKey] = [
            'pending' => 0,
            'ordered' => 0,
            'received' => 0,
            'cancelled' => 0,
            'total_qty' => 0,
        ];
    }
    $status = (string)($request['status'] ?? 'pending');
    $qty = (int)($request['quantity'] ?? 1);
    $stats[$supplierKey][$status] = ($stats[$supplierKey][$status] ?? 0) + 1;
    $stats[$supplierKey]['total_qty'] += $qty;
    if (in_array($status, ['pending', 'ordered'], true)) {
        $openRequests[] = $request;
    }
}

function procurementPriorityLabel(string $priority): string {
    return match ($priority) {
        'today' => __('today_priority'),
        'this_week' => __('this_week_priority'),
        'later' => __('later_priority'),
        default => $priority,
    };
}

function procurementStatusLabel(string $status): string {
    return match ($status) {
        'pending' => __('pending_status'),
        'ordered' => __('ordered_status'),
        'received' => __('received_status'),
        'cancelled' => __('cancelled_status_proc'),
        default => $status,
    };
}

function procurementStatusBadge(string $status): string {
    return match ($status) {
        'pending' => 'bg-warning text-dark',
        'ordered' => 'bg-primary',
        'received' => 'bg-success',
        'cancelled' => 'bg-secondary',
        default => 'bg-dark',
    };
}

function procurementPriorityBadge(string $priority): string {
    return match ($priority) {
        'today' => 'bg-danger',
        'this_week' => 'bg-info text-dark',
        'later' => 'bg-secondary',
        default => 'bg-dark',
    };
}

$requestsJson = json_encode($openRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$can_manage_procurement = hasPermission('procurement_manage') || hasPermission('admin_access');
// Manually queuing/ordering a part is a manager/admin action (server enforces it too).
// Technicians never order manually — out-of-stock parts are auto-queued when used in a repair.
$can_add_procurement = $can_order;
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h2 class="mb-1"><?php echo __('procurement_queue'); ?></h2>
        <div class="text-white-75 small"><?php echo __('proc_desc'); ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if (crmCanManageCatalogs()): ?>
        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#catalogUpdateModal">
            <i class="fas fa-sync me-2"></i><?php echo __('update_catalog'); ?>
        </button>
        <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#catalogAddModal">
            <i class="fas fa-plus me-2"></i><?php echo __('add_catalog'); ?>
        </button>
        <?php endif; ?>
        <?php if ($can_add_procurement): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
            <i class="fas fa-plus me-2"></i><?php echo __('add_btn_proc'); ?>
        </button>
        <?php endif; ?>
        <a href="orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i><?php echo __('back_to_orders'); ?></a>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<?php
// Flash: přidání katalogu dodavatele
if (isset($_GET['catalog_source_added'])): ?>
    <div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?php echo __('catalog_source_added'); ?></div>
<?php elseif (isset($_GET['catalog_source_deleted'])): ?>
    <div class="alert alert-success border-0 shadow-sm">
        <i class="fas fa-check-circle me-2"></i><?php echo __('catalog_source_deleted'); ?>
        <span class="badge bg-danger ms-2"><?php echo __('catalog_items_removed'); ?>: <?php echo max(0, (int)($_GET['catalog_removed_items'] ?? 0)); ?></span>
        <?php if ((int)($_GET['catalog_kept_items'] ?? 0) > 0): ?>
            <span class="badge bg-secondary ms-1"><?php echo __('catalog_items_kept'); ?>: <?php echo (int)$_GET['catalog_kept_items']; ?></span>
        <?php endif; ?>
    </div>
<?php elseif (!empty($_GET['catalog_source_error'])): ?>
    <div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-triangle-exclamation me-2"></i><?php
        echo $_GET['catalog_source_error'] === 'exists' ? __('catalog_source_exists') : __('catalog_source_invalid');
    ?></div>
<?php endif;

// Flash result of a catalog import (parse_catalog.php redirects here now).
if (isset($_GET['catalog_imported'])):
    $cAdded = max(0, (int)($_GET['catalog_added'] ?? 0));
    $cUpdated = max(0, (int)($_GET['catalog_updated'] ?? 0));
?>
    <div class="alert alert-success border-0 shadow-sm">
        <span class="fw-bold me-2"><i class="fas fa-check-circle me-1"></i><?php echo __('update_catalog'); ?>:</span>
        <span class="badge bg-success me-1"><?php echo __('new_items'); ?>: <?php echo $cAdded; ?></span>
        <span class="badge bg-primary"><?php echo __('updated'); ?>: <?php echo $cUpdated; ?></span>
    </div>
<?php elseif (!empty($_GET['catalog_error'])):
    $errMap = [
        'invalid_url' => __('invalid_catalog_url'),
        'fetch_failed' => __('catalog_fetch_failed'),
        'no_products' => __('catalog_no_products'),
        'processing_failed' => __('catalog_processing_failed'),
    ];
    $errMsg = $errMap[$_GET['catalog_error']] ?? __('catalog_processing_failed');
    $errDetail = trim((string)($_GET['catalog_error_detail'] ?? ''));
?>
    <div class="alert alert-danger border-0 shadow-sm">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo e($errMsg); ?>
        <?php if ($errDetail !== ''): ?><div class="small text-white-50 mt-1"><?php echo e($errDetail); ?></div><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($selectedOrderId > 0 && !empty($pendingOrders)): ?>
    <div class="alert alert-info border-0 shadow-sm mb-4">
        <div class="fw-bold"><?php echo __('linked_to_order'); ?><?php echo $selectedOrderId; ?></div>
        <?php foreach ($pendingOrders as $order): ?>
            <div class="small text-dark-emphasis"><?php echo __('order_col'); ?>: <?php echo e(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? '')); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ── HLAVNÍ SEKCE: Katalog dílů k objednání (styl Sklad) ─────────────────── -->
<div class="card glass-card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <h5 class="mb-0"><?php echo __('nakupy_catalog_title'); ?></h5>
            <div class="small text-white-75"><?php echo __('nakupy_catalog_desc'); ?></div>
        </div>
        <span class="badge bg-primary bg-opacity-75"><?php echo (int)$catalogTotal; ?></span>
    </div>
    <div class="card-body pt-0">
        <form action="procurement.php" method="GET" class="row g-2 align-items-end mb-3">
            <?php if ($selectedOrderId > 0): ?>
                <input type="hidden" name="order_id" value="<?php echo $selectedOrderId; ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <label class="form-label small mb-1"><?php echo __('search_sku_placeholder'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo e($catalogSearch); ?>" placeholder="<?php echo __('name_or_sku'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1"><?php echo __('supplier_col'); ?></label>
                <select name="supplier" class="form-select form-select-sm">
                    <option value=""><?php echo __('all_suppliers'); ?></option>
                    <?php foreach ($suppliers as $supplierKey => $supplier): ?>
                        <option value="<?php echo e($supplierKey); ?>" <?php echo $selectedSupplier === $supplierKey ? 'selected' : ''; ?>><?php echo e($supplier['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?php echo __('apply_btn'); ?></button>
                <a href="procurement.php<?php echo $selectedOrderId > 0 ? '?order_id=' . $selectedOrderId : ''; ?>" class="btn btn-sm btn-outline-secondary"><?php echo __('reset_btn'); ?></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-2"><?php echo __('photo_col'); ?></th>
                        <th><?php echo __('part_name'); ?></th>
                        <th><?php echo __('sku'); ?></th>
                        <th><?php echo __('supplier_col'); ?></th>
                        <th><?php echo __('availability_col'); ?></th>
                        <th><?php echo __('in_stock_col'); ?></th>
                        <th><?php echo __('buy_price'); ?></th>
                        <th><?php echo __('sell_price'); ?></th>
                        <th class="text-end pe-2"><?php echo __('actions_col'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($catalog)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-white-75">
                                <i class="fas fa-boxes fa-3x mb-3 d-block opacity-25"></i>
                                <?php echo __('no_requests'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($catalog as $item): ?>
                        <?php
                            $supplierKey = (string)($item['source_supplier'] ?? '');
                            $supplierUrl = trim((string)($item['source_url'] ?? ''));
                            $availabilityText = trim((string)($item['supplier_availability'] ?? ''));
                            $availabilityQty = isset($item['supplier_stock_qty']) && $item['supplier_stock_qty'] !== null && $item['supplier_stock_qty'] !== '' ? (int)$item['supplier_stock_qty'] : null;
                        ?>
                        <tr>
                            <td class="ps-2">
                                <?php if (!empty($item['image_path'] ?? '')): ?>
                                    <a href="<?php echo e($item['image_path']); ?>" data-fancybox="procurement-catalog">
                                        <img src="<?php echo e($item['image_path']); ?>" class="rounded shadow-sm" style="width: 40px; height: 40px; object-fit: cover;">
                                    </a>
                                <?php else: ?>
                                    <div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center shadow-sm border border-secondary" style="width: 40px; height: 40px;">
                                        <i class="fas fa-image text-muted opacity-25"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><div class="fw-bold"><?php echo e($item['part_name']); ?></div></td>
                            <td><?php echo !empty($item['sku']) ? '<code>' . e($item['sku']) . '</code>' : '<span class="text-white-75">—</span>'; ?></td>
                            <td>
                                <?php if ($supplierKey !== ''): ?>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge bg-info text-dark d-inline-block"><?php echo e(supplierLabel($supplierKey)); ?></span>
                                        <?php if ($supplierUrl !== ''): ?>
                                            <a href="<?php echo e($supplierUrl); ?>" target="_blank" rel="noopener noreferrer" class="small text-decoration-none">
                                                <i class="fas fa-external-link-alt me-1"></i>Katalog
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-white-75">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($availabilityText !== ''): ?>
                                    <div class="fw-medium"><?php echo e($availabilityText); ?></div>
                                    <?php if ($availabilityQty !== null): ?>
                                        <div class="small text-white-75"><?php echo $availabilityQty; ?> <?php echo __('pcs_short'); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-white-75">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="fw-medium"><?php echo (int)($item['quantity'] ?? 0); ?> <?php echo __('pcs_short'); ?></span></td>
                            <td><?php echo formatMoney($item['cost_price'] ?? 0); ?></td>
                            <td class="fw-bold text-primary"><?php echo formatMoney($item['sale_price'] ?? 0); ?></td>
                            <td class="text-end pe-2">
                                <?php if ($can_order): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-success order-part-btn"
                                        data-inventory-id="<?php echo (int)$item['id']; ?>"
                                        data-part-name="<?php echo e($item['part_name']); ?>"
                                        data-supplier-key="<?php echo e($supplierKey); ?>"
                                        title="<?php echo __('order_part_title'); ?>">
                                        <i class="fas fa-cart-plus me-1"></i><?php echo __('order_part'); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-white-50">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($catalogPages > 1): ?>
        <?php
            $params_p = $_GET;
            unset($params_p['p']);
            $qs = http_build_query($params_p);
            $url_pre = $qs ? "&$qs" : "";

            $pagination_window = 10;
            if ($catalogPages <= $pagination_window) {
                $start_page = 1;
                $end_page = $catalogPages;
            } else {
                $half_window = (int)floor($pagination_window / 2);
                $start_page = max(1, $catalogPage - $half_window);
                $end_page = $start_page + $pagination_window - 1;
                if ($end_page > $catalogPages) {
                    $end_page = $catalogPages;
                    $start_page = max(1, $end_page - $pagination_window + 1);
                }
            }
        ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1 mb-0">
                <li class="page-item <?php echo $catalogPage <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo max(1, $catalogPage - 1) . $url_pre; ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php if ($start_page > 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $catalogPage == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i . $url_pre; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($end_page < $catalogPages): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item <?php echo $catalogPage >= $catalogPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo min($catalogPages, $catalogPage + 1) . $url_pre; ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Supplier stat cards -->
<div class="row g-3 mb-4">
    <?php foreach ($suppliers as $supplierKey => $supplier): ?>
        <?php $s = $stats[$supplierKey] ?? ['pending' => 0, 'ordered' => 0, 'received' => 0, 'cancelled' => 0, 'total_qty' => 0]; ?>
        <div class="col-md-4">
            <div class="card glass-card border-0 h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <div class="text-white-75 small"><?php echo __('supplier_col'); ?></div>
                            <h5 class="mb-0"><?php echo e($supplier['name']); ?></h5>
                        </div>
                        <span class="badge bg-primary bg-opacity-75"><?php echo (int)$s['total_qty']; ?> <?php echo __('pieces_short'); ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-3 small">
                        <span class="badge bg-warning text-dark"><?php echo __('pending_status'); ?>: <?php echo (int)$s['pending']; ?></span>
                        <span class="badge bg-primary"><?php echo __('ordered_status'); ?>: <?php echo (int)$s['ordered']; ?></span>
                        <span class="badge bg-success"><?php echo __('received_status'); ?>: <?php echo (int)$s['received']; ?></span>
                    </div>
                    <?php if ($can_add_procurement): ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-sm btn-outline-primary copy-supplier-list" data-supplier="<?php echo e($supplierKey); ?>">
                            <i class="fas fa-copy me-1"></i><?php echo __('copy_list'); ?>
                        </button>
                        <button class="btn btn-sm btn-outline-success open-supplier-request" data-supplier="<?php echo e($supplierKey); ?>">
                            <i class="fas fa-plus me-1"></i><?php echo __('add_btn_proc'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ── SEKUNDÁRNÍ SEKCE: Fronta objednávek ──────────────────────────────────── -->
<div class="card glass-card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
        <div>
            <h5 class="mb-0"><?php echo __('procurement_queue_section'); ?></h5>
            <div class="small text-white-75"><?php echo __('default_order_hint'); ?></div>
        </div>
        <div class="text-white-75 small"><?php echo count($openRequests); ?> <?php echo __('open_requests'); ?></div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?php echo __('supplier_col'); ?></th>
                    <th><?php echo __('part_col'); ?></th>
                    <th class="text-center"><?php echo __('qty_col'); ?></th>
                    <th><?php echo __('priority_col'); ?></th>
                    <th><?php echo __('status_col'); ?></th>
                    <th><?php echo __('order_col'); ?></th>
                    <th><?php echo __('note_col'); ?></th>
                    <th class="text-end"><?php echo __('actions_col'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="8" class="text-center text-white-75 py-5"><?php echo __('no_requests'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $supplierKey = (string)($request['supplier_key'] ?? '');
                            $supplierName = supplierLabel($supplierKey);
                            $status = (string)($request['status'] ?? 'pending');
                            $priority = (string)($request['priority'] ?? 'this_week');
                            $orderText = !empty($request['order_id']) ? '#' . (int)$request['order_id'] . ' ' . trim((string)($request['device_brand'] ?? '') . ' ' . (string)($request['device_model'] ?? '')) : '—';
                        ?>
                        <tr id="request-row-<?php echo (int)$request['id']; ?>">
                            <td>
                                <div class="fw-semibold"><?php echo e($supplierName); ?></div>
                                <div class="small text-white-75"><?php echo e($supplierKey); ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo e($request['item_name']); ?></div>
                                <?php if (!empty($request['sku'])): ?><div class="small text-white-75"><code><?php echo e($request['sku']); ?></code></div><?php endif; ?>
                            </td>
                            <td class="text-center fw-bold"><?php echo (int)$request['quantity']; ?></td>
                            <td><span class="badge <?php echo procurementPriorityBadge($priority); ?>"><?php echo procurementPriorityLabel($priority); ?></span></td>
                            <td><span class="badge <?php echo procurementStatusBadge($status); ?>"><?php echo procurementStatusLabel($status); ?></span></td>
                            <td class="small"><?php echo e($orderText); ?></td>
                            <td class="small text-white-75"><?php echo nl2br(e((string)($request['notes'] ?? ''))); ?></td>
                            <td class="text-end">
                                <?php if ($can_manage_procurement || $can_add_procurement): ?>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($can_add_procurement && (int)($request['inventory_id'] ?? 0) > 0 && $status !== 'cancelled'): ?>
                                        <button
                                            class="btn btn-outline-info assign-procurement-btn"
                                            data-id="<?php echo (int)$request['id']; ?>"
                                            data-item-name="<?php echo e((string)($request['item_name'] ?? '')); ?>"
                                            data-quantity="<?php echo (int)($request['quantity'] ?? 1); ?>"
                                            data-order-id="<?php echo (int)($request['order_id'] ?? 0); ?>"
                                            data-status="<?php echo e((string)$status); ?>"
                                            title="<?php echo e(__('assign_to_order')); ?>"
                                        >
                                            <i class="fas fa-link"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_manage_procurement): ?>
                                    <?php if ($status !== 'ordered'): ?>
                                        <button class="btn btn-outline-primary procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="ordered" title="<?php echo e(__('ordered_status')); ?>"><i class="fas fa-paper-plane"></i></button>
                                    <?php endif; ?>
                                    <?php if ($status !== 'received'): ?>
                                        <button class="btn btn-outline-success procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="received" title="<?php echo e(__('received_status')); ?>"><i class="fas fa-box-open"></i></button>
                                    <?php endif; ?>
                                    <?php if ($status !== 'cancelled'): ?>
                                        <button class="btn btn-outline-secondary procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="cancelled" title="<?php echo e(__('cancel')); ?>"><i class="fas fa-ban"></i></button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger procurement-delete-btn" data-id="<?php echo (int)$request['id']; ?>" title="<?php echo e(__('delete')); ?>"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                    <span class="text-white-50 small"><?php echo __('manager_only_short'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Order part modal (krok 3 — objednání z katalogu) -->
<div class="modal fade" id="orderPartModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="orderPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="inventory_id" id="orderPartInventoryId">
                <input type="hidden" name="supplier_key" id="orderPartSupplierKey">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('order_part_title'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo __('close'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 mb-3">
                        <div class="small text-dark-emphasis mb-1"><?php echo __('part_col'); ?></div>
                        <div class="fw-semibold" id="orderPartName">—</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('order_qty'); ?></label>
                            <input type="number" name="quantity" id="orderPartQty" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?php echo __('priority_col'); ?></label>
                            <select name="priority" id="orderPartPriority" class="form-select">
                                <option value="today"><?php echo __('today_priority'); ?></option>
                                <option value="this_week" selected><?php echo __('this_week_priority'); ?></option>
                                <option value="later"><?php echo __('later_priority'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('order_col'); ?></label>
                            <select name="order_id" id="orderPartOrder" class="form-select"></select>
                            <div class="form-text text-white-75"><?php echo __('proc_optional'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="button" id="orderPartSubmitBtn" class="btn btn-success"><i class="fas fa-cart-plus me-2"></i><?php echo __('order_part'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request modal -->
<div class="modal fade" id="requestModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="procurementRequestForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="order_id" value="<?php echo $selectedOrderId; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('add_part_request'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('supplier_col'); ?></label>
                            <select name="supplier_key" id="requestSupplier" class="form-select" required>
                                <option value=""><?php echo __('proc_select_supplier'); ?></option>
                                <?php foreach ($suppliers as $key => $supplier): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo $selectedSupplier === $key ? 'selected' : ''; ?>><?php echo e($supplier['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?php echo __('part_col'); ?></label>
                            <select name="inventory_id" id="requestInventory" class="form-select">
                                <option value=""><?php echo __('proc_manual_entry'); ?></option>
                            </select>
                            <div class="form-text text-white-75"><?php echo __('proc_catalog_hint'); ?></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?php echo __('item_name'); ?></label>
                            <input type="text" name="item_name" id="requestItemName" class="form-control" placeholder="<?php echo __('part_desc_placeholder'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('sku_code'); ?></label>
                            <input type="text" name="sku" id="requestSku" class="form-control" placeholder="<?php echo __('proc_optional'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('qty_col'); ?></label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('priority_col'); ?></label>
                            <select name="priority" class="form-select">
                                <option value="today"><?php echo __('today_priority'); ?></option>
                                <option value="this_week" selected><?php echo __('this_week_priority'); ?></option>
                                <option value="later"><?php echo __('later_priority'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('note_col'); ?></label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo __('proc_note_placeholder'); ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i><?php echo __('proc_save_queue'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign request to order modal -->
<div class="modal fade" id="assignProcurementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="assignProcurementForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="assign_order">
                <input type="hidden" name="request_id" id="assignRequestId">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('proc_assign_title'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo __('close'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 mb-3">
                        <div class="small text-dark-emphasis mb-1"><?php echo __('proc_sel_part'); ?></div>
                        <div class="fw-semibold" id="assignRequestItemName"></div>
                        <div class="small text-dark-emphasis mt-1" id="assignRequestStatus"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('order_col'); ?></label>
                        <select name="order_id" id="assignProcurementOrder" class="form-select" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('proc_qty'); ?></label>
                        <input type="number" name="quantity" id="assignProcurementQty" class="form-control" value="1" min="1" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-link me-2"></i><?php echo __('assign_to_order'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: přidat katalog dodavatele -->
<div class="modal fade" id="catalogAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form action="api/add_supplier_catalog.php" method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="fas fa-book me-2 text-info"></i><?php echo __('add_catalog'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo __('close'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('catalog_name'); ?></label>
                        <input type="text" name="catalog_name" class="form-control" placeholder="např. iFixit Store" required maxlength="80">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('catalog_url'); ?></label>
                        <div id="catalogAddUrls">
                            <div class="input-group mb-2 catalog-url-row">
                                <input type="url" name="catalog_urls[]" class="form-control" placeholder="https://obchod.cz/nahradni-dily/" required maxlength="500">
                                <button type="button" class="btn btn-outline-danger catalog-url-remove" title="<?php echo __('delete'); ?>" style="display:none;">&times;</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-info" id="catalogAddUrlBtn"><i class="fas fa-plus me-1"></i><?php echo __('catalog_add_url_row'); ?></button>
                        <div class="form-text text-white-75"><?php echo __('add_catalog_hint'); ?> <?php echo __('catalog_multi_hint'); ?></div>
                    </div>

                    <?php if (!empty($suppliers)): ?>
                    <hr class="border-secondary">
                    <label class="form-label mb-2"><?php echo __('catalog_existing'); ?></label>
                    <?php foreach ($suppliers as $exKey => $exSupplier): $exUrls = supplierCatalogUrls((string)($exSupplier['default_url'] ?? '')); ?>
                        <div class="d-flex align-items-center justify-content-between py-1 border-bottom border-secondary border-opacity-25">
                            <div class="me-2 text-truncate">
                                <span class="fw-semibold"><?php echo e($exSupplier['name']); ?></span>
                                <span class="text-white-50 small ms-1"><?php echo e($exSupplier['host']); ?> · <?php echo count($exUrls); ?>&times; URL</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger catalog-delete-btn" data-skey="<?php echo e($exKey); ?>" data-name="<?php echo e($exSupplier['name']); ?>" title="<?php echo __('catalog_delete'); ?>"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text text-white-75 mt-1"><?php echo __('catalog_delete_hint'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-info"><i class="fas fa-plus me-2"></i><?php echo __('add_catalog'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Catalog update modal (ruční aktualizace z katalogu) -->
<div class="modal fade" id="catalogUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="catalogUpdateForm" action="api/parse_catalog.php" method="POST" onsubmit="return confirmCatalogUpdate(this);">
                <?php echo csrfField(); ?>
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('update_catalog'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo __('close'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="catalogPreset" class="form-label"><?php echo __('quick_supplier_preset'); ?></label>
                        <select id="catalogPreset" class="form-select">
                            <option value=""><?php echo __('custom_url'); ?></option>
                            <?php foreach ($suppliers as $supplierKey => $supplier): ?>
                                <option value="<?php echo e($supplierKey); ?>" data-url="<?php echo e($supplier['default_url']); ?>">
                                    <?php echo e($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-white-75"><?php echo __('supplier_url_autofill_hint'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="catalogUrl" class="form-label"><?php echo __('catalog_url'); ?></label>
                        <textarea
                            id="catalogUrl"
                            name="catalog_url"
                            class="form-control"
                            rows="3"
                            placeholder="https://www.mobilnidily.cz/nahradni-dily-apple-iphone/"
                            required
                        ></textarea>
                        <div class="form-text text-white-75"><?php echo __('catalog_url_hint'); ?> <?php echo __('catalog_multi_lines_hint'); ?></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($suppliers as $supplierKey => $supplier): $firstUrl = supplierCatalogUrls((string)($supplier['default_url'] ?? ''))[0] ?? '#'; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($firstUrl); ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-external-link-alt me-1"></i><?php echo e($supplier['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync me-2"></i><?php echo __('update_catalog'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.PROCUREMENT_REQUESTS = <?php echo $requestsJson ?: '[]'; ?>;
window.PROCUREMENT_SUPPLIERS = <?php echo json_encode($suppliers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function procurementCopyText(supplierKey) {
    const supplier = (window.PROCUREMENT_SUPPLIERS || {})[supplierKey] || {name: supplierKey};
    const rows = (window.PROCUREMENT_REQUESTS || []).filter(r => (r.supplier_key || '') === supplierKey && ['pending', 'ordered'].includes(r.status));
    const lines = [supplier.name + ':'];
    if (!rows.length) {
        lines.push('- no open items');
        return lines.join('\n');
    }
    rows.forEach(r => {
        const sku = r.sku ? ' [' + r.sku + ']' : '';
        const orderRef = r.order_id ? ' #'+ r.order_id : '';
        lines.push('- ' + r.quantity + 'x ' + r.item_name + sku + orderRef);
    });
    return lines.join('\n');
}

function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    return Promise.resolve();
}

$(function() {
    const modalEl = document.getElementById('requestModal');
    const requestModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const assignModalEl = document.getElementById('assignProcurementModal');
    const assignModal = assignModalEl ? new bootstrap.Modal(assignModalEl) : null;
    const orderPartModalEl = document.getElementById('orderPartModal');
    const orderPartModal = orderPartModalEl ? new bootstrap.Modal(orderPartModalEl) : null;

    // ── Order part (catalog row → small order modal) ──────────────────────────
    if ($('#orderPartOrder').length) {
        $('#orderPartOrder').select2({
            dropdownParent: $('#orderPartModal'),
            width: '100%',
            allowClear: true,
            placeholder: '<?php echo __('proc_optional'); ?>',
            ajax: {
                url: 'api/search_orders.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return { q: params.term || '', limit: 20 };
                },
                processResults: function(data) {
                    return { results: data.results || [] };
                }
            }
        });
    }

    $('.order-part-btn').on('click', function() {
        const inventoryId = $(this).data('inventory-id');
        const partName = $(this).data('part-name') || '—';
        const supplierKey = String($(this).data('supplier-key') || '');

        $('#orderPartInventoryId').val(inventoryId);
        $('#orderPartSupplierKey').val(supplierKey);
        $('#orderPartName').text(partName);
        $('#orderPartQty').val(1);
        $('#orderPartPriority').val('this_week');
        $('#orderPartOrder').val(null).trigger('change');

        <?php if ($presetOrderId > 0): ?>
        const presetOrder = <?php echo (int)$presetOrderId; ?>;
        const presetLabel = <?php echo json_encode($presetOrderLabel !== '' ? $presetOrderLabel : ('#' . (int)$presetOrderId), JSON_UNESCAPED_UNICODE); ?>;
        const opt = new Option(presetLabel, presetOrder, true, true);
        $('#orderPartOrder').append(opt).trigger('change');
        <?php endif; ?>

        if (orderPartModal) orderPartModal.show();
    });

    function afxSubmitOrderPart() {
        var $btn = $('#orderPartSubmitBtn');
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        $.ajax({
            url: 'api/procurement_request.php',
            method: 'POST',
            data: $('#orderPartForm').serialize(),
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success) {
                if (window.afxLabelToast) window.afxLabelToast("🛒 <?php echo __('part_added_to_orders'); ?>", true);
                location.reload();
            } else {
                $btn.prop('disabled', false);
                showAlert('<?php echo __('order_save_failed'); ?>' + ((res && res.message) || '<?php echo __('unknown_error'); ?>'));
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false);
            var msg = '<?php echo __('unknown_error'); ?>';
            try { msg = (JSON.parse(xhr.responseText) || {}).message || msg; } catch (e) {}
            showAlert('<?php echo __('order_save_failed'); ?>' + msg);
        });
    }
    // Přímý click na tlačítko (type="button") — nezávislý na submit události, ať se vždy provede.
    $('#orderPartSubmitBtn').on('click', function(e) { e.preventDefault(); afxSubmitOrderPart(); });
    // Enter ve formuláři taky odešle (a nikdy se nedělá default reload stránky).
    $('#orderPartForm').on('submit', function(e) { e.preventDefault(); afxSubmitOrderPart(); return false; });

    $('.copy-supplier-list').on('click', function() {
        const supplierKey = $(this).data('supplier');
        copyTextToClipboard(procurementCopyText(supplierKey)).then(function() {
            showAlert("<?php echo __('list_copied'); ?>");
        });
    });

    $('.open-supplier-request').on('click', function() {
        const supplierKey = $(this).data('supplier');
        $('#requestSupplier').val(supplierKey).trigger('change');
        if (requestModal) requestModal.show();
    });

    $('#requestSupplier').on('change', function() {
        $('#requestInventory').val(null).trigger('change');
    });

    $('#requestInventory').select2({
        dropdownParent: $('#requestModal'),
        width: '100%',
        placeholder: '<?php echo __('search_supplier_catalog'); ?>',
        ajax: {
            url: 'api/search_catalog_items.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term || '',
                    supplier: $('#requestSupplier').val() || '',
                    limit: 20
                };
            },
            processResults: function(data) {
                return {
                    results: (data.results || []).map(function(item) {
                        return {
                            id: item.id,
                            text: item.text,
                            part_name: item.part_name,
                            sku: item.sku || '',
                            supplier_key: item.supplier_key || ''
                        };
                    })
                };
            }
        }
    });

    $('#requestInventory').on('select2:select', function(e) {
        const item = e.params.data || {};
        if (item.part_name) $('#requestItemName').val(item.part_name);
        if (item.sku) $('#requestSku').val(item.sku);
        if (item.supplier_key) $('#requestSupplier').val(item.supplier_key).trigger('change');
    });

    $('#assignProcurementOrder').select2({
        dropdownParent: $('#assignProcurementModal'),
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
                return { results: data.results || [] };
            }
        }
    });

    const statusLabels = {
        pending: '<?php echo __('pending_status'); ?>',
        ordered: '<?php echo __('ordered_status'); ?>',
        received: '<?php echo __('received_status'); ?>',
        cancelled: '<?php echo __('cancelled_status_proc'); ?>'
    };

    $('.assign-procurement-btn').on('click', function() {
        const requestId = $(this).data('id');
        const itemName = $(this).data('item-name') || '—';
        const qty = Math.max(1, Number($(this).data('quantity') || 1));
        const orderId = Number($(this).data('order-id') || 0);
        const status = String($(this).data('status') || 'pending');

        $('#assignRequestId').val(requestId);
        $('#assignRequestItemName').text(itemName);
        $('#assignRequestStatus').text('<?php echo __('procurement_status_label'); ?>' + (statusLabels[status] || status));
        $('#assignProcurementQty').val(qty);

        const $assignOrder = $('#assignProcurementOrder');
        $assignOrder.empty().trigger('change');
        if (orderId > 0) {
            const option = new Option('#' + orderId, orderId, true, true);
            $assignOrder.append(option).trigger('change');
        }

        if (assignModal) assignModal.show();
    });

    $('#assignProcurementForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/procurement_request.php', $(this).serialize(), function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert("<?php echo __('error_label'); ?>: " + res.message);
            }
        });
    });

    $('#procurementRequestForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/procurement_request.php', $(this).serialize(), function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert("<?php echo __('error_label'); ?>: " + res.message);
            }
        });
    });

    $('.procurement-status-btn').on('click', function() {
        const id = $(this).data('id');
        const status = $(this).data('status');
        $.post('api/procurement_request.php', {action: 'update', id: id, status: status}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert("<?php echo __('error_label'); ?>: " + res.message);
            }
        });
    });

    $('.procurement-delete-btn').on('click', function() {
        const id = $(this).data('id');
        if (!confirm("<?php echo __('confirm_delete_request'); ?>")) return;
        $.post('api/procurement_request.php', {action: 'delete', id: id}, function(res) {
            if (res.success) {
                $('#request-row-' + id).fadeOut();
            } else {
                showAlert("<?php echo __('error_label'); ?>: " + res.message);
            }
        });
    });

    // ── Catalog update modal: supplier preset autofill ────────────────────────
    const presets = {
        <?php foreach ($suppliers as $supplierKey => $supplier): ?>
        <?php echo json_encode($supplierKey); ?>: <?php echo json_encode($supplier['default_url']); ?>,
        <?php endforeach; ?>
    };

    $('#catalogPreset').on('change', function() {
        const url = presets[$(this).val()];
        if (url) {
            $('#catalogUrl').val(url);   // víc URL po řádcích se vloží všechny
        }
    });

    // ── Přidat katalog: více polí s odkazem ──
    $('#catalogAddUrlBtn').on('click', function() {
        const $wrap = $('#catalogAddUrls');
        const $row = $wrap.find('.catalog-url-row').first().clone();
        $row.find('input').val('').prop('required', false);
        $row.find('.catalog-url-remove').show();
        $wrap.append($row);
    });
    $('#catalogAddUrls').on('click', '.catalog-url-remove', function() {
        $(this).closest('.catalog-url-row').remove();
    });

    // ── Odstranění katalogu dodavatele ──
    $('.catalog-delete-btn').on('click', function() {
        const skey = $(this).data('skey');
        const name = $(this).data('name');
        showConfirm('<?php echo __('catalog_delete_confirm'); ?>'.replace('%s', name), function() {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'api/delete_supplier_catalog.php';
            f.innerHTML = <?php echo json_encode(csrfField()); ?> + '<input type="hidden" name="skey">';
            f.querySelector('input[name="skey"]').value = skey;
            document.body.appendChild(f);
            f.submit();
        });
    });

    if (<?php echo ($selectedOrderId > 0 && $can_add_procurement) ? 'true' : 'false'; ?> && requestModal) {
        requestModal.show();
    }
});

function confirmCatalogUpdate(form) {
    const urlInput = form.querySelector('[name="catalog_url"]');
    if (!urlInput || !urlInput.value.trim()) {
        return false;
    }

    showConfirm('<?php echo __('parse_confirm'); ?>', function() {
        // pojistka proti dvojímu spuštění: import běží minuty, druhý klik by ho rozjel znovu
        if (form.dataset.importRunning === '1') { return; }
        form.dataset.importRunning = '1';
        const btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Import běží…'; }
        form.submit();
    });

    return false;
}
</script>
