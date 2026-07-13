<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
    exit;
}

$term = trim($_GET['q'] ?? '');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;


$params = [];
$where = '';
$use_recent = ($term === '');
if (!$use_recent) {
    // Hledání zvládne i CELÉ jméno ("Barbara Ozima"): rozdělíme dotaz na slova a
    // KAŽDÉ slovo musí sedět na některé pole (jméno/příjmení/telefon/firma/e-mail)
    // nebo na složené celé jméno. Dřív hledalo jen celý řetězec v jednom poli, takže
    // "Barbara Ozima" (jméno + příjmení zvlášť) se nikdy nenašlo.
    $tokens = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
    $andClauses = [];
    foreach ($tokens as $tok) {
        $like = '%' . $tok . '%';
        $andClauses[] = "(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR company LIKE ? OR email LIKE ? OR CONCAT_WS(' ', first_name, last_name) LIKE ? OR CONCAT_WS(' ', last_name, first_name) LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $full = '%' . $term . '%';
    // …plus přímá shoda celého dotazu na složené jméno (obě pořadí) i firmu
    $fullClause = "CONCAT_WS(' ', first_name, last_name) LIKE ? OR CONCAT_WS(' ', last_name, first_name) LIKE ? OR company LIKE ?";
    array_push($params, $full, $full, $full);
    $where = "WHERE ((" . implode(' AND ', $andClauses) . ") OR (" . $fullClause . "))";
}

try {
    if ($use_recent) {
        $rows = $pdo->query(
            "SELECT c.id, c.first_name, c.last_name, c.phone, c.company
             FROM customers c
             JOIN (
                 SELECT customer_id, MAX(created_at) AS last_order
                 FROM orders
                 GROUP BY customer_id
             ) o ON o.customer_id = c.id
             ORDER BY o.last_order DESC
             LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = count($rows);
    } else {
        $count_sql = "SELECT COUNT(*) FROM customers $where";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT id, first_name, last_name, phone, company FROM customers $where ORDER BY last_name ASC LIMIT $per_page OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $results = [];
    foreach ($rows as $r) {
        $first = trim((string)($r['first_name'] ?? ''));
        $last = trim((string)($r['last_name'] ?? ''));
        if (in_array($first, ['-', '–', '—'], true)) $first = '';
        if (in_array($last, ['-', '–', '—'], true)) $last = '';

        $name = trim($first . ' ' . $last);
        $company = trim((string)($r['company'] ?? ''));
        if (!in_array($company, ['-', '–', '—'], true) && $company !== '') {
            $name = $company . ($name !== '' ? ' (' . $name . ')' : '');
        }
        $phone = $r['phone'] ?? '';
        $text = $name . ($phone !== '' ? ' (' . $phone . ')' : '');
        $results[] = [
            'id' => (int)$r['id'],
            'text' => $text,
            'name' => $name,
            'phone' => $phone
        ];
    }

    echo json_encode([
        'results' => $results,
        'pagination' => ['more' => (!$use_recent && ($offset + $per_page) < $total)]
    ]);
} catch (Exception $e) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
}
?>
