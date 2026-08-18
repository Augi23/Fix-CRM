<?php
/* Odeslání kopie dokumentu e-mailem na adresu uvedenou V dokumentu
   (pole customer_email). POST: id. Vrací {ok, to}. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sde_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { sde_fail('Nepřihlášeno', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sde_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { sde_fail('Neplatný token', 419); }

$doc = crmGetDocument((int)($_POST['id'] ?? 0));
if (!$doc) { sde_fail('Dokument nenalezen', 404); }

$to = trim((string)($doc['customer_email'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    sde_fail('V dokumentu není vyplněný platný e-mail klienta.');
}

$cfg = crmDocTypes()[(string)$doc['doc_type']] ?? null;
if (!$cfg) { sde_fail('Neznámý typ dokumentu'); }

$lang = crmDocLangOrDefault($doc['lang'] ?? 'cs');
$company = get_setting('company_name', 'AppleFix');
$subject = $company . ' — ' . __($cfg['title_key'], $lang) . ' ' . (string)$doc['doc_number'];
$html = crmRenderDocumentEmailHtml($doc);

// Výkupní list navíc nese tlačítko „Vyplnit online": klient si doma doplní své
// údaje a sériové číslo — odesláním se dokument uloží do CRM a vykoupený produkt
// se založí/doplní sám (jako by list vyplnila prodejna). Cena zůstává zamčená.
if ((string)$doc['doc_type'] === 'vykup') {
    $onlineUrl = 'https://admin.applefix.cloud/vykup_online.php?t=' . rawurlencode(crmDocPublicToken((int)$doc['id']));
    $fillBlock = '<div style="max-width:840px;margin:18px auto 0;background:#fff;border:1px solid #e8ebf0;border-radius:14px;padding:20px 22px;text-align:center;font-family:-apple-system,Segoe UI,Arial,sans-serif;">'
        . '<div style="font-size:14px;color:#1d1d1f;padding-bottom:14px;"><strong>Chybí ve formuláři některé údaje?</strong><br>'
        . 'Vyplňte je pohodlně online — po odeslání se rovnou propíšou k nám do systému.</div>'
        . '<a href="' . e($onlineUrl) . '" style="display:inline-block;background:#0b57d0;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 30px;border-radius:12px;">✍️ Vyplnit výkupní list online</a>'
        . '<div style="font-size:11px;color:#86868b;padding-top:12px;">Odkaz je určen jen vám — prosíme, nepřeposílejte ho.</div>'
        . '</div>';
    $html = (stripos($html, '</body>') !== false)
        ? str_ireplace('</body>', $fillBlock . '</body>', $html)
        : $html . $fillBlock;
}

if (!function_exists('smtpSendMail')) { sde_fail('Odesílání e-mailů není nastaveno.'); }
[$ok, $err] = smtpSendMail($to, $subject, $html);
if (!$ok) { sde_fail('E-mail se nepodařilo odeslat: ' . $err, 500); }

crmAuditLog('document.email', [
    'entity_type' => 'document', 'entity_id' => (int)$doc['id'], 'entity_label' => (string)$doc['doc_number'],
    'summary' => 'Dokument ' . $doc['doc_number'] . ' odeslán e-mailem na ' . $to,
]);
echo json_encode(['ok' => true, 'to' => $to], JSON_UNESCAPED_UNICODE);
