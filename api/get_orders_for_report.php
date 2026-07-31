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

// Platby k fakturám (částečné úhrady = zálohy) — pojistka, kdyby kód doběhl na server
// dřív než run_migrations.php; bez sloupce paid_amount by dotaz níž spadl.
if (function_exists('afxEnsureInvoicePayments')) { afxEnsureInvoicePayments(); }

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$start = $start_date . ' 00:00:00';
$end = $end_date . ' 23:59:59';

$where = "WHERE 1=1";
$params = [];
if (!isBranchGlobalViewer()) {
    $where .= " AND o.branch_id = ?";
    $params[] = getCurrentStaffBranchId();
}
$joins = "JOIN customers c ON o.customer_id = c.id";
$selectExtra = "";
$orderBy = "o.id DESC";

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
        // Agregace na JEDEN řádek na zakázku (stejná logika jako v reports.php):
        //  - dřívější `AND inv.status = 'paid'` úplně zahodil částečně zaplacené faktury,
        //    takže u zakázky nebylo vidět, že na ni už přišla záloha,
        //  - a při dvou uhrazených fakturách JOIN zakázku zdvojil.
        // total_amount zůstává jen z CELÝCH úhrad → tržba se nemění.
        // Sloupec paid_amount přidává migrace 037 / afxEnsureInvoicePayments(); kdyby přesto
        // chyběl, nesmí kvůli němu selhat celý výpis zakázek — dosadí se nula.
        $hasPaidCol = false;
        try { $hasPaidCol = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'paid_amount'")->fetch(); }
        catch (Throwable $e) { $hasPaidCol = false; }
        $paidExpr = $hasPaidCol ? 'SUM(COALESCE(iv.paid_amount, 0))' : '0';
        $joins .= " LEFT JOIN (
                        SELECT iv.order_id,
                               MAX(CASE WHEN iv.status = 'paid' THEN iv.payment_date END) AS payment_date,
                               SUM(CASE WHEN iv.status = 'paid' THEN iv.total_amount END) AS total_amount,
                               SUM(iv.total_amount) AS invoiced_amount,
                               $paidExpr AS paid_amount
                        FROM invoices iv
                        WHERE iv.order_id IS NOT NULL
                          AND iv.status <> 'cancelled'
                          AND COALESCE(iv.invoice_type, 'invoice') <> 'credit_note'
                        GROUP BY iv.order_id
                    ) inv ON inv.order_id = o.id";
        $selectExtra = ", COALESCE(DATE(o.work_finished_at), inv.payment_date, DATE(o.shipping_date), DATE(o.updated_at)) AS finance_date"
            . ", COALESCE(o.work_duration_seconds, 0) AS work_duration_seconds"
            . ", COALESCE(inv.paid_amount, 0) AS paid_amount"
            . ", COALESCE(inv.invoiced_amount, 0) AS invoiced_amount";
        $where .= " AND o.status IN (" . sqlPlaceholders($statuses) . ") AND COALESCE(DATE(o.work_finished_at), inv.payment_date, DATE(o.shipping_date), DATE(o.updated_at)) BETWEEN ? AND ?";
        $params = array_merge($params, $statuses, [$start_date, $end_date]);
        $orderBy = "finance_date DESC, o.id DESC";
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
    $sql = "SELECT o.id, o.device_brand, o.device_model, o.status, o.final_cost, o.estimated_cost, o.created_at, c.first_name, c.last_name $selectExtra
            FROM orders o 
            $joins 
            $where 
            ORDER BY $orderBy";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
