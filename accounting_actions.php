<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Access Check
if (!hasPermission('admin_access')) {
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Handle CSRF dynamically for ALL POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(json_encode(['success' => false, 'error' => 'Security token invalid.']));
}

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$valid_actions = ['save_invoice', 'get_invoice', 'delete_invoice', 'update_status', 'create_credit_note', 'export_pohoda', 'export_s3money', 'get_order_data'];
if (!in_array($action, $valid_actions)) {
    die(json_encode(['success' => false, 'error' => 'Invalid action']));
}

switch ($action) {
    case 'save_invoice':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);

            // UZÁVĚRKA: doklad v uzamčeném měsíci se nesmí měnit — čísla v CRM by se
            // rozešla s tím, co je odevzdané na úřadě. Kontroluje se datum vystavení
            // NOVÉ podoby dokladu i PŮVODNÍ datum u editace (přesun dokladu ven ze
            // zamčeného měsíce je taky změna zamčeného období).
            if (function_exists('afxAccountingAssertOpen')) {
                afxAccountingAssertOpen((string)($_POST['date_issue'] ?? date('Y-m-d')), 'fakturu');
                if (!empty($_POST['id'])) {
                    $pv = $pdo->prepare("SELECT date_issue FROM invoices WHERE id = ?");
                    $pv->execute([(int)$_POST['id']]);
                    $pvDate = (string)$pv->fetchColumn();
                    if ($pvDate !== '') { afxAccountingAssertOpen($pvDate, 'fakturu'); }
                }
            }
            
            // Allow JS to send order_id via the from_order_id field
            if (empty($_POST['order_id']) && !empty($_POST['from_order_id'])) {
                $_POST['order_id'] = $_POST['from_order_id'];
            }

            $result = $manager->saveInvoice($_POST);
            if (!empty($result['success'])) {
                $__isEdit = !empty($_POST['id']);
                $__invNo = trim((string)($_POST['invoice_number'] ?? '')) ?: ('#' . (int)($result['id'] ?? 0));
                crmAuditLog($__isEdit ? 'invoice.update' : 'invoice.create', [
                    'entity_type' => 'invoice', 'entity_id' => (int)($result['id'] ?? 0), 'entity_label' => $__invNo,
                    'summary' => ($__isEdit ? 'Upravena faktura ' : 'Vystavena faktura ') . $__invNo
                        . (!empty($_POST['total_amount']) ? ' (' . $_POST['total_amount'] . ' Kč)' : ''),
                ]);
            }
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get_invoice':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $invoice = $manager->getInvoice((int)$_GET['id']);
            
            if ($invoice) {
                echo json_encode(['success' => true, 'data' => $invoice]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invoice not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'delete_invoice':
        try {
            $id = (int)$_POST['id'];
            // účetní doklady nikdy nemaže (crmCanAccountingDelete) a zamčený měsíc
            // nesmaže nikdo — smazání je nejtvrdší možná změna období
            if (function_exists('crmCanAccountingDelete') && !crmCanAccountingDelete()) {
                echo json_encode(['success' => false, 'error' => 'Mazání dokladů je jen pro vedení — účetní doklad stornuje, nemaže.']); break;
            }
            if (function_exists('afxAccountingAssertOpen')) {
                $dv = $pdo->prepare("SELECT date_issue FROM invoices WHERE id = ?");
                $dv->execute([$id]);
                $dvDate = (string)$dv->fetchColumn();
                if ($dvDate !== '') { afxAccountingAssertOpen($dvDate, 'fakturu'); }
            }
            // číslo faktury pro historii zjistit PŘED smazáním
            $__invNo = '';
            try { $ns = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?"); $ns->execute([$id]); $__invNo = (string)$ns->fetchColumn(); } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
            crmAuditLog('invoice.delete', [
                'entity_type' => 'invoice', 'entity_id' => $id, 'entity_label' => $__invNo,
                'summary' => 'Smazána faktura ' . ($__invNo !== '' ? $__invNo : ('#' . $id)),
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update_status':
        // změna stavu (zaplaceno/…) zapisuje payment_date k dnešku a sahá na doklad —
        // u dokladu z uzamčeného měsíce ji pustit nesmíme
        if (function_exists('afxAccountingAssertOpen') && !empty($_POST['id'])) {
            try {
                $sv = $pdo->prepare("SELECT date_issue FROM invoices WHERE id = ?");
                $sv->execute([(int)$_POST['id']]);
                $svDate = (string)$sv->fetchColumn();
                if ($svDate !== '') { afxAccountingAssertOpen($svDate, 'fakturu'); }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]); break;
            }
        }
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $success = $manager->updateStatus((int)$_POST['id'], $_POST['status'], $_POST['payment_method'] ?? null);
            if ($success) {
                crmAuditLog('invoice.status_change', [
                    'entity_type' => 'invoice', 'entity_id' => (int)$_POST['id'],
                    'summary' => 'Faktura #' . (int)$_POST['id'] . ' — stav: ' . (string)$_POST['status']
                        . (!empty($_POST['payment_method']) ? ' (' . $_POST['payment_method'] . ')' : ''),
                ]);
            }
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'create_credit_note':
        require_once 'models/InvoiceManager.php';
        $manager = new InvoiceManager($pdo);
        $result = $manager->createCreditNote((int)$_POST['id']);
        if (!empty($result['success'])) {
            crmAuditLog('invoice.credit_note', [
                'entity_type' => 'invoice', 'entity_id' => (int)($result['id'] ?? 0),
                'summary' => 'Vystaven dobropis k faktuře #' . (int)$_POST['id'],
            ]);
        }
        echo json_encode($result);
        break;

    case 'export_pohoda':
        $id = (int)$_GET['id'];
        // Implementation for Pohoda XML
        require_once 'export_utils.php';
        $exporter = new AccountingExporter($pdo);
        $file = $exporter->exportToPohoda($id);
        echo json_encode(['success' => true, 'file' => $file]);
        break;

    case 'export_s3money':
        $id = (int)$_GET['id'];
        // Implementation for S3 Money CSV
        require_once 'export_utils.php';
        $exporter = new AccountingExporter($pdo);
        $file = $exporter->exportToS3Money($id);
        echo json_encode(['success' => true, 'file' => $file]);
        break;

    case 'get_order_data':
        $order_id = (int)$_GET['order_id'];
        $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company, c.address, c.phone, c.email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            $is_vat_payer = (get_setting('acc_is_vat_payer', '0') == '1');
            $data = [
                'customer_id' => $order['customer_id'],
                'customer_display' => $order['company'] ?: ($order['first_name'] . ' ' . $order['last_name']),
                'total_amount' => $order['final_cost'] ?: $order['estimated_cost'],
                'is_vat_payer' => $is_vat_payer,
                'items' => [
                    ['name' => 'Oprava ' . $order['device_brand'] . ' ' . $order['device_model'], 'quantity' => 1, 'unit' => 'ks', 'price' => $order['final_cost'] ?: $order['estimated_cost'], 'vat_rate' => $is_vat_payer ? get_setting('acc_vat_rate', '21') : 0]
                ]
            ];
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
        }
        break;
}
