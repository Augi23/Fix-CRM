<?php
/** Interní týmový chat — GET vrací zprávy (?after=id), POST odešle novou.
 *  Přístup: každý přihlášený zaměstnanec. Jméno autora se ukládá natvrdo. */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$actor = crmChatActor();
if (!isset($_SESSION['user_id']) || $actor === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => __('unauthorized')]); exit;
}
ensureStaffChatTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => __('csrf_token_invalid')]); exit;
    }

    $action = trim((string)($_POST['action'] ?? 'send'));
    if ($action === 'delete') {
        $messageId = max(0, (int)($_POST['id'] ?? 0));
        if ($messageId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Neplatná zpráva']); exit;
        }
        try {
            // Vlastnictví se kontroluje v DELETE podmínce — uživatel nemůže
            // smazat cizí zprávu ani podvržením ID v požadavku.
            $st = $pdo->prepare("DELETE FROM staff_chat WHERE id = ? AND actor_type = ? AND actor_id = ?");
            $st->execute([$messageId, $actor[0], $actor[1]]);
            if ($st->rowCount() !== 1) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Zpráva neexistuje nebo není vaše']); exit;
            }
            if (function_exists('crmAuditLog')) {
                crmAuditLog('chat.delete', [
                    'entity_type' => 'staff_chat',
                    'entity_id' => $messageId,
                    'summary' => 'Odesílatel smazal vlastní zprávu z týmového chatu',
                ]);
            }
            echo json_encode(['ok' => true, 'deleted_id' => $messageId]); exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Smazání zprávy selhalo']); exit;
        }
    }

    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') { echo json_encode(['ok' => false, 'message' => 'Prázdná zpráva']); exit; }
    if (function_exists('mb_substr')) { $msg = mb_substr($msg, 0, 2000); } else { $msg = substr($msg, 0, 2000); }
    $author = trim((string)($_SESSION['full_name'] ?? '')) ?: trim((string)($_SESSION['username'] ?? 'Zaměstnanec'));
    try {
        $st = $pdo->prepare("INSERT INTO staff_chat (actor_type, actor_id, author_name, message) VALUES (?, ?, ?, ?)");
        $st->execute([$actor[0], $actor[1], $author, $msg]);
        // Push všem ostatním zaměstnancům (bezpečný no-op bez APNs klíče).
        try { require_once __DIR__ . '/../includes/notify_push.php'; crmPushChat($pdo, (int)($_SESSION['user_id'] ?? 0), $author, $msg); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Uložení selhalo']);
    }
    exit;
}

// GET: ?after=<id> → novější zprávy; after=0 → posledních 60
$after = max(0, (int)($_GET['after'] ?? 0));
try {
    if ($after > 0) {
        $st = $pdo->prepare("SELECT id, actor_type, actor_id, author_name, message, created_at FROM staff_chat WHERE id > ? ORDER BY id ASC LIMIT 200");
        $st->execute([$after]);
        $rows = $st->fetchAll();
    } else {
        $st = $pdo->query("SELECT id, actor_type, actor_id, author_name, message, created_at FROM staff_chat ORDER BY id DESC LIMIT 60");
        $rows = array_reverse($st->fetchAll());
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'     => (int)$r['id'],
            'author' => (string)$r['author_name'],
            'mine'   => ($r['actor_type'] === $actor[0] && (int)$r['actor_id'] === $actor[1]),
            'time'   => date('H:i', strtotime((string)$r['created_at'])),
            'day'    => date('Y-m-d', strtotime((string)$r['created_at'])),
            'text'   => (string)$r['message'],
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'messages' => []]);
}
