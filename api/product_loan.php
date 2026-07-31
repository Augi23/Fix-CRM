<?php
/**
 * Zapůjčeno / komisní prodej — zapnutí a vrácení.
 *
 * Kus zůstává ve skladu (stock_qty se nemění), jen se označí, že fyzicky není
 * u nás: na e-shop se neposílá, v CRM je vidět včetně toho, u koho je a od kdy.
 * Dřív se to řešilo nastavením stock_qty=0, což vypadalo jako vyprodáno.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {
    echo json_encode(['success' => false, 'message' => 'Jen pro vedení.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

ensureProductsLoanColumns();
ensureSkladBranchSchema();

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$to     = trim((string)($_POST['loan_to'] ?? ''));
$note   = trim((string)($_POST['loan_note'] ?? ''));

if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Chybí produkt.']); exit; }

$st = $pdo->prepare("SELECT id, title FROM products WHERE id = ?");
$st->execute([$id]);
$prod = $st->fetch();
if (!$prod) { echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit; }
// Pobočková pojistka: se zapůjčkou produktu smí hýbat jen zaměstnanec JEHO pobočky (admin/Boss vždy).
if (!crmCanModifyBranchStock(crmProductBranchId($id))) {
    echo json_encode(['success' => false, 'message' => 'Tento produkt patří jiné pobočce — zapůjčku smí řešit jen její zaměstnanci.']); exit;
}

if ($action === 'lend') {
    if ($to === '') { echo json_encode(['success' => false, 'message' => 'Vyplň, komu je kus zapůjčen.']); exit; }
    $who = trim((string)($_SESSION['user_name'] ?? $_SESSION['tech_name'] ?? ''));
    $up = $pdo->prepare("UPDATE products SET loan_to = ?, loan_note = ?, loan_by = ?, loan_at = NOW(), updated_at = NOW() WHERE id = ?");
    $up->execute([mb_substr($to, 0, 120), mb_substr($note, 0, 255), mb_substr($who, 0, 120), $id]);
    echo json_encode(['success' => true, 'state' => 'loaned', 'loan_to' => $to,
                      'message' => 'Označeno jako zapůjčené: ' . $to], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'return') {
    $up = $pdo->prepare("UPDATE products SET loan_to = NULL, loan_note = NULL, loan_by = NULL, loan_at = NULL, updated_at = NOW() WHERE id = ?");
    $up->execute([$id]);
    echo json_encode(['success' => true, 'state' => 'returned',
                      'message' => 'Kus je zpět ve skladu.'], JSON_UNESCAPED_UNICODE); exit;
}

echo json_encode(['success' => false, 'message' => 'Neznámá akce.']);
