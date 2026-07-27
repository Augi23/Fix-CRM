<?php
/* Přesun ZAŘÍZENÍ zakázky na druhou pobočku (fyzické umístění, zakázka zůstává
   své pobočce). POST id=<order_id> → přepne device_branch_id na opačnou pobočku,
   než kde zařízení právě je; návrat na domovskou pobočku sloupec vyNULLuje
   (pilulka zmizí). Vrací {ok, moved_to, moved_to_label, at_home}. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function md_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { md_fail('Nepřihlášeno', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { md_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { md_fail('Neplatný token', 419); }

ensureOrderDeviceBranchColumn();

$orderId = (int)($_POST['id'] ?? 0);
if ($orderId <= 0) { md_fail('Chybí id zakázky'); }

$st = $pdo->prepare("SELECT id, order_code, branch_id, device_branch_id, technician_id FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order) { md_fail('Zakázka nenalezena', 404); }
if (!canAccessOrderBranch($order)) { md_fail('Bez oprávnění', 403); }

[$targetId, $targetShort] = crmOrderDeviceMoveTarget($order);
if ($targetId <= 0) { md_fail('Není k dispozici druhá pobočka'); }

// Návrat na domovskou pobočku zakázky → NULL (zařízení „doma", pilulka zmizí).
$homeId = (int)($order['branch_id'] ?? 0);
$newValue = ($targetId === $homeId) ? null : $targetId;

try {
    $pdo->prepare("UPDATE orders SET device_branch_id = ? WHERE id = ?")->execute([$newValue, $orderId]);

    $label = getBranchLabel($targetId);
    crmAuditLog('order.device_move', [
        'entity_type' => 'order', 'entity_id' => $orderId,
        'entity_label' => (string)($order['order_code'] ?: ('#' . $orderId)),
        'summary' => 'Zařízení zakázky ' . ($order['order_code'] ?: ('#' . $orderId)) . ' přesunuto na pobočku ' . $label,
        'branch_id' => $homeId ?: null,
    ]);

    echo json_encode([
        'ok' => true,
        'moved_to' => $targetId,
        'moved_to_label' => $label,
        'at_home' => $newValue === null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    md_fail('Chyba serveru', 500);
}
