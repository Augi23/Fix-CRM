<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureProcurementSchema();

$suppliers = getSupplierCatalogs();
$selectedOrderId = max(0, (int)($_GET['order_id'] ?? 0));
$selectedSupplier = trim((string)($_GET['supplier'] ?? ''));

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
$can_add_procurement = $can_manage_procurement || (($_SESSION['role'] ?? '') === 'technician');
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h2 class="mb-1"><?php echo __('procurement_queue'); ?></h2>
        <div class="text-white-75 small"><?php echo __('proc_desc'); ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($can_add_procurement): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
            <i class="fas fa-plus me-2"></i><?php echo __('add_btn_proc'); ?>
        </button>
        <?php endif; ?>
        <a href="orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i><?php echo __('back_to_orders'); ?></a>
    </div>
</div>

<?php if ($selectedOrderId > 0 && !empty($pendingOrders)): ?>
    <div class="alert alert-info border-0 shadow-sm mb-4">
        <div class="fw-bold"><?php echo __('linked_to_order'); ?><?php echo $selectedOrderId; ?></div>
        <?php foreach ($pendingOrders as $order): ?>
            <div class="small text-dark-emphasis"><?php echo __('order_col'); ?>: <?php echo htmlspecialchars(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? '')); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($suppliers as $supplierKey => $supplier): ?>
        <?php $s = $stats[$supplierKey] ?? ['pending' => 0, 'ordered' => 0, 'received' => 0, 'cancelled' => 0, 'total_qty' => 0]; ?>
        <div class="col-md-4">
            <div class="card glass-card border-0 h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <div class="text-white-75 small"><?php echo __('supplier_col'); ?></div>
                            <h5 class="mb-0"><?php echo htmlspecialchars($supplier['name']); ?></h5>
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
                        <button class="btn btn-sm btn-outline-primary copy-supplier-list" data-supplier="<?php echo htmlspecialchars($supplierKey); ?>">
                            <i class="fas fa-copy me-1"></i><?php echo __('copy_list'); ?>
                        </button>
                        <button class="btn btn-sm btn-outline-success open-supplier-request" data-supplier="<?php echo htmlspecialchars($supplierKey); ?>">
                            <i class="fas fa-plus me-1"></i><?php echo __('add_btn_proc'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card glass-card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
        <div>
            <h5 class="mb-0"><?php echo __('request_overview'); ?></h5>
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
                                <div class="fw-semibold"><?php echo htmlspecialchars($supplierName); ?></div>
                                <div class="small text-white-75"><?php echo htmlspecialchars($supplierKey); ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($request['item_name']); ?></div>
                                <?php if (!empty($request['sku'])): ?><div class="small text-white-75"><code><?php echo htmlspecialchars($request['sku']); ?></code></div><?php endif; ?>
                            </td>
                            <td class="text-center fw-bold"><?php echo (int)$request['quantity']; ?></td>
                            <td><span class="badge <?php echo procurementPriorityBadge($priority); ?>"><?php echo procurementPriorityLabel($priority); ?></span></td>
                            <td><span class="badge <?php echo procurementStatusBadge($status); ?>"><?php echo procurementStatusLabel($status); ?></span></td>
                            <td class="small"><?php echo htmlspecialchars($orderText); ?></td>
                            <td class="small text-white-75"><?php echo nl2br(htmlspecialchars((string)($request['notes'] ?? ''))); ?></td>
                            <td class="text-end">
                                <?php if ($can_manage_procurement || $can_add_procurement): ?>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($can_add_procurement && (int)($request['inventory_id'] ?? 0) > 0 && $status !== 'cancelled'): ?>
                                        <button
                                            class="btn btn-outline-info assign-procurement-btn"
                                            data-id="<?php echo (int)$request['id']; ?>"
                                            data-item-name="<?php echo htmlspecialchars((string)($request['item_name'] ?? ''), ENT_QUOTES); ?>"
                                            data-quantity="<?php echo (int)($request['quantity'] ?? 1); ?>"
                                            data-order-id="<?php echo (int)($request['order_id'] ?? 0); ?>"
                                            data-status="<?php echo htmlspecialchars((string)$status, ENT_QUOTES); ?>"
                                            title="Assign to order"
                                        >
                                            <i class="fas fa-link"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_manage_procurement): ?>
                                    <?php if ($status !== 'ordered'): ?>
                                        <button class="btn btn-outline-primary procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="ordered" title="Ordered"><i class="fas fa-paper-plane"></i></button>
                                    <?php endif; ?>
                                    <?php if ($status !== 'received'): ?>
                                        <button class="btn btn-outline-success procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="received" title="Received"><i class="fas fa-box-open"></i></button>
                                    <?php endif; ?>
                                    <?php if ($status !== 'cancelled'): ?>
                                        <button class="btn btn-outline-secondary procurement-status-btn" data-id="<?php echo (int)$request['id']; ?>" data-status="cancelled" title="Cancel"><i class="fas fa-ban"></i></button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger procurement-delete-btn" data-id="<?php echo (int)$request['id']; ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                    <span class="text-white-50 small">Manager only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $selectedSupplier === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($supplier['name']); ?></option>
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
                            <label class="form-label">SKU / kód</label>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-link me-2"></i>Assign to order</button>
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

    $('.copy-supplier-list').on('click', function() {
        const supplierKey = $(this).data('supplier');
        copyTextToClipboard(procurementCopyText(supplierKey)).then(function() {
            showAlert('List copied.');
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
        placeholder: 'Search selected supplier catalog',
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
        placeholder: 'Search order',
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
        pending: 'Pending ordering',
        ordered: 'Ordered',
        received: 'Received',
        cancelled: 'Cancelled'
    };

    $('.assign-procurement-btn').on('click', function() {
        const requestId = $(this).data('id');
        const itemName = $(this).data('item-name') || '—';
        const qty = Math.max(1, Number($(this).data('quantity') || 1));
        const orderId = Number($(this).data('order-id') || 0);
        const status = String($(this).data('status') || 'pending');

        $('#assignRequestId').val(requestId);
        $('#assignRequestItemName').text(itemName);
        $('#assignRequestStatus').text('Procurement status: ' + (statusLabels[status] || status));
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
                showAlert('Error: ' + res.message);
            }
        });
    });

    $('#procurementRequestForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/procurement_request.php', $(this).serialize(), function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('Error: ' + res.message);
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
                showAlert('Error: ' + res.message);
            }
        });
    });

    $('.procurement-delete-btn').on('click', function() {
        const id = $(this).data('id');
        if (!confirm('Are you sure you want to delete this request?')) return;
        $.post('api/procurement_request.php', {action: 'delete', id: id}, function(res) {
            if (res.success) {
                $('#request-row-' + id).fadeOut();
            } else {
                showAlert('Error: ' + res.message);
            }
        });
    });

    if (<?php echo ($selectedOrderId > 0 && $can_add_procurement) ? 'true' : 'false'; ?> && requestModal) {
        requestModal.show();
    }
});
</script>
