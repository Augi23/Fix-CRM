<?php
/** Ruční záloha / obnova zálohy — POUZE administrátor (obnova je zásah do celé DB). */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

// max. času na dump/import — u větší DB ať to nespadne v polovině
@set_time_limit(300);

if (!isset($_SESSION['user_id']) || !crmCanManageSettings()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Jen administrátor.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$action = (string)($_POST['op'] ?? '');

try {
    if ($action === 'run') {
        [$ok, $msg] = crmRunBackupNow('manual');
        if ($ok) {
            crmAuditLog('system.backup_run', ['entity_type' => 'system', 'summary' => 'Ruční záloha CRM (' . $msg . ')']);
            echo json_encode(['success' => true, 'message' => 'Záloha vytvořena: ' . $msg]);
        } else {
            echo json_encode(['success' => false, 'message' => $msg]);
        }
        exit;
    }

    if ($action === 'restore') {
        $name = (string)($_POST['name'] ?? '');
        [$ok, $msg] = crmRestoreBackup($name);
        echo json_encode(['success' => $ok, 'message' => $msg]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Neznámá akce.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Chyba: ' . $e->getMessage()]);
}
