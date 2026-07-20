<?php
/* Klientský prohlížeč dokumentů. Vydá dokument POUZE pokud patří přihlášenému
   zákazníkovi. Typy: order_sheet (zakázkový list), invoice (faktura),
   receipt (účtenka), complaint (reklamační protokol). Vykresluje se přes
   embed režim příslušného tiskového souboru (bez staff přihlášení). */
require_once 'includes/config.php';
require_once 'includes/auth.php';

clientRequireAuth();

$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
$orderId = (int)($_GET['order'] ?? 0);
$target_lang = crm_get_language();

if ($customerId <= 0) { http_response_code(403); die(e(__('access_denied'))); }

function clientDocDeny(): void { http_response_code(404); die(e(__('print_not_found'))); }

/* ------- reklamační protokol (nemá order, ale complaint id) ------- */
if ($type === 'complaint') {
    $complaintId = (int)($_GET['complaint'] ?? 0);
    if ($complaintId <= 0) clientDocDeny();
    if (function_exists('ensureComplaintsClientColumns')) { ensureComplaintsClientColumns($pdo); }
    $stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cust_phone, cu.email, cu.address
                           FROM complaints c
                           LEFT JOIN customers cu ON cu.id = c.customer_id
                           WHERE c.id = ? AND c.customer_id = ? LIMIT 1");
    $stmt->execute([$complaintId, $customerId]);
    $complaint = $stmt->fetch();
    if (!$complaint) clientDocDeny();

    $complaintPhotos = [];
    try {
        $ps = $pdo->prepare("SELECT file_path, file_name FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC");
        $ps->execute([$complaintId]);
        $complaintPhotos = $ps->fetchAll();
    } catch (Throwable $e) { $complaintPhotos = []; }

    define('COMPLAINT_DOC_EMBED', true);
    include __DIR__ . '/../print_complaint.php';
    exit;
}

/* ------- vše ostatní je vázané na zakázku vlastníka ------- */
if ($orderId <= 0) clientDocDeny();

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email
                       FROM orders o
                       JOIN customers c ON o.customer_id = c.id
                       WHERE o.id = ? AND o.customer_id = ? LIMIT 1");
$stmt->execute([$orderId, $customerId]);
$order = $stmt->fetch();
if (!$order) clientDocDeny();

switch ($type) {
    case 'order_sheet':
        $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        define('ORDER_DOC_EMBED', true);
        include __DIR__ . '/../print_order.php';
        exit;

    case 'invoice':
        $stmt = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic,
                                      o.device_brand, o.device_model, o.serial_number
                               FROM invoices i
                               JOIN customers c ON i.customer_id = c.id
                               LEFT JOIN orders o ON i.order_id = o.id
                               WHERE i.order_id = ? AND i.customer_id = ? AND i.status IN ('issued','paid') AND i.invoice_type = 'invoice'
                               ORDER BY i.created_at DESC LIMIT 1");
        $stmt->execute([$orderId, $customerId]);
        $invoice = $stmt->fetch();
        if (!$invoice) clientDocDeny();
        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([(int)$invoice['id']]);
        $items = $stmt->fetchAll();
        $is_vat_payer = $invoice['is_vat_payer'];
        define('INVOICE_DOC_EMBED', true);
        include __DIR__ . '/../print_invoice.php';
        exit;

    default:
        clientDocDeny();
}
