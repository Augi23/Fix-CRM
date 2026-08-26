<?php
/**
 * Prodávající z dřívějšího výkupu (v3.63.0).
 *
 * Stálí prodávající chodí opakovaně a přepisovat pokaždé celou identifikaci
 * (rodné číslo, doklad totožnosti, adresa…) je zdržení i zdroj překlepů.
 * Endpoint proto umí najít dřívější výkupní list / zástavní formulář téhož
 * člověka a vrátit z něj údaje o osobě — NIKDY ne údaje o věci a částce,
 * ty musí být u každého výkupu vlastní.
 *
 * GET ?q=<hledaný text>   → seznam osob (jméno, telefon, poslední doklad)
 * GET ?id=<id dokladu>    → údaje o osobě z toho dokladu (zapíše se do auditu)
 *
 * Vrací osobní údaje podle zákona č. 253/2008 Sb., proto jen přihlášené
 * obsluze, která smí doklady vystavovat (účetní ne — stejně jako dokument.php).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/rate_limit.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => __('unauthorized')], JSON_UNESCAPED_UNICODE); exit;
}
if (function_exists('crmIsAccountant') && crmIsAccountant()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Výkupní doklady nejsou pro roli účetní.'], JSON_UNESCAPED_UNICODE); exit;
}
checkApiRateLimit('doc_seller_lookup', 120, 3600);
ensureCrmDocumentsTable();

/** Které údaje se smí přenést: jen o OSOBĚ, nikdy o vykupované věci. */
function afxSellerReusableFields(): array {
    $out = [];
    foreach (crmDocTypes() as $cfg) {
        foreach (($cfg['sections'] ?? []) as $sec) {
            foreach (($sec['fields'] ?? []) as $f) {
                $n = (string)($f['n'] ?? '');
                if ($n !== '' && str_starts_with($n, 'customer_')) { $out[$n] = true; }
            }
        }
    }
    // ověření totožnosti dělá KONKRÉTNÍ pracovník při KONKRÉTNÍM výkupu —
    // opsat cizí podpis pod ověření by bylo v rozporu s § 8 zák. 253/2008 Sb.
    unset($out['customer_id_verified']);
    return array_keys($out);
}

$docId = (int)($_GET['id'] ?? 0);
if ($docId > 0) {
    $doc = crmGetDocument($docId);
    if (!$doc || !in_array((string)$doc['doc_type'], ['vykup', 'zastava'], true)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Doklad nenalezen.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $fields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];
    $out = [];
    foreach (afxSellerReusableFields() as $name) {
        $v = trim((string)($fields[$name] ?? ''));
        if ($v !== '') { $out[$name] = $v; }
    }
    // čtení identifikačních údajů se zaznamenává — jde o AML agendu
    if (function_exists('crmAuditLog')) {
        crmAuditLog('document.seller_reuse', [
            'entity_type' => 'crm_document', 'entity_id' => $docId,
            'entity_label' => (string)$doc['doc_number'],
            'summary' => 'Převzaty údaje prodávajícího z dokladu ' . $doc['doc_number']
                . ' (' . (string)($doc['customer_name'] ?? '') . ') do nového výkupního listu',
        ]);
    }
    echo json_encode([
        'ok' => true,
        'doc_number' => (string)$doc['doc_number'],
        'doc_date' => (string)($doc['doc_date'] ?? ''),
        'fields' => $out,
        'count' => count($out),
    ], JSON_UNESCAPED_UNICODE); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'people' => []], JSON_UNESCAPED_UNICODE); exit;
}
$like = '%' . $q . '%';
// telefon se píše různě (mezery, +420) — porovnávat i jen podle číslic
$digits = preg_replace('/\D+/', '', $q) ?? '';
$digitsLike = $digits !== '' && strlen($digits) >= 6 ? '%' . $digits . '%' : null;

try {
    $sql = "SELECT id, doc_type, doc_number, doc_date, customer_name, customer_phone, customer_email
            FROM crm_documents
            WHERE doc_type IN ('vykup','zastava')
              AND COALESCE(customer_name, '') <> ''
              AND (customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ?";
    $par = [$like, $like, $like];
    if ($digitsLike !== null) {
        $sql .= " OR REPLACE(REPLACE(REPLACE(COALESCE(customer_phone,''), ' ', ''), '+', ''), '-', '') LIKE ?";
        $par[] = $digitsLike;
    }
    $sql .= ") ORDER BY id DESC LIMIT 60";
    $st = $pdo->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('doc_seller_lookup: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Hledání selhalo.'], JSON_UNESCAPED_UNICODE); exit;
}

// sloučit doklady téže osoby (jméno + telefon) — nabídne se nejnovější
$people = [];
foreach ($rows as $r) {
    $key = mb_strtolower(trim((string)$r['customer_name']) . '|'
        . preg_replace('/\D+/', '', (string)($r['customer_phone'] ?? '')), 'UTF-8');
    if (!isset($people[$key])) {
        $people[$key] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['customer_name'],
            'phone' => (string)($r['customer_phone'] ?? ''),
            'email' => (string)($r['customer_email'] ?? ''),
            'doc_number' => (string)$r['doc_number'],
            'doc_type' => (string)$r['doc_type'],
            'doc_date' => (string)($r['doc_date'] ?? ''),
            'count' => 0,
        ];
    }
    $people[$key]['count']++;
}
echo json_encode(['ok' => true, 'people' => array_slice(array_values($people), 0, 8)], JSON_UNESCAPED_UNICODE);
