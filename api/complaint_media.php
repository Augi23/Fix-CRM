<?php
/* Přílohy reklamace (detail reklamace) — analogie api/upload_media.php u zakázek.
   - action=upload : nahrání souborů (fotky/PDF) do uploads/complaints/<id>/
                     whitelist jpg/jpeg/png/heic/webp/pdf, MIME přes finfo, limit 15 MB/soubor
                     (nahrávat smí každý přihlášený zaměstnanec)
   - action=delete : smazání přílohy — JEN vedení (admin/Boss/manažer);
                     src='media' (complaint_media) | 'attachment' (starší complaint_attachments) */
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

function cm_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) cm_fail(__('unauthorized'), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cm_fail(__('cl_err_invalid_request'), 405);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) cm_fail(__('csrf_token_invalid'), 419);

$action = trim((string)($_POST['action'] ?? 'upload'));

ensureComplaintsClientColumns($pdo);
ensureComplaintsWorkflowColumns($pdo);
ensureComplaintMediaTable($pdo);

/* ───────────── smazání přílohy (jen vedení) ───────────── */
if ($action === 'delete') {
    if (!crmComplaintCanManage()) cm_fail(__('cmpl_delete_only_management'), 403);

    $mid = (int)($_POST['media_id'] ?? 0);
    $src = trim((string)($_POST['src'] ?? 'media'));
    if ($mid <= 0) cm_fail(__('missing_id'));
    $table = $src === 'attachment' ? 'complaint_attachments' : 'complaint_media';

    try {
        $st = $pdo->prepare("SELECT id, complaint_id, file_path, file_name FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$mid]);
        $row = $st->fetch();
        if (!$row) cm_fail(__('cmpl_not_found'), 404);

        // soubor smazat jen uvnitř uploads/ (ochrana proti podvrženému file_path v DB)
        $rel = ltrim((string)$row['file_path'], '/');
        $base = realpath(__DIR__ . '/../uploads');
        $full = realpath(__DIR__ . '/../' . $rel);
        if ($full !== false && $base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
            @unlink($full);
        }

        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$mid]);

        crmAuditLog('complaint.media_delete', [
            'entity_type' => 'complaint', 'entity_id' => (int)$row['complaint_id'],
            'summary' => 'Reklamace #' . (int)$row['complaint_id'] . ' — smazána příloha '
                . (string)($row['file_name'] ?: basename((string)$row['file_path'])),
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('complaint_media delete: ' . $e->getMessage());
        cm_fail(__('cmpl_save_failed'), 500);
    }
    exit;
}

/* ───────────── nahrání příloh ───────────── */
if ($action !== 'upload') cm_fail(__('cl_err_invalid_request'));

$cid = (int)($_POST['complaint_id'] ?? 0);
if ($cid <= 0) cm_fail(__('missing_id'));

$st = $pdo->prepare("SELECT id, complaint_code FROM complaints WHERE id = ? LIMIT 1");
$st->execute([$cid]);
$complaint = $st->fetch();
if (!$complaint) cm_fail(__('cmpl_not_found'), 404);

if (empty($_FILES['files']['name'][0])) cm_fail(__('upload_no_files'));

// whitelist: fotky + PDF (finfo — $_FILES['type'] lze podvrhnout)
$allowed_mime_to_ext = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heic',
    'application/pdf' => 'pdf',
];
$allowed_exts = ['jpg', 'jpeg', 'png', 'heic', 'webp', 'pdf'];
$max_bytes = 15 * 1024 * 1024;
$max_files = 12;

$uploads_root = __DIR__ . '/../uploads/';
$upload_dir = $uploads_root . 'complaints/' . $cid . '/';
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0775, true); }
if (!is_dir($upload_dir) || !is_writable($upload_dir)) cm_fail(__('upload_dir_not_writable'), 500);

// .htaccess proti spuštění PHP v uploads/ (platí rekurzivně i pro podsložky)
$htaccess = $uploads_root . '.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess,
        "# Deny PHP execution in uploads\n" .
        "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
        "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
        "RemoveType .php .phtml .php3 .php4 .php5\n"
    );
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$saved = 0;
$rejected = [];
$by = mb_substr(crmStaffDisplayName(), 0, 100);
$count = count($_FILES['files']['name']);

for ($i = 0; $i < $count && $saved < $max_files; $i++) {
    $name = basename((string)($_FILES['files']['name'][$i] ?? ''));
    $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $rejected[] = $name . ' — ' . (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'soubor je větší než limit serveru (' . ini_get('upload_max_filesize') . ')'
            : 'chyba nahrávání č. ' . $err);
        continue;
    }
    $tmp = (string)($_FILES['files']['tmp_name'][$i] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { $rejected[] = $name . ' (chybí dočasný soubor)'; continue; }
    if (filesize($tmp) > $max_bytes) { $rejected[] = $name . ' (větší než 15 MB)'; continue; }

    $mime = strtolower((string)finfo_file($finfo, $tmp));
    if (!isset($allowed_mime_to_ext[$mime])) {
        error_log("complaint_media: blocked upload type=$mime name=$name");
        $rejected[] = $name . ' (nepovolený typ: ' . ($mime ?: 'neznámý') . ')';
        continue;
    }
    // fotky ověřit jako skutečné obrázky (HEIC getimagesize neumí — přeskočit)
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && getimagesize($tmp) === false) {
        $rejected[] = $name . ' (poškozený obrázek)';
        continue;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) { $ext = $allowed_mime_to_ext[$mime]; }
    $new = bin2hex(random_bytes(16)) . '.' . $ext;

    if (move_uploaded_file($tmp, $upload_dir . $new)) {
        $ins = $pdo->prepare("INSERT INTO complaint_media (complaint_id, file_path, file_type, file_name, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$cid, 'uploads/complaints/' . $cid . '/' . $new, $mime, $name, $by]);
        $saved++;
    } else {
        $rejected[] = $name . ' (soubor se nepodařilo uložit)';
    }
}
finfo_close($finfo);

if ($saved === 0) {
    $detail = $rejected ? (' ' . implode('; ', array_slice($rejected, 0, 3))) : '';
    cm_fail(__('upload_no_valid_file') . $detail);
}

crmAuditLog('complaint.media_upload', [
    'entity_type' => 'complaint', 'entity_id' => $cid,
    'entity_label' => (string)$complaint['complaint_code'],
    'summary' => 'Reklamace ' . $complaint['complaint_code'] . ' — nahráno příloh: ' . $saved,
]);

echo json_encode(['ok' => true, 'count' => $saved, 'rejected' => $rejected], JSON_UNESCAPED_UNICODE);
