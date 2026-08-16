<?php
/**
 * Nevyplacené výkupní listy pro Pokladnu (tlačítko „Výplata výkupu"):
 * poslední listy MÉ pobočky bez vazby na prodejku (payout_sale_id IS NULL),
 * volitelně filtr ?q= (číslo listu / předmět / jméno). Vrací i částku z listu
 * (výchozí vyplácená částka) — obsluha ji na kase může upravit.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/documents.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !crmCanUsePos()) {
    http_response_code(401);
    echo json_encode(['results' => []]); exit;
}

ensureCrmDocumentsTable();

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));

try {
    $sql = "SELECT id, doc_number, doc_date, customer_name, subject, price, branch_id, created_at
            FROM crm_documents WHERE doc_type = 'vykup' AND payout_sale_id IS NULL";
    $params = [];
    // kasa vyplácí jen výkupy SVÉ pobočky (admin/Boss vidí vše) — stejné pravidlo
    // jako v checkout (crmCanModifyBranchStock nad pobočkou dokumentu)
    if (!isBranchGlobalViewer()) {
        $sql .= " AND COALESCE(NULLIF(branch_id, 0), ?) = ?";
        $params[] = (int)getDefaultBranchId();
        $params[] = (int)getCurrentStaffBranchId();
    }
    if ($q !== '') {
        $sql .= " AND (doc_number LIKE ? OR subject LIKE ? OR customer_name LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $results = [];
    foreach ($st as $r) {
        $amount = crmParseAmountCzk((string)($r['price'] ?? ''));
        $results[] = [
            'id' => (int)$r['id'],
            'doc_number' => (string)$r['doc_number'],
            'subject' => (string)($r['subject'] ?? ''),
            'customer' => (string)($r['customer_name'] ?? ''),
            'amount' => $amount > 0 ? round($amount, 2) : 0,
            'date' => (string)($r['doc_date'] ?: substr((string)$r['created_at'], 0, 10)),
        ];
    }
    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_vykup_list: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
