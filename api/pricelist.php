<?php
/* Ceník oprav pro wizard zakázky: op=models (hledání modelu),
   op=repairs (opravy+varianty s cenami pro model). */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}

ensureRepairPricelistTable();
$op = trim((string)($_GET['op'] ?? 'models'));

try {
    if ($op === 'models') {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT category, brand, model, MIN(model_code) AS model_code
                FROM repair_pricelist";
        $params = [];
        if ($q !== '') {
            $sql .= " WHERE model LIKE ? OR model_code LIKE ? OR brand LIKE ?";
            $like = '%' . $q . '%';
            $params = [$like, $like, $like];
        }
        $sql .= " GROUP BY category, brand, model ORDER BY brand, model LIMIT 30";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'repairs') {
        $brand = trim((string)($_GET['brand'] ?? ''));
        $model = trim((string)($_GET['model'] ?? ''));
        if ($brand === '' || $model === '') { echo json_encode(['ok' => false, 'error' => 'Chybí model']); exit; }
        $st = $pdo->prepare("SELECT repair_name, variant, price, duration_min, category
                             FROM repair_pricelist WHERE brand = ? AND model = ?
                             ORDER BY repair_name, price IS NULL, price DESC, variant");
        $st->execute([$brand, $model]);
        echo json_encode(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Neznámá operace']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru'], JSON_UNESCAPED_UNICODE);
}
