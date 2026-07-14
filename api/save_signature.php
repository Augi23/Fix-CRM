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
$sigType = (string)($_POST['sig_type'] ?? '');
$dataUrl = (string)($_POST['image'] ?? '');

if ($orderId <= 0 || !in_array($sigType, ['prijem', 'vydej'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Chybné parametry']); exit;
}

$st = $pdo->prepare("SELECT id, branch_id, technician_id FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }

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
