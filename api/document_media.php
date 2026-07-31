<?php
/* Fotky dokumentu (výkupní list — stav zařízení apod.).
   - action=upload : fotky do uploads/documents/<id>/ (finfo whitelist, 15 MB/soubor)
   - action=delete : smazání fotky (kterýkoli přihlášený zaměstnanec; auditováno) */
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

function dm_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) dm_fail(__('unauthorized'), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dm_fail('Chybná metoda', 405);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) dm_fail('Neplatný token', 419);

$action = trim((string)($_POST['action'] ?? 'upload'));
ensureDocumentMediaTable();

if ($action === 'delete') {
    $mid = (int)($_POST['media_id'] ?? 0);
    if ($mid <= 0) dm_fail('Chybí id');
    try {
        $st = $pdo->prepare("SELECT id, document_id, file_path, file_name FROM document_media WHERE id = ? LIMIT 1");
        $st->execute([$mid]);
        $row = $st->fetch();
        if (!$row) dm_fail('Fotka nenalezena', 404);

        // sken dokladu totožnosti smí mazat jen vedení
        $jeSken = str_starts_with(ltrim((string)$row['file_path'], '/'), 'id_scans/');
        if ($jeSken && !(function_exists('crmCanManageInvoices') && crmCanManageInvoices())) {
            dm_fail('Sken dokladu totožnosti smí mazat jen vedení.', 403);
        }

        // soubor smazat jen uvnitř povoleného úložiště (ochrana proti podvrženému file_path)
        $rel = ltrim((string)$row['file_path'], '/');
        $base = realpath($jeSken ? crmIdScanRoot() : __DIR__ . '/../uploads');
        $full = realpath(($jeSken ? dirname(crmIdScanRoot()) : __DIR__ . '/..') . '/' . $rel);
        if ($full !== false && $base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
            @unlink($full);
        }
        $pdo->prepare("DELETE FROM document_media WHERE id = ?")->execute([$mid]);

        crmAuditLog($jeSken ? 'document.id_scan_delete' : 'document.media_delete', [
            'entity_type' => 'document', 'entity_id' => (int)$row['document_id'],
            'summary' => 'Dokument #' . (int)$row['document_id'] . ($jeSken
                ? ' — smazán sken dokladu totožnosti'
                : ' — smazána fotka ' . (string)($row['file_name'] ?: basename((string)$row['file_path']))),
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('document_media delete: ' . $e->getMessage());
        dm_fail('Chyba serveru', 500);
    }
    exit;
}

if ($action !== 'upload') dm_fail('Neznámá akce');

// nahrát doklad totožnosti smí jen vedení (admin, Boss) — je to citlivý osobní údaj
if (($_POST['kind'] ?? 'photo') !== 'photo' && !(function_exists('crmCanManageInvoices') && crmCanManageInvoices())) {
    dm_fail('Doklad totožnosti smí nahrávat jen vedení.', 403);
}

$did = (int)($_POST['document_id'] ?? 0);
if ($did <= 0) dm_fail('Chybí id dokumentu');

// Druh přílohy: 'photo' = fotky zařízení (tisknou se), 'id_front'/'id_back' = sken
// dokladu totožnosti (citlivý údaj, jen interně — do tisku ani e-mailu nejde).
$kind = (string)($_POST['kind'] ?? 'photo');
if (!in_array($kind, ['photo', 'id_front', 'id_back'], true)) { $kind = 'photo'; }
$isIdScan = $kind !== 'photo';
$doc = crmGetDocument($did);
if (!$doc) dm_fail('Dokument nenalezen', 404);

if (empty($_FILES['files']['name'][0])) dm_fail('Žádné soubory');

$allowed_mime_to_ext = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/heic' => 'heic', 'image/heif' => 'heic',
];
$allowed_exts = ['jpg', 'jpeg', 'png', 'heic', 'webp'];
$max_bytes = 15 * 1024 * 1024;
$max_files = $isIdScan ? 1 : 12;   // doklad totožnosti: jedna strana = jeden soubor

$uploads_root = __DIR__ . '/../uploads/';
$upload_dir = $uploads_root . 'documents/' . $did . '/';
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0775, true); }
if (!is_dir($upload_dir) || !is_writable($upload_dir)) dm_fail('Úložiště není zapisovatelné', 500);

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
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$count = count($_FILES['files']['name']);

