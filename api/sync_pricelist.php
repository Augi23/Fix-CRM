<?php
/* Sync ceníku oprav z applefix.cz (RepairPlugin) — jedna kategorie na request,
   volá se sekvenčně z Nastavení → Integrace (tlačítko „Načíst ceník"). */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    echo json_encode(['ok' => false, 'error' => 'Jen pro administrátora.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token.']); exit;
}

$category = trim((string)($_POST['category'] ?? ''));
$brand    = trim((string)($_POST['brand'] ?? 'Apple'));
if ($category === '' || $brand === '') {
    echo json_encode(['ok' => false, 'error' => 'Chybí kategorie/značka.']); exit;
}

set_time_limit(240);
try {
    $res = crmSyncRepairPricelist($category, $brand);
    echo json_encode(['ok' => true, 'category' => $category, 'brand' => $brand,
        'models' => $res['models'], 'rows' => $res['rows']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
