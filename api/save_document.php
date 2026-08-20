<?php
/* Uložení dokumentu (výkupní list / zástavní formulář).
   POST: type, id (0 = nový), lang, fields (JSON všech polí formuláře).
   Vrací {ok, id, doc_number}. Číslo dokumentu se přiděluje při prvním uložení. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sd_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { sd_fail('Nepřihlášeno', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sd_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { sd_fail('Neplatný token', 419); }

$type = (string)($_POST['type'] ?? '');
$cfg = crmDocTypes()[$type] ?? null;
if (!$cfg) { sd_fail('Neznámý typ dokumentu'); }

$id = (int)($_POST['id'] ?? 0);
$lang = crmDocLangOrDefault($_POST['lang'] ?? 'cs');

$fields = json_decode((string)($_POST['fields'] ?? ''), true);
if (!is_array($fields)) { sd_fail('Chybná data formuláře'); }
// jen řetězce, rozumné délky (obrana proti nesmyslům v payloadu)
$clean = [];
foreach ($fields as $k => $v) {
    if (!is_string($k) || !preg_match('/^[a-z0-9_]{1,60}$/', $k)) continue;
    $clean[$k] = mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, 4000);
}

// sloupce pro seznam
$name  = mb_substr((string)($clean['customer_name'] ?? ''), 0, 190);
$phone = mb_substr((string)($clean['customer_phone'] ?? ''), 0, 60);
$email = mb_substr((string)($clean['customer_email'] ?? ''), 0, 190);
$subject = '';
foreach ($cfg['subject_fields'] as $sf) {
    $sv = trim((string)($clean[$sf] ?? ''));
    if ($sv !== '') { $subject = trim($subject . ($subject !== '' ? ' — ' : '') . $sv); }
}
$subject = mb_substr($subject, 0, 255);
$price = mb_substr((string)($clean[$cfg['price_field']] ?? ''), 0, 60);

// Výkupní částka je POVINNÁ (přání majitele 20.8.2026): list bez částky založí
// produkt bez nákupní ceny a kasa by nabídla výplatu 0 Kč.
if ($type === 'vykup') {
    $__amt = function_exists('crmParseAmountCzk') ? crmParseAmountCzk($price) : (float)$price;
    if ($__amt <= 0) { sd_fail('Vyplň výkupní částku — bez ní nejde výkupní list uložit.'); }
}

// datum dokumentu (z pole doc_date d.m.Y, jinak dnešek)
$docDate = date('Y-m-d');
if (!empty($clean['doc_date'])) {
    $ts = strtotime(str_replace(' ', '', (string)$clean['doc_date']));
    if ($ts) { $docDate = date('Y-m-d', $ts); }
}

ensureCrmDocumentsTable();
$payload = json_encode($clean, JSON_UNESCAPED_UNICODE);
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$branch = (int)getCurrentStaffBranchId() ?: null;

try {
    if ($id > 0) {
        $st = $pdo->prepare("SELECT id, doc_number, doc_type FROM crm_documents WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { sd_fail('Dokument nenalezen', 404); }
        if ((string)$existing['doc_type'] !== $type) { sd_fail('Typ dokumentu nesouhlasí'); }
        $pdo->prepare("UPDATE crm_documents SET doc_date=?, customer_name=?, customer_phone=?, customer_email=?, subject=?, price=?, lang=?, payload=? WHERE id=?")
            ->execute([$docDate, $name, $phone, $email, $subject, $price, $lang, $payload, $id]);
        $docNumber = (string)$existing['doc_number'];
        crmAuditLog('document.update', [
            'entity_type' => 'document', 'entity_id' => $id, 'entity_label' => $docNumber,
            'summary' => 'Upraven dokument ' . $docNumber . ($name !== '' ? ' — ' . $name : ''),
            'branch_id' => $branch,
        ]);
    } else {
        // číslo přidělit atomicky (unikátní klíč doc_type+doc_number, při kolizi zkusit znovu)
        $docNumber = '';
        for ($try = 0; $try < 3; $try++) {
            $docNumber = crmNextDocNumber($type);
            try {
                $pdo->prepare("INSERT INTO crm_documents (doc_type, doc_number, doc_date, customer_name, customer_phone, customer_email, subject, price, lang, payload, created_by, branch_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$type, $docNumber, $docDate, $name, $phone, $email, $subject, $price, $lang, $payload, $by !== '' ? mb_substr($by, 0, 100) : null, $branch]);
                $id = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $dup = ($e->getCode() === '23000');
                if (!$dup || $try === 2) { throw $e; }
            }
        }
        crmAuditLog('document.create', [
            'entity_type' => 'document', 'entity_id' => $id, 'entity_label' => $docNumber,
            'summary' => 'Vytvořen dokument ' . $docNumber . ($name !== '' ? ' — ' . $name : ''),
            'branch_id' => $branch,
        ]);
    }
    // Výkup hotově = výdej z kasy (pokladní deník) — drží se v souladu s dokumentem.
    if ($type === 'vykup') {
        // vykoupený kus rovnou do skladu (kategorie Výkupy) — kasa je až za tím
        try { crmSyncVykupProduct($id); }
        catch (Throwable $e) { error_log('save_document vykup product: ' . $e->getMessage()); }
        try { crmSyncVykupCashMovement($id); }
        catch (AfxAccountingClosedException $e) {
            // UZÁVĚRKA se nesmí tiše spolknout: klient by dostal peníze na ruku,
            // ale kniha by o výdeji nevěděla (rozdíl by se našel až při inventuře).
            // Dokument je v tuhle chvíli už uložený — proto se vrací CHYBA s jasnou
            // hláškou, ať obsluha ví, že hotovost teď vyplatit nesmí.
            sd_fail('Dokument je uložený, ale hotovost NEVYPLÁCEJ: ' . $e->getMessage(), 423);
        }
        catch (Throwable $e) { error_log('save_document kasa: ' . $e->getMessage()); /* kasa je bonus */ }
    }

    echo json_encode(['ok' => true, 'id' => $id, 'doc_number' => $docNumber], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('save_document: ' . $e->getMessage());
    sd_fail('Chyba serveru', 500);
}
