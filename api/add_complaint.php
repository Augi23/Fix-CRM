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

// Z detailu zakázky se obsluha vrací zpět na zakázku (tam i vidí novou reklamaci);
// z přehledu reklamací zůstává přehled. return_order_id posílá modal (openComplaintForOrder).
$__returnOrderId = (int)($_POST['return_order_id'] ?? 0);
function complaint_redirect(string $qs): void {
    global $__returnOrderId;
    if ($__returnOrderId > 0) {
        header("Location: ../view_order.php?id=" . $__returnOrderId . "&" . $qs);
    } else {
        header("Location: ../reklamace.php?" . $qs);
    }
    exit;
}

$customer_id   = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: null;
$device_type   = trim($_POST['device_type'] ?? '');
$device_model  = trim($_POST['device_model'] ?? '');
$serial        = trim($_POST['serial_number'] ?? '');
$purchase_date = trim($_POST['purchase_date'] ?? '');
$orig_ref      = trim($_POST['orig_ref'] ?? '');
$order_id_in   = (int)($_POST['order_id'] ?? 0);   // z tlačítka „Reklamace" na zakázce
$reason        = trim($_POST['reason'] ?? '');
$resolution    = trim($_POST['resolution'] ?? '');

if ($device_model === '' || $reason === '') {
    complaint_redirect('error=' . urlencode('Vyplň model zařízení a popis problému.'));
}

try {
    // sloupce order_id/order_code/source si dosud zajišťovaly jen jiné stránky —
    // na čerstvé instalaci by INSERT níž spadl na „Unknown column" (DDL před transakcí)
    ensureComplaintsClientColumns($pdo);
    $pdo->beginTransaction();

    // Reklamace k existující zakázce: zakázka je zdroj pravdy pro vazbu (order_id,
    // order_code) i pro klienta — reklamace se pak klientovi ukáže v jeho portálu
    // u té zakázky, ať ji obsluha přiřadí komukoli (na zakázce je jen jeden klient).
    $orderRow = null;
    if ($order_id_in > 0) {
        $oq = $pdo->prepare("SELECT id, order_code, customer_id FROM orders WHERE id = ? LIMIT 1");
        $oq->execute([$order_id_in]);
        $orderRow = $oq->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$orderRow) {
            $pdo->rollBack();
            complaint_redirect('error=' . urlencode('Zakázka k reklamaci nebyla nalezena.'));
        }
        if (!$customer_id && trim($_POST['nc_first_name'] ?? '') === '') {
            $customer_id = (int)$orderRow['customer_id'];   // klient ze zakázky, když obsluha nic nevybrala
        }
    }

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

    // Zákonné náležitosti (§ 19 z. 634/1992 Sb.) žijí ve vlastních sloupcích —
    // důvod reklamace zůstává ČISTÝ popis vady (dřív se sem lepilo všechno).
    ensureComplaintsLegalColumns($pdo);
    $received_by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $pd = null;
    if ($purchase_date !== '') { $ts = strtotime($purchase_date); if ($ts) $pd = date('Y-m-d', $ts); }
    // „Doklad/zakázka" → zkusit dohledat skutečnou zakázku (propíše původní opravu)
    $order_id = null; $order_code = ($orig_ref !== '' ? $orig_ref : null);
    // Vazba na zakázku jen když reklamaci podává klient zakázky (nebo někdo se stejným
    // telefonem) — reklamace cizího člověka by se jinak ukázala klientovi zakázky v portálu.
    $sameParty = static function (int $a, int $b) use ($pdo): bool {
        if ($a === $b) return true;
        try {
            $q = $pdo->prepare("SELECT id, phone FROM customers WHERE id IN (?, ?)");
            $q->execute([$a, $b]);
            $ph = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = preg_replace('/\D+/', '', (string)$r['phone']) ?: '';
                if (str_starts_with($d, '00')) $d = substr($d, 2);
                if (strlen($d) === 9) $d = '420' . $d;
                $ph[(int)$r['id']] = strlen($d) >= 11 ? $d : '';
            }
            return ($ph[$a] ?? '') !== '' && ($ph[$a] ?? '') === ($ph[$b] ?? null);
        } catch (Throwable $e) { return false; }
    };
    if ($orderRow && !$sameParty((int)$customer_id, (int)$orderRow['customer_id'])) {
        $orderRow = null;                       // jiný člověk → bez vazby na zakázku
        if ($orig_ref !== '' && $order_code === $orig_ref) { $order_code = null; }
        $orig_ref = '';
    }
    if ($orderRow) {
        $order_id = (int)$orderRow['id'];
        $order_code = (string)$orderRow['order_code'] !== '' ? (string)$orderRow['order_code'] : $order_code;
    } elseif ($orig_ref !== '') {
        try {
            $oq = $pdo->prepare("SELECT id, order_code FROM orders WHERE order_code = ? LIMIT 1");
            $oq->execute([$orig_ref]);
            if ($or = $oq->fetch(PDO::FETCH_ASSOC)) { $order_id = (int)$or['id']; $order_code = (string)$or['order_code']; }
        } catch (Throwable $e) {}
    }

    $device = trim($device_type . ' ' . $device_model);
    $stmt = $pdo->prepare("INSERT INTO complaints
        (complaint_code, customer_id, phone, device, serial_number, complaint_reason, complaint_status,
         requested_resolution, received_by, purchase_date, order_id, order_code)
        VALUES (?, ?, ?, ?, ?, ?, 'Přijato', ?, ?, ?, ?, ?)");
    $stmt->execute([$code, $customer_id, $phone, $device, $serial, $reason,
                    ($resolution !== '' ? $resolution : null), ($received_by !== '' ? $received_by : null),
                    $pd, $order_id, $order_code]);
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
    // created_id spouští automatiku po založení (tisk štítku + podpis protokolu) — viz main.js
    complaint_redirect('created=' . urlencode($code) . '&created_id=' . (int)$complaint_id);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('add_complaint: ' . $e->getMessage());
    complaint_redirect('error=' . urlencode('Reklamaci se nepodařilo založit: ' . $e->getMessage()));
}
