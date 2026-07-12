<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/scan_resolver.php';
require_once 'includes/header.php';

// Filter for Dashboard - keep same accepted statuses as orders.php
$allowed_statuses = getAllowedOrderFilterStatuses();
$filter_status = in_array($_GET['filter'] ?? '', $allowed_statuses, true) ? $_GET['filter'] : null;

// Branch scope for stats: managers/admins see all, branch staff see only their branch.
$tech_cond = orderBranchScopeSql('branch_id');
$tech_cond_o = orderBranchScopeSql('o.branch_id');

// Count for Stats
$newStatuses = orderStatusSqlIn($pdo, 'new');
$pendingStatuses = orderStatusSqlIn($pdo, 'pending_approval');
$progressStatuses = orderStatusSqlIn($pdo, 'in_progress');
$waitingStatuses = orderStatusSqlIn($pdo, 'waiting_parts');
$doneStatuses = orderStatusSqlIn($pdo, 'done');
$activeStatuses = orderStatusSqlIn($pdo, 'active');

$new_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($newStatuses)" . $tech_cond)->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($pendingStatuses)" . $tech_cond)->fetchColumn();
$progress_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($progressStatuses)" . $tech_cond)->fetchColumn();
$ready_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($doneStatuses)" . $tech_cond)->fetchColumn();

