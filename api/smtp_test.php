<?php
/** Testovací e-mail (jen admin) — ověření SMTP z Nastavení → Integrace.
 *  Bez tohohle šlo nastavení ověřit jen „naostro" na skutečné zakázce,
 *  a automatické maily (připraveno k vyzvednutí, poděkování, e-shop,
 *  reklamace z portálu) při špatném SMTP mlčky vypadly. */
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

$to = trim((string)($_POST['email'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => 'Zadej platnou e-mailovou adresu.']); exit; }

$company = get_setting('company_name', 'AppleFix');
$html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;color:#1d1d1f">'
    . '<h2 style="margin:0 0 10px">Testovací e-mail z CRM</h2>'
    . '<p>Nastavení SMTP v CRM <strong>' . e($company) . '</strong> funguje — tento e-mail odešel ' . date('d.m.Y H:i') . '.</p>'
    . '<p style="color:#6b7280;font-size:12px">Automatické e-maily klientům (připraveno k vyzvednutí, poděkování po vydání) i potvrzení z e-shopu odcházejí stejnou cestou.</p>'
    . '</div>';
[$ok, $err] = smtpSendMail($to, $company . ' — testovací e-mail z CRM', $html);
crmMailAudit('testovací e-mail z Nastavení', (bool)$ok, $err, ['entity_type' => 'system', 'to' => $to]);
echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Odesláno' : (string)$err], JSON_UNESCAPED_UNICODE);
