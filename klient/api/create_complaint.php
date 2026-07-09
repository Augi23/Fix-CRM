<?php
/* Založení reklamace z klientské sekce.
   - jen přihlášený zákazník (client session) + CSRF
   - jen k VLASTNÍ zakázce, která je již vydaná (skupina 'collected')
   - propíše se do CRM (tabulka complaints, source='client', napojeno na zakázku)
   - pošle upozornění na info@applefix.cz */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function cc_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!clientIsLoggedIn()) cc_fail('Nejste přihlášeni.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cc_fail('Neplatný požadavek.', 405);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) cc_fail('Neplatný bezpečnostní token. Obnovte stránku.', 419);

$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
$orderId    = (int)($_POST['order_id'] ?? 0);
$reason     = trim((string)($_POST['reason'] ?? ''));
$resolution = trim((string)($_POST['resolution'] ?? ''));

if ($customerId <= 0) cc_fail('Neznámý zákazník.', 403);
if ($orderId <= 0)    cc_fail('Chybí zakázka.');
if ($reason === '')   cc_fail('Popište prosím, co reklamujete.');

if (!isset($pdo)) cc_fail('Databáze není dostupná.', 500);

/* --- zakázka musí patřit zákazníkovi a být vydaná --- */
$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.email
                       FROM orders o JOIN customers c ON c.id = o.customer_id
                       WHERE o.id = ? AND o.customer_id = ? LIMIT 1");
$stmt->execute([$orderId, $customerId]);
$order = $stmt->fetch();
if (!$order) cc_fail('Zakázka nenalezena.', 404);

if (!isOrderStatusIn((string)$order['status'], 'collected')) {
    cc_fail('Reklamovat lze až dokončenou a vydanou opravu.');
}

ensureComplaintsClientColumns($pdo);

$orderCode = trim((string)($order['order_code'] ?? '')) !== '' ? (string)$order['order_code'] : ('#' . $orderId);
$device    = trim(((string)($order['device_brand'] ?? '')) . ' ' . ((string)($order['device_model'] ?? '')));
$serial    = (string)($order['serial_number'] ?? '');
$phone     = (string)($order['phone'] ?? '');
$custName  = trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? '')));

/* --- už existuje otevřená klientská reklamace k této zakázce? --- */
try {
    $chk = $pdo->prepare("SELECT complaint_code FROM complaints
                          WHERE order_id = ? AND source = 'client' AND staff_ack_at IS NULL
                          ORDER BY id DESC LIMIT 1");
    $chk->execute([$orderId]);
    if ($existing = $chk->fetchColumn()) {
        echo json_encode(['ok' => true, 'code' => $existing, 'already' => true,
            'message' => 'Reklamaci k této zakázce už evidujeme.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) { /* pokračuj */ }

/* --- vygeneruj kód RK-NNN a založ --- */
try {
    $pdo->beginTransaction();

    $max  = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(complaint_code,'-',-1) AS UNSIGNED)),0) FROM complaints")->fetchColumn();
    $code = sprintf('RK-%03d', $max + 1);

    $fullReason = $reason;
    $meta = [];
    if ($resolution !== '') $meta[] = 'Požadavek: ' . $resolution;
    $meta[] = 'Doklad/zakázka: ' . $orderCode;
    $meta[] = 'Založeno klientem z portálu';
    $fullReason .= "\n" . implode(' · ', $meta);

    $ins = $pdo->prepare("INSERT INTO complaints
        (complaint_code, customer_id, order_id, order_code, phone, device, serial_number, complaint_reason, complaint_status, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Přijato', 'client')");
    $ins->execute([$code, $customerId, $orderId, $orderCode, $phone, $device, $serial, $fullReason]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cc_fail('Reklamaci se nepodařilo uložit. Zkuste to prosím znovu.', 500);
}

/* --- upozornění na e-mail (best-effort, neblokuje výsledek) --- */
try {
    $notify = trim((string) get_setting('complaint_notify_email', 'info@applefix.cz')) ?: 'info@applefix.cz';
    $company = get_setting('company_name', 'AppleFix');
    $subject = $company . ' — nová reklamace z klientské sekce ' . $code . ' (' . $orderCode . ')';
    $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;color:#1d1d1f">'
        . '<h2 style="margin:0 0 10px">Nová reklamace z klientské sekce</h2>'
        . '<p style="margin:0 0 4px"><strong>Kód:</strong> ' . e($code) . '</p>'
        . '<p style="margin:0 0 4px"><strong>Zakázka:</strong> ' . e($orderCode) . '</p>'
        . '<p style="margin:0 0 4px"><strong>Zákazník:</strong> ' . e($custName) . ' · ' . e($phone) . '</p>'
        . '<p style="margin:0 0 4px"><strong>Zařízení:</strong> ' . e($device) . ($serial !== '' ? ' · SN ' . e($serial) : '') . '</p>'
        . '<p style="margin:10px 0 4px"><strong>Popis:</strong></p>'
        . '<div style="white-space:pre-wrap;border:1px solid #e5e7eb;border-radius:8px;padding:10px">' . nl2br(e($fullReason)) . '</div>'
        . '<p style="margin:14px 0 0;color:#6b7280;font-size:12px">Reklamace je v CRM přiřazena nahoře v sekci Reklamace, dokud se jí někdo ze servisu neujme.</p>'
        . '</div>';
    if (function_exists('smtpSendMail')) { smtpSendMail($notify, $subject, $html); }
} catch (Throwable $e) { /* mail selhal — reklamace i tak založena */ }

echo json_encode(['ok' => true, 'code' => $code,
    'message' => 'Reklamace byla odeslána. Ozveme se vám co nejdříve.'], JSON_UNESCAPED_UNICODE);
