<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

$tech_id = $_GET['tech_id'] ?? null;
$type = $_GET['type'] ?? '';

// Security: If user has no elevated reporting permission, force own technician scope
if (!hasPermission('admin_access') && !hasPermission('view_reports_all') && ($_SESSION['role'] ?? '') == 'technician') {
    $tech_id = $_SESSION['tech_id'];
}

$start = ($_GET['start_date'] ?? date('Y-m-01')) . ' 00:00:00';
$end = ($_GET['end_date'] ?? date('Y-m-t')) . ' 23:59:59';

$where = "WHERE 1=1";
$params = [];

if ($tech_id) {
    $where .= " AND o.technician_id = ?";
    $params[] = $tech_id;
}

switch ($type) {
    case 'received':
        $where .= " AND o.created_at BETWEEN ? AND ?";
        $params[] = $start;
        $params[] = $end;
        break;
    case 'in_progress':
        $statuses = getOrderStatusList('in_progress');
        $where .= " AND o.status IN (" . sqlPlaceholders($statuses) . ") AND o.updated_at BETWEEN ? AND ?";
        $params = array_merge($params, $statuses, [$start, $end]);
        break;
    case 'completed':
        $statuses = getOrderStatusList('done');
        $where .= " AND o.status IN (" . sqlPlaceholders($statuses) . ") AND o.updated_at BETWEEN ? AND ?";
        $params = array_merge($params, $statuses, [$start, $end]);
        break;
    case 'cancelled':
        $statuses = getOrderStatusList('cancelled');
        $where .= " AND o.status IN (" . sqlPlaceholders($statuses) . ") AND o.updated_at BETWEEN ? AND ?";
        $params = array_merge($params, $statuses, [$start, $end]);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit;
}

try {
    $sql = "SELECT o.id, o.device_brand, o.device_model, o.status, o.final_cost, o.estimated_cost, o.created_at, c.first_name, c.last_name 
            FROM orders o 
            JOIN customers c ON o.customer_id = c.id 
            $where 
            ORDER BY o.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
