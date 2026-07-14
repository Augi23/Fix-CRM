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

try {
    $scope = orderBranchScopeSql('branch_id');   // '' nebo ' AND branch_id = N'
    $lastOrder = (int)($pdo->query("SELECT MAX(id) FROM orders" . ($scope !== '' ? ' WHERE ' . substr($scope, 5) : ''))->fetchColumn() ?: 0);
} catch (Throwable $e) { $lastOrder = 0; }
try { $lastLog = (int)($pdo->query("SELECT MAX(id) FROM order_status_log")->fetchColumn() ?: 0); } catch (Throwable $e) { $lastLog = 0; }
try { $ordersBadge = (int)($pdo->query("SELECT COUNT(*) FROM orders WHERE status IN (" . orderStatusSqlIn($pdo, 'active') . ")" . orderBranchScopeSql('branch_id'))->fetchColumn() ?: 0); } catch (Throwable $e) { $ordersBadge = 0; }
try { $complaintsBadge = (int)($pdo->query("SELECT COUNT(*) FROM complaints WHERE complaint_status NOT IN ('Vyřízeno','Zamítnuto')")->fetchColumn() ?: 0); } catch (Throwable $e) { $complaintsBadge = 0; }
try { $procurementBadge = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('pending','ordered')")->fetchColumn() ?: 0); } catch (Throwable $e) { $procurementBadge = 0; }

echo json_encode([
    'ok' => true,
    'last_order_id' => $lastOrder,
    'last_status_log_id' => $lastLog,
    'orders_badge' => $ordersBadge,
    'complaints_badge' => $complaintsBadge,
    'procurement_badge' => $procurementBadge,
]);
