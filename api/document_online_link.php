<?php
/* Odkaz pro online vyplnění výkupního listu klientem (vykup_online.php?t=…).
   Vrací ho obsluze do schránky/SMS/WhatsAppu — e-mail ho přikládá sám.
   POST: id, csrf_token → {ok, url}. Jen výkupní listy. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dol_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { dol_fail('Nepřihlášeno', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { dol_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { dol_fail('Neplatný token', 419); }

$doc = crmGetDocument((int)($_POST['id'] ?? 0));
if (!$doc) { dol_fail('Dokument nenalezen', 404); }
if ((string)$doc['doc_type'] !== 'vykup') { dol_fail('Online vyplnění je jen pro výkupní listy.'); }

$url = 'https://admin.applefix.cloud/vykup_online.php?t=' . rawurlencode(crmDocPublicToken((int)$doc['id']));
echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_UNICODE);
