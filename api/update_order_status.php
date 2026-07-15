<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
ob_clean();
header('Content-Type: application/json');

checkApiRateLimit('order_status', 30, 60);

$ui_lang = $_REQUEST['ui_lang'] ?? null;
$t = static function(string $key) use ($ui_lang): string {
    return __($key, is_string($ui_lang) ? $ui_lang : null);
};

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => $t('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('csrf_token_invalid')]);
    exit;
}

$order_id = $_REQUEST['order_id'] ?? null;
$requested_status = $_REQUEST['status'] ?? null;
$new_status = $requested_status !== null ? normalizeOrderStatus($requested_status) : null;
$final_cost = $_REQUEST['final_cost'] ?? null;
$technician_id = $_REQUEST['technician_id'] ?? null;

ensureOrderWorkTrackingSchema();
ensureOrderWorkLogSchema(); // DDL — must run before beginTransaction()

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => $t('missing_data')]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT order_code, status, technician_id, branch_id, estimated_cost, final_cost, work_started_at, work_finished_at, work_duration_seconds FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();

    if (!$order_data) {
        throw new Exception($t('order_not_found'));
    }

    // Od 1.6.0 (požadavek 15.7.2026): změnu stavu zakázky smí provést KAŽDÝ
    // přihlášený zaměstnanec a technika smí přeřadit na kohokoliv (dřívější
    // pobočková brána canAccessOrderBranch a omezení „technik smí jen převzít
    // nepřiřazenou zakázku" hlásily technikům „Přístup odepřen").

    $current_status = $order_data['status'];
    $current_tech_id = $order_data['technician_id'];
    $current_estimated = $order_data['estimated_cost'];
    $current_final = $order_data['final_cost'];
    $target_tech_id = ($technician_id && $technician_id !== '') ? (int)$technician_id : (int)$current_tech_id;

    $target_branch_id = (int)($order_data['branch_id'] ?? getCurrentStaffBranchId());
    if (!canAssignTechnicianToOrder($target_tech_id, $target_branch_id)) {
        throw new Exception('Vybraný technik nepatří do pobočky zakázky.');
    }
    if ($target_tech_id) {
        $stmtTechBranch = $pdo->prepare('SELECT branch_id FROM technicians WHERE id = ? LIMIT 1');
        $stmtTechBranch->execute([$target_tech_id]);
        $techBranchId = (int)$stmtTechBranch->fetchColumn();
        if ($techBranchId > 0) {
            $target_branch_id = $techBranchId;
        }
    }

    if (isOrderStatusIn($current_status, 'collected') && !isOrderStatusIn($new_status, 'collected')) {
        throw new Exception($t('status_locked_after_collected'));
    }

    $finishing_statuses = getOrderStatusList('done');
    $was_finished = in_array($current_status, $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);
    $is_starting = (isOrderStatusIn($new_status, 'in_progress') && !isOrderStatusIn($current_status, 'in_progress'));
    $technician_changed = ((int)$target_tech_id !== (int)$current_tech_id);
    $is_reassigning_in_progress = (isOrderStatusIn($current_status, 'in_progress') && isOrderStatusIn($new_status, 'in_progress') && $technician_changed);

    if (isOrderStatusIn($new_status, 'in_progress')) {
        if (!$target_tech_id) {
            throw new Exception($t('in_progress_requires_technician'));
        }
        $active_count = getTechnicianInProgressCount($target_tech_id, (int)$order_id);
        if ($active_count >= 2 && !$was_finished) {
            throw new Exception($t('technician_in_progress_limit_reached'));
        }
    }

    $sql = 'UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$new_status];

    if ($is_starting) {
        $sql .= ', work_started_at = CASE WHEN work_started_at IS NULL OR work_finished_at IS NOT NULL THEN CURRENT_TIMESTAMP ELSE work_started_at END, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL';
        $params[] = $target_tech_id;
    }

    if ($is_reassigning_in_progress) {
        $sql .= ', work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, CURRENT_TIMESTAMP)) ELSE 0 END, work_started_at = CURRENT_TIMESTAMP, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL';
        $params[] = $target_tech_id;
    }

    if (isOrderStatusIn($current_status, 'in_progress') && $is_finishing) {
        $sql .= ', work_finished_at = IFNULL(work_finished_at, CURRENT_TIMESTAMP), work_finished_by = IFNULL(work_finished_by, ?), work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, IFNULL(work_finished_at, CURRENT_TIMESTAMP))) ELSE 0 END';
        $params[] = $target_tech_id;
    }

    if (isOrderStatusIn($new_status, 'collected')) {
        $sql .= ', shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP)';
    }

    if (isOrderStatusIn($new_status, 'collected') && ($final_cost === null || $final_cost === '')) {
        $final_cost = ($current_final !== null && $current_final !== '') ? $current_final : $current_estimated;
    }

    if ($final_cost !== null && $final_cost !== '') {
        $sql .= ', final_cost = ?';
        $params[] = $final_cost;
    }

    $updated_tech_id = ($technician_id && $technician_id !== '') ? (int)$technician_id : (int)$current_tech_id;
    $sql .= ', technician_id = ?, branch_id = ?';
    // 0 = „bez technika" → SQL NULL, jinak padá FK orders_ibfk_2 (technik id 0 neexistuje);
    // týká se hlavně zakázek z RepairPluginu, které vznikají bez přiřazeného technika
    $params[] = $updated_tech_id ?: null;
    $params[] = $target_branch_id;

    if (isset($_REQUEST['extra_expenses']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sql .= ', extra_expenses = ?';
        $params[] = $_REQUEST['extra_expenses'];
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Per-technician work segments (mirror the orders.work_* transitions above).
    if ($is_starting || $is_reassigning_in_progress) {
        workSegmentOpen((int)$order_id, (int)$target_tech_id);
    }
    if (isOrderStatusIn($current_status, 'in_progress') && $is_finishing) {
        workSegmentClose((int)$order_id);
    }
    assignmentSegmentSync((int)$order_id, (int)$updated_tech_id ?: null, (string)($new_status ?? $current_status));

    if ($current_status !== $new_status) {
        logOrderStatusChange($order_id, $current_status, $new_status);
    }

    if (!$was_finished && $is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
    } elseif ($was_finished && !$is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
    }

    $status_changed = ($current_status !== $new_status);
    $technician_changed = ((int)$current_tech_id !== (int)$updated_tech_id);

    $pdo->commit();

    if ($status_changed) {
        $__oc = trim((string)($order_data['order_code'] ?? '')) !== '' ? (string)$order_data['order_code'] : ('#' . (int)$order_id);
        crmAuditLog('order.status_change', [
            'entity_type' => 'order', 'entity_id' => (int)$order_id, 'entity_label' => $__oc,
            'summary' => 'Zakázka ' . $__oc . ' — stav: ' . $current_status . ' → ' . $new_status,
        ]);
    }

    if ($status_changed || $technician_changed) {
        crmNotifyOrderLifecycleEvent([
            'type' => 'order_status_changed',
            'order_id' => (int)$order_id,
            'old_status' => (string)$current_status,
            'new_status' => (string)$new_status,
            'technician_id' => (int)$updated_tech_id,
            'previous_technician_id' => (int)$current_tech_id,
            'final_cost' => $final_cost,
            'actor_role' => (string)($_SESSION['role'] ?? ''),
            'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
            'actor_name' => (string)($_SESSION['full_name'] ?? ''),
        ]);
    }

    echo json_encode(['success' => true, 'message' => $t('status_updated')]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
