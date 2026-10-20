<?php
/**
 * Výběr kusu ze skladu pro INTERNÍ zakázku (v3.96.0) — „Reklamace" skladového zařízení,
 * které před prodejem potřebuje opravit. Vrací kusy skladem (select2) i s předvyplněním
 * polí zakázky (typ, značka, model, S/N, vzhled) a s informací, zda už kus v opravě je.
 *   GET q=…   → hledání (název, model, kód/IMEI)
 *   GET id=…  → jeden konkrétní kus (tlačítko Reklamace ve skladu)
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['results' => [], 'error' => 'auth']); exit;
}

ensureOrderStockProductColumn();
$id = (int)($_GET['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

try {
    $cols = "p.id, p.product_code, p.title, p.manufacturer, p.model, p.grade, p.battery, p.price, p.stock_qty, p.branch_id";
    if ($id > 0) {
        $st = $pdo->prepare("SELECT $cols FROM products p WHERE p.id = ? LIMIT 1");
        $st->execute([$id]);
    } else {
        $like = '%' . $q . '%';
        // vlastní pobočka první; příslušenství (obaly, skla, kabely…) se nenabízí
        $own = (int)getCurrentStaffBranchId();
        $st = $pdo->prepare("SELECT $cols FROM products p
            WHERE p.stock_qty > 0
              AND (p.title LIKE ? OR p.model LIKE ? OR p.product_code LIKE ?)
              AND p.title NOT REGEXP '(obal|kryt|pouzdro|sklo|f[oó]lie|kabel|nab[ií]je|adapt[eé]r|řemínek|drz[aá]k)'
            ORDER BY (p.branch_id = ?) DESC, p.title ASC LIMIT 20");
        $st->execute([$like, $like, $like, $own]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $repairs = crmStockRepairOrders(array_column($rows, 'id'));
    $branchNames = [];
    foreach (getBranches(false) as $b) { $branchNames[(int)$b['id']] = (string)$b['name']; }

    $results = [];
    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        $rep = $repairs[$pid] ?? null;
        $label = $r['title'] . ($r['grade'] ? ' · stav ' . $r['grade'] : '')
            . ($r['product_code'] ? ' · ' . $r['product_code'] : '')
            . (isset($branchNames[(int)$r['branch_id']]) ? ' · ' . $branchNames[(int)$r['branch_id']] : '');
        $results[] = [
            'id' => $pid,
            'text' => $label . ($rep ? ' — už v opravě (' . $rep['code'] . ')' : ''),
            'disabled' => $rep !== null && $id <= 0,
            'in_repair' => $rep,
            'prefill' => crmStockDevicePrefill($r),
            'title' => (string)$r['title'],
            'code' => (string)$r['product_code'],
        ];
    }
    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('stock_device_search: ' . $e->getMessage());
    echo json_encode(['results' => [], 'error' => 'db']);
}
