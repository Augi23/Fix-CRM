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
            // přílohy načíst PŘED smazáním zprávy (potom už je nespáruji)
            $paths = [];
            try {
                $fq = $pdo->prepare("SELECT f.file_path FROM staff_chat_files f
                                     JOIN staff_chat m ON m.id = f.message_id
                                     WHERE f.message_id = ? AND m.actor_type = ? AND m.actor_id = ?");
                $fq->execute([$messageId, $actor[0], $actor[1]]);
                $paths = $fq->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {}
            $st = $pdo->prepare("DELETE FROM staff_chat WHERE id = ? AND actor_type = ? AND actor_id = ?");
            $st->execute([$messageId, $actor[0], $actor[1]]);
            if ($st->rowCount() === 1) {
                try { $pdo->prepare("DELETE FROM staff_chat_files WHERE message_id = ?")->execute([$messageId]); }
                catch (Throwable $e) {}
                foreach ($paths as $rel) {
                    $abs = crmChatResolveFilePath((string)$rel);
                    if ($abs !== null) { @unlink($abs); }
                }
            }
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
    if (function_exists('mb_substr')) { $msg = mb_substr($msg, 0, 2000); } else { $msg = substr($msg, 0, 2000); }

    // ── přílohy ─────────────────────────────────────────────────────────────
    // Soubory se nejdřív ULOŽÍ na disk a teprve pak vzniká zpráva — kdyby
    // upload selhal, v chatu nezůstane prázdná bublina s chybějící přílohou.
    $files = [];
    $rejected = [];
    $hasUpload = !empty($_FILES['files']['name'][0]);
    if ($hasUpload) {
        $allowed = crmChatAllowedTypes();
        $maxBytes = crmChatMaxUploadBytes();
        $dir = crmChatUploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            echo json_encode(['ok' => false, 'message' => 'Složku pro přílohy se nepodařilo vytvořit.'], JSON_UNESCAPED_UNICODE); exit;
        }
        if (!is_writable($dir)) {
            echo json_encode(['ok' => false, 'message' => 'Do složky pro přílohy nejde zapisovat.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $names = (array)$_FILES['files']['name'];
        foreach ($names as $k => $origName) {
            if (count($files) >= 10) { $rejected[] = 'víc než 10 příloh najednou nejde'; break; }
            $err = (int)($_FILES['files']['error'][$k] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $rejected[] = basename((string)$origName) . ' — ' . (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'je větší než limit serveru (' . ini_get('upload_max_filesize') . ')'
                    : 'chyba nahrávání č. ' . $err);
                continue;
            }
            $tmp = (string)($_FILES['files']['tmp_name'][$k] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) { $rejected[] = basename((string)$origName) . ' — soubor nedorazil'; continue; }
            $size = (int)($_FILES['files']['size'][$k] ?? 0);
            if ($size <= 0) { $rejected[] = basename((string)$origName) . ' — prázdný soubor'; continue; }
            if ($size > $maxBytes) {
                $rejected[] = basename((string)$origName) . ' — je větší než ' . round($maxBytes / 1048576, 1) . ' MB';
                continue;
            }
            // typ VŽDY z obsahu, ne z hlavičky prohlížeče (ta jde podvrhnout)
            $mime = strtolower((string)finfo_file($finfo, $tmp));
            if (!isset($allowed[$mime])) {
                $rejected[] = basename((string)$origName) . ' — nepodporovaný typ (' . ($mime ?: 'neznámý') . ')';
                continue;
            }
            if (strncmp($mime, 'image/', 6) === 0 && !in_array($mime, ['image/heic', 'image/heif'], true) && @getimagesize($tmp) === false) {
                $rejected[] = basename((string)$origName) . ' — poškozený obrázek';
                continue;
            }
            $newName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($tmp, $dir . $newName)) {
                $rejected[] = basename((string)$origName) . ' — nepodařilo se uložit';
                continue;
            }
            @chmod($dir . $newName, 0640);
            $files[] = [
                'path' => 'secure/chat/' . $newName,
                'name' => mb_substr(basename((string)$origName), 0, 255),
                'mime' => $mime,
                'size' => $size,
            ];
        }
        if (is_resource($finfo)) { finfo_close($finfo); }
    }

    if ($msg === '' && !$files) {
        $why = $rejected ? implode('; ', $rejected) : 'Prázdná zpráva';
        // uklidit případné částečně nahrané soubory, aby se nekupily bez zprávy
        foreach ($files as $f) { @unlink(dirname(__DIR__) . '/' . $f['path']); }
        echo json_encode(['ok' => false, 'message' => $why], JSON_UNESCAPED_UNICODE); exit;
    }

    $author = trim((string)($_SESSION['full_name'] ?? '')) ?: trim((string)($_SESSION['username'] ?? 'Zaměstnanec'));
    try {
        $st = $pdo->prepare("INSERT INTO staff_chat (actor_type, actor_id, author_name, message) VALUES (?, ?, ?, ?)");
        $st->execute([$actor[0], $actor[1], $author, $msg]);
        $messageId = (int)$pdo->lastInsertId();
        if ($files) {
            $fi = $pdo->prepare("INSERT INTO staff_chat_files (message_id, file_path, file_name, file_mime, file_size) VALUES (?, ?, ?, ?, ?)");
            foreach ($files as $f) { $fi->execute([$messageId, $f['path'], $f['name'], $f['mime'], $f['size']]); }
        }
        // Push všem ostatním zaměstnancům (bezpečný no-op bez APNs klíče).
        $pushText = $msg !== '' ? $msg : ('📎 ' . count($files) . ' ' . (count($files) === 1 ? 'příloha' : 'přílohy'));
        try { require_once __DIR__ . '/../includes/notify_push.php'; crmPushChat($pdo, (int)($_SESSION['user_id'] ?? 0), $author, $pushText); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'id' => $messageId, 'rejected' => $rejected], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        foreach ($files as $f) { @unlink(dirname(__DIR__) . '/' . $f['path']); }
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
    $filesByMsg = crmChatFilesForMessages(array_map(static fn($r) => (int)$r['id'], $rows));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'     => (int)$r['id'],
            'author' => (string)$r['author_name'],
            'mine'   => ($r['actor_type'] === $actor[0] && (int)$r['actor_id'] === $actor[1]),
            'time'   => date('H:i', strtotime((string)$r['created_at'])),
            'day'    => date('Y-m-d', strtotime((string)$r['created_at'])),
            'text'   => (string)$r['message'],
            'files'  => $filesByMsg[(int)$r['id']] ?? [],
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'messages' => []]);
}
