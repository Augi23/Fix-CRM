<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Filter for Dashboard
$filter_status = $_GET['filter'] ?? null;

// Permission check for stats - technicians only see their orders unless they have view_all_orders
$tech_cond = "";
if ($_SESSION['role'] == 'technician' && !hasPermission('view_all_orders')) {
    $tech_cond = " AND technician_id = " . (int)$_SESSION['tech_id'];
}

// Count for Stats
$new_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'New'" . $tech_cond)->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending Approval'" . $tech_cond)->fetchColumn();
$progress_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'In Progress'" . $tech_cond)->fetchColumn();
$ready_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Completed', 'Collected')" . $tech_cond)->fetchColumn();

// Design-system stats
$waiting_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Waiting for Parts'" . $tech_cond)->fetchColumn();
$active_count = (int)$new_count + (int)$pending_count + (int)$progress_count + $waiting_count;
$urgent_waiting = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Waiting for Parts' AND priority = 'High'" . $tech_cond)->fetchColumn();
try {
    $completed_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Completed','Collected') AND DATE(updated_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $planned_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $new_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'New' AND DATE(created_at) = CURDATE()" . $tech_cond)->fetchColumn();
    $revenue_month = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ('Completed','Collected') AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())" . $tech_cond)->fetchColumn();
    $revenue_prev = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ('Completed','Collected') AND MONTH(updated_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(updated_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)" . $tech_cond)->fetchColumn();
    $revenue_trend = $revenue_prev > 0 ? round((($revenue_month - $revenue_prev) / $revenue_prev) * 100) : 0;
    $revenue_12m = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = $pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ('Completed','Collected') AND YEAR(updated_at)*12+MONTH(updated_at) = YEAR(CURDATE())*12+MONTH(CURDATE()) - $i" . $tech_cond)->fetchColumn();
        $revenue_12m[] = (float)$m;
    }
} catch (Throwable $e) {
    $completed_today = $planned_today = $new_today = 0;
    $revenue_month = $revenue_prev = 0; $revenue_trend = 0; $revenue_12m = array_fill(0, 12, 0);
}
$month_labels = ['D','L','Ú','B','D','Č','Č','S','Z','Ř','L','D'];
$rev_max = max(1, max($revenue_12m));

// Online Techs (Last 5 minutes) - Admin or those with admin_access
$online_count = 0;
if (hasPermission('admin_access')) {
    $online_count = $pdo->query("SELECT COUNT(*) FROM technicians WHERE last_seen > (NOW() - INTERVAL 5 MINUTE) AND is_active = 1")->fetchColumn();
}

// Load technicians list once for new order modal
$techs_list = [];
try {
    $techs_list = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    $techs_list = [];
}

$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

?>

