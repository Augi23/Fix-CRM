<?php
/* Lehký poll pro zvuková upozornění a živá počítadla v doku:
   poslední ID zakázky + poslední ID změny stavu + čísla badge.
   Klient (main.js) porovnává s uloženým stavem a přehraje zvuk. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false]); exit;
}

// Poor-man's cron: poller běží každých ~20 s od každého přihlášeného → ideální
// místo pro odpálení automatické zálohy (každých 15 minut, na pozadí).
crmBackupMaybeSchedule();

// Měření aktivního času v systému (statistiky: hodiny Bosse/adminů = práce na CRM)
crmTrackStaffActivity();

// Jednorázově srovnat zakázky bez pobočky pod výchozí pobočku (interní/importované),
// aby je technici viděli v seznamu a mohli měnit jejich stav (ne jen admin/Boss).
ensureOrdersHaveBranch();

try {
    // stejný scope jako header badge/list: svoje přiřazené zakázky se počítají vždy (v3.8.x),
    // jinak poller každý tick přepíše badge starým číslem a zvonek u cross-branch přiřazení nezazní
    $scope = orderBranchScopeSql('branch_id', 'technician_id');   // '' nebo ' AND (…)'
    $lastOrder = (int)($pdo->query("SELECT MAX(id) FROM orders" . ($scope !== '' ? ' WHERE ' . substr($scope, 5) : ''))->fetchColumn() ?: 0);
} catch (Throwable $e) { $lastOrder = 0; }
try { $lastLog = (int)($pdo->query("SELECT MAX(id) FROM order_status_log")->fetchColumn() ?: 0); } catch (Throwable $e) { $lastLog = 0; }
try { $ordersBadge = (int)($pdo->query("SELECT COUNT(*) FROM orders WHERE status IN (" . orderStatusSqlIn($pdo, 'active') . ")" . orderBranchScopeSql('branch_id', 'technician_id'))->fetchColumn() ?: 0); } catch (Throwable $e) { $ordersBadge = 0; }
try { $complaintsBadge = (int)($pdo->query("SELECT COUNT(*) FROM complaints WHERE complaint_status NOT IN ('Vyřízeno','Zamítnuto')")->fetchColumn() ?: 0); } catch (Throwable $e) { $complaintsBadge = 0; }
try { $procurementBadge = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('pending','ordered')")->fetchColumn() ?: 0); } catch (Throwable $e) { $procurementBadge = 0; }
// nová e-shop objednávka (applefix.click) → zvuk + odznak jako u zakázek
try { $lastEshopOrder = (int)($pdo->query("SELECT MAX(id) FROM eshop_orders")->fetchColumn() ?: 0); } catch (Throwable $e) { $lastEshopOrder = 0; }

// Týmový chat: poslední zpráva od JINÉHO (zvuk) + počet nepřečtených (badge)
$lastChatOther = 0; $chatUnread = 0;
try {
    ensureStaffChatTable();
    $me = crmChatActor();
    if ($me !== null) {
        $q = $pdo->prepare("SELECT MAX(id) FROM staff_chat WHERE NOT (actor_type = ? AND actor_id = ?)");
        $q->execute([$me[0], $me[1]]);
        $lastChatOther = (int)($q->fetchColumn() ?: 0);
        $seen = max(0, (int)($_GET['chat_seen'] ?? 0));
        $q = $pdo->prepare("SELECT COUNT(*) FROM staff_chat WHERE id > ? AND NOT (actor_type = ? AND actor_id = ?)");
        $q->execute([$seen, $me[0], $me[1]]);
        $chatUnread = (int)($q->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {}

echo json_encode([
    'ok' => true,
    // aktuální CSRF token — dlouho otevřené záložky si jím obnovují meta tag,
    // aby akce nepadaly na „neplatný bezpečnostní token"
    'csrf' => (string)($_SESSION['csrf_token'] ?? ''),
    'last_chat_other_id' => $lastChatOther,
    'chat_unread' => $chatUnread,
    'last_order_id' => $lastOrder,
    'last_eshop_order_id' => $lastEshopOrder,
    'last_status_log_id' => $lastLog,
    'orders_badge' => $ordersBadge,
    'complaints_badge' => $complaintsBadge,
    'procurement_badge' => $procurementBadge,
]);
