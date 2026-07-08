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

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email
                       FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();
if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }

// e-mail klienta: z profilu, nebo lze poslat na jiný zadaný
$to = trim((string)($_POST['email'] ?? $order['email'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Klient nemá platný e-mail. Doplňte ho v profilu klienta nebo v zakázce.']); exit;
}

// položky (parts)
$st = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
$st->execute([$order_id]);
$items = $st->fetchAll();

// vykreslit dokument do HTML
$target_lang = 'cs';
$__EMAIL_MODE = true;
define('ORDER_DOC_EMBED', true);
ob_start();
include __DIR__ . '/../print_order.php';
$html = ob_get_clean();

$code = orderDisplayCode($order);
$subject = (get_setting('company_name', 'AppleFix')) . ' — zakázkový list ' . $code;

[$ok, $err] = smtpSendMail($to, $subject, $html);
if ($ok) {
    echo json_encode(['ok' => true, 'to' => $to]);
} else {
    echo json_encode(['ok' => false, 'error' => $err]);
}
