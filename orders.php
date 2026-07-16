<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

require_once 'includes/scan_resolver.php';

ensureOrderCreatedByColumn();   // sloupec „kdo zakázku vytvořil" (levý sloupec seznamu)

require_once 'includes/header.php';

// ── Pagination ────────────────────────────────────────────────────────────────
$limit  = 13;
$page   = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $limit;

// FIX #7: Whitelist filter values to prevent unexpected SQL behavior
$allowed_statuses = getAllowedOrderFilterStatuses();
$filter_status    = in_array($_GET['filter'] ?? '', $allowed_statuses, true) ? $_GET['filter'] : null;

$orders       = [];
$total_orders = 0;

if (isset($pdo)) {
    try {
        $search = trim($_GET['search'] ?? '');

        $where_clauses = [];
        $sql_params    = []; // FIX #9: renamed from $params to avoid collision with pagination block

        addOrderBranchScope($where_clauses, $sql_params, 'o');
        $branch_filter = isBranchGlobalViewer() ? (int)($_GET['branch_id'] ?? 0) : 0;
        if ($branch_filter > 0) {
            $where_clauses[] = 'o.branch_id = ?';
            $sql_params[] = $branch_filter;
        }

        // FIX #1: exact ID match also via PDO parameter
        if ($search !== '') {
            $term = "%$search%";
            if (is_numeric($search)) {
                $where_clauses[] = '(o.order_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ? OR o.id = ?)';
                for ($i = 0; $i < 9; $i++) $sql_params[] = $term;
                $sql_params[] = (int)$search;
            } else {
                $where_clauses[] = '(o.order_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?)';
                for ($i = 0; $i < 9; $i++) $sql_params[] = $term;
            }
        }

        if ($filter_status) {
            $filter_group = getOrderStatusGroup($filter_status);
            if ($filter_group !== null) {
                $filter_key = $filter_group === 'completed' ? 'done' : $filter_group;
                $where_clauses[] = 'o.status IN (' . orderStatusSqlIn($pdo, $filter_key) . ')';
            } else {
                $where_clauses[] = 'o.status = ?';
                $sql_params[]    = $filter_status;
            }
        }

        $where_sql = $where_clauses ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

        // Rezervace z webu se zobrazují jako běžné zakázky (auto-vytvoření ve webhooku).
        // Pojistka: pokud některá konverze dřív selhala, potichu ji zopakovat tady.
        try {
            ensureWebBookingsSchema();
            $pendingWb = $pdo->query("SELECT id FROM web_bookings WHERE status = 'new' ORDER BY id ASC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($pendingWb as $wbId) { crmCreateOrderFromWebBooking((int)$wbId); }
        } catch (Throwable $e) { /* jen pojistka */ }

        // Count
        $count_stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM orders o JOIN customers c ON o.customer_id = c.id' . $where_sql
        );
        $count_stmt->execute($sql_params);
        $total_orders = (int)$count_stmt->fetchColumn();

        // Fetch orders
        $search_id      = is_numeric($search) ? (int)$search : 0;
        $fetch_params   = $sql_params;
        $fetch_params[] = $search_id;

        $stmt = $pdo->prepare(
            'SELECT o.*, c.first_name, c.last_name, c.phone, c.email, t.name as tech_name,
                    (SELECT MAX(l.changed_at) FROM order_status_log l WHERE l.order_id = o.id) AS last_status_change,
                    (SELECT MAX(wb.appointment_at) FROM web_bookings wb WHERE wb.order_id = o.id) AS web_appointment_at
             FROM orders o
             JOIN customers c ON o.customer_id = c.id
             LEFT JOIN technicians t ON o.technician_id = t.id'
            . $where_sql
            . ' ORDER BY ' . orderSortSql('o', 'o.id = ?') . "
              LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($fetch_params);
        $orders = $stmt->fetchAll();

        // FIX #4: Pre-load media flags in one query instead of N+1 in loop
        $has_media_ids = [];
        if (!empty($orders)) {
            $order_ids    = array_column($orders, 'id');
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $m_stmt       = $pdo->prepare(
                "SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id"
            );
            $m_stmt->execute($order_ids);
            $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
        }

    } catch (PDOException $e) {
        error_log('orders.php query error: ' . $e->getMessage());
    }
}

$total_pages = $total_orders > 0 ? (int)ceil($total_orders / $limit) : 1;
$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

$sla_new_hours = (int)get_setting('sla_new_hours', 24);
$sla_progress_hours = (int)get_setting('sla_progress_hours', 72);
$now_ts = time();

// FIX #9 (stats): single query instead of 4 separate queries
$s_new = $s_pending = $s_progress = $s_ready = 0;
if (isset($pdo)) {
    try {
        $stats_where  = '';
        $stats_params = [];
        if (!isBranchGlobalViewer()) {
            $stats_where  = ' AND branch_id = ?';
            $stats_params[] = getCurrentStaffBranchId();
        } elseif ((int)($_GET['branch_id'] ?? 0) > 0) {
            $stats_where = ' AND branch_id = ?';
            $stats_params[] = (int)$_GET['branch_id'];
        }
        $s = $pdo->prepare(
            "SELECT
                SUM(status IN (" . orderStatusSqlIn($pdo, 'new') . ")) as cnt_new,
                SUM(status IN (" . orderStatusSqlIn($pdo, 'pending_approval') . ")) as cnt_pending,
                SUM(status IN (" . orderStatusSqlIn($pdo, 'in_progress') . ")) as cnt_progress,
                SUM(status IN (" . orderStatusSqlIn($pdo, 'done') . ")) as cnt_ready
             FROM orders WHERE 1=1" . $stats_where
        );
        $s->execute($stats_params);
        $stats_row  = $s->fetch();
        $s_new      = (int)($stats_row['cnt_new'] ?? 0);
        $s_pending  = (int)($stats_row['cnt_pending'] ?? 0);
        $s_progress = (int)($stats_row['cnt_progress'] ?? 0);
        $s_ready    = (int)($stats_row['cnt_ready'] ?? 0);
    } catch (PDOException $e) {
        error_log('orders.php stats error: ' . $e->getMessage());
    }
}

// FIX #5: Load technicians once (used in both New Order and Quick Edit modals)
$techs_list = getActiveTechnicians(true);   // volny vyber technika — vsichni aktivni
$branches = getBranches();
$active_branch_filter = isBranchGlobalViewer() ? (int)($_GET['branch_id'] ?? 0) : getCurrentStaffBranchId();
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-md-3">
        <a href="?filter=<?php echo urlencode('Přijato'); ?>" class="text-decoration-none">
            <div class="card bg-primary bg-opacity-10 border-0 p-3 <?php echo isOrderStatusIn($filter_status, 'new') ? 'ring-2 ring-primary border-primary border-1 shadow-sm' : ''; ?>">
                <div class="d-flex align-items-center">
                    <i class="fas fa-clipboard-list text-primary fa-2x me-3"></i>
                    <div>
                        <h4 class="mb-0 text-white"><?php echo $s_new; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('new_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <a href="?filter=<?php echo urlencode('Čeká na zákazníka'); ?>" class="text-decoration-none">
            <div class="card bg-info bg-opacity-10 border-0 p-3 <?php echo isOrderStatusIn($filter_status, 'pending_approval') ? 'ring-2 ring-info border-info border-1 shadow-sm' : ''; ?>">
                <div class="d-flex align-items-center">
                    <i class="fas fa-handshake text-info fa-2x me-3"></i>
                    <div>
                        <h4 class="mb-0 text-white"><?php echo $s_pending; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('pending_approval_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <a href="?filter=<?php echo urlencode('V opravě'); ?>" class="text-decoration-none">
            <div class="card bg-warning bg-opacity-10 border-0 p-3 <?php echo isOrderStatusIn($filter_status, 'in_progress') ? 'ring-2 ring-warning border-warning border-1 shadow-sm' : ''; ?>">
                <div class="d-flex align-items-center">
                    <i class="fas fa-spinner text-warning fa-2x me-3"></i>
                    <div>
                        <h4 class="mb-0 text-white"><?php echo $s_progress; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('in_progress_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <a href="?filter=<?php echo urlencode('Připraveno k převzetí'); ?>" class="text-decoration-none">
            <div class="card bg-success bg-opacity-10 border-0 p-3 <?php echo (isOrderStatusIn($filter_status, 'done')) ? 'ring-2 ring-success border-success border-1 shadow-sm' : ''; ?>">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-double text-success fa-2x me-3"></i>
                    <div>
                        <h4 class="mb-0 text-white"><?php echo $s_ready; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('completed_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><?php echo __('orders'); ?></h2>
        <?php if($filter_status): ?>
            <?php $status_label = getOrderStatusLabel($filter_status); ?>
            <div class="mt-1">
                <span class="badge bg-secondary text-white"><?php echo e(__('status')); ?>: <?php echo e($status_label); ?></span>
                <a href="orders.php" class="text-danger small ms-2"><i class="fas fa-times me-1"></i><?php echo e(__('cancel')); ?></a>
            </div>
        <?php else: ?>
            <small class="text-white-75"><?php echo __('all_orders'); ?>: <?php echo $total_orders; ?></small>
        <?php endif; ?>
        <?php if (isBranchGlobalViewer() && !empty($branches)): ?>
            <div class="mt-2 d-flex flex-wrap gap-2">
                <a class="badge rounded-pill text-decoration-none <?php echo $active_branch_filter === 0 ? 'bg-primary' : 'bg-secondary'; ?>" href="orders.php<?php echo $filter_status ? '?filter=' . urlencode($filter_status) : ''; ?>"><?php echo __('all_branches'); ?></a>
                <?php foreach ($branches as $branch): ?>
                    <?php $qs = http_build_query(array_filter(['filter' => $filter_status, 'branch_id' => (int)$branch['id']])); ?>
                    <a class="badge rounded-pill text-decoration-none <?php echo $active_branch_filter === (int)$branch['id'] ? 'bg-primary' : 'bg-secondary'; ?>" href="orders.php?<?php echo e($qs); ?>"><?php echo e($branch['name']); ?></a>
                <?php endforeach; ?>
            </div>
        <?php elseif (!isBranchGlobalViewer()): ?>
            <div class="mt-1"><span class="badge bg-secondary"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel(getCurrentStaffBranchId())); ?></span></div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if(!empty($_GET['search'])): ?>
            <a href="orders.php" class="btn btn-outline-secondary"><?php echo __('cancel'); ?></a>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOrderModal">
            <i class="fas fa-plus me-2"></i> <?php echo __('new_order'); ?>
        </button>
    </div>
</div>

<?php /* Sdílené horní dlaždice — stejné rozvržení i obsah jako na Nástěnce */ ?>
<div class="crm-stat-row mb-4">
<?php include __DIR__ . '/includes/partials/stat_tiles.php'; ?>
</div>

<?php
// ── Filtr podle stavu (chips) — všechny aktuální stavy + „Vše" ──
$status_defs = getOrderStatusDefinitions();
$branch_qs   = ($active_branch_filter > 0 && isBranchGlobalViewer()) ? '&branch_id=' . (int)$active_branch_filter : '';
$search_qs   = !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
?>
<div class="orders-status-filter d-flex flex-wrap gap-2 mb-3">
    <a href="orders.php?<?php echo ltrim($branch_qs . $search_qs, '&'); ?>"
       class="crm-filter-chip<?php echo $filter_status ? '' : ' active'; ?>">
        <i class="fas fa-layer-group"></i> <?php echo __('all_orders'); ?>
    </a>
    <?php foreach ($status_defs as $st => $meta): if (!empty($meta['legacy'])) continue; ?>
        <a href="orders.php?filter=<?php echo urlencode($st) . $branch_qs . $search_qs; ?>"
           class="crm-filter-chip chip--<?php echo e($meta['badge']); ?><?php echo ($filter_status === $st) ? ' active' : ''; ?>">
            <span class="chip-dot"></span><?php echo e(getOrderStatusLabel($st)); ?>
        </a>
    <?php endforeach; ?>
</div>


<div class="card glass-card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive orders-table-wrap">
            <table class="table table-hover align-middle mb-0 orders-center-table">
                <thead class="bg-transparent sticky-top" style="z-index: 10;">
                    <tr>
                        <th class="ps-4"><?php echo __('order_no'); ?> / <?php echo __('created'); ?></th>
                        <th><?php echo __('client'); ?></th>
                        <th><?php echo __('device_model'); ?></th>
                        <th><?php echo __('problem'); ?></th>
                        <th><?php echo __('status'); ?></th>
                        <th class="col-priority"><?php echo __('priority'); ?></th>
                        <th><?php echo __('amount'); ?></th>
                        <th class="text-end pe-4"><?php echo __('action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-white-75">
                                <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>
                                <?php echo __('not_found'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <?php
                            // FIX #4: use pre-loaded array instead of per-row query
                            $has_media   = isset($has_media_ids[$order['id']]);
                            $device_icon = getDeviceIcon($order['device_type']);
                            $client_phone = $order['phone'] ?? '';
                            $phone_clean  = normalizePhoneForTel($client_phone);
                        ?>
                        <?php [$staleCls, $staleTitle] = orderStaleRowAttrs($order); ?>
                        <?php $__isInternal = crmIsInternalCustomer($order['customer_id'] ?? 0); ?>
                        <tr class="order-row order-row--status-<?php echo e(getOrderStatusBadgeToken($order['status'])); ?><?php echo $staleCls; ?><?php echo $__isInternal ? ' order-row--internal' : ''; ?>"<?php echo $staleTitle ? ' title="' . e($staleTitle) . '"' : ''; ?> data-order-url="view_order.php?id=<?php echo (int)$order['id']; ?>" style="cursor: pointer;">
                            <td class="ps-4">
                                <a href="view_order.php?id=<?php echo (int)$order['id']; ?>" class="fw-bold text-decoration-none"><?php echo e(orderDisplayCode($order)); ?></a>
                                <?php if($has_media): ?>
                                    <i class="fas fa-camera text-info ms-1" title="<?php echo __('media_files'); ?>"></i>
                                <?php endif; ?>
                                <div class="small text-white-75"><?php echo date('d.m.Y', strtotime($order['created_at'])); ?></div>
                                <div class="small text-white-75"><i class="far fa-clock me-1" style="font-size:.7rem;"></i><?php echo date('H:i:s', strtotime($order['created_at'])); ?></div>
                                <?php if (trim((string)($order['created_by_name'] ?? '')) !== ''): ?>
                                    <div class="text-white-50" style="font-size:.72rem;" title="Zakázku vytvořil(a)"><i class="fas fa-user-pen me-1" style="font-size:.65rem;"></i><?php echo e($order['created_by_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($__isInternal): ?>
                                    <div><span class="afx-internal-chip" title="Interní zakázka — není pro veřejného klienta"><i class="fas fa-screwdriver-wrench"></i>Interní</span></div>
                                    <div class="small text-white-50 mt-1"><?php echo e($order['first_name'] . ' ' . $order['last_name']); ?></div>
                                <?php else: ?>
                                    <div><?php echo e($order['first_name'] . ' ' . $order['last_name']); ?></div>
                                <?php endif; ?>
                                <?php if($client_phone): ?>
                                    <?php if ($phone_clean !== ''): ?>
                                    <a class="phone-qr-trigger small text-white-75 text-decoration-none crm-phone-text"
                                       href="tel:<?php echo e($phone_clean); ?>"
                                       data-phone="<?php echo e($phone_clean); ?>"
                                       onclick="event.stopPropagation();">
                                        <i class="fas fa-phone me-1 text-success"></i><?php echo e($client_phone); ?>
                                    </a>
                                    <?php else: ?>
                                    <div class="small text-white-75 crm-phone-text">
                                        <i class="fas fa-phone me-1 text-success"></i><?php echo e($client_phone); ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($order['email'])): ?>
                                    <div><a class="small text-white-75 text-decoration-none" href="mailto:<?php echo e($order['email']); ?>" onclick="event.stopPropagation();">
                                        <i class="fas fa-envelope me-1 text-info"></i><?php echo e($order['email']); ?>
                                    </a></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-medium text-primary"><?php echo $device_icon; ?> <?php echo htmlspecialchars($order['device_brand']); ?></div>
                                <div class="small text-white-75"><?php echo htmlspecialchars($order['device_model']); ?></div>
                                <?php if(!empty($order['serial_number'])): ?>
                                    <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn1'); ?>: <?php echo htmlspecialchars($order['serial_number']); ?></div>
                                <?php endif; ?>
                                <?php if(!empty($order['serial_number_2'])): ?>
                                    <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn2'); ?>: <?php echo htmlspecialchars($order['serial_number_2']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 240px; font-size: 0.92rem; line-height: 1.35;" title="<?php echo htmlspecialchars($order['problem_description']); ?>">
                                    <?php echo htmlspecialchars($order['problem_description']); ?>
                                </div>
                            </td>
                            <td class="cell-status">
                                <?php echo getStatusBadge($order['status']); ?>
                                <?php if (!empty($order['web_appointment_at'])):
                                    $wbTs = strtotime((string)$order['web_appointment_at']);
                                    $wbToday = $wbTs && date('Y-m-d', $wbTs) === date('Y-m-d');
                                ?>
                                <div class="afx-booked-chip<?php echo $wbToday ? ' is-today' : ''; ?>" title="<?php echo e(__('web_booking_no')); ?>">
                                    <i class="far fa-calendar-check"></i>
                                    <b><?php echo $wbToday ? __('today') : date('j.n.', $wbTs); ?> <?php echo date('H:i', $wbTs); ?></b>
                                </div>
                                <?php endif; ?>
                                <?php if(!empty($order['shipping_method'])): ?>
                                    <div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i><?php echo htmlspecialchars($order['shipping_method']); ?></div>
                                <?php endif; ?>
                                <?php if($_SESSION['role'] == 'admin' && $order['extra_expenses'] > 0): ?>
                                    <div class="mt-1 small text-danger"><i class="fas fa-minus-circle me-1"></i><?php echo __('extra_expenses'); ?>: <?php echo e($order['extra_expenses']); ?></div>
                                <?php endif; ?>
                                <div class="small text-white-75 mt-1">
                                    <i class="far fa-clock me-1"></i><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?>
                                </div>
                                <?php if(!empty($order['tech_name'])): ?>
                                <div class="small text-white-75 mt-1">
                                    <i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($order['tech_name']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isBranchGlobalViewer() && !empty($order['branch_id'])): ?>
                                <div class="small mt-1"><span class="badge bg-dark border border-secondary"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel((int)$order['branch_id'])); ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-priority"><?php echo getOrderPriorityBadge($order['priority'] ?? 'Normal', (string)$order['status']); ?></td>
                            <td class="fw-bold text-white"><?php echo formatMoney($order['final_cost'] ?: $order['estimated_cost']); ?></td>
                            <td class="text-end pe-4">
                                <?php
                                    // Od 1.6.1: rychlou změnu stavu smí provést KAŽDÝ zaměstnanec
                                    // (dřív jen vedoucí nebo přiřazený technik — u cizích/nepřiřazených
                                    // zakázek technici neměli v seznamu žádné rychlé ovládání).
                                    $can_quick = true;
                                ?>
                                <?php
                                    $can_cancel = !isOrderStatusIn($order['status'], 'cancelled') && !isOrderStatusIn($order['status'], 'collected');
                                    $show_quick = $can_quick && (
                                        !isOrderStatusIn($order['status'], 'terminal') || $can_cancel
                                    );
                                ?>
                                <?php // inline quick-status buttons removed; using dropdown only ?>
                                <div class="btn-group btn-group-sm shadow-sm">
                                    <?php if ($show_quick): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" title="<?php echo __('quick_status'); ?>">
                                            <i class="fas fa-bolt text-primary"></i>
                                        </button>
                                        <ul class="dropdown-menu shadow dropdown-menu-end">
                                            <?php if (isOrderStatusIn($order['status'], 'new')): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="javascript:void(0)" data-id="<?php echo (int)$order['id']; ?>" data-status="V opravě"><i class="fas fa-play me-2 text-primary"></i><?php echo __('move_to_in_progress'); ?></a></li>
                                            <?php elseif (isOrderStatusIn($order['status'], 'pending_approval') || isOrderStatusIn($order['status'], 'waiting_parts') || isOrderStatusIn($order['status'], 'in_progress')): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="javascript:void(0)" data-id="<?php echo (int)$order['id']; ?>" data-status="Připraveno k převzetí"><i class="fas fa-check me-2 text-success"></i><?php echo __('move_to_completed'); ?></a></li>
                                            <?php elseif (isOrderStatusIn($order['status'], 'completed') || isOrderStatusIn($order['status'], 'uncollected')): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="javascript:void(0)" data-id="<?php echo (int)$order['id']; ?>" data-status="Vydáno"><i class="fas fa-box me-2 text-info"></i><?php echo __('move_to_collected'); ?></a></li>
                                            <?php endif; ?>
                                            <?php if ($can_cancel): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="javascript:void(0)" data-id="<?php echo (int)$order['id']; ?>" data-status="Stornováno"><i class="fas fa-ban me-2 text-danger"></i><?php echo __('cancel'); ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" title="<?php echo __('print'); ?>">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <ul class="dropdown-menu shadow dropdown-menu-end">
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="openUniversalPreview('print_order.php?id=<?php echo $order['id']; ?>', '<?php echo __('order_header'); ?> #<?php echo $order['id']; ?>')"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="openUniversalPreview('print_workshop.php?id=<?php echo $order['id']; ?>', '<?php echo __('work_order'); ?> #<?php echo $order['id']; ?>')"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                                        </ul>
                                    </div>
                                    <?php /* Účtování: admin a Boss; mazání zůstává jen adminovi */ ?>
                                    <?php if (crmCanManageInvoices()): ?>
                                    <button type="button" class="btn btn-outline-secondary accounting-btn" data-id="<?php echo $order['id']; ?>" title="<?php echo __('accounting'); ?>">
                                        <i class="fas fa-file-invoice-dollar text-success"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (hasPermission('admin_access')): ?>
                                    <button type="button" class="btn btn-outline-danger" onclick="event.stopPropagation(); deleteOrder(<?php echo (int)$order['id']; ?>);" title="<?php echo __('delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav class="mt-4">
    <ul class="pagination justify-content-center">
        <?php
        // FIX #9: renamed to $query_params to avoid confusion with SQL $sql_params
        $query_params = $_GET;
        unset($query_params['p']);
        $url_prefix = ($qs = http_build_query($query_params)) ? "&$qs" : '';
        ?>
        
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $page - 1 . $url_prefix; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>

        <?php 
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        
        if ($start > 1) {
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=1'.$url_prefix.'">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }

        for ($i = $start; $i <= $end; $i++): 
        ?>
            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $i . $url_prefix; ?>">
                    <?php echo $i; ?>
                </a>
            </li>
        <?php endfor; ?>

        <?php 
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p='.$total_pages.$url_prefix.'">'.$total_pages.'</a></li>';
        }
        ?>

        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $page + 1 . $url_prefix; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
<?php endif; ?>



<!-- QR Popover Container -->
<div class="qr-popover" id="phoneQrPopover">
    <div class="qr-phone-label" id="qrPhoneLabel"></div>
    <div id="qrContainer"></div>
    <a href="#" class="btn btn-sm btn-success qr-call-btn" id="qrCallBtn">
        <i class="fas fa-phone me-1"></i><?php echo __('call'); ?>
    </a>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="quickStatusToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="quickStatusToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
// Phone QR Popover using Google Charts API (no library needed)
document.addEventListener('DOMContentLoaded', function() {
    const popover = document.getElementById('phoneQrPopover');
    const qrContainer = document.getElementById('qrContainer');
    const phoneLabel = document.getElementById('qrPhoneLabel');
    const callBtn = document.getElementById('qrCallBtn');
    let hideTimeout;

    document.querySelectorAll('.phone-qr-trigger').forEach(el => {
        el.addEventListener('mouseenter', function(e) {
            clearTimeout(hideTimeout);
            const phone = this.dataset.phone;
            if (!phone) return;

            // Generate QR code using QR Server API
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent('tel:' + phone);
            qrContainer.innerHTML = '<img src="' + qrUrl + '" alt="QR" style="width:120px;height:120px;">';

            phoneLabel.textContent = phone;
            callBtn.href = 'tel:' + phone;

            // Position popover
            const rect = e.target.getBoundingClientRect();
            popover.style.left = (rect.right + 10) + 'px';
            popover.style.top = rect.top + 'px';
            popover.style.display = 'block';
        });

        el.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                popover.style.display = 'none';
            }, 300);
        });
    });

    popover.addEventListener('mouseenter', function() {
        clearTimeout(hideTimeout);
    });

    popover.addEventListener('mouseleave', function() {
        popover.style.display = 'none';
    });
});
</script>

<!-- New Order Modal moved to includes/modals/new_order_modal.php and included via footer.php -->

<!-- Quick View & Edit Modal -->
<div class="modal fade" id="quickOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickOrderTitle"><?php echo __('order_header'); ?> #</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="quickOrderBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div>
                    <?php if(hasPermission('admin_access')): ?>
                    <button type="button" class="btn btn-outline-danger me-2" id="deleteQuickOrderBtn"><?php echo __('delete'); ?></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                </div>
                <div class="d-flex gap-2">
                    <div class="dropdown dropup">
                        <button class="btn btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport">
                            <i class="fas fa-print me-2"></i> <?php echo __('print'); ?>
                        </button>
                        <ul class="dropdown-menu shadow dropdown-menu-end">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="openUniversalPreview('print_order.php?id=${o.id}', '<?php echo __('order_header'); ?> #' + o.id)"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="openUniversalPreview('print_workshop.php?id=${o.id}', '<?php echo __('work_order'); ?> #' + o.id)"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                        </ul>
                    </div>
                    <a href="#" id="fullViewBtn" class="btn btn-outline-primary"><?php echo __('open_full_view'); ?></a>
                    <button type="button" class="btn btn-primary" id="saveQuickOrderBtn"><?php echo __('save_changes'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// FIX #3: escHTML prevents XSS when injecting data into innerHTML/template literals
function escHTML(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}

const canEditQuickTechnician = true;   // od 1.6.0: technika smi zmenit kazdy zamestnanec

$(document).ready(function() {
    $('.order-modal-trigger').on('click', function() {
        const id = $(this).data('id');
        $('#quickOrderTitle').text('<?php echo __('order_header'); ?> #' + id);
        $('#quickOrderModal').modal('show');
        $('#quickOrderBody').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');
        $('#fullViewBtn').attr('href', 'view_order.php?id=' + id);
        $('#saveQuickOrderBtn').prop('disabled', true);

        $.get('api/get_order_details.php', {id: id}, function(res) {
            if (res && res.success) {
                const o = res.order;
                const attachments = res.attachments || [];
                
                let mediaHtml = '';
                if (attachments.length > 0) {
                    mediaHtml = '<div class="row g-2 mt-2">';
                    attachments.forEach(file => {
                        const isVideo = String(file.file_type || '').includes('video');
                        mediaHtml += `
                            <div class="col-3 col-md-2" id="media-item-${file.id}">
                                <div class="card h-100 shadow-sm border position-relative">
                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-1 line-height-1" style="z-index: 10; font-size: 0.6rem;" onclick="deleteMedia(${file.id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="ratio ratio-1x1 bg-dark bg-opacity-25 border-secondary overflow-hidden">
                                        ${isVideo ? 
                                            `<div class="w-100 h-100 d-flex align-items-center justify-content-center bg-dark"><i class="fas fa-video text-white"></i></div>` : 
                                            `<img src="${file.file_path}" class="w-100 h-100 object-fit-cover d-block" alt="Attachment">`
                                        }
                                    </div>
                                </div>
                            </div>`;
                    });
                    mediaHtml += '</div>';
                } else {
                    mediaHtml = '<div class="text-white-75 small mt-2"><?php echo __('no_media_files'); ?></div>';
                }

                let html = `
                    <form id="quickOrderForm" enctype="multipart/form-data">
                        <input type="hidden" name="order_id" value="${(+o.id) || 0}">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('client_and_date'); ?></label>
                                <div class="fw-bold">${escHTML(o.first_name)} ${escHTML(o.last_name)}</div>
                                <div class="small text-primary"><i class="far fa-clock me-1"></i>${new Date(o.created_at).toLocaleString()}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('device'); ?></label>
                                <div class="fw-bold">${escHTML(o.device_brand)} ${escHTML(o.device_model)}</div>
                                <div class="badge ${o.order_type == 'Warranty' ? 'bg-success' : 'bg-secondary'}">${o.order_type == 'Warranty' ? '<?php echo __('warranty'); ?>' : '<?php echo __('non_warranty'); ?>'}</div>
                                ${o.shipping_method ? `<div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i>${escHTML(o.shipping_method)}</div>` : ''}
                                ${res.role == 'admin' && o.extra_expenses > 0 ? `<div class="mt-1 small text-danger"><i class="fas fa-minus-circle me-1"></i><?php echo __('extra_expenses'); ?>: ${escHTML(o.extra_expenses)}</div>` : ''}
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('serial_numbers'); ?></label>
                                <div class="small"><?php echo __('sn1'); ?>: <strong>${escHTML(o.serial_number) || '---'}</strong></div>
                                <div class="small"><?php echo __('sn2'); ?>: <strong>${escHTML(o.serial_number_2) || '---'}</strong></div>
                            </div>
                            
                            <hr class="my-3">

                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('status'); ?></label>
                                <select name="status" class="form-select">
                                    <?php foreach (getOrderStatusOptions(true) as $statusValue => $statusLabel): ?>
                                    <option value="<?php echo e($statusValue); ?>" ${o.status==<?php echo json_encode($statusValue, JSON_UNESCAPED_UNICODE); ?> ? 'selected':''}><?php echo e($statusLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('technician'); ?></label>
                                <select name="technician_id" class="form-select" ${!canEditQuickTechnician ? 'disabled' : ''}>
                                    <option value="">-- <?php echo __('edit'); ?> --</option>
                                    <?php
                                    // FIX #5: use pre-loaded $techs_list
                                    foreach ($techs_list as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('price_estimated'); ?></label>
                                <div class="input-group">
                                    <input type="number" step="1" name="estimated_cost" class="form-control" value="${Math.round(o.estimated_cost || 0)}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('price_final'); ?></label>
                                <div class="input-group">
                                    <input type="number" step="1" name="final_cost" class="form-control" value="${Math.round(o.final_cost || o.estimated_cost || 0)}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-12 ${res.role == 'admin' ? '' : 'd-none'}">
                                <label class="form-label"><?php echo __('extra_expenses_desc'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="extra_expenses" class="form-control" step="1" value="${Math.round(o.extra_expenses || 0)}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('problem'); ?></label>
                                <textarea name="problem_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('notes'); ?></label>
                                <textarea name="technician_notes" class="form-control" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <label class="form-label"><?php echo __('media_files'); ?></label>
                                ${mediaHtml}
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('add_media_files'); ?></label>
                                <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                <div class="form-text"><?php echo __('upload_multiple_hint'); ?></div>
                            </div>
                        </div>
                    </form>
                `;
                $('#quickOrderBody').html(html);
                // FIX #3: Use .val() for textarea content to prevent XSS via innerHTML
                $('#quickOrderBody textarea[name="problem_description"]').val(o.problem_description || '');
                $('#quickOrderBody textarea[name="technician_notes"]').val(o.technician_notes || '');
                $('#quickOrderBody select[name="technician_id"]').val(o.technician_id);
                
                // Update print links in modal footer
                const footerLinks = $('#quickOrderModal .modal-footer .dropdown-item');
                footerLinks.eq(0).attr('onclick', `openUniversalPreview('print_order.php?id=${o.id}', '<?php echo __('order_header'); ?> #${o.id}')`);
                footerLinks.eq(1).attr('onclick', `openUniversalPreview('print_workshop.php?id=${o.id}', '<?php echo __('work_order'); ?> #${o.id}')`);

                $('#saveQuickOrderBtn').prop('disabled', false);
                
                // Set delete order button action
                $('#deleteQuickOrderBtn').off('click').on('click', function() {
                    deleteOrder(o.id);
                });
            } else {
                const message = (res && res.message) ? res.message : '<?php echo __('error'); ?>';
                $('#quickOrderBody').html('<div class="alert alert-danger">' + message + '</div>');
            }
        });
    });

    $('#saveQuickOrderBtn').off('click').on('click', function(e) {
        e.preventDefault();
        const form = $('#quickOrderForm');
        const formData = new FormData(form[0]);
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');
        
        $.ajax({
            url: 'api/update_order_full.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            cache: false,
            success: function(res) {
                if (res.success) {
                    // Hide modal first
                    const modalEl = document.getElementById('quickOrderModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    
                    // Small delay before reload to ensure UI state is clean
                    setTimeout(() => {
                        window.location.reload();
                    }, 150);
                } else {
                    btn.prop('disabled', false).text('<?php echo __('save_changes'); ?>');
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).text('<?php echo __('save_changes'); ?>');
                showAlert('<?php echo __('error'); ?>');
            }
        });
    });

    // (Helpers moved to main.js)

    $('.order-template-select').on('change', function() {
        const value = $(this).val();
        if (!value) return;
        const targetName = $(this).data('target');
        const $area = $(this).closest('form').find('textarea[name="' + targetName + '"]');
        if (!$area.length) return;
        const current = $area.val().trim();
        $area.val(current ? (current + "\n" + value) : value).trigger('input');
        $(this).val('');
    });

    function showQuickToast(message, type) {
        const toastEl = document.getElementById('quickStatusToast');
        const toastBody = document.getElementById('quickStatusToastBody');
        if (!toastEl || !toastBody) return showAlert(message);
        toastEl.classList.remove('text-bg-success', 'text-bg-danger');
        toastEl.classList.add(type === 'success' ? 'text-bg-success' : 'text-bg-danger');
        toastBody.textContent = message;
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    }

    function performQuickStatusUpdate(id, status, btn) {
        $.post('api/update_order_status.php', { order_id: id, status: status }, function(res) {
            if (res.success) {
                showQuickToast('<?php echo __('updated_success'); ?>', 'success');
                setTimeout(() => window.location.reload(), 300);
            } else {
                if (btn) btn.prop('disabled', false);
                showQuickToast(res.message || '<?php echo __('error'); ?>', 'danger');
            }
        }, 'json').fail(function() {
            if (btn) btn.prop('disabled', false);
            showQuickToast('<?php echo __('error'); ?>', 'danger');
        });
    }

    $('.quick-status-btn').on('click', function() {
        const id = $(this).data('id');
        const status = $(this).data('status');
        if (!id || !status) return;
        const btn = $(this);
        btn.prop('disabled', true);

        if (status === 'Stornováno') {
            return showConfirm('<?php echo __('delete_confirm'); ?>', function() {
                performQuickStatusUpdate(id, status, btn);
            });
        }

        performQuickStatusUpdate(id, status, btn);
    });

    $(document).on('click', '.order-row', function(e) {
        if ($(e.target).closest('a, button, .btn, .dropdown, .dropdown-menu, .dropdown-item, .phone-qr-trigger, .quick-status-btn, .accounting-btn, input, select, textarea, label').length) {
            return;
        }
        const url = $(this).data('order-url');
        if (url) window.location.href = url;
    });

    $(document).on('keydown', '.order-row', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            const url = $(this).data('order-url');
            if (url) {
                e.preventDefault();
                window.location.href = url;
            }
        }
    });

    // Inline New Customer: company/private toggle
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#inline_company_fields').removeClass('d-none');
            $('#inline_first_name').val('Firma');
            $('#inline_last_name').val('');
        } else {
            $('#inline_company_fields').addClass('d-none');
            $('#inline_first_name').val('');
            $('#inline_last_name').val('');
        }
    });

    // Inline New Customer: ARES fetch
    $('#inline_btn_fetch_ares').on('click', function() {
        const ico = $('#inline_ico_input').val().trim();
        if (!ico) return showAlert('<?php echo __('enter_ico'); ?>');
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                if (data && data.obchodniJmeno) {
                    $('#inline_ares_name').val(data.obchodniJmeno);
                    $('#inline_last_name').val(data.obchodniJmeno);
                    $('#inline_first_name').val('Firma');
                    
                    if (data.dic) {
                        $('#inline_ares_dic').val(data.dic);
                    }

                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#inline_address').val(addr.trim());
                    }
                } else {
                    showAlert('<?php echo __('ares_data_not_found'); ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                showAlert('<?php echo __('ares_fetch_error'); ?>');
            }
        });
    });

    // Inline New Customer: AJAX submit and bind to New Order select
    // (namespace .saveCust NAHRADÍ globální handler z main.js — bez toho se
    // klient odesílal dvakrát a vznikali duplicitní klienti)
    $('#saveNewCustomerBtn').off('click.saveCust').on('click.saveCust', function() {
        const $panel = $('#newCustomerInlineForm');
        const firstName = $('#inline_first_name').val().trim();
        const lastName = $('#inline_last_name').val().trim();
        const phone = $('#inline_phone').val().trim();
        const email = ($('#inline_email').val() || '').trim();

        if (!firstName || !lastName || !phone || !email || email.indexOf('@') < 1) {
            showAlert('<?php echo __('fill_required_fields'); ?>');
            return;
        }
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');

        const formData = {
            first_name: firstName,
            last_name: lastName,
            phone: phone,
            email: $panel.find('input[name="inline_email"]').val() || '',
            address: $('#inline_address').val() || '',
            customer_type: $panel.find('input[name="customer_type"]:checked').val() || 'private',
            language: $('#inline_language').val() || 'cs',
            ico: $('#inline_ico_input').val() || '',
            company_name: $('#inline_ares_name').val() || '',
            dic: $('#inline_ares_dic').val() || '',
            csrf_token: $('input[name="csrf_token"]').first().val()
        };

        $.post('api/add_customer.php', formData, function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            if (res.success) {
                const id = res.id;
                const label = (lastName + ' ' + firstName).trim() + (phone ? ' (' + phone + ')' : '');
                const $select = $('.select2-customer');
                if ($select.length) {
                    const newOption = new Option(label, id, true, true);
                    $select.append(newOption).trigger('change');
                }
                // Reset inline form fields
                $('#inline_first_name, #inline_last_name, #inline_phone, #inline_ares_name, #inline_ares_dic, #inline_ico_input').val('');
                $panel.find('input[name="inline_email"]').val('');
                $('#inline_address').val('');
                $('#inline_company_fields').addClass('d-none');
                $panel.find('#inline_type_private').prop('checked', true);
                // Collapse the panel
                const collapseEl = document.getElementById('inlineNewCustomerPanel');
                const bsCollapse = bootstrap.Collapse.getInstance(collapseEl);
                if (bsCollapse) bsCollapse.hide();
            } else {
                showAlert(res.message || '<?php echo __('add_client_error'); ?>');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            showAlert('<?php echo __('network_error_client'); ?>');
        });
    });

    // Accounting Modal Logic
    $('.accounting-btn').on('click', function() {
        const orderId = $(this).data('id');
        $('#invoiceModal').modal('show');
        $('#invoiceForm').trigger('reset');
        $('#invoiceOrderId').val(orderId);
        $('#dynamic-items-container').html(`
            <div class="row g-2 mb-2 item-row">
                <div class="col-8">
                    <input type="text" name="item_name[]" class="form-control form-control-sm" value="<?php echo __('repair_service'); ?> #${orderId}" required>
                </div>
                <div class="col-3">
                    <input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" required>
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-sm btn-outline-primary add-item-btn"><i class="fas fa-plus"></i></button>
                </div>
            </div>
        `);
        
        $.get('api/get_invoice_data.php', {order_id: orderId}, function(res) {
            if (res.success) {
                // Number and VS are now Order ID
                $('#invoiceNumber').val(orderId);
                $('#variableSymbol').val(orderId);
                $('#dateIssue').val(res.date_issue);
                $('#dateTax').val(res.date_tax);
                $('#dateDue').val(res.date_due);
                $('#totalAmount').val(res.total_amount);
                $('#invoiceCustomerName').text(res.order.company || (res.order.first_name + ' ' + res.order.last_name));
                
                // Show hints
                $('#orderProblemHint').text(res.order.problem_description);
                $('#orderNotesHint').text(res.order.technician_notes || '---');
                
                // Set initial price to total amount
                $('.item-price').val(res.total_amount);
            } else {
                showAlert(res.message);
                $('#invoiceModal').modal('hide');
            }
        });
    });

    // Dynamic items logic
    $(document).on('click', '.add-item-btn', function() {
        const newRow = `
            <div class="row g-2 mb-2 item-row">
                <div class="col-8">
                    <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="<?php echo __('invoice_item_placeholder'); ?>" required>
                </div>
                <div class="col-3">
                    <input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" required>
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn"><i class="fas fa-minus"></i></button>
                </div>
            </div>
        `;
        $('#dynamic-items-container').append(newRow);
    });

    $(document).on('click', '.remove-item-btn', function() {
        $(this).closest('.item-row').remove();
        calculateInvoiceTotal();
    });

    $(document).on('input', '.item-price', function() {
        calculateInvoiceTotal();
    });

    function calculateInvoiceTotal() {
        let total = 0;
        $('.item-price').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#totalAmount').val(total.toFixed(2));
    }

    $('#saveInvoiceBtn').on('click', function() {
        const formData = $('#invoiceForm').serialize();
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        
        $.post('api/create_invoice.php', formData, function(res) {
            if (res.success) {
                window.open('print_invoice.php?id=' + res.id, '_blank');
                location.reload();
            } else {
                showAlert(res.message);
                $('#saveInvoiceBtn').prop('disabled', false).text('<?php echo __('create_invoice'); ?>');
            }
        });
    });
});
</script>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="invoiceForm">
                <input type="hidden" name="order_id" id="invoiceOrderId">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('invoice'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3 border-bottom pb-2">
                        <div class="col-md-6">
                            <label class="form-label text-white-75 small"><?php echo __('client'); ?></label>
                            <div id="invoiceCustomerName" class="fw-bold"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75 small"><?php echo __('hint_problem_notes'); ?></label>
                            <div class="small text-danger" id="orderProblemHint"></div>
                            <div class="small text-white-75 italic" id="orderNotesHint"></div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('invoice_number'); ?></label>
                            <input type="text" name="invoice_number" id="invoiceNumber" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('variable_symbol'); ?></label>
                            <input type="text" name="variable_symbol" id="variableSymbol" class="form-control">
                        </div>
                        
                        <div class="col-12 mt-3 mb-1">
                            <label class="form-label fw-bold"><?php echo __('invoice_items_label'); ?></label>
                            <div id="dynamic-items-container">
                                <!-- Dynamic rows here -->
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" id="dateIssue" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('date_tax'); ?></label>
                            <input type="date" name="date_tax" id="dateTax" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" id="dateDue" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('total_to_pay'); ?></label>
                            <div class="input-group">
                                <input type="number" name="total_amount" id="totalAmount" class="form-control" step="0.01" required>
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="button" id="saveInvoiceBtn" class="btn btn-success"><?php echo __('create_invoice'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Language Selection for Reception Act Modal -->
<script>

function deleteMedia(id) {
    if (typeof showConfirm !== 'function') {
        if (confirm('<?php echo __('confirm_delete_file'); ?>')) {
            $.post('api/delete_media.php', {id: id}, function(res) {
                if (res.success) $('#media-item-' + id).fadeOut();
                else alert('<?php echo __('error'); ?>: ' + res.message);
            });
        }
        return;
    }
    showConfirm('<?php echo __('confirm_delete_file'); ?>', function() {
        $.post('api/delete_media.php', {id: id}, function(res) {
            if (res.success) {
                $('#media-item-' + id).fadeOut();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
