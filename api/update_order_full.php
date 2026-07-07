<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

$ui_lang = $_REQUEST['ui_lang'] ?? null;
$t = static function(string $key) use ($ui_lang): string {
    return __($key, is_string($ui_lang) ? $ui_lang : null);
};

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => $t('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('csrf_token_invalid')]);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => $t('missing_id')]);
    exit;
}

try {
    // DDL checks can trigger implicit commits on MySQL/MariaDB,
    // so run schema/bootstrap guard before starting explicit transaction.
    ensureOrderWorkTrackingSchema();
    ensureOrderWorkLogSchema(); // DDL — must run before beginTransaction()

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new Exception($t('order_not_found'));
    }

    if (!canAccessOrderBranch($current)) {
        throw new Exception($t('access_denied_msg'));
    }

    $new_status = normalizeOrderStatus($_POST['status'] ?? $current['status']);
    $technician_id = ($_POST['technician_id'] ?? '') !== '' ? (int)$_POST['technician_id'] : (int)$current['technician_id'];
    $branch_id = (int)($current['branch_id'] ?? getCurrentStaffBranchId());
    if (!canAssignTechnicianToOrder($technician_id, $branch_id)) {
        throw new Exception('Vybraný technik nepatří do pobočky zakázky.');
    }
    if ($technician_id) {
        $stmtTechBranch = $pdo->prepare('SELECT branch_id FROM technicians WHERE id = ? LIMIT 1');
        $stmtTechBranch->execute([$technician_id]);
        $techBranchId = (int)$stmtTechBranch->fetchColumn();
        if ($techBranchId > 0) {
            $branch_id = $techBranchId;
        }
    }
    $is_starting = (isOrderStatusIn($new_status, 'in_progress') && !isOrderStatusIn($current['status'], 'in_progress'));
    $was_finished = isOrderStatusIn($current['status'], 'done');
    $is_finishing = isOrderStatusIn($new_status, 'done');
    $technician_changed = (int)$technician_id !== (int)($current['technician_id'] ?? 0);
    $is_reassigning_in_progress = (isOrderStatusIn($current['status'], 'in_progress') && isOrderStatusIn($new_status, 'in_progress') && $technician_changed);

    if (isOrderStatusIn($new_status, 'in_progress')) {
        if (!$technician_id) {
            throw new Exception($t('in_progress_requires_technician'));
        }

        if ($is_starting || $technician_changed) {
            $active_count = getTechnicianInProgressCount($technician_id, (int)$order_id);
            if ($active_count >= 2 && !$was_finished) {
                throw new Exception($t('technician_in_progress_limit_reached'));
            }
        }
    }

    $sql = "UPDATE orders SET
        customer_id = ?,
        device_model = ?,
        device_brand = ?,
        device_type = ?,
        order_type = ?,
        status = ?,
        technician_id = ?,
        branch_id = ?,
        estimated_cost = ?,
        final_cost = ?,
        extra_expenses = ?,
        problem_description = ?,
        technician_notes = ?,
        pin_code = ?,
        appearance = ?,
        priority = ?,
        serial_number = ?,
        serial_number_2 = ?";

    $params = [
        !empty($_POST['customer_id']) ? $_POST['customer_id'] : $current['customer_id'],
        isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'],
        isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand'],
        isset($_POST['device_type']) ? $_POST['device_type'] : $current['device_type'],
        isset($_POST['order_type']) ? $_POST['order_type'] : $current['order_type'],
        $new_status,
        $technician_id,
        $branch_id,
        isset($_POST['estimated_cost']) ? $_POST['estimated_cost'] : $current['estimated_cost'],
        isset($_POST['final_cost']) ? $_POST['final_cost'] : $current['final_cost'],
        isset($_POST['extra_expenses']) ? $_POST['extra_expenses'] : $current['extra_expenses'],
        isset($_POST['problem_description']) ? $_POST['problem_description'] : $current['problem_description'],
        isset($_POST['technician_notes']) ? $_POST['technician_notes'] : $current['technician_notes'],
        isset($_POST['pin_code']) ? $_POST['pin_code'] : $current['pin_code'],
        isset($_POST['appearance']) ? $_POST['appearance'] : $current['appearance'],
        isset($_POST['priority']) ? $_POST['priority'] : $current['priority'],
        isset($_POST['serial_number']) ? $_POST['serial_number'] : $current['serial_number'],
        isset($_POST['serial_number_2']) ? $_POST['serial_number_2'] : $current['serial_number_2']
    ];

    if ($is_starting) {
        $sql .= ", work_started_at = CASE WHEN work_started_at IS NULL OR work_finished_at IS NOT NULL THEN CURRENT_TIMESTAMP ELSE work_started_at END, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL";
        $params[] = $technician_id;
    }

    if ($is_reassigning_in_progress) {
        $sql .= ", work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, CURRENT_TIMESTAMP)) ELSE 0 END, work_started_at = CURRENT_TIMESTAMP, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL";
        $params[] = $technician_id;
    }

    if (isOrderStatusIn($current['status'], 'in_progress') && $is_finishing) {
        $sql .= ", work_finished_at = IFNULL(work_finished_at, CURRENT_TIMESTAMP), work_finished_by = IFNULL(work_finished_by, ?), work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, IFNULL(work_finished_at, CURRENT_TIMESTAMP))) ELSE 0 END";
        $params[] = $technician_id;
    }

    $sql .= " WHERE id = ?";
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Per-technician work segments (mirror the orders.work_* transitions above).
    if ($is_starting || $is_reassigning_in_progress) {
        workSegmentOpen((int)$order_id, (int)$technician_id);
    }
    if (isOrderStatusIn($current['status'], 'in_progress') && $is_finishing) {
        workSegmentClose((int)$order_id);
    }
    assignmentSegmentSync((int)$order_id, (int)$technician_id ?: null, (string)$new_status);

    if (isset($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $htaccess = $upload_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "# Deny PHP execution in uploads\n" .
                "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
                "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
                "RemoveType .php .phtml .php3 .php4 .php5\n"
            );
        }

        $allowed_mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
        ];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
        if (!is_writable($upload_dir)) {
            throw new Exception($t('upload_dir_not_writable'));
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $uploaded_count = 0;
        $rejected = [];

        foreach ($_FILES['files']['tmp_name'] as $key => $tmp) {
            $err = (int)($_FILES['files']['error'][$key] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $rejected[] = basename((string)($_FILES['files']['name'][$key] ?? 'file')) . " (upload error " . $err . ")";
                continue;
            }

            $name = (string)($_FILES['files']['name'][$key] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $rejected[] = basename($name) . " (temporary upload missing)";
                continue;
            }

            $real_type = strtolower((string)finfo_file($finfo, $tmp));
            if (!isset($allowed_mime_to_ext[$real_type])) {
                $rejected[] = basename($name) . " (unsupported type: " . ($real_type ?: 'unknown') . ")";
                continue;
            }
            if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) {
                $rejected[] = basename($name) . " (image validation failed)";
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) {
                $ext = $allowed_mime_to_ext[$real_type];
            }

            $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $stmt_file = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
                $stmt_file->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($name)]);
                $uploaded_count++;
            } else {
                $rejected[] = basename($name) . " (cannot move uploaded file)";
            }
        }

        finfo_close($finfo);

        if ($uploaded_count === 0) {
            $detail = !empty($rejected) ? (' ' . implode('; ', array_slice($rejected, 0, 3))) : '';
            throw new Exception($t('upload_no_valid_file') . $detail);
        }
    }


    $new_status = normalizeOrderStatus($_POST['status'] ?? $current['status']);
    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    $was_finished = isOrderStatusIn($current['status'], 'done');
    $is_finishing = isOrderStatusIn($new_status, 'done');

    if (isOrderStatusIn($current['status'], 'collected') && !isOrderStatusIn($new_status, 'collected')) {
        throw new Exception($t('status_locked_after_collected'));
    }

    $status_changed = ((string)$current['status'] !== (string)$new_status);
    $technician_changed = ((int)($current['technician_id'] ?? 0) !== (int)$technician_id);

    if ($status_changed) {
        if (!$was_finished && $is_finishing) {
            processOrderInventoryChange($order_id, $is_finishing, $was_finished);

            if (isOrderStatusIn($new_status, 'completed') && get_setting('acc_auto_create_invoice', '0') == '1') {
                require_once '../models/InvoiceManager.php';
                $manager = new InvoiceManager($pdo);
                $check = $pdo->prepare('SELECT id FROM invoices WHERE order_id = ?');
                $check->execute([$order_id]);
                if (!$check->fetch()) {
                    $stmt_ord = $pdo->prepare('SELECT o.*, c.first_name, c.last_name, c.company FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?');
                    $stmt_ord->execute([$order_id]);
                    $orderData = $stmt_ord->fetch();

                    if ($orderData) {
                        $prefix = get_setting('acc_invoice_prefix', date('Y'));
                        $count = $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
                        $inv_number = $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

                        $final_price = (float)($_POST['final_cost'] ?? ($orderData['final_cost'] ?: $orderData['estimated_cost']));

                        $invoiceData = [
                            'invoice_number' => $inv_number,
                            'customer_id' => $orderData['customer_id'],
                            'order_id' => $order_id,
                            'date_issue' => date('Y-m-d'),
                            'date_tax' => date('Y-m-d'),
                            'date_due' => date('Y-m-d', strtotime('+14 days')),
                            'status' => 'issued',
                            'payment_method' => 'bank_transfer',
                            'currency' => get_setting('currency', 'Kč'),
                            'is_vat_payer' => get_setting('acc_is_vat_payer', '0'),
                            'items' => [
                                [
                                    'name' => 'Oprava ' . $orderData['device_brand'] . ' ' . $orderData['device_model'],
                                    'quantity' => 1,
                                    'unit' => 'ks',
                                    'price' => $final_price,
                                    'vat_rate' => get_setting('acc_vat_rate', '21')
                                ]
                            ]
                        ];
                        $manager->saveInvoice($invoiceData);
                    }
                }
            }
        } elseif ($was_finished && !$is_finishing) {
            processOrderInventoryChange($order_id, $is_finishing, $was_finished);
        }
        logOrderStatusChange($order_id, $current['status'], $new_status);
    }

    $pdo->commit();

    if ($status_changed || $technician_changed) {
        crmNotifyOrderLifecycleEvent([
            'type' => 'order_status_changed',
            'order_id' => (int)$order_id,
            'old_status' => (string)$current['status'],
            'new_status' => (string)$new_status,
            'technician_id' => (int)$technician_id,
            'previous_technician_id' => (int)($current['technician_id'] ?? 0),
            'final_cost' => $final_cost,
            'actor_role' => (string)($_SESSION['role'] ?? ''),
            'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
            'actor_name' => (string)($_SESSION['full_name'] ?? ''),
        ]);
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
