<?php
/* Odeslání faktury e-mailem (z Pokladny i odjinud). Faktura v mailu nese blok
   „Platba převodem" s číslem účtu, VS, splatností a QR platbou (SPAYD) — klient
   zaplatí online z telefonu; párování s účtem zatím kontroluje majitel ručně.

   POST JSON { csrf_token, invoice_id, to? }
     → {ok, to}  |  {ok:false, error, need_email?:1}
   need_email=1 = klient nemá uložený e-mail → UI si adresu vyžádá a pošle znovu. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}

$invoiceId = (int)($in['invoice_id'] ?? 0);
if ($invoiceId <= 0) { echo json_encode(['ok' => false, 'error' => 'Chybí faktura.']); exit; }

$to = trim((string)($in['to'] ?? ''));
if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Zadaný e-mail nemá platný tvar.']); exit;
}

[$ok, $msg, $sentTo] = crmSendInvoiceEmail($invoiceId, $to !== '' ? $to : null);
if (!$ok) {
    // „klient nemá e-mail" → řízený dotaz v UI místo mrtvé chyby (přesná shoda
    // s hláškou crmSendInvoiceEmail — SMTP chyby dotaz vyvolat nesmí)
    $needEmail = $to === '' && mb_stripos((string)$msg, 'nemá platný e-mail') !== false;
    echo json_encode(['ok' => false, 'error' => (string)$msg, 'need_email' => $needEmail ? 1 : 0], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['ok' => true, 'to' => $sentTo], JSON_UNESCAPED_UNICODE);
