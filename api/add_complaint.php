<?php
/* Založení reklamace z modalu „Nová reklamace" (multipart POST).
   Ukládá do `complaints` (existující tabulka) + fotky do `complaint_attachments`
   (tabulku si v případě potřeby založí — funguje i bez spuštění migrace 018). */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    header("Location: ../login.php");
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(__('csrf_token_invalid'));
}

function complaint_redirect(string $qs): void {
    header("Location: ../reklamace.php?" . $qs);
    exit;
}

$customer_id   = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: null;
$device_type   = trim($_POST['device_type'] ?? '');
$device_model  = trim($_POST['device_model'] ?? '');
$serial        = trim($_POST['serial_number'] ?? '');
$purchase_date = trim($_POST['purchase_date'] ?? '');
$orig_ref      = trim($_POST['orig_ref'] ?? '');
$reason        = trim($_POST['reason'] ?? '');
$resolution    = trim($_POST['resolution'] ?? '');

if ($device_model === '' || $reason === '') {
    complaint_redirect('error=' . urlencode('Vyplň model zařízení a popis problému.'));
}

try {
    $pdo->beginTransaction();

    // nový klient (když není vybraný existující)
    if (!$customer_id) {
        $nf = trim($_POST['nc_first_name'] ?? '');
        $nl = trim($_POST['nc_last_name'] ?? '');
        $np = trim($_POST['nc_phone'] ?? '');
        $ne = trim($_POST['nc_email'] ?? '');
        if ($nf === '' || $np === '') {
            $pdo->rollBack();
            complaint_redirect('error=' . urlencode('Vyber klienta, nebo vyplň nového (jméno + telefon).'));
        }
        $stmt = $pdo->prepare("INSERT INTO customers (customer_type, first_name, last_name, phone, email) VALUES ('private', ?, ?, ?, ?)");
        $stmt->execute([$nf, ($nl !== '' ? $nl : '—'), $np, ($ne !== '' ? $ne : null)]);
        $customer_id = (int)$pdo->lastInsertId();
        $phone = $np;
    } else {
        $stmt = $pdo->prepare("SELECT phone FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $phone = (string)($stmt->fetchColumn() ?: '');
    }

    // kód reklamace: pokračuje v číselné řadě za posledním segmentem (jako import)
    $max  = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(complaint_code,'-',-1) AS UNSIGNED)),0) FROM complaints")->fetchColumn();
    $code = sprintf('RK-%03d', $max + 1);

    // strukturovaný důvod (list reklamací zobrazuje jediné textové pole)
    $extras = [];
    if ($resolution    !== '') $extras[] = 'Požadavek: ' . $resolution;
    if ($purchase_date !== '') {
        $ts = strtotime($purchase_date);
        $extras[] = 'Zakoupeno: ' . ($ts ? date('d.m.Y', $ts) : $purchase_date);
    }
    if ($orig_ref !== '') $extras[] = 'Doklad/zakázka: ' . $orig_ref;
    $full_reason = $reason . ($extras ? "\n" . implode(' · ', $extras) : '');

    $device = trim($device_type . ' ' . $device_model);
    $stmt = $pdo->prepare("INSERT INTO complaints (complaint_code, customer_id, phone, device, serial_number, complaint_reason, complaint_status) VALUES (?, ?, ?, ?, ?, ?, 'Přijato')");
    $stmt->execute([$code, $customer_id, $phone, $device, $serial, $full_reason]);
    $complaint_id = (int)$pdo->lastInsertId();
    crmAuditLog('complaint.create', [
        'entity_type' => 'complaint', 'entity_id' => $complaint_id, 'entity_label' => (string)$code,
        'summary' => 'Vytvořena reklamace ' . $code . ($device !== '' ? ' — ' . $device : ''),
    ]);

    // ---- fotodokumentace ----
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `complaint_attachments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `complaint_id` INT(11) NOT NULL,
            `file_path` VARCHAR(255) NOT NULL,
            `file_type` VARCHAR(50) DEFAULT NULL,
            `file_name` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `complaint_id` (`complaint_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $upload_dir = __DIR__ . '/../uploads/complaints/';
        if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0775, true); }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                    'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heic'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $count = count($_FILES['photos']['name']);
        $saved = 0;
        for ($i = 0; $i < $count && $saved < 12; $i++) {
            if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp = $_FILES['photos']['tmp_name'][$i];
            if (!is_uploaded_file($tmp) || filesize($tmp) > 15 * 1024 * 1024) continue;
            $mime = finfo_file($finfo, $tmp) ?: '';
            if (!isset($allowed[$mime])) continue;
            $new = uniqid('rk_' . $complaint_id . '_', true) . '.' . $allowed[$mime];
            if (move_uploaded_file($tmp, $upload_dir . $new)) {
                $stmt = $pdo->prepare("INSERT INTO complaint_attachments (complaint_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$complaint_id, 'uploads/complaints/' . $new, $mime, basename($_FILES['photos']['name'][$i])]);
                $saved++;
            }
        }
        finfo_close($finfo);
    }

    $pdo->commit();
    complaint_redirect('created=' . urlencode($code));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('add_complaint: ' . $e->getMessage());
    complaint_redirect('error=' . urlencode('Reklamaci se nepodařilo založit: ' . $e->getMessage()));
}
