<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Access Check: vedení (admin_access), POBOČKOVÝ MANAŽER a role účetní
// (crmCanUseInvoices) — bez toho by na accounting.php viděli faktury, ale každé
// tlačítko by vrátilo Access denied. Mazání uvnitř hlídá crmCanAccountingDelete
// (účetní ani manažer NE) a fakturační údaje firmy crmCanManageInvoices.
if (!hasPermission('admin_access') && !crmCanUseInvoices()) {
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Handle CSRF dynamically for ALL POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(json_encode(['success' => false, 'error' => 'Security token invalid.']));
}

header('Content-Type: application/json');

// Pobočkový manažer vidí a mění jen doklady SVÉ prodejny (admin, Boss a účetní
// obě — účetnictví se vede za firmu). Bez téhle stráže by si přes ID doklady
// druhé pobočky přečetl i změnil.
afxEnsureInvoiceBranch();
function afxAssertInvoiceBranch(int $invoiceId): void {
    global $pdo;
    if ($invoiceId <= 0) { return; }
    try {
        $st = $pdo->prepare("SELECT branch_id FROM invoices WHERE id = ?");
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { return; }                    // neexistuje → řeší volající
        if (!crmCanSeeInvoiceBranch($row['branch_id'])) {
            die(json_encode(['success' => false, 'error' => 'Doklad patří jiné provozovně.'], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) { /* chyba čtení nesmí zablokovat práci */ }
}

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
                // `?:` a ne `??` — prázdný řetězec z formuláře by jinak assert obešel
                // (InvoiceManager by pak stejně uložil dnešek). Hlídá se i DUZP:
                // právě date_tax určuje zdaňovací období DPH.
                afxAccountingAssertOpen(trim((string)($_POST['date_issue'] ?? '')) ?: date('Y-m-d'), 'fakturu');
                afxAccountingAssertOpen(trim((string)($_POST['date_tax'] ?? '')) ?: date('Y-m-d'), 'fakturu (DUZP)');
                if (!empty($_POST['id'])) {
                    $pv = $pdo->prepare("SELECT date_issue, date_tax FROM invoices WHERE id = ?");
                    $pv->execute([(int)$_POST['id']]);
                    $pvRow = $pv->fetch(PDO::FETCH_ASSOC) ?: [];
                    // doklad bez data (importy) konzervativně hlídat přes dnešek
                    afxAccountingAssertOpen((string)($pvRow['date_issue'] ?? '') ?: date('Y-m-d'), 'fakturu');
                    afxAccountingAssertOpen((string)($pvRow['date_tax'] ?? '') ?: date('Y-m-d'), 'fakturu (DUZP)');
                }
            }
            
            // Allow JS to send order_id via the from_order_id field
            if (empty($_POST['order_id']) && !empty($_POST['from_order_id'])) {
                $_POST['order_id'] = $_POST['from_order_id'];
            }
            if (!empty($_POST['id'])) { afxAssertInvoiceBranch((int)$_POST['id']); }

            $result = $manager->saveInvoice($_POST);
            // adresa pro rovnou odeslání e-mailem: ruční e-mail odběratele, jinak
            // e-mail z karty klienta (formulář ho nemá, ale server ano)
            if (!empty($result['success'])) {
                try {
                    $em = $pdo->prepare("SELECT COALESCE(NULLIF(i.cust_email_override, ''), c.email) AS mail
                                         FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                                         WHERE i.id = ?");
                    $em->execute([(int)($result['id'] ?? 0)]);
                    $result['email'] = (string)($em->fetchColumn() ?: '');
                } catch (Throwable $e) { $result['email'] = ''; }
            }
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
        afxAssertInvoiceBranch((int)($_GET['id'] ?? 0));
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
            afxAssertInvoiceBranch($id);
            if (function_exists('afxAccountingAssertOpen')) {
                $dv = $pdo->prepare("SELECT date_issue FROM invoices WHERE id = ?");
                $dv->execute([$id]);
                $dvDate = (string)$dv->fetchColumn();
                // faktura bez data (import) → konzervativně hlídat dnešek
                afxAccountingAssertOpen($dvDate !== '' ? $dvDate : date('Y-m-d'), 'fakturu');
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
        afxAssertInvoiceBranch((int)($_POST['id'] ?? 0));
        // změna stavu (zaplaceno/…) zapisuje payment_date k dnešku a sahá na doklad —
        // u dokladu z uzamčeného měsíce ji pustit nesmíme
        if (function_exists('afxAccountingAssertOpen') && !empty($_POST['id'])) {
            try {
                $sv = $pdo->prepare("SELECT date_issue FROM invoices WHERE id = ?");
                $sv->execute([(int)$_POST['id']]);
                $svDate = (string)$sv->fetchColumn();
                afxAccountingAssertOpen($svDate !== '' ? $svDate : date('Y-m-d'), 'fakturu');
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
        // Dobropis = vrácení peněz, stejná citlivost jako storno prodeje v kase
        // (to smí taky jen vedení). Účetní ano — opravný doklad je její práce.
        if (!(function_exists('crmCanAccountingEdit') && crmCanAccountingEdit())) {
            die(json_encode(['success' => false,
                'error' => 'Dobropis smí vystavit jen vedení nebo účetní.'], JSON_UNESCAPED_UNICODE));
        }
        afxAssertInvoiceBranch((int)($_POST['id'] ?? 0));
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
        afxAssertInvoiceBranch($id);
        // Implementation for Pohoda XML
        require_once 'export_utils.php';
        $exporter = new AccountingExporter($pdo);
        $file = $exporter->exportToPohoda($id);
        echo json_encode(['success' => true, 'file' => $file]);
        break;

    case 'export_s3money':
        $id = (int)$_GET['id'];
        afxAssertInvoiceBranch($id);
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
        // zakázka druhé prodejny se manažerovi otevřít nesmí (stejně jako v
        // api/get_invoice_data.php) — jinak by si přes ID pročetl cizí klienty
        if ($order && function_exists('canAccessOrderBranch') && !canAccessOrderBranch($order)) {
            echo json_encode(['success' => false, 'error' => 'Zakázka patří jiné provozovně.'], JSON_UNESCAPED_UNICODE);
            break;
        }

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