<div class="crm-stat-grid mb-4">
    <a href="orders.php" class="crm-stat-card crm-stat-1 text-decoration-none">
        <div class="crm-stat-label">Aktivní zakázky</div>
        <div class="crm-stat-value"><?php echo (int)$active_count; ?></div>
        <div class="crm-stat-sub <?php echo $new_today > 0 ? 'up' : ''; ?>">
            <?php if ($new_today > 0): ?>↑ <?php echo $new_today; ?> dnes<?php else: ?>beze změny<?php endif; ?>
        </div>
    </a>
    <a href="?filter=Waiting for Parts" class="crm-stat-card crm-stat-2 text-decoration-none">
        <div class="crm-stat-label">Čeká na díl</div>
        <div class="crm-stat-value"><?php echo (int)$waiting_count; ?></div>
        <div class="crm-stat-sub <?php echo $urgent_waiting > 0 ? 'down' : ''; ?>">
            <?php echo $urgent_waiting > 0 ? $urgent_waiting.' urgentní' : 'v normě'; ?>
        </div>
    </a>
    <a href="?filter=Completed" class="crm-stat-card crm-stat-3 text-decoration-none">
        <div class="crm-stat-label">Opraveno dnes</div>
        <div class="crm-stat-value"><?php echo (int)$completed_today; ?></div>
        <div class="crm-stat-sub">z <?php echo (int)$planned_today; ?> plánovaných</div>
    </a>
    <div class="crm-stat-card crm-stat-4">
        <?php $_monthCz = ['leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec']; ?>
        <div class="crm-stat-label">Tržby (<?php echo $_monthCz[(int)date('n')-1] ?? ''; ?>)</div>
        <div class="crm-stat-value"><?php echo number_format($revenue_month, 0, ',', ' '); ?> Kč</div>
        <div class="crm-stat-sub <?php echo $revenue_trend > 0 ? 'up' : ($revenue_trend < 0 ? 'down' : ''); ?>">
            <?php if ($revenue_trend > 0): ?>↑ <?php echo $revenue_trend; ?> % vs. minulý<?php elseif ($revenue_trend < 0): ?>↓ <?php echo abs($revenue_trend); ?> % vs. minulý<?php else: ?>beze změny<?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4 align-items-start dashboard-main-row">
    <div class="col-12 col-lg-8">
        <div class="card glass-card border-0">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php 
                    if ($filter_status == 'New') echo __('new_orders');
                    elseif ($filter_status == 'Pending Approval') echo __('pending_approval_orders');
                    elseif ($filter_status == 'In Progress') echo __('in_progress_orders');
                    elseif ($filter_status == 'Completed') echo __('completed_orders');
                    else echo __('recent_orders'); 
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
                                <th>ID</th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('device_model'); ?></th>
                                <th><?php echo __('problem'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th><?php echo __('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $search = $_GET['search'] ?? '';
                            
                            // Permission check for technicians
                            $tech_filter = "";
                            if ($_SESSION['role'] == 'technician' && !hasPermission('view_all_orders')) {
                                $tech_filter = " AND o.technician_id = " . (int)$_SESSION['tech_id'];
                            }
                            
                            $where_clause = " WHERE (1=1)" . $tech_filter;
                            $params = [];

                            if ($search) {
                                $search = trim($search);
                                $exact_id_filter = is_numeric($search) ? " OR o.id = ?" : "";
                                $where_clause .= " AND (o.id LIKE ? OR o.device_model LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?$exact_id_filter)";
                                $searchTerm = "%$search%";
                                array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                                if (is_numeric($search)) {
                                    $params[] = (int)$search;
                                }
                            }

                            if ($filter_status) {
                                if ($filter_status == 'Completed') {
                                    $where_clause .= " AND o.status IN ('Completed', 'Collected')";
                                } else {
                                    $where_clause .= " AND o.status = ?";
                                    $params[] = $filter_status;
                                }
                            }

                            $search_id = ($search && is_numeric($search)) ? (int)$search : 0;
                            $sql = "SELECT o.*, c.first_name, c.last_name, c.phone, c.company, c.customer_type, t.name as tech_name 
                                    FROM orders o 
                                    JOIN customers c ON o.customer_id = c.id 
                                    LEFT JOIN technicians t ON o.technician_id = t.id" . 
                                    $where_clause . 
                                    " ORDER BY (CASE WHEN o.id = ? THEN 1 ELSE 2 END), o.created_at DESC LIMIT 15";
                            
                            $stmt = $pdo->prepare($sql);
                            // Add search_id to params for the ORDER BY clause
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
                            ?>
                            <tr class="clickable-order-row<?php echo !empty($r['company']) || ($r['customer_type'] ?? '') === 'company' ? ' order-row--company' : ''; ?><?php echo $r['priority'] == 'High' ? ' order-row--high' : ''; ?>" style="cursor: pointer;" onclick="window.location.href='view_order.php?id=<?php echo (int)$r['id']; ?>'" tabindex="0" role="link">
                                <td>
                                    <a href="view_order.php?id=<?php echo $r['id']; ?>" class="fw-bold text-decoration-none">#<?php echo $r['id']; ?></a>
                                    <?php if($has_media): ?>
                                        <i class="fas fa-camera text-info ms-1" title="<?php echo __('has_media'); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></strong><br>
                                    <small class="text-white-75">
                                        <?php if ($phone_href !== ''): ?>
                                            <a href="tel:<?php echo e($phone_href); ?>" class="text-reset text-decoration-none" onclick="event.stopPropagation();"><?php echo htmlspecialchars($r['phone']); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($r['phone']); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo $icon; ?> <strong><?php echo htmlspecialchars($r['device_brand']); ?></strong><br>
                                    <small class="text-white-75"><?php echo htmlspecialchars($r['device_model']); ?></small>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars(mb_strimwidth($r['problem_description'], 0, 40, "...")); ?></small><br>
                                    <span class="badge bg-transparent border border-secondary text-white-75"><i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($r['tech_name'] ?? '---'); ?></span>
                                </td>
                                <td><?php echo getStatusBadge($r['status']); ?></td>
                                <td><strong><?php echo formatMoney($r['final_cost'] ?? $r['estimated_cost']); ?></strong></td>
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
    <div class="col-12 col-lg-4">
        <!-- Revenue chart (design-system) -->
        <div class="crm-revenue-card mb-4">
            <div class="crm-revenue-label">Tržby po měsících</div>
            <div class="crm-revenue-value"><?php echo number_format($revenue_month, 0, ',', ' '); ?> Kč</div>
            <?php
                $chart_h = 56; $chart_w = 260;
                $bar_w = 12; $bar_gap = ($chart_w - count($revenue_12m)*$bar_w) / max(1, count($revenue_12m)-1);
            ?>
            <svg class="crm-revenue-chart" width="<?php echo $chart_w; ?>" height="<?php echo $chart_h + 16; ?>" viewBox="0 0 <?php echo $chart_w; ?> <?php echo $chart_h + 16; ?>">
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

        <!-- Fronta dnes (design-system) -->
        <?php
        try {
            $queue_today = $pdo->query("SELECT o.id, o.device_model, o.device_brand, o.status, c.first_name, c.last_name, t.name AS tech_name FROM orders o JOIN customers c ON o.customer_id=c.id LEFT JOIN technicians t ON o.technician_id=t.id WHERE o.status IN ('New','Pending Approval','In Progress','Waiting for Parts')" . $tech_cond . " ORDER BY o.priority='High' DESC, o.created_at DESC LIMIT 6")->fetchAll();
        } catch (Throwable $e) { $queue_today = []; }
        ?>
        <div class="crm-queue-card mb-4">
            <div class="crm-queue-head">
                <div class="crm-queue-title">Fronta dnes</div>
                <div class="crm-queue-date"><?php echo date('j. n. Y'); ?></div>
            </div>
            <div class="crm-queue-body">
                <?php if (empty($queue_today)): ?>
                    <div class="crm-queue-empty">Žádné otevřené zakázky</div>
                <?php else: foreach ($queue_today as $q):
                    $init = strtoupper(mb_substr($q['tech_name'] ?? '?', 0, 2));
                ?>
                    <a href="view_order.php?id=<?php echo (int)$q['id']; ?>" class="crm-queue-item text-decoration-none">
                        <div class="crm-queue-avatar"><?php echo e($init); ?></div>
                        <div class="crm-queue-meta">
                            <div class="crm-queue-name"><?php echo e(trim($q['first_name'].' '.$q['last_name'])); ?></div>
                            <div class="crm-queue-device"><?php echo e(trim($q['device_brand'].' '.$q['device_model'])); ?></div>
                        </div>
                        <?php echo getStatusBadge($q['status']); ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card glass-card border-0 mb-4 imei-check-card">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-mobile-screen-button text-info me-2"></i><?php echo __('imei_check_title'); ?></h5>
                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><?php echo __('police_db'); ?></span>
            </div>
            <div class="card-body">
                <form id="imeiCheckForm">
                    <label class="form-label">IMEI</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="imeiCheckInput" placeholder="<?php echo e(__('imei_input_placeholder')); ?>" inputmode="numeric" autocomplete="off" maxlength="15">
                        <button class="btn btn-outline-info" type="submit">
                            <i class="fas fa-search me-1"></i><?php echo __('check'); ?>
                        </button>
                    </div>
                    <div class="form-text text-white-75"><?php echo __('imei_help_text'); ?></div>
                </form>
                <div id="imeiCheckResult" class="imei-check-result mt-3" aria-live="polite"></div>
            </div>
        </div>

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

<!-- New Order Modal — 3-step wizard -->
<div class="modal fade crm-wizard-modal" id="newOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_order.php" method="POST" enctype="multipart/form-data" id="newOrderForm">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-3">
                    <div class="w-100">
                        <h5 class="modal-title crm-grad-text mb-1">Nová zakázka</h5>
                        <div class="crm-wizard-step-label">Krok <span data-wizard-current>1</span> ze 3</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="crm-wizard-progress">
                        <div class="crm-wizard-seg" data-seg="1"></div>
                        <div class="crm-wizard-seg" data-seg="2"></div>
                        <div class="crm-wizard-seg" data-seg="3"></div>
                    </div>
                    <div class="crm-wizard-step" data-step="1">
                    <!-- ═══ 1. KLIENT ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user text-primary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('client'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <select name="customer_id" class="form-select select2-customer" style="width: 100%;" required>
                                    <option value=""><?php echo __('enter_name_or_phone'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary w-100" id="toggleNewCustomerPanelBtn" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel" aria-expanded="false">
                                    <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                                </button>
                            </div>
                            <!-- Inline New Customer Panel (collapsible, inside the same modal) -->
                            <div class="col-12">
                                <div class="collapse" id="inlineNewCustomerPanel">
                                    <div class="card border-secondary bg-dark bg-opacity-25 mt-2">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6 class="mb-0 text-white"><i class="fas fa-user-plus me-2 text-primary"></i><?php echo __('add_customer'); ?></h6>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div id="newCustomerInlineForm">
                                                <div class="mb-3">
                                                    <div class="btn-group w-100" role="group">
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_private" value="private" checked>
                                                        <label class="btn btn-outline-primary" for="inline_type_private"><?php echo __('private_person'); ?></label>
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_company" value="company">
                                                        <label class="btn btn-outline-primary" for="inline_type_company"><?php echo __('company_entity'); ?></label>
                                                    </div>
                                                </div>
                                                <div id="inline_company_fields" class="d-none border border-secondary p-3 rounded bg-transparent mb-3">
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('ico'); ?></label>
                                                        <div class="input-group">
                                                            <input type="text" name="ico" id="inline_ico_input" class="form-control" placeholder="12345678">
                                                            <button class="btn btn-info text-white" type="button" id="inline_btn_fetch_ares">
                                                                <i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('company_name'); ?></label>
                                                        <input type="text" name="company_name" id="inline_ares_name" class="form-control">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('dic'); ?></label>
                                                        <input type="text" name="dic" id="inline_ares_dic" class="form-control" placeholder="CZ12345678">
                                                    </div>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('name_col'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="first_name" id="inline_first_name" class="form-control">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('last_name_label'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="last_name" id="inline_last_name" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('phone'); ?> <span class="text-danger">*</span></label>
                                                        <input type="tel" name="phone" id="inline_phone" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="inline_email" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('address'); ?></label>
                                                        <textarea name="address" id="inline_address" class="form-control" rows="2"></textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="button" class="btn btn-success w-100" id="saveNewCustomerBtn">
                                                            <i class="fas fa-check me-2"></i><?php echo __('save'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    </div><!-- /step 1 -->
                    <div class="crm-wizard-step" data-step="2" hidden>
                    <!-- ═══ 2. ZAŘÍZENÍ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-laptop text-info me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_device'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_type'); ?></label>
                                <select name="device_type" class="form-select select2-device-type" style="width: 100%;" required>
                                    <option value="Phone">📱 <?php echo __('Phone'); ?></option>
                                    <option value="Notebook">💻 <?php echo __('Notebook'); ?></option>
                                    <option value="PC">🖥️ <?php echo __('PC'); ?></option>
                                    <option value="Tablet">📟 <?php echo __('Tablet'); ?></option>
                                    <option value="HDD">💾 <?php echo __('HDD'); ?></option>
                                    <option value="Other">❓ <?php echo __('Other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('warranty_type'); ?></label>
                                <select name="order_type" class="form-select select2-order-type" style="width: 100%;" required>
                                    <option value="Non-Warranty">🛠 <?php echo __('warranty_no'); ?></option>
                                    <option value="Warranty">📜 <?php echo __('warranty_yes'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_brand'); ?></label>
                                <select name="device_brand" class="form-select select2-brand" style="width: 100%;" required>
                                    <option value=""><?php echo __('brand_placeholder'); ?></option>
                                    <?php foreach(getDeviceBrands() as $brand): ?>
                                        <option value="<?php echo $brand; ?>"><?php echo $brand; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_model'); ?></label>
                                <input type="text" name="device_model" class="form-control" placeholder="<?php echo __('model_placeholder'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial'); ?></label>
                                <input type="text" name="serial_number" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial_2'); ?></label>
                                <input type="text" name="serial_number_2" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('pin'); ?></label>
                                <input type="text" name="pin_code" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('appearance'); ?></label>
                                <input type="text" name="appearance" class="form-control">
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 3. ПРОБЛЕМА ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_problem'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('priority'); ?></label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="priority" value="High" id="priorityHighDashboard">
                                    <label class="form-check-label" for="priorityHighDashboard"><?php echo __('high'); ?></label>
                                </div>
                            </div>
                            <?php if (!empty($order_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_note_templates) ? '4' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates'); ?></label>
                                <select class="form-select order-template-select" data-target="problem_description">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order_note_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_templates) ? '5' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates_notes'); ?></label>
                                <select class="form-select order-template-select" data-target="technician_notes">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_note_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('problem'); ?></label>
                                <textarea name="problem_description" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('notes'); ?> <?php echo __('comment_suffix'); ?></label>
                                <textarea name="technician_notes" class="form-control" rows="2" placeholder="<?php echo __('notes_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    </div><!-- /step 2 -->
                    <div class="crm-wizard-step" data-step="3" hidden>
                    <div class="crm-wizard-summary">
                        <div class="crm-wizard-summary-label">Přehled zakázky</div>
                        <div class="crm-wizard-summary-grid">
                            <div><span>Zákazník:</span> <strong data-summary="customer">—</strong></div>
                            <div><span>Zařízení:</span> <strong data-summary="device">—</strong></div>
                            <div><span>Typ opravy:</span> <strong data-summary="service">—</strong></div>
                            <div><span>Priorita:</span> <strong data-summary="priority">Normální</strong></div>
                        </div>
                    </div>
                    <!-- ═══ 3. FINANCE / PŘIŘAZENÍ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-coins text-success me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_financial'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('cost_est'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="estimated_cost" class="form-control" step="0.01">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 5. ИСПОЛНИТЕЛЬ ═══ -->
                    <div class="mb-0">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user-cog text-secondary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_execution'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('technician'); ?></label>
                                <select name="technician_id" class="form-select">
                                    <option value="">-- <?php echo __('technician'); ?> --</option>
                                    <?php foreach ($techs_list as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (($_SESSION['role'] ?? '') !== 'admin' && $t['id'] == ($_SESSION['tech_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('media_files'); ?></label>
                                <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                <div class="form-text"><?php echo __('upload_multiple_hint'); ?></div>
                            </div>
                        </div>
                    </div>
                    </div><!-- /step 3 -->
                </div>
                <div class="modal-footer bg-transparent border-secondary crm-wizard-footer">
                    <button type="button" class="btn btn-secondary" data-wizard-prev hidden>← Zpět</button>
                    <button type="button" class="btn btn-primary" data-wizard-next>Pokračovat →</button>
                    <button type="submit" class="btn btn-primary" data-wizard-submit hidden>Vytvořit zakázku</button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
$(document).ready(function() {
    let currentCustomerSearch = '';
    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }
    function highlightMatch(text, term) {
        if (!term) return escapeHtml(text);
        const safe = escapeHtml(text);
        const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        return safe.replace(re, '<span class="match">$1</span>');
    }

    function initNewOrderModalSelects() {
        const $modal = $('#newOrderModal');
        const $dropdownParent = $modal.find('.modal-content');

        const $customerSelect = $modal.find('.select2-customer');
        if ($customerSelect.length) {
            if ($customerSelect.data('select2')) {
                $customerSelect.select2('destroy');
            }

            $customerSelect.select2({
                dropdownParent: $dropdownParent,
                placeholder: "<?php echo __('search_client_placeholder'); ?>",
                allowClear: true,
                minimumInputLength: 0,
                width: '100%',
                ajax: {
                    url: 'api/search_customers.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        currentCustomerSearch = params.term || '';
                        return { q: params.term, page: params.page || 1 };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return { results: data.results, pagination: { more: data.pagination.more } };
                    }
                },
                templateResult: function(item) {
                    if (item.loading) return item.text;
                    const name = item.name || item.text || '';
                    const phone = item.phone || '';
                    const title = highlightMatch(name, currentCustomerSearch);
                    const meta = phone ? '<span class="meta">' + highlightMatch(phone, currentCustomerSearch) + '</span>' : '';
                    return $('<div class="customer-option"><div>' + title + '</div>' + meta + '</div>');
                },
                templateSelection: function(item) {
                    return item.text || item.name || '';
                },
                escapeMarkup: function(markup) { return markup; }
            });
        }

        const $brandSelect = $modal.find('.select2-brand');
        if ($brandSelect.length) {
            if ($brandSelect.data('select2')) {
                $brandSelect.select2('destroy');
            }

            $brandSelect.select2({
                dropdownParent: $dropdownParent,
                placeholder: "<?php echo __('brand_placeholder'); ?>",
                tags: true,
                width: '100%',
                dropdownAutoWidth: false
            });
        }

        const $deviceTypeSelect = $modal.find('.select2-device-type');
        if ($deviceTypeSelect.length) {
            if ($deviceTypeSelect.data('select2')) {
                $deviceTypeSelect.select2('destroy');
            }

            $deviceTypeSelect.select2({
                dropdownParent: $dropdownParent,
                width: '100%',
                minimumResultsForSearch: Infinity,
                dropdownAutoWidth: false
            });
        }

        const $orderTypeSelect = $modal.find('.select2-order-type');
        if ($orderTypeSelect.length) {
            if ($orderTypeSelect.data('select2')) {
                $orderTypeSelect.select2('destroy');
            }

            $orderTypeSelect.select2({
                dropdownParent: $dropdownParent,
                width: '100%',
                minimumResultsForSearch: Infinity,
                dropdownAutoWidth: false
            });
        }
    }

    $('#newOrderModal').on('shown.bs.modal', initNewOrderModalSelects);

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
    $('#saveNewCustomerBtn').on('click', function() {
        const $panel = $('#newCustomerInlineForm');
        const firstName = $('#inline_first_name').val().trim();
        const lastName = $('#inline_last_name').val().trim();
        const phone = $('#inline_phone').val().trim();
        
        if (!firstName || !lastName || !phone) {
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

    const $imeiInput = $('#imeiCheckInput');
    const $imeiResult = $('#imeiCheckResult');
    const IMEI_I18N = {
        policeDb: <?php echo json_encode(__('police_db'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        ifreeicloudNotConfigured: <?php echo json_encode(__('ifreeicloud_not_configured'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        ifreeicloudIssue: <?php echo json_encode(__('ifreeicloud_issue'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        ifreeicloudSuccess: <?php echo json_encode(__('ifreeicloud_success'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        ifreeicloudInconclusive: <?php echo json_encode(__('ifreeicloud_inconclusive'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        yes: <?php echo json_encode(__('yes'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        no: <?php echo json_encode(__('no'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        imeiMinDigits: <?php echo json_encode(__('imei_min_digits_warning'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checking: <?php echo json_encode(__('checking'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checkFailed: <?php echo json_encode(__('imei_check_failed_try_again'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checkCouldNotComplete: <?php echo json_encode(__('imei_check_could_not_complete'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultUnknown: <?php echo json_encode(__('imei_result_unknown'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultFound: <?php echo json_encode(__('imei_result_found'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultNotFound: <?php echo json_encode(__('imei_result_not_found'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    function renderImeiResult(type, message, details) {
        const icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        const alertClass = type === 'success'
            ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : type === 'danger'
                ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';

        const detailHtml = details ? `<div class="small mt-1 opacity-75">${escapeHtml(details)}</div>` : '';
        $imeiResult.html(`<div class="${alertClass} mb-0 py-2"><i class="fas fa-${icon} me-2"></i>${escapeHtml(message)}${detailHtml}</div>`);
    }

    function renderIfreeicloudResult(result) {
        if (!result) return '';
        if (!result.configured) {
            return `<div class="alert alert-secondary border-secondary border-opacity-25 bg-secondary bg-opacity-10 text-white-75 mb-0 py-2"><i class="fas fa-cloud me-2"></i>${escapeHtml(result.message || IMEI_I18N.ifreeicloudNotConfigured)}</div>`;
        }

        const type = result.status === 'error' ? 'danger' : result.status === 'success' ? 'success' : 'warning';
        const icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        const alertClass = type === 'success'
            ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : type === 'danger'
                ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';
        const headline = type === 'danger'
            ? IMEI_I18N.ifreeicloudIssue
            : type === 'success'
                ? IMEI_I18N.ifreeicloudSuccess
                : IMEI_I18N.ifreeicloudInconclusive;

        const firstDefined = (obj, keys) => {
            if (!obj) return undefined;
            for (const key of keys) {
                if (Object.prototype.hasOwnProperty.call(obj, key) && obj[key] !== undefined && obj[key] !== null && obj[key] !== '') {
                    return obj[key];
                }
            }
            return undefined;
        };

        const normalizeYesNo = (value) => {
            if (value === true || value === 1 || value === '1') return { text: IMEI_I18N.yes, state: 'yes' };
            if (value === false || value === 0 || value === '0') return { text: IMEI_I18N.no, state: 'no' };
            if (typeof value === 'string') {
                const v = value.trim().toLowerCase();
                if (['yes', 'y', 'true', 'on', 'locked', 'active', 'enabled', 'ano', 'fmi on'].includes(v)) return { text: 'Yes', state: 'yes' };
                if (['no', 'n', 'false', 'off', 'unlocked', 'inactive', 'disabled', 'ne'].includes(v)) return { text: 'No', state: 'no' };
            }
            return { text: String(value), state: '' };
        };

        const extractImageUrl = (source) => {
            if (!source) return '';
            if (typeof source === 'string') {
                const imgMatch = source.match(/<img[^>]+src=["']([^"']+)["']/i);
                if (imgMatch && imgMatch[1]) return imgMatch[1];
                const urlMatch = source.match(/https?:\/\/[^\s"'<>]+\.(?:png|jpe?g|gif|webp)(?:\?[^\s"'<>]*)?/i);
                if (urlMatch && urlMatch[0]) return urlMatch[0];
            }
            return '';
        };

        const rawImageUrl = result.image_url
            || extractImageUrl(result.response)
            || extractImageUrl(result.message)
            || extractImageUrl(result.raw ? JSON.stringify(result.raw) : '');

        const imageHtml = rawImageUrl
            ? `<div class="ifreeicloud-preview mb-3"><img src="${escapeHtml(rawImageUrl)}" alt="iFreeiCloud preview" loading="lazy" referrerpolicy="no-referrer"></div>`
            : '';

        const fieldMap = [
            ['model', 'Model'],
            ['capacity', 'Capacity'],
            ['colour', 'Color'],
            ['color', 'Color'],
            ['network', 'Network'],
            ['imei', 'IMEI'],
            ['imei2', 'IMEI2'],
            ['meid', 'MEID'],
            ['serial', 'Serial number'],
            ['warrantyStatus', 'Warranty'],
            ['estPurchaseDate', 'Purchase date'],
            ['technicalSupport', 'Technical support'],
            ['repairCoverage', 'Service coverage'],
            ['replaced', 'Replaced by Apple'],
            ['usaBlockStatus', 'US block status'],
            ['simLock', 'SIM lock'],
            ['isAppleDevice', 'Apple device'],
        ];

        const object = result.object && typeof result.object === 'object' ? result.object : null;
        let detailsHtml = '';

        if (object) {
            const rows = [];
            fieldMap.forEach(([key, label]) => {
                const value = firstDefined(object, [key]);
                if (value === undefined) return;
                let display = value;
                if (typeof display === 'boolean' || display === 0 || display === 1 || display === '0' || display === '1') {
                    display = normalizeYesNo(display).text;
                } else if (display !== null && display !== undefined && typeof display !== 'string') {
                    display = String(display);
                }
                rows.push(`<div class="ifreeicloud-row"><span>${escapeHtml(label)}</span><span class="ifreeicloud-value">${escapeHtml(display)}</span></div>`);
            });

            const findMySource = firstDefined(object, ['fmiOn', 'findMyIphone', 'findMyiPhone', 'find_my_iphone', 'findMyiphone']);
            if (findMySource !== undefined) {
                const normalized = normalizeYesNo(findMySource);
                const stateClass = normalized.state === 'yes' ? 'ifreeicloud-row--yes' : 'ifreeicloud-row--no';
                rows.push(`<div class="ifreeicloud-row ifreeicloud-row--findmy ${stateClass}"><span>Find My iPhone</span><span class="ifreeicloud-value">${escapeHtml(normalized.text)}</span></div>`);
            }

            const lostModeSource = firstDefined(object, ['lostMode', 'lost_mode', 'lostmode']);
            if (lostModeSource !== undefined) {
                const normalized = normalizeYesNo(lostModeSource);
                const stateClass = normalized.state === 'yes' ? 'ifreeicloud-row--yes' : 'ifreeicloud-row--no';
                rows.push(`<div class="ifreeicloud-row ifreeicloud-row--lostmode ${stateClass}"><span>Lost mode</span><span class="ifreeicloud-value">${escapeHtml(normalized.text)}</span></div>`);
            }

            detailsHtml = rows.length ? `<div class="ifreeicloud-details">${rows.join('')}</div>` : '';
        }

        const messageText = (() => {
            const raw = typeof result.message === 'string' ? result.message : '';
            if (!raw) return '';
            if (/[<>&]/.test(raw)) return '';
            return raw;
        })();
        const message = messageText ? `<div class="small mt-1 opacity-75">${escapeHtml(messageText)}</div>` : '';
        const meta = result.service_id !== undefined ? `<div class="small mt-2 opacity-50">Service ID: ${escapeHtml(String(result.service_id))}${result.http_code ? ` · HTTP ${escapeHtml(String(result.http_code))}` : ''}</div>` : '';
        const note = (!detailsHtml && !imageHtml && result.summary) ? `<pre class="small mt-2 mb-0 p-2 rounded border border-opacity-25 bg-dark bg-opacity-25 text-white-75" style="white-space: pre-wrap;">${escapeHtml(result.summary)}</pre>` : '';

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
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + escapeHtml(IMEI_I18N.checking));
        $imeiResult.empty();

        $.post('api/check_imei.php', { imei: imei, csrf_token: $('input[name="csrf_token"]').first().val() }, function(res) {
            $btn.prop('disabled', false).html(oldHtml);

            if (!res || !res.success) {
                const policeMsg = res && res.police && res.police.message ? res.police.message : (res && res.message ? res.message : IMEI_I18N.checkCouldNotComplete);
                const warningAlert = `<div class="alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-circle-exclamation me-2"></i>${escapeHtml(policeMsg)}</div></div>`;
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
                const detailHtml = policeMessage ? `<div class="small mt-1 opacity-75">${escapeHtml(policeMessage)}</div>` : '';
                return `<div class="${alertClass} mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-${icon} me-2"></i>${escapeHtml(policeHeadline)}</div>${detailHtml}</div>`;
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

