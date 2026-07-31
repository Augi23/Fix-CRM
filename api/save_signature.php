<?php
/* Uloží podpis klienta (canvas → PNG data URL) k zakázce.
   sig_type: prijem (souhlas s podmínkami) | vydej (převzetí hotové zakázky).
   Opakovaný podpis stejného typu nahradí ten předchozí. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$complaintId = (int)($_POST['complaint_id'] ?? 0);
$documentId = (int)($_POST['document_id'] ?? 0);
$sigType = (string)($_POST['sig_type'] ?? '');
$dataUrl = (string)($_POST['image'] ?? '');

$isComplaint = ($complaintId > 0 && $sigType === 'reklamace');
$isDocument = ($documentId > 0 && $sigType === 'dokument');
if ($isDocument) {
    require_once __DIR__ . '/../includes/documents.php';
    $document = crmGetDocument($documentId);
    if (!$document) { echo json_encode(['ok' => false, 'error' => 'Dokument nenalezen']); exit; }
} elseif (!$isComplaint) {
    if ($orderId <= 0 || !in_array($sigType, ['prijem', 'vydej'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Chybné parametry']); exit;
    }

    $st = $pdo->prepare("SELECT id, branch_id, technician_id FROM orders WHERE id = ? LIMIT 1");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
    if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }
} else {
    $st = $pdo->prepare("SELECT id, complaint_code FROM complaints WHERE id = ? LIMIT 1");
    $st->execute([$complaintId]);
    $complaint = $st->fetch();
    if (!$complaint) { echo json_encode(['ok' => false, 'error' => 'Reklamace nenalezena']); exit; }
}

// data URL → PNG binárka (limit ~1.5 MB, kontrola PNG hlavičky)
if (!preg_match('#^data:image/png;base64,(.+)$#', $dataUrl, $m)) {
    echo json_encode(['ok' => false, 'error' => 'Chybný formát podpisu']); exit;
}
$bin = base64_decode($m[1], true);
if ($bin === false || strlen($bin) < 200 || strlen($bin) > 1572864
    || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    echo json_encode(['ok' => false, 'error' => 'Podpis se nepodařilo přečíst']); exit;
}

$dir = __DIR__ . '/../uploads/signatures';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
if (!is_dir($dir) || !is_writable($dir)) {
    echo json_encode(['ok' => false, 'error' => 'Úložiště podpisů není zapisovatelné']); exit;
}

// ── Dokument (výkupní list / zástavní formulář) → document_signatures ──
if ($isDocument) {
    try {
        ensureDocumentSignatureSupport();

        // nahradit předchozí podpis (soubor i záznam)
        $old = $pdo->prepare("SELECT id, file_path FROM document_signatures WHERE document_id = ?");
        $old->execute([$documentId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $p = __DIR__ . '/../' . ltrim((string)$o['file_path'], '/');
            if (is_file($p)) { @unlink($p); }
            $pdo->prepare("DELETE FROM document_signatures WHERE id = ?")->execute([(int)$o['id']]);
        }

        $name = 'sig_doc_' . $documentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($dir . '/' . $name, $bin);

        $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        // uložit i větu, kterou klient nad podpisem viděl (v jazyce dokumentu) — bez ní
        // by později nešlo doložit, s čím přesně souhlasil
        $__docLang = 'cs';
        try {
            $lq = $pdo->prepare("SELECT lang FROM crm_documents WHERE id = ?");
            $lq->execute([$documentId]);
            $__docLang = crmDocLangOrDefault((string)$lq->fetchColumn());
        } catch (Throwable $e) { /* zůstane čeština */ }
        $__terms = __('cdoc_sign_terms', $__docLang);
        $pdo->prepare("INSERT INTO document_signatures (document_id, file_path, requested_by, terms_text) VALUES (?, ?, ?, ?)")
            ->execute([$documentId, 'uploads/signatures/' . $name, $by !== '' ? mb_substr($by, 0, 100) : null, $__terms]);

        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId > 0) {
            try {
                $pdo->prepare("UPDATE signature_requests SET status = 'done' WHERE id = ? AND document_id = ?")->execute([$reqId, $documentId]);
            } catch (Throwable $e) { /* podpis je uložen, zbytek best-effort */ }
        }

        crmAuditLog('document.signature_add', [
            'entity_type' => 'document', 'entity_id' => (int)$documentId,
            'entity_label' => (string)($document['doc_number'] ?? ('#' . $documentId)),
            'summary' => 'Uložen podpis klienta k dokumentu ' . (string)($document['doc_number'] ?? ('#' . $documentId)),
        ]);
        echo json_encode(['ok' => true, 'signed_at' => date('d.m.Y H:i'), 'emailed' => false]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
    }
    exit;
}

