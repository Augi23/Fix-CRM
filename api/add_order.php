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
$priority         = in_array($_POST['priority'] ?? '', ['High', 'Normal']) ? $_POST['priority'] : 'Normal';
$estimated_cost   = max(0, filter_input(INPUT_POST, 'estimated_cost', FILTER_VALIDATE_FLOAT) ?: 0);
$shipping_method  = trim($_POST['shipping_method'] ?? '') ?: null;

if (!$customer_id || !$device_model) {
    die(__('missing_fields'));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO orders (customer_id, technician_id, device_type, order_type, device_brand, device_model,
         problem_description, technician_notes, serial_number, serial_number_2, pin_code, appearance, priority, estimated_cost, shipping_method)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $customer_id, $technician_id, $device_type, $order_type, $device_brand, $device_model,
        $problem_description, $technician_notes, $serial_number, $serial_number_2,
        $pin_code, $appearance, $priority, $estimated_cost, $shipping_method
    ]);
    $order_id = (int)$pdo->lastInsertId();

    logOrderStatusChange($order_id, '', 'New');

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
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
        ];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
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

    crmNotifyOrderLifecycleEvent([
        'type' => 'order_created',
        'order_id' => (int)$order_id,
        'technician_id' => (int)($technician_id ?? 0),
        'new_status' => 'New',
        'actor_role' => (string)($_SESSION['role'] ?? ''),
        'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
        'actor_name' => (string)($_SESSION['full_name'] ?? ''),
    ]);

    header("Location: ../orders.php");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("add_order error: " . $e->getMessage());
    die('Order creation failed. Please try again.');
}
?>
