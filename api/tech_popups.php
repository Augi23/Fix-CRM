<?php
/* Poll endpoint pro popup „nová přidělená zakázka".
   Vrátí přihlášenému technikovi nezobrazené popupy (a rovnou je označí jako zobrazené),
   se základními informacemi o zakázce a zařízení. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$techId = (int)($_SESSION['tech_id'] ?? 0);
if ($techId <= 0) {
    echo json_encode(['ok' => false, 'items' => []]);
    exit;
}

try {
    ensureTechPopupTable($pdo);
    $st = $pdo->prepare(
        "SELECT p.id, p.order_id, o.order_code, o.device_brand, o.device_model, o.problem_description, o.priority,
                TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS customer
         FROM tech_assignment_popups p
         JOIN orders o ON o.id = p.order_id
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE p.technician_id = ? AND p.seen_at IS NULL
         ORDER BY p.id ASC LIMIT 10"
    );
    $st->execute([$techId]);
    $rows = $st->fetchAll();

    $items = [];
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['id'];
        $items[] = [
            'order_id'      => (int)$r['order_id'],
            'order_code'    => trim((string)($r['order_code'] ?? '')) !== '' ? (string)$r['order_code'] : ('#' . (int)$r['order_id']),
            'device'        => trim(((string)($r['device_brand'] ?? '')) . ' ' . ((string)($r['device_model'] ?? ''))) ?: 'Zařízení',
            'customer'      => trim((string)($r['customer'] ?? '')) ?: '—',
            'problem'       => trim((string)($r['problem_description'] ?? '')) ?: '—',
            'priority_high' => (($r['priority'] ?? '') === 'High'),
        ];
    }

    if ($ids) {
        $pdo->prepare("UPDATE tech_assignment_popups SET seen_at = NOW() WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")")->execute();
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'items' => []]);
}