// Design-system stats
$waiting_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($waitingStatuses)" . $tech_cond)->fetchColumn();
$active_count = (int)$new_count + (int)$pending_count + (int)$progress_count + $waiting_count;
$urgent_waiting = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($waitingStatuses) AND priority = 'High'" . $tech_cond)->fetchColumn();
try {
    $completed_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($doneStatuses) AND DATE(updated_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $planned_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $new_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($newStatuses) AND DATE(created_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $revenue_today = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND DATE(updated_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $revenue_yesterday = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND DATE(updated_at) = CURDATE() - INTERVAL 1 DAY" . $tech_cond)->fetchColumn();
    $revenue_today_trend = $revenue_yesterday > 0 ? round((($revenue_today - $revenue_yesterday) / $revenue_yesterday) * 100) : 0;

    $revenue_month = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())" . $tech_cond)->fetchColumn();
    $revenue_prev = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND MONTH(updated_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(updated_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)" . $tech_cond)->fetchColumn();
    $revenue_trend = $revenue_prev > 0 ? round((($revenue_month - $revenue_prev) / $revenue_prev) * 100) : 0;
    $revenue_12m = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = $pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND YEAR(updated_at)*12+MONTH(updated_at) = YEAR(CURDATE())*12+MONTH(CURDATE()) - $i" . $tech_cond)->fetchColumn();
        $revenue_12m[] = (float)$m;
    }
} catch (Throwable $e) {
    $completed_today = $planned_today = $new_today = 0;
    $revenue_today = $revenue_yesterday = $revenue_today_trend = 0;
    $revenue_month = $revenue_prev = 0; $revenue_trend = 0; $revenue_12m = array_fill(0, 12, 0);
}
$month_labels = explode(',', __('month_initials'));
$rev_max = max(1, max($revenue_12m));

$branch_overview = [];
if (isBranchGlobalViewer()) {
    try {
        $branch_overview = $pdo->query("SELECT b.id, b.name,
                COUNT(o.id) AS total_orders,
                SUM(o.status IN ($activeStatuses)) AS active_orders,
                SUM(o.status IN ($doneStatuses)) AS done_orders,
                COALESCE(SUM(CASE WHEN o.status IN ($doneStatuses) THEN o.final_cost ELSE 0 END), 0) AS revenue
            FROM branches b
            LEFT JOIN orders o ON o.branch_id = b.id
            WHERE b.is_active = 1
            GROUP BY b.id, b.name
            ORDER BY b.id ASC")->fetchAll();
    } catch (Throwable $e) {
        $branch_overview = [];
    }
}

// Online Techs (Last 5 minutes) - Admin or those with admin_access
$online_count = 0;
if (hasPermission('admin_access')) {
    $online_count = $pdo->query("SELECT COUNT(*) FROM technicians WHERE last_seen > (NOW() - INTERVAL 5 MINUTE) AND is_active = 1")->fetchColumn();
}

// Load technicians list once for new order modal
$techs_list = [];
try {
    $techs_list = getActiveTechnicians();
} catch (PDOException $e) {
    $techs_list = [];
}

$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

?>

<!-- 4 statistiky + pobočky na jednom řádku (pobočky nad sebou, stejná celková výška) -->
<div class="crm-stat-row mb-4">
<div class="crm-stat-grid">
    <a href="orders.php" class="crm-stat-card crm-stat-1 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('active_orders'); ?></div>
        <div class="crm-stat-value"><?php echo (int)$active_count; ?></div>
        <div class="crm-stat-sub <?php echo $new_today > 0 ? 'up' : ''; ?>">
            <?php if ($new_today > 0): ?>↑ <?php echo $new_today; ?> <?php echo __('today'); ?><?php else: ?><?php echo __('no_change'); ?><?php endif; ?>
        </div>
    </a>
    <a href="?filter=<?php echo urlencode('Čeká na díl'); ?>" class="crm-stat-card crm-stat-2 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('waiting_parts'); ?></div>
        <div class="crm-stat-value"><?php echo (int)$waiting_count; ?></div>
        <div class="crm-stat-sub <?php echo $urgent_waiting > 0 ? 'down' : ''; ?>">
            <?php echo $urgent_waiting > 0 ? $urgent_waiting.' '.__('urgent') : __('in_queue'); ?>
        </div>
    </a>
    <a href="?filter=<?php echo urlencode('Připraveno k převzetí'); ?>" class="crm-stat-card crm-stat-3 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('repaired_today'); ?></div>
        <div class="crm-stat-value"><?php echo (int)$completed_today; ?></div>
        <div class="crm-stat-sub"><?php echo __('of'); ?> <?php echo (int)$planned_today; ?> <?php echo __('planned'); ?></div>
    </a>
    <div class="crm-stat-card crm-stat-4">
        <div class="crm-stat-label"><?php echo __('daily_revenue'); ?></div>
        <div class="crm-stat-value"><?php echo number_format($revenue_today, 0, ',', ' '); ?> Kč</div>
        <div class="crm-stat-sub <?php echo $revenue_today_trend > 0 ? 'up' : ($revenue_today_trend < 0 ? 'down' : ''); ?>">
            <?php if ($revenue_today_trend > 0): ?>↑ <?php echo $revenue_today_trend; ?> % <?php echo __('vs_yesterday'); ?><?php elseif ($revenue_today_trend < 0): ?>↓ <?php echo abs($revenue_today_trend); ?> % <?php echo __('vs_yesterday'); ?><?php else: ?><?php echo __('no_change'); ?><?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($branch_overview)): ?>
<div class="crm-branch-col">
    <?php foreach ($branch_overview as $branch): ?>
    <div class="crm-branch-mini card glass-card border-0">
        <div class="crm-branch-mini-main">
            <div class="crm-branch-mini-name"><i class="fas fa-store me-2 text-primary"></i><?php echo e($branch['name']); ?></div>
            <div class="crm-branch-mini-sub"><?php echo __('active_short'); ?>: <?php echo (int)$branch['active_orders']; ?> · <?php echo __('done_short'); ?>: <?php echo (int)$branch['done_orders']; ?></div>
        </div>
        <div class="crm-branch-mini-num">
            <b><?php echo (int)$branch['total_orders']; ?></b>
            <small><?php echo number_format((float)$branch['revenue'], 0, ',', ' '); ?> Kč</small>
        </div>
        <a class="stretched-link" href="orders.php?branch_id=<?php echo (int)$branch['id']; ?>" aria-label="<?php echo e(__('branch')); ?> <?php echo e($branch['name']); ?>"></a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /.crm-stat-row -->

<div class="row g-4 align-items-start dashboard-main-row">
    <div class="col-12 col-lg-9 dashboard-main-col">
        <div class="card glass-card border-0">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php
                    if ($filter_status === null) {
                        echo __('recent_orders');
                    } else {
                        echo e(getOrderStatusLabel($filter_status));
                    }
                    ?>
                </h5>
                <?php if ($filter_status): ?>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary"><?php echo __('show_all'); ?></a>
                <?php else: ?>
                    <a href="orders.php" class="btn btn-sm btn-primary"><?php echo __('all_orders'); ?></a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?php echo __('order_no'); ?> / <?php echo __('created'); ?></th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('device_model'); ?></th>
                                <th><?php echo __('problem'); ?></th>
                                <th><?php echo __('technician'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th><?php echo __('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $search = trim($_GET['search'] ?? '');

                            $where_clauses = [];
                            $params = [];

                            addOrderBranchScope($where_clauses, $params, 'o');

                            // Same search fields as orders.php
                            if ($search !== '') {
                                $searchTerm = "%$search%";
                                if (is_numeric($search)) {
                                    $where_clauses[] = '(o.order_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ? OR o.id = ?)';
                                    for ($i = 0; $i < 9; $i++) $params[] = $searchTerm;
                                    $params[] = (int)$search;
                                } else {
                                    $where_clauses[] = '(o.order_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?)';
                                    for ($i = 0; $i < 9; $i++) $params[] = $searchTerm;
                                }
                            }

                            if ($filter_status) {
                                $filter_group = getOrderStatusGroup($filter_status);
                                if ($filter_group !== null) {
                                    $filter_key = $filter_group === 'completed' ? 'done' : $filter_group;
                                    $where_clauses[] = 'o.status IN (' . orderStatusSqlIn($pdo, $filter_key) . ')';
                                } else {
                                    $where_clauses[] = 'o.status = ?';
                                    $params[] = $filter_status;
                                }
                            }

                            $where_clause = $where_clauses ? ' WHERE ' . implode(' AND ', $where_clauses) : '';
                            $search_id = is_numeric($search) ? (int)$search : 0;
                            $sql = "SELECT o.*, c.first_name, c.last_name, c.phone, c.company, c.customer_type, t.name as tech_name,
                                           (SELECT MAX(l.changed_at) FROM order_status_log l WHERE l.order_id = o.id) AS last_status_change
                                    FROM orders o
                                    JOIN customers c ON o.customer_id = c.id
                                    LEFT JOIN technicians t ON o.technician_id = t.id" .
                                    $where_clause .
                                    " ORDER BY " . orderSortSql('o', 'o.id = ?') . " LIMIT 15";

                            $stmt = $pdo->prepare($sql);
                            $exec_params = array_merge($params, [$search_id]);
                            $stmt->execute($exec_params);

                            $orders_list = $stmt->fetchAll();
                            
                            $has_media_ids = [];
                            if (!empty($orders_list)) {
                                $order_ids = array_column($orders_list, 'id');
                                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                                $m_stmt = $pdo->prepare("SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id");
                                $m_stmt->execute($order_ids);
                                $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
                            }
                            
                            $found = false;
                            foreach($orders_list as $r):
                                $found = true;
                                $icon = getDeviceIcon($r['device_type']);
                                $phone_href = normalizePhoneForTel($r['phone'] ?? '');

                                $has_media = isset($has_media_ids[$r['id']]);
                                $display_code = orderDisplayCode($r);
                            ?>
                            <?php [$staleCls, $staleTitle] = orderStaleRowAttrs($r); ?>
                            <tr <?php echo $staleTitle ? 'title="' . e($staleTitle) . '" ' : ''; ?>class="clickable-order-row order-row--status-<?php echo e(getOrderStatusBadgeToken($r['status'])); ?><?php echo $staleCls; ?><?php echo !empty($r['company']) || ($r['customer_type'] ?? '') === 'company' ? ' order-row--company' : ''; ?><?php echo $r['priority'] == 'High' ? ' order-row--high' : ''; ?>" style="cursor: pointer;" onclick="window.location.href='view_order.php?id=<?php echo (int)$r['id']; ?>'" tabindex="0" role="link">
                                <td>
                                    <a href="view_order.php?id=<?php echo (int)$r['id']; ?>" class="fw-bold text-decoration-none"><?php echo e($display_code); ?></a>
                                    <?php if($has_media): ?>
                                        <i class="fas fa-camera text-info ms-1" title="<?php echo __('has_media'); ?>"></i>
                                    <?php endif; ?>
                                    <div class="small text-white-75"><?php echo date('d.m.Y', strtotime($r['created_at'])); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></strong><br>
                                    <small class="text-white-75">
                                        <?php if ($phone_href !== ''): ?>
                                            <a href="tel:<?php echo e($phone_href); ?>" class="text-reset text-decoration-none crm-phone-text" onclick="event.stopPropagation();"><?php echo htmlspecialchars($r['phone']); ?></a>
                                        <?php else: ?>
                                            <span class="crm-phone-text"><?php echo htmlspecialchars($r['phone']); ?></span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo $icon; ?> <strong><?php echo htmlspecialchars($r['device_brand']); ?></strong><br>
                                    <small class="text-white-75"><?php echo htmlspecialchars($r['device_model']); ?></small>
                                    <?php if(!empty($r['serial_number'])): ?>
                                        <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn1'); ?>: <?php echo htmlspecialchars($r['serial_number']); ?></div>
                                    <?php endif; ?>
                                    <?php if(!empty($r['serial_number_2'])): ?>
                                        <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn2'); ?>: <?php echo htmlspecialchars($r['serial_number_2']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars(mb_strimwidth($r['problem_description'], 0, 40, "...")); ?></small>
                                </td>
                                <td>
                                    <span class="dashboard-tech-name"><i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($r['tech_name'] ?? '---'); ?></span>
                                </td>
                                <td>
                                    <?php echo getStatusBadge($r['status']); ?>
                                    <?php if(!empty($r['shipping_method'])): ?>
                                        <div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i><?php echo htmlspecialchars($r['shipping_method']); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-white-75 mt-1">
                                        <i class="far fa-clock me-1"></i><?php echo date('d.m.Y H:i', strtotime($r['updated_at'])); ?>
                                    </div>
                                </td>
                                <td><strong><?php echo formatMoney($r['final_cost'] ?: $r['estimated_cost']); ?></strong></td>
                            </tr>
                            <?php endforeach; 
                            
                            if (!$found): ?>
                                <tr><td colspan="7" class="text-center text-white-75 py-4"><?php echo __('not_found'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-3">
        <!-- Revenue chart (design-system) -->
        <div class="crm-revenue-card mb-4">
            <div class="crm-revenue-label"><?php echo __('revenue_by_months'); ?></div>
            <div class="crm-revenue-value"><?php echo number_format($revenue_month, 0, ',', ' '); ?> Kč</div>
            <?php
                $chart_h = 56; $chart_w = 260;
                $bar_w = 12; $bar_gap = ($chart_w - count($revenue_12m)*$bar_w) / max(1, count($revenue_12m)-1);
            ?>
            <svg class="crm-revenue-chart" width="100%" height="<?php echo $chart_h + 16; ?>" viewBox="0 0 <?php echo $chart_w; ?> <?php echo $chart_h + 16; ?>">
                <defs>
                    <linearGradient id="barGrad" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#0A84FF"/>
                        <stop offset="50%" stop-color="#5E5CE6"/>
                        <stop offset="100%" stop-color="#BF5AF2"/>
                    </linearGradient>
                </defs>
                <?php foreach ($revenue_12m as $i => $v):
                    $bh = max(1, round(($v / $rev_max) * $chart_h));
                    $x = $i * ($bar_w + $bar_gap);
                    $is_last = $i === count($revenue_12m) - 1;
                ?>
                    <rect x="<?php echo $x; ?>" y="<?php echo $chart_h - $bh; ?>" width="<?php echo $bar_w; ?>" height="<?php echo $bh; ?>" rx="3"
                        fill="<?php echo $is_last ? 'url(#barGrad)' : 'rgba(110,58,250,0.25)'; ?>"/>
                <?php endforeach; ?>
                <?php foreach ($month_labels as $i => $lbl): ?>
                    <text x="<?php echo $i*($bar_w+$bar_gap) + $bar_w/2; ?>" y="<?php echo $chart_h + 12; ?>" text-anchor="middle" font-size="8" fill="rgba(255,255,255,0.25)"><?php echo $lbl; ?></text>
                <?php endforeach; ?>
            </svg>
        </div>

        <div class="card glass-card border-0 mb-4 imei-check-card">
            <div class="card-header bg-transparent border-bottom-0 d-flex align-items-center">
                <h5 class="mb-0"><i class="fas fa-mobile-screen-button text-info me-2"></i><?php echo __('imei_check_title'); ?></h5>
            </div>
            <div class="card-body">
                <form id="imeiCheckForm">
                    <label class="form-label"><?php echo __('serial'); ?></label>
                    <div class="mb-2">
                        <input type="text" class="form-control w-100" id="imeiCheckInput" placeholder="<?php echo e(__('imei_input_placeholder')); ?>" inputmode="numeric" autocomplete="off" maxlength="15">
                    </div>
                    <div class="text-center">
                        <button class="btn btn-outline-info px-4" type="submit">
                            <i class="fas fa-search me-1"></i><?php echo __('check'); ?>
                        </button>
                    </div>
                    <div class="form-text text-white-75"><?php echo __('imei_help_text'); ?></div>
                </form>
                <div id="imeiCheckResult" class="imei-check-result mt-3" aria-live="polite"></div>
            </div>
        </div>

        <!-- Fronta dnes (design-system) -->
        <?php
        try {
            $queue_today = $pdo->query("SELECT o.id, o.order_code, o.device_model, o.device_brand, o.status, c.first_name, c.last_name, t.name AS tech_name FROM orders o JOIN customers c ON o.customer_id=c.id LEFT JOIN technicians t ON o.technician_id=t.id WHERE o.status IN ($activeStatuses)" . $tech_cond_o . " ORDER BY o.priority='High' DESC, " . orderSortSql('o') . " LIMIT 6")->fetchAll();
        } catch (Throwable $e) { $queue_today = []; }
        ?>
        <div class="crm-queue-card mb-4 mt-5">
            <div class="crm-queue-head">
                <div class="crm-queue-title"><?php echo __('queue_today'); ?></div>
                <div class="crm-queue-date"><?php echo date('j. n. Y'); ?></div>
            </div>
            <div class="crm-queue-body">
                <?php if (empty($queue_today)): ?>
                    <div class="crm-queue-empty"><?php echo __('no_open_orders'); ?></div>
                <?php else: foreach ($queue_today as $q):
                    $init = strtoupper(mb_substr($q['tech_name'] ?? '?', 0, 2));
                ?>
                    <a href="view_order.php?id=<?php echo (int)$q['id']; ?>" class="crm-queue-item text-decoration-none">
                        <div class="crm-queue-avatar"><?php echo e($init); ?></div>
                        <div class="crm-queue-meta">
                            <?php // v servisu je dominantní, CO se opravuje — zařízení nahoře, klient menší pod ním ?>
                            <div class="crm-queue-name"><?php echo e(trim($q['device_brand'].' '.$q['device_model'])); ?></div>
                            <div class="crm-queue-device"><?php echo e(trim($q['first_name'].' '.$q['last_name'])); ?></div>
                        </div>
                        <?php echo getStatusBadge($q['status']); ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Rezervace z webu (RepairPlugin) — nadcházející termíny -->
        <?php
        $dashWebBookings = [];
        try {
            ensureWebBookingsSchema();
            $dashWebBookings = $pdo->query("SELECT wb.*, o.order_code AS wb_order_code
                FROM web_bookings wb
                LEFT JOIN orders o ON o.id = wb.order_id
                WHERE wb.status IN ('new','converted')
                  AND (wb.appointment_at IS NULL OR wb.appointment_at >= NOW() - INTERVAL 1 DAY)
                ORDER BY (wb.appointment_at IS NULL), wb.appointment_at ASC, wb.created_at ASC
                LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $dashWebBookings = []; }
        if (!empty($dashWebBookings)):
        ?>
        <div class="card glass-card border-0 mb-4 afx-dash-webres">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-globe text-info me-2"></i><?php echo __('web_bookings'); ?></h5>
                <a href="orders.php" class="small text-decoration-none text-white-75"><?php echo __('view_all'); ?> <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
            <div class="card-body pt-1">
                <?php foreach ($dashWebBookings as $wb):
                    $ts = !empty($wb['appointment_at']) ? strtotime((string)$wb['appointment_at']) : null;
                    $isToday = $ts && date('Y-m-d', $ts) === date('Y-m-d');
                    $rowHref = !empty($wb['order_id']) ? 'view_order.php?id=' . (int)$wb['order_id'] : 'orders.php';
                ?>
                <a href="<?php echo $rowHref; ?>" class="afx-dash-webres-item text-decoration-none">
                    <div class="afx-dash-webres-time <?php echo $isToday ? 'is-today' : ''; ?>">
                        <?php if ($ts): ?>
                            <b><?php echo date('H:i', $ts); ?></b>
                            <small><?php echo $isToday ? __('today') : date('j.n.', $ts); ?></small>
                        <?php else: ?>
                            <b>—</b><small><?php echo __('no_appointment'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="afx-dash-webres-info">
                        <div class="afx-dash-webres-name"><?php echo e($wb['customer_name'] ?: __('unknown_client')); ?></div>
                        <div class="afx-dash-webres-sub">
                            <?php echo e($wb['device'] ?: ''); ?><?php if (!empty($wb['service'])): ?> · <?php echo e($wb['service']); ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($wb['order_id'])): ?>
                        <span class="badge status-pill status-pill--repairplugin afx-dash-webres-badge"><?php echo e($wb['wb_order_code'] ?: ($wb['wp_booking_id'] ?: __('order'))); ?></span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark afx-dash-webres-badge"><i class="fas fa-hourglass-half"></i></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('quick_actions'); ?></h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-2"></i> <?php echo __('new_order'); ?></button>
                    <?php if (hasPermission('edit_customers')): ?>
                    <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-user-plus me-2"></i> <?php echo __('customers'); ?></a>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_inventory')): ?>
                    <a href="inventory.php" class="btn btn-outline-info"><i class="fas fa-search me-2"></i> <?php echo __('check_stock'); ?></a>
                    <?php endif; ?>
                    <a href="vykup-zarizeni.php" target="_blank" rel="noopener" class="btn btn-outline-light mt-2">
                        <i class="fas fa-file-signature me-2"></i> <?php echo __('buyout_sheet_purchase_agreement'); ?>
                    </a>
                    <a href="zastava.php" target="_blank" rel="noopener" class="btn btn-outline-light">
                        <i class="fas fa-file-contract me-2"></i> <?php echo __('pawn_form'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Dashboard Right Column (Techs list if Admin) -->
        <?php if ($_SESSION['role'] == 'admin'): ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('online_techs'); ?></h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php
                    $all_techs = $pdo->query("SELECT name, last_seen FROM technicians WHERE is_active = 1 ORDER BY last_seen DESC")->fetchAll();
                    foreach ($all_techs as $tech):
                        $is_online = (strtotime($tech['last_seen'] ?? '0') > strtotime("-5 minutes"));
                    ?>
                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <div class="position-relative me-3">
                                <i class="fas fa-user-circle fa-2x text-white-75 opacity-50"></i>
                                <span class="position-absolute bottom-0 end-0 p-1 <?php echo $is_online ? 'bg-success' : 'bg-secondary'; ?> border border-light rounded-circle"></span>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($tech['name']); ?></div>
                                <small class="text-white-75">
                                    <?php echo $is_online ? __('tech_online') : __('tech_last_seen') . ': ' . ($tech['last_seen'] ? date('H:i, d.m', strtotime($tech['last_seen'])) : __('never')); ?>
                                </small>
                            </div>
                        </div>
                        <?php if ($is_online): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2"><?php echo __('tech_online'); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>



<script>
$(document).ready(function() {
        serviceId: <?php echo json_encode(__('service_id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        yesRaw: <?php echo json_encode(__('yes'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        noRaw: <?php echo json_encode(__('no'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    function renderImeiResult(type, message, details) {
        const icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        const alertClass = type === 'success'
            ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : type === 'danger'
                ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';

        const detailHtml = details ? `<div class="small mt-1 opacity-75">${window.escapeHtml(details)}</div>` : '';
        $imeiResult.html(`<div class="${alertClass} mb-0 py-2"><i class="fas fa-${icon} me-2"></i>${window.escapeHtml(message)}${detailHtml}</div>`);
    }

    function renderIfreeicloudResult(result) {
        const meta = result.service_id !== undefined ? `<div class="small mt-2 opacity-50">${window.escapeHtml(IMEI_I18N.serviceId)}: ${window.escapeHtml(String(result.service_id))}${result.http_code ? ` · HTTP ${window.escapeHtml(String(result.http_code))}` : ''}</div>` : '';
        const note = (!detailsHtml && !imageHtml && result.summary) ? `<pre class="small mt-2 mb-0 p-2 rounded border border-opacity-25 bg-dark bg-opacity-25 text-white-75" style="white-space: pre-wrap;">${window.escapeHtml(result.summary)}</pre>` : '';

        return `<div class="${alertClass} mb-0 py-2"><div class="fw-semibold mb-1"><i class="fas fa-sim-card me-2"></i>iFreeiCloud</div><div><i class="fas fa-${icon} me-2"></i>${headline}</div>${message}${imageHtml}${detailsHtml}${note}${meta}</div>`;
    }

    $imeiInput.on('input', function() {
        this.value = this.value.replace(/\D+/g, '').slice(0, 15);
    });

    $('#imeiCheckForm').on('submit', function(e) {
        e.preventDefault();

        const imei = ($imeiInput.val() || '').replace(/\D+/g, '').slice(0, 15);
        if (imei.length < 14) {
            renderImeiResult('warning', IMEI_I18N.imeiMinDigits);
            return;
        }

        const $btn = $(this).find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + window.escapeHtml(IMEI_I18N.checking));
        $imeiResult.empty();

        $.post('api/check_imei.php', { imei: imei, csrf_token: $('input[name="csrf_token"]').first().val() }, function(res) {
            $btn.prop('disabled', false).html(oldHtml);

            if (!res || !res.success) {
                const policeMsg = res && res.police && res.police.message ? res.police.message : (res && res.message ? res.message : IMEI_I18N.checkCouldNotComplete);
                const warningAlert = `<div class="alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${window.escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-circle-exclamation me-2"></i>${window.escapeHtml(policeMsg)}</div></div>`;
                $imeiResult.html(warningAlert + renderIfreeicloudResult(res && res.ifreeicloud));
                return;
            }

            const policeMessage = res.police && res.police.message ? res.police.message : (res.message || '');
            let policeType = 'warning';
            let policeHeadline = IMEI_I18N.resultUnknown;
            if (res.status === 'found') {
                policeType = 'danger';
                policeHeadline = IMEI_I18N.resultFound;
            } else if (res.status === 'not_found') {
                policeType = 'success';
                policeHeadline = IMEI_I18N.resultNotFound;
            } else if (policeMessage) {
                policeHeadline = policeMessage;
            }

            const policeAlert = (() => {
                const icon = policeType === 'success' ? 'check-circle' : policeType === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
                const alertClass = policeType === 'success'
                    ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
                    : policeType === 'danger'
                        ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                        : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';
                const detailHtml = policeMessage ? `<div class="small mt-1 opacity-75">${window.escapeHtml(policeMessage)}</div>` : '';
                return `<div class="${alertClass} mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${window.escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-${icon} me-2"></i>${window.escapeHtml(policeHeadline)}</div>${detailHtml}</div>`;
            })();

            const ifreeicloudHtml = renderIfreeicloudResult(res.ifreeicloud);
            $imeiResult.html(policeAlert + ifreeicloudHtml);
        }, 'json').fail(function() {
            $btn.prop('disabled', false).html(oldHtml);
            renderImeiResult('warning', IMEI_I18N.checkFailed);
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

