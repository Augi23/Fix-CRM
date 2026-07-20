<?php
/** Testovací SMS (jen admin) — ověření nastavení GoSMS z Integrací. */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !crmCanManageSettings()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Jen administrátor.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$phone = trim((string)($_POST['phone'] ?? ''));
if ($phone === '') { echo json_encode(['success' => false, 'message' => 'Zadej telefonní číslo.']); exit; }

[$ok, $err] = crmSendSms($phone, 'AppleFix CRM: testovaci SMS - nastaveni GoSMS funguje.');
if ($ok) {
    crmAuditLog('sms.sent', ['entity_type' => 'system', 'summary' => 'Testovací SMS na ' . (crmSmsNormalizePhone($phone) ?: $phone)]);
}
echo json_encode(['success' => $ok, 'message' => $ok ? 'Odesláno' : $err], JSON_UNESCAPED_UNICODE);
