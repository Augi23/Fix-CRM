<?php
/**
 * JEDNORÁZOVÝ seed demo účtu pro Apple App Review — po spuštění SMAZAT!
 *
 * Vytvoří prázdnou pobočku „Demo servis" a zaměstnance apple.review (role
 * engineer) přiřazeného do ní, aby reviewer neviděl ostrá zákaznická data.
 * Chráněno dlouhým secretem v URL; idempotentní (existující účet nepřepíše).
 */
require_once 'includes/config.php';

$SECRET = 'afx-demo-9f3k2m8x1q7w4e6r5t0y-2026';
if (($_GET['secret'] ?? '') !== $SECRET) { http_response_code(404); exit; }

header('Content-Type: application/json; charset=utf-8');
$out = [];
try {
    // 1) Demo pobočka
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE name = ?");
    $stmt->execute(['Demo servis']);
    $branchId = (int)($stmt->fetchColumn() ?: 0);
    if (!$branchId) {
        $pdo->prepare("INSERT INTO branches (name, address, is_active) VALUES (?, ?, 1)")
            ->execute(['Demo servis', 'Ukázková pobočka pro App Review']);
        $branchId = (int)$pdo->lastInsertId();
        $out['branch'] = "created #$branchId";
    } else {
        $out['branch'] = "exists #$branchId";
    }

    // 2) Demo zaměstnanec
    $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? UNION SELECT id FROM users WHERE username = ?");
    $stmt->execute(['apple.review', 'apple.review']);
    if ($stmt->fetch()) {
        $out['user'] = 'exists';
    } else {
        $pdo->prepare("INSERT INTO technicians (name, email, phone, specialization, role, branch_id, telegram_id, telegram_username, username, password, pay_by_time)
                       VALUES (?, ?, '', '', 'engineer', ?, NULL, NULL, ?, ?, 0)")
            ->execute(['Apple Review', 'review@applefix.cz', $branchId, 'apple.review',
                       password_hash('AFXreview-2026!', PASSWORD_DEFAULT)]);
        $out['user'] = 'created #' . (int)$pdo->lastInsertId();
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    http_response_code(500);
    $out = ['ok' => false, 'error' => $e->getMessage()];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
