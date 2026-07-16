<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();

// add_order.php returns a redirect (not JSON), so handle errors differently
if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    header("Location: ../login.php");
    exit;
}

// CSRF validation
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(__('csrf_token_invalid'));
}

// ── Input validation ──────────────────────────────────────────────────────────
$customer_id      = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$technician_id    = filter_input(INPUT_POST, 'technician_id', FILTER_VALIDATE_INT) ?: null;
$branch_id        = isBranchGlobalViewer() ? (filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: getDefaultBranchId()) : getCurrentStaffBranchId();
$device_type      = trim($_POST['device_type'] ?? 'Other');
$order_type       = trim($_POST['order_type'] ?? 'Non-Warranty');
$device_brand     = trim($_POST['device_brand'] ?? '');
$device_model     = trim($_POST['device_model'] ?? '');
$problem_description = trim($_POST['problem_description'] ?? '');
$technician_notes = trim($_POST['technician_notes'] ?? '');
$serial_number    = trim($_POST['serial_number'] ?? '');
$serial_number_2  = trim($_POST['serial_number_2'] ?? '');
$pin_code         = trim($_POST['pin_code'] ?? '');
$appearance       = trim($_POST['appearance'] ?? '');
$priority         = normalizeOrderPriority($_POST['priority'] ?? '');
$estimated_cost   = max(0, filter_input(INPUT_POST, 'estimated_cost', FILTER_VALIDATE_FLOAT) ?: 0);
// Příplatek (Urgentní) / sleva (Klidná) k prioritě — zadává se kladné číslo
$priority_adjust  = abs((float)str_replace(',', '.', (string)($_POST['priority_adjust'] ?? 0)));
if ($priority === 'Low') { $priority_adjust = -$priority_adjust; }
elseif ($priority !== 'High') { $priority_adjust = 0.0; }
$base_cost = $estimated_cost;
if ($priority_adjust != 0.0) {
    $estimated_cost = max(0, $estimated_cost + $priority_adjust);   // celková cena vč. příplatku/slevy
}
$shipping_method  = trim($_POST['shipping_method'] ?? '') ?: null;
$status           = getDefaultOrderStatus();

if (!$customer_id || !$device_model || $pin_code === '') {
    die(__('missing_fields'));
}

if (!canAssignTechnicianToOrder($technician_id, $branch_id)) {
    die('Vybraný technik neexistuje nebo není aktivní.');
}

// Pobočka zakázky = pobočka zvolená při založení (u technika jeho pobočka),
// NEMĚNÍ se podle přiřazeného technika — jinak by technik přiřazením kolegy
// z jiné pobočky vlastní novou zakázku okamžitě „schoval" sám sobě
// (orderBranchScopeSql by mu ji vyřadil ze seznamu).

