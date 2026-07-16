<?php
/**
 * Sdílené horní dlaždice (Nástěnka + Zakázky): Aktivní zakázky, Čeká na díly,
 * Opraveno dnes, Denní tržba, Nepřidělené, Nedokončené — mřížka 3×2.
 * Samostatně si spočítá vše, co potřebuje (lokální __st_* proměnné), takže jde
 * vložit kamkoliv: <?php include __DIR__ . '/includes/partials/stat_tiles.php'; ?>
 * Scoping: první čtyři dlaždice dle orderBranchScopeSql (Karlín vidí vše);
 * Nepřidělené/Nedokončené: vedení = obě pobočky, zaměstnanci = jen svá pobočka.
 */
$__st_cond = orderBranchScopeSql('branch_id');
$__st_new = orderStatusSqlIn($pdo, 'new');
$__st_pending = orderStatusSqlIn($pdo, 'pending_approval');
$__st_progress = orderStatusSqlIn($pdo, 'in_progress');
$__st_waiting = orderStatusSqlIn($pdo, 'waiting_parts');
$__st_done = orderStatusSqlIn($pdo, 'done');
$__st_active = orderStatusSqlIn($pdo, 'active');

try {
    $__st_active_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_new)" . $__st_cond)->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_pending)" . $__st_cond)->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_progress)" . $__st_cond)->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_waiting)" . $__st_cond)->fetchColumn();
    $__st_new_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_new) AND DATE(created_at) = CURDATE()" . $__st_cond)->fetchColumn();
    $__st_waiting_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_waiting)" . $__st_cond)->fetchColumn();
    $__st_urgent_waiting = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_waiting) AND priority = 'High'" . $__st_cond)->fetchColumn();
    $__st_completed_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_done) AND DATE(updated_at) = CURDATE()" . $__st_cond)->fetchColumn();
    $__st_planned_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()" . $__st_cond)->fetchColumn();
    $__st_revenue_today = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($__st_done) AND DATE(updated_at) = CURDATE()" . $__st_cond)->fetchColumn();
    $__st_revenue_yesterday = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($__st_done) AND DATE(updated_at) = CURDATE() - INTERVAL 1 DAY" . $__st_cond)->fetchColumn();
    $__st_revenue_trend = $__st_revenue_yesterday > 0 ? round((($__st_revenue_today - $__st_revenue_yesterday) / $__st_revenue_yesterday) * 100) : 0;
} catch (Throwable $e) {
    $__st_active_count = $__st_new_today = $__st_waiting_count = $__st_urgent_waiting = 0;
    $__st_completed_today = $__st_planned_today = 0;
    $__st_revenue_today = 0.0; $__st_revenue_trend = 0;
}

// Nepřidělené/Nedokončené: vedení = obě pobočky, řadoví zaměstnanci = jen svoje
$__st_global = isBranchGlobalViewer();
$__st_myBranch = (int)getCurrentStaffBranchId();
$__st_pair_cond = (!$__st_global && $__st_myBranch > 0) ? " AND branch_id = " . $__st_myBranch : '';
$__st_pair_label = $__st_global ? 'Obě pobočky' : getBranchLabel($__st_myBranch);
try {
    $__st_unassigned = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_active) AND (technician_id IS NULL OR technician_id = 0)" . $__st_pair_cond)->fetchColumn();
    $__st_unfinished = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($__st_active)" . $__st_pair_cond)->fetchColumn();
} catch (Throwable $e) { $__st_unassigned = $__st_unfinished = 0; }
?>
<div class="crm-stat-grid">
    <a href="orders.php" class="crm-stat-card crm-stat-1 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('active_orders'); ?></div>
        <div class="crm-stat-value"><?php echo $__st_active_count; ?></div>
        <div class="crm-stat-sub <?php echo $__st_new_today > 0 ? 'up' : ''; ?>">
            <?php if ($__st_new_today > 0): ?>↑ <?php echo $__st_new_today; ?> <?php echo __('today'); ?><?php else: ?><?php echo __('no_change'); ?><?php endif; ?>
        </div>
    </a>
    <a href="orders.php?filter=<?php echo urlencode('Čeká na díl'); ?>" class="crm-stat-card crm-stat-2 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('waiting_parts'); ?></div>
        <div class="crm-stat-value"><?php echo $__st_waiting_count; ?></div>
        <div class="crm-stat-sub <?php echo $__st_urgent_waiting > 0 ? 'down' : ''; ?>">
            <?php echo $__st_urgent_waiting > 0 ? $__st_urgent_waiting . ' ' . __('urgent') : __('in_queue'); ?>
        </div>
    </a>
    <a href="orders.php?filter=<?php echo urlencode('Připraveno k převzetí'); ?>" class="crm-stat-card crm-stat-3 text-decoration-none">
        <div class="crm-stat-label"><?php echo __('repaired_today'); ?></div>
        <div class="crm-stat-value"><?php echo $__st_completed_today; ?></div>
        <div class="crm-stat-sub"><?php echo __('of'); ?> <?php echo $__st_planned_today; ?> <?php echo __('planned'); ?></div>
    </a>
    <div class="crm-stat-card crm-stat-4">
        <div class="crm-stat-label"><?php echo __('daily_revenue'); ?></div>
        <div class="crm-stat-value"><?php echo number_format($__st_revenue_today, 0, ',', ' '); ?> Kč</div>
        <div class="crm-stat-sub <?php echo $__st_revenue_trend > 0 ? 'up' : ($__st_revenue_trend < 0 ? 'down' : ''); ?>">
            <?php if ($__st_revenue_trend > 0): ?>↑ <?php echo $__st_revenue_trend; ?> % <?php echo __('vs_yesterday'); ?><?php elseif ($__st_revenue_trend < 0): ?>↓ <?php echo abs($__st_revenue_trend); ?> % <?php echo __('vs_yesterday'); ?><?php else: ?><?php echo __('no_change'); ?><?php endif; ?>
        </div>
    </div>
    <a href="orders.php" class="crm-stat-card crm-stat-5 text-decoration-none">
        <div class="crm-stat-label">Nepřidělené zakázky</div>
        <div class="crm-stat-value"><?php echo $__st_unassigned; ?></div>
        <div class="crm-stat-sub <?php echo $__st_unassigned > 0 ? 'down' : ''; ?>"><i class="fas fa-store me-1" style="font-size:.7rem;"></i><?php echo e($__st_pair_label); ?></div>
    </a>
    <a href="orders.php" class="crm-stat-card crm-stat-6 text-decoration-none">
        <div class="crm-stat-label">Nedokončené zakázky</div>
        <div class="crm-stat-value"><?php echo $__st_unfinished; ?></div>
        <div class="crm-stat-sub"><i class="fas fa-store me-1" style="font-size:.7rem;"></i><?php echo e($__st_pair_label); ?></div>
    </a>
</div>
