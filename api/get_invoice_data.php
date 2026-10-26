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

// Fakturační data (jméno/IČO/DIČ, ceny, číslo faktury): administrátor a Boss —
// shodně s UI (tlačítko účtování).
if (!crmCanUseInvoices()) {
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing order ID']);
    exit;
}

try {
    $order_id = $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company, c.ico, c.dic 
                           FROM orders o 
                           JOIN customers c ON o.customer_id = c.id 
                           WHERE o.id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception('Order not found');
    }
    if (!canAccessOrderBranch($order)) {
        throw new Exception(__('access_denied_msg'));
    }

    // zakázka s fakturou → druhou nevystavovat (modal se zavře s hláškou)
    $exq = $pdo->prepare("SELECT invoice_number FROM invoices WHERE order_id = ?
        AND COALESCE(invoice_type, 'invoice') = 'invoice' AND status <> 'cancelled' LIMIT 1");
    $exq->execute([(int)$order_id]);
    $exNo = (string)$exq->fetchColumn();
    if ($exNo !== '') {
        throw new Exception('K zakázce už existuje faktura ' . $exNo . ' — najdeš ji v Účetnictví.');
    }

    // další volné číslo z řady s.r.o. (dřív legacy acc_invoice_next_number, které nikdo
    // neposouval — přehled zakázek pak dosazoval číslo zakázky a rozbíjel řadu)
    $invoice_number = afxNextInvoiceNumber($pdo, afxInvoiceSeriesPrefix('acc_invoice_prefix', date('Y')));

    echo json_encode([
        'success' => true,
        'order' => $order,
        'next_invoice_number' => $invoice_number,
        'variable_symbol' => $invoice_number, // Default VS to invoice number
        'date_issue' => date('Y-m-d'),
        'date_tax' => date('Y-m-d'),
        'date_due' => date('Y-m-d', strtotime('+14 days')),
        'total_amount' => $order['final_cost'] ?: $order['estimated_cost']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
