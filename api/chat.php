<?php
/** Interní týmový chat — GET vrací zprávy (?after=id) nebo seznam členů
 *  (?op=members, pro @zmínky), POST odešle novou zprávu (volitelně se soubory
 *  přes multipart files[]) nebo smaže vlastní (action=delete).
 *  Přístup: každý přihlášený zaměstnanec. Jméno autora se ukládá natvrdo.
 *  Přílohy: secure/chat/ (napřímo blokované), výdej přes api/chat_file.php. */
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
ensureStaffChatFilesTable();

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
            // Přílohy smazané zprávy: pryč z DB i z disku (cesty přes bezpečný resolver)
            try {
                $fs = $pdo->prepare("SELECT id, file_path FROM staff_chat_files WHERE message_id = ?");
                $fs->execute([$messageId]);
                foreach ($fs->fetchAll() as $f) {
                    $abs = crmChatResolveFilePath((string)$f['file_path']);
                    if ($abs !== null) { @unlink($abs); }
                }
                $pdo->prepare("DELETE FROM staff_chat_files WHERE message_id = ?")->execute([$messageId]);
            } catch (Throwable $e) { error_log('chat delete attachments: ' . $e->getMessage()); }
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
    if (function_exists('mb_substr')) { $msg = mb_substr($msg, 0, 2000); } else { $msg = substr($msg, 0, 2000); }

    // ── Přílohy (fotky, video, dokumenty) — multipart files[] ────────────────
    // Nejdřív se VŠECHNY soubory zvalidují (typ přes finfo, ne spoofnutelný
    // $_FILES type; velikost), teprve pak se něco ukládá — žádné polovičaté zprávy.
    $uploads = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
        $names = $_FILES['files']['name'];
        for ($i = 0; $i < count($names); $i++) {
            if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            $uploads[] = [
                'name' => (string)$names[$i],
                'tmp'  => (string)($_FILES['files']['tmp_name'][$i] ?? ''),
                'err'  => (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_OK),
                'size' => (int)($_FILES['files']['size'][$i] ?? 0),
            ];
        }
    }
    if (count($uploads) > 6) {
        echo json_encode(['ok' => false, 'message' => 'Najednou lze poslat nejvýš 6 souborů.']); exit;
    }
    if ($msg === '' && !$uploads) { echo json_encode(['ok' => false, 'message' => 'Prázdná zpráva']); exit; }

    $allowed = crmChatAllowedTypes();
    $cap = crmChatMaxUploadBytes();
    $valid = [];
    if ($uploads) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        foreach ($uploads as $u) {
            $label = $u['name'] !== '' ? $u['name'] : 'soubor';
            if ($u['err'] !== UPLOAD_ERR_OK || $u['tmp'] === '' || !is_uploaded_file($u['tmp'])) {
                echo json_encode(['ok' => false, 'message' => 'Nahrání souboru „' . $label . '" selhalo.']); exit;
            }
            if ($u['size'] <= 0 || $u['size'] > $cap) {
                echo json_encode(['ok' => false, 'message' => 'Soubor „' . $label . '" je moc velký (limit ' . round($cap / 1048576) . ' MB).']); exit;
            }
            $mime = strtolower((string)$fi->file($u['tmp']));
            if (!isset($allowed[$mime])) {
                echo json_encode(['ok' => false, 'message' => 'Typ souboru „' . $label . '" není povolený (fotky, video, audio, PDF, dokumenty).']); exit;
            }
            $valid[] = ['name' => $label, 'tmp' => $u['tmp'], 'size' => $u['size'], 'mime' => $mime, 'ext' => $allowed[$mime]];
        }
    }

    $author = trim((string)($_SESSION['full_name'] ?? '')) ?: trim((string)($_SESSION['username'] ?? 'Zaměstnanec'));
    try {
        $st = $pdo->prepare("INSERT INTO staff_chat (actor_type, actor_id, author_name, message) VALUES (?, ?, ?, ?)");
        $st->execute([$actor[0], $actor[1], $author, $msg]);
        $msgId = (int)$pdo->lastInsertId();

        // Uložení příloh: náhodné jméno (nikdy ne původní), mimo webroot-viditelné cesty
        if ($valid) {
            $dir = crmChatUploadDir();
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            // Apache pojistka (server běží na Caddy, který secure/ blokuje sám)
            $ht = $dir . '.htaccess';
            if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }
            $stored = [];
            $insF = $pdo->prepare("INSERT INTO staff_chat_files (message_id, file_path, file_name, file_mime, file_size) VALUES (?, ?, ?, ?, ?)");
            foreach ($valid as $v) {
                $rel = 'secure/chat/' . date('Ym') . '_' . bin2hex(random_bytes(16)) . '.' . $v['ext'];
                $absTarget = dirname(__DIR__) . '/' . $rel;
                if (!move_uploaded_file($v['tmp'], $absTarget)) {
                    foreach ($stored as $s) { @unlink($s); }
                    $pdo->prepare("DELETE FROM staff_chat_files WHERE message_id = ?")->execute([$msgId]);
                    $pdo->prepare("DELETE FROM staff_chat WHERE id = ?")->execute([$msgId]);
                    echo json_encode(['ok' => false, 'message' => 'Uložení souboru „' . $v['name'] . '" selhalo.']); exit;
                }
                @chmod($absTarget, 0644);
                $stored[] = $absTarget;
                $fname = function_exists('mb_substr') ? mb_substr($v['name'], 0, 255) : substr($v['name'], 0, 255);
                $insF->execute([$msgId, $rel, $fname, $v['mime'], $v['size']]);
            }
        }

        // Push všem ostatním zaměstnancům (bezpečný no-op bez APNs klíče).
        $pushText = $msg !== '' ? $msg : ('📎 ' . (count($valid) === 1 ? 'příloha' : count($valid) . ' přílohy'));
        try { require_once __DIR__ . '/../includes/notify_push.php'; crmPushChat($pdo, (int)($_SESSION['user_id'] ?? 0), $author, $pushText); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'id' => $msgId]);
    } catch (Throwable $e) {
        error_log('chat send: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'Uložení selhalo']);
    }
    exit;
}

// GET ?op=members → jména zaměstnanců pro @zmínky (users + aktivní technici)
if (($_GET['op'] ?? '') === 'members') {
    $names = [];
    try {
        foreach ($pdo->query("SELECT full_name, username FROM users") as $r) {
            $n = trim((string)($r['full_name'] ?? '')) ?: trim((string)($r['username'] ?? ''));
            if ($n !== '') { $names[$n] = true; }
        }
        foreach ($pdo->query("SELECT name FROM technicians WHERE is_active = 1") as $r) {
            $n = trim((string)$r['name']);
            if ($n !== '') { $names[$n] = true; }
        }
    } catch (Throwable $e) { error_log('chat members: ' . $e->getMessage()); }
    $list = array_keys($names);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    echo json_encode(['ok' => true, 'members' => array_values($list)], JSON_UNESCAPED_UNICODE); exit;
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
    $filesBy = crmChatFilesForMessages(array_map(static fn($r) => (int)$r['id'], $rows));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'     => (int)$r['id'],
            'author' => (string)$r['author_name'],
            'mine'   => ($r['actor_type'] === $actor[0] && (int)$r['actor_id'] === $actor[1]),
            'time'   => date('H:i', strtotime((string)$r['created_at'])),
            'day'    => date('Y-m-d', strtotime((string)$r['created_at'])),
            'text'   => (string)$r['message'],
            'files'  => $filesBy[(int)$r['id']] ?? [],
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'messages' => []]);
}