// ── Reklamace: podpis reklamačního protokolu → complaint_signatures ──
if ($isComplaint) {
    try {
        ensureComplaintSignatureSupport();

        // nahradit předchozí podpis (soubor i záznam)
        $old = $pdo->prepare("SELECT id, file_path FROM complaint_signatures WHERE complaint_id = ?");
        $old->execute([$complaintId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $p = __DIR__ . '/../' . ltrim((string)$o['file_path'], '/');
            if (is_file($p)) { @unlink($p); }
            $pdo->prepare("DELETE FROM complaint_signatures WHERE id = ?")->execute([(int)$o['id']]);
        }

        $name = 'sig_rk_' . $complaintId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($dir . '/' . $name, $bin);

        $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $pdo->prepare("INSERT INTO complaint_signatures (complaint_id, file_path, requested_by) VALUES (?, ?, ?)")
            ->execute([$complaintId, 'uploads/signatures/' . $name, $by !== '' ? mb_substr($by, 0, 100) : null]);

        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId > 0) {
            try {
                $pdo->prepare("UPDATE signature_requests SET status = 'done' WHERE id = ? AND complaint_id = ?")->execute([$reqId, $complaintId]);
            } catch (Throwable $e) { /* podpis je uložen, zbytek best-effort */ }
        }

        crmAuditLog('complaint.signature_add', [
            'entity_type' => 'complaint', 'entity_id' => (int)$complaintId,
            'entity_label' => (string)($complaint['complaint_code'] ?? ('#' . $complaintId)),
            'summary' => 'Uložen podpis klienta k reklamaci ' . (string)($complaint['complaint_code'] ?? ('#' . $complaintId)),
        ]);
        echo json_encode(['ok' => true, 'signed_at' => date('d.m.Y H:i'), 'emailed' => false]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
    }
    exit;
}

try {
    ensureOrderSignaturesTable();

    // nahradit předchozí podpis stejného typu (soubor i záznam)
    $old = $pdo->prepare("SELECT id, file_path FROM order_signatures WHERE order_id = ? AND sig_type = ?");
    $old->execute([$orderId, $sigType]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $p = __DIR__ . '/../' . ltrim((string)$o['file_path'], '/');
        if (is_file($p)) { @unlink($p); }
        $pdo->prepare("DELETE FROM order_signatures WHERE id = ?")->execute([(int)$o['id']]);
    }

    $name = 'sig_' . $orderId . '_' . $sigType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($dir . '/' . $name, $bin);

    $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $pdo->prepare("INSERT INTO order_signatures (order_id, sig_type, file_path, requested_by) VALUES (?, ?, ?, ?)")
        ->execute([$orderId, $sigType, 'uploads/signatures/' . $name, $by !== '' ? mb_substr($by, 0, 100) : null]);

    // požadavek z podpisové stanice → označit vyřízený (+ případný auto e-mail listu)
    $reqId = (int)($_POST['request_id'] ?? 0);
    $emailed = false;
    if ($reqId > 0) {
        try {
            ensureSignatureRequestsTable();
            $rq = $pdo->prepare("SELECT email_after FROM signature_requests WHERE id = ? AND order_id = ? LIMIT 1");
            $rq->execute([$reqId, $orderId]);
            $emailAfter = (int)($rq->fetchColumn() ?: 0) === 1;
            $pdo->prepare("UPDATE signature_requests SET status = 'done' WHERE id = ? AND order_id = ?")->execute([$reqId, $orderId]);
            if ($emailAfter) {
                // zakázkový list odchází UŽ S PODPISEM (print_order podpisy vkládá)
                [$emailed, ] = crmSendOrderSheetEmail($orderId);
            }
        } catch (Throwable $e) { /* podpis je uložen, zbytek je best-effort */ }
    }

    crmAuditLog('order.signature_add', [
        'entity_type' => 'order', 'entity_id' => (int)$orderId,
        'summary' => 'Uložen podpis klienta k zakázce #' . (int)$orderId . ' (' . $sigType . ')' . ($emailed ? ', list odeslán e-mailem' : ''),
    ]);
    echo json_encode(['ok' => true, 'signed_at' => date('d.m.Y H:i'), 'emailed' => $emailed]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
}
