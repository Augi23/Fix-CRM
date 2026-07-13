<?php
/* Odešle zakázkový list klientovi e-mailem (SMTP z Nastavení → Integrace → E-mail).
   Dokument = print_order.php vykreslený do HTML (ORDER_DOC_EMBED). */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}

$order_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($order_id <= 0) { echo json_encode(['ok' => false, 'error' => 'Chybí zakázka']); exit; }

$to = trim((string)($_POST['email'] ?? ''));
[$ok, $err] = crmSendOrderSheetEmail($order_id, $to !== '' ? $to : null);
if ($ok) {
    echo json_encode(['ok' => true, 'to' => $to]);
} else {
    echo json_encode(['ok' => false, 'error' => $err]);
}
