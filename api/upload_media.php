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
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $t('unauthorized')]);
    exit;
}

// CSRF validation
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('csrf_token_invalid')]);
    exit;
}

$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => $t('missing_id')]);
    exit;
}

// Branch authorization: only staff who can access the order's branch may attach media to it
// (mirrors view_order.php / edit_order.php; prevents cross-branch IDOR via a forged order_id).
$orderStmt = $pdo->prepare('SELECT id, branch_id FROM orders WHERE id = ? LIMIT 1');
$orderStmt->execute([$order_id]);
$orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
if (!$orderRow) {
    echo json_encode(['success' => false, 'message' => $t('missing_id')]);
    exit;
}
if (!canAccessOrderBranch($orderRow)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('access_denied_msg')]);
    exit;
}

if (empty($_FILES['files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => $t('upload_no_files')]);
    exit;
}

$upload_dir = __DIR__ . '/../uploads/';
$allowed_mime_to_ext = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'video/mp4' => 'mp4',
    'video/quicktime' => 'mov',
    'video/x-msvideo' => 'avi',
];
$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
$success_count = 0;
$rejected = [];

// Ensure upload directory exists
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if (!is_writable($upload_dir)) {
    echo json_encode(['success' => false, 'message' => $t('upload_dir_not_writable')]);
    exit;
}

// Create .htaccess to prevent PHP execution in uploads folder
$htaccess = $upload_dir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess,
        "# Deny PHP execution in uploads\n" .
        "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
        "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
        "RemoveType .php .phtml .php3 .php4 .php5\n"
    );
}

// Use finfo for real MIME type detection (not $_FILES['type'] which can be spoofed)
$finfo = finfo_open(FILEINFO_MIME_TYPE);

foreach ($_FILES['files']['name'] as $key => $name) {
    $err = (int)($_FILES['files']['error'][$key] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $rejected[] = basename((string)$name) . " (upload error " . $err . ")";
        continue;
    }

    $tmp = $_FILES['files']['tmp_name'][$key] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $rejected[] = basename((string)$name) . " (temporary upload missing)";
        continue;
    }

    // Detect real MIME type from file content
    $real_type = strtolower((string)finfo_file($finfo, $tmp));

    if (!isset($allowed_mime_to_ext[$real_type])) {
        error_log("Blocked upload attempt: type=$real_type, name=$name");
        $rejected[] = basename((string)$name) . " (unsupported type: " . ($real_type ?: 'unknown') . ")";
        continue;
    }

    // Additional validation: ensure images are real images
    if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) {
        error_log("Blocked fake image upload: name=$name");
        $rejected[] = basename((string)$name) . " (image validation failed)";
        continue;
    }

    // Generate a cryptographically random filename (not guessable)
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        $ext = $allowed_mime_to_ext[$real_type];
    }

    $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = $upload_dir . $new_name;

    if (move_uploaded_file($tmp, $path)) {
        $stmt = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, 'uploads/' . $new_name, $real_type, basename((string)$name)]);
        $success_count++;
    } else {
        $rejected[] = basename((string)$name) . " (cannot move uploaded file)";
    }
}

finfo_close($finfo);

if ($success_count === 0) {
    $detail = !empty($rejected) ? (' ' . implode('; ', array_slice($rejected, 0, 3))) : '';
    echo json_encode(['success' => false, 'message' => $t('upload_no_valid_file') . $detail]);
    exit;
}

echo json_encode(['success' => true, 'count' => $success_count]);
?>
