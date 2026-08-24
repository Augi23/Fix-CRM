<?php
/**
 * Čekající REZERVACE z e-shopu pro Pokladnu (tlačítko „Rezervace e-shopu“).
 * Objednávka s platbou při vyzvednutí (pay_id 'odber') není prodej — zboží je
 * rezervované a kasou projde teprve při placení. Vrací i položky s cenami
 * z CRM, aby je kasa mohla vložit do košíku.
 * Volitelný filtr ?q= (číslo objednávky / jméno / telefon / kód produktu).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!crmCanUsePos()) {
    http_response_code(403);   // ne 401 — to by spustilo okno „obnovení přihlášení"

    echo json_encode(['results' => []]); exit;
}

ensureEshopOrdersTable();
ensureEshopReservationSchema();

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));

try {
    echo json_encode(['results' => afxEshopReservationHits($q, $limit)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_eshop_list: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
