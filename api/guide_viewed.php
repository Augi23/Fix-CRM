<?php
/* Označí návod jako přečtený PŘIHLÁŠENÝM pracovníkem — glow ikonky v Návodech
   tím pro něj zhasne (viz navody.php, tabulka guide_views). */
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['ok' => false]); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false]); exit;
}

$gid = (string)($_POST['guide_id'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,64}$/', $gid) || crmStaffKey() === '') {
    echo json_encode(['ok' => false]); exit;
}

try {
    ensureGuideViewsTable();
    $st = $pdo->prepare("INSERT IGNORE INTO guide_views (staff_key, guide_id) VALUES (?, ?)");
    $st->execute([crmStaffKey(), $gid]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