try {
    ensureOrderPriorityLowValue();
    ensureOrderCreatedByColumn();   // DDL — před transakcí
    ensureOrderPriceLinesTable();   // DDL — před transakcí (rozpis ceny)
    ensureWebBookingsSchema();      // DDL — před transakcí (vazba na web rezervaci)
    $pdo->beginTransaction();

    $created_by_name = trim((string)($_SESSION['full_name'] ?? '')) ?: trim((string)($_SESSION['username'] ?? ''));
    $stmt = $pdo->prepare(
        "INSERT INTO orders (customer_id, technician_id, branch_id, device_type, order_type, device_brand, device_model,
         problem_description, technician_notes, serial_number, serial_number_2, pin_code, appearance, priority, estimated_cost, shipping_method, status, order_code, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $new_order_code = function_exists('generateNextOrderCode') ? generateNextOrderCode($pdo) : null;
    $stmt->execute([
        $customer_id, $technician_id, $branch_id, $device_type, $order_type, $device_brand, $device_model,
        $problem_description, $technician_notes, $serial_number, $serial_number_2,
        $pin_code, $appearance, $priority, $estimated_cost, $shipping_method, $status, $new_order_code,
        $created_by_name !== '' ? $created_by_name : null
    ]);
    $order_id = (int)$pdo->lastInsertId();

    // Rozpis ceny na zakázkový list: položky z ceníku / základ opravy + příplatek/sleva
    try {
        $plItems = json_decode((string)($_POST['pricelist_items'] ?? ''), true);
        $plItems = is_array($plItems) ? array_values(array_filter($plItems, fn($i) => is_array($i) && trim((string)($i['label'] ?? '')) !== '')) : [];
        $sortN = 0;
        if (count($plItems) > 0) {
            $linesSum = 0.0;
            foreach ($plItems as $pi) {
                $piPrice = (isset($pi['price']) && is_numeric($pi['price'])) ? (float)$pi['price'] : null;
                if ($piPrice === null) { continue; }   // „na dotaz" — jen v popisu závady
                crmAddOrderPriceLine($order_id, (string)$pi['label'], $piPrice, $sortN++);
                $linesSum += $piPrice;
            }
            // ruční úprava ceny (personál přepsal částku) → vyrovnávací řádek, ať rozpis sedí
            $diff = $base_cost - $linesSum;
            if ($sortN > 0 && abs($diff) > 0.01) {
                crmAddOrderPriceLine($order_id, 'Úprava ceny', $diff, $sortN++);
            }
            if ($priority_adjust != 0.0) {
                crmAddOrderPriceLine($order_id, $priority === 'High' ? 'Expresní příplatek (přednostní oprava)' : 'Sleva — oprava beze spěchu', $priority_adjust, $sortN++);
            }
        } elseif ($priority_adjust != 0.0 && $base_cost > 0) {
            crmAddOrderPriceLine($order_id, 'Oprava' . ($problem_description !== '' ? ': ' . (function_exists('mb_strimwidth') ? mb_strimwidth($problem_description, 0, 80, '…') : substr($problem_description, 0, 80)) : ''), $base_cost, $sortN++);
            crmAddOrderPriceLine($order_id, $priority === 'High' ? 'Expresní příplatek (přednostní oprava)' : 'Sleva — oprava beze spěchu', $priority_adjust, $sortN++);
        }
    } catch (Throwable $e) { /* rozpis je bonus */ }
    assignmentSegmentSync($order_id, $technician_id ? (int)$technician_id : null, (string)$status);

    logOrderStatusChange($order_id, '', $status);


    // Zakázka vznikla z webové rezervace → označit rezervaci jako převzatou
    $webBookingId = (int)($_POST['web_booking_id'] ?? 0);
    if ($webBookingId > 0) {
        try {
            ensureWebBookingsSchema();
            $pdo->prepare("UPDATE web_bookings SET status = 'converted', order_id = ? WHERE id = ?")
                ->execute([$order_id, $webBookingId]);
        } catch (Throwable $e) { /* rezervace zůstane v seznamu, nevadí */ }
    }

    // ── Secure file upload ────────────────────────────────────────────────────
    if (!empty($_FILES['files']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        // Protect uploads from PHP execution
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
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
        ];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'mp4', 'mov', 'avi'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        foreach ($_FILES['files']['tmp_name'] as $key => $tmp) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;

            $real_type = finfo_file($finfo, $tmp);
            if (!isset($allowed_mime_to_ext[$real_type])) continue;
            if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) continue;

            $ext = strtolower(pathinfo($_FILES['files']['name'][$key], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) {
                $ext = $allowed_mime_to_ext[$real_type];
            }

            $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)")
                    ->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($_FILES['files']['name'][$key])]);
            }
        }
        finfo_close($finfo);
    }

    $pdo->commit();

    // Od commitu je zakázka VYTVOŘENÁ — audit a notifikace jsou best-effort a
    // NESMÍ shodit odpověď na „Order creation failed" (uživatel by ji zkusil
    // založit znovu → duplicitní zakázky). Cokoliv tu spadne, jen se zaloguje.
    try {
        // Audit až PO commitu (mimo transakci — ensureAuditLogTable dělá DDL)
        $__dev = trim(($device_brand ?? '') . ' ' . ($device_model ?? ''));
        crmAuditLog('order.create', [
            'entity_type' => 'order', 'entity_id' => $order_id,
            'entity_label' => ($new_order_code ?: ('#' . $order_id)),
            'summary' => 'Vytvořena zakázka ' . ($new_order_code ?: ('#' . $order_id)) . ($__dev !== '' ? ' — ' . $__dev : ''),
            'branch_id' => $branch_id ?? null,
        ]);

        crmNotifyOrderLifecycleEvent([
            'type' => 'order_created',
            'order_id' => (int)$order_id,
            'technician_id' => (int)($technician_id ?? 0),
            'new_status' => $status,
            'actor_role' => (string)($_SESSION['role'] ?? ''),
            'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
            'actor_name' => (string)($_SESSION['full_name'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('add_order post-commit (audit/notify) selhal, zakázka #' . (int)$order_id . ' vznikla: ' . $e->getMessage());
    }

    header("Location: ../orders.php?created_order=" . (int)$order_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("add_order error: " . $e->getMessage());
    die('Order creation failed. Please try again.');
}
?>