for ($i = 0; $i < $count && $saved < $max_files; $i++) {
    $name = basename((string)($_FILES['files']['name'][$i] ?? ''));
    $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) { $rejected[] = $name . ' (chyba nahrávání č. ' . $err . ')'; continue; }
    $tmp = (string)($_FILES['files']['tmp_name'][$i] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { $rejected[] = $name . ' (chybí dočasný soubor)'; continue; }
    if (filesize($tmp) > $max_bytes) { $rejected[] = $name . ' (větší než 15 MB)'; continue; }

    $mime = strtolower((string)finfo_file($finfo, $tmp));
    if (!isset($allowed_mime_to_ext[$mime])) { $rejected[] = $name . ' (nepovolený typ)'; continue; }
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && getimagesize($tmp) === false) {
        $rejected[] = $name . ' (poškozený obrázek)'; continue;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) { $ext = $allowed_mime_to_ext[$mime]; }
    $new = bin2hex(random_bytes(16)) . '.' . $ext;

    // sken dokladu totožnosti jde MIMO web (viz crmIdScanRoot) — pod webem by ho
    // stáhl kdokoli s odkazem, protože Caddy .htaccess neřeší
    $targetDir = $isIdScan ? crmIdScanRoot() . '/' . $did . '/' : $upload_dir;
    $storedPath = $isIdScan ? ('id_scans/' . $did . '/' . $new) : ('uploads/documents/' . $did . '/' . $new);
    if ($isIdScan && !is_dir($targetDir)) { @mkdir($targetDir, 0700, true); }
    if ($isIdScan && (!is_dir($targetDir) || !is_writable($targetDir))) {
        $rejected[] = $name . ' (privátní úložiště není zapisovatelné)'; continue;
    }

    if (move_uploaded_file($tmp, $targetDir . $new)) {
        if ($isIdScan) { @chmod($targetDir . $new, 0600); }
        // nová strana dokladu nahrazuje předchozí — ať se nehromadí kopie občanek
        if ($isIdScan) {
            $old = $pdo->prepare("SELECT id, file_path FROM document_media WHERE document_id = ? AND kind = ?");
            $old->execute([$did, $kind]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $rel = ltrim((string)$o['file_path'], '/');
                $base = str_starts_with($rel, 'id_scans/') ? crmIdScanRoot() . '/..' : __DIR__ . '/../';
                $fs = realpath(rtrim($base, '/') . '/' . $rel);
                $safeRoot = realpath(str_starts_with($rel, 'id_scans/') ? crmIdScanRoot() : $uploads_root) ?: '';
                if ($fs && $safeRoot !== '' && str_starts_with($fs, $safeRoot) && is_file($fs)) { @unlink($fs); }
                $pdo->prepare("DELETE FROM document_media WHERE id = ?")->execute([(int)$o['id']]);
            }
        }
        $pdo->prepare("INSERT INTO document_media (document_id, file_path, file_type, file_name, uploaded_by, kind) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$did, $storedPath, $mime, $name, $by !== '' ? mb_substr($by, 0, 100) : null, $kind]);
        // nahrání dokladu totožnosti je zásah do citlivých údajů → do historie změn
        if ($isIdScan && function_exists('crmAuditLog')) {
            crmAuditLog('document.id_scan', ['entity_type' => 'document', 'entity_id' => $did,
                'summary' => 'Nahrán sken dokladu totožnosti (' . ($kind === 'id_front' ? 'přední strana' : 'zadní strana')
                    . ') k dokumentu #' . $did]);
        }
        $saved++;
    } else {
        $rejected[] = $name . ' (soubor se nepodařilo uložit)';
    }
}
finfo_close($finfo);

if ($saved === 0) {
    dm_fail('Nenahrál se žádný platný soubor.' . ($rejected ? ' ' . implode('; ', array_slice($rejected, 0, 3)) : ''));
}

crmAuditLog('document.media_upload', [
    'entity_type' => 'document', 'entity_id' => $did,
    'entity_label' => (string)$doc['doc_number'],
    'summary' => 'Dokument ' . $doc['doc_number'] . ' — nahráno fotek: ' . $saved,
]);
echo json_encode(['ok' => true, 'count' => $saved, 'rejected' => $rejected], JSON_UNESCAPED_UNICODE);
