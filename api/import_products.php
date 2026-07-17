<?php
/**
 * Import produktů pro e-shop ze souboru naskladňovací Mac appky (Upgates CSV).
 * Upsert podle [PRODUCT_CODE] (sériové číslo / IMEI / AFX-…) → opakované nahrání
 * stejného souboru je bezpečné. NIKDY nemaže — řádky chybějící v souboru se jen
 * označí (last_seen_at) a stránka je zvýrazní.
 *
 * Sloupce se mapují podle NÁZVU hlavičky, ne podle pořadí — v oběhu jsou starší
 * varianty souboru s méně sloupci a appka přidává nové sloupce na konec.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {
    echo json_encode(['success' => false, 'message' => 'Import produktů smí jen vedení (admin, Boss, manažer).']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Neplatný požadavek.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Nepřišel žádný soubor.']); exit;
}
if ((int)$_FILES['file']['size'] > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Soubor je podezřele velký (přes 8 MB) — tohle není tabulka z appky.']); exit;
}

$data = (string)file_get_contents($_FILES['file']['tmp_name']);
// BOM: appka ukládá utf-8, ale čte utf-8-sig — soubor může mít obě podoby
if (str_starts_with($data, "\xEF\xBB\xBF")) { $data = substr($data, 3); }
if (trim($data) === '') {
    echo json_encode(['success' => false, 'message' => 'Soubor je prázdný. Pozor na Google Drive — soubor musí být stažený, ne jen zástupce v cloudu.']); exit;
}

// oddělovač: appka píše středník (Upgates), starší exporty můžou mít čárku
$firstLine = strtok($data, "\r\n") ?: '';
$delim = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

$stream = fopen('php://temp', 'r+');
fwrite($stream, $data);
rewind($stream);

// escape '' = RFC-4180 chování (uvozovky se zdvojují) — appka píše Python csv.writerem,
// backslash NEescapuje; s výchozím '\\' by pole končící lomítkem slilo dva sloupce
$header = fgetcsv($stream, 0, $delim, '"', '');
if (!is_array($header) || count($header) < 3) {
    echo json_encode(['success' => false, 'message' => 'Soubor nemá hlavičku — nevypadá jako tabulka z naskladňovací appky.']); exit;
}
$header = array_map(static fn($h) => trim((string)$h), $header);
$idx = [];
foreach ($header as $i => $name) { if ($name !== '' && !isset($idx[$name])) { $idx[$name] = $i; } }

if (!isset($idx['[PRODUCT_CODE]']) || !isset($idx['[TITLE]'])) {
    echo json_encode(['success' => false, 'message' => 'V hlavičce chybí [PRODUCT_CODE] nebo [TITLE] — tohle není soubor z naskladňovací appky.']); exit;
}

$col = static function (array $row, string $name) use ($idx): string {
    if (!isset($idx[$name])) return '';
    return trim((string)($row[$idx[$name]] ?? ''));
};
$parsePrice = static function (string $v): float {
    $v = str_replace([' ', "\xc2\xa0"], '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
};

ensureProductsTable();   // DDL před transakcí (implicitní commit)
ensureProductsPosColumn();
ensureProductsCrmColumns();

// čas začátku VŽDY z hodin databáze — last_seen_at plní NOW() z MySQL a kdyby
// PHP a MySQL běžely v jiném pásmu, stale-detekce by označila i čerstvé řádky
$startedAt = (string)$pdo->query("SELECT NOW()")->fetchColumn();
$created = 0; $updated = 0; $skipped = 0; $rows = 0; $crmOwned = 0;

// Aktualizovat JEN sloupce, jejichž hlavička v nahraném souboru opravdu je.
// V oběhu jsou starší, užší varianty souboru — kdyby se updatovalo vše,
// starý soubor bez [IMAGES]/[STOCK_STOCK]/PCR by tiše smazal fotky,
// pobočky a PCR výsledky u všech dřív naimportovaných produktů.
$fields = [
    'product_code' => static fn(array $row) => mb_substr($col($row, '[PRODUCT_CODE]'), 0, 64),
    'title'        => static fn(array $row) => mb_substr($col($row, '[TITLE]'), 0, 255),
];
$optional = [
    'manufacturer'  => ['[MANUFACTURER]', 64],
    'category_code' => ['[CATEGORIES]', 64],
    'model'         => ['[PARAMETER "Model"]', 128],
    'capacity'      => ['[PARAMETER "Kapacita"]', 32],
    'color'         => ['[PARAMETER "Barva"]', 64],
    'grade'         => ['[PARAMETER "Stav"]', 16],
    'battery'       => ['[PARAMETER "Baterie"]', 16],
    'image_url'     => ['[IMAGES]', 500],
    'pcr_result'    => ['PCR_VYSLEDEK', 255],
];
foreach ($optional as $field => [$hdr, $len]) {
    if (isset($idx[$hdr])) {
        $fields[$field] = static fn(array $row) => mb_substr($col($row, $hdr), 0, $len) ?: null;
    }
}
if (isset($idx['[PRICE_ORIGINAL "Výchozí"]'])) {
    $fields['price'] = static fn(array $row) => $parsePrice($col($row, '[PRICE_ORIGINAL "Výchozí"]'));
}
if (isset($idx['[STOCK]'])) {
    $fields['stock_qty'] = static fn(array $row) => (int)$col($row, '[STOCK]');
}
if (isset($idx['[STOCK_STOCK "karlin"]']) || isset($idx['[STOCK_STOCK "vaclavak"]'])) {
    $fields['stock_key'] = static function (array $row) use ($col): string {
        if ($col($row, '[STOCK_STOCK "karlin"]') !== '') return 'karlin';
        if ($col($row, '[STOCK_STOCK "vaclavak"]') !== '') return 'vaclavak';
        return '';
    };
}
if (isset($idx['PRIDANO'])) {
    $fields['added_at'] = static function (array $row) use ($col): ?string {
        $v = $col($row, 'PRIDANO');
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    };
}
// celý řádek uchovat pro budoucí e-shop (krátký popis, DPH, RAM, ročník…)
$fields['raw_csv'] = static function (array $row) use ($idx): string {
    $assoc = [];
    foreach ($idx as $name => $i) { $assoc[$name] = trim((string)($row[$i] ?? '')); }
    return (string)json_encode($assoc, JSON_UNESCAPED_UNICODE);
};

$colNames = array_keys($fields);   // pevný seznam výše — do SQL nejde nic ze souboru
$updateParts = [];
foreach ($colNames as $c) {
    if ($c === 'product_code') continue;
    $updateParts[] = $c === 'added_at'
        ? "added_at = COALESCE(VALUES(added_at), added_at)"
        : "$c = VALUES($c)";
}
$updateParts[] = "last_seen_at = NOW()";

try {
    $up = $pdo->prepare("INSERT INTO products (" . implode(', ', $colNames) . ", last_seen_at)
        VALUES (" . implode(', ', array_fill(0, count($colNames), '?')) . ", NOW())
        ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts));

    // kusy založené/převzaté PŘÍMO v CRM (source='crm') import appky NEPŘEPISUJE —
    // CRM je pro ně autorita; soubor appky je může mít zastarale
    $crmCheck = $pdo->prepare("SELECT source FROM products WHERE product_code = ?");

    $pdo->beginTransaction();

    while (($row = fgetcsv($stream, 0, $delim, '"', '')) !== false) {
        if ($row === [null] || $row === ['']) continue;   // prázdný řádek
        $rows++;
        if ($rows > 20000) { throw new Exception('Soubor má přes 20 000 řádků — to nevypadá jako tabulka z appky.'); }

        if ($col($row, '[TITLE]') === '' || $col($row, '[PRODUCT_CODE]') === '') { $skipped++; continue; }   // bez názvu/kódu nejde upsertovat

        $crmCheck->execute([mb_substr($col($row, '[PRODUCT_CODE]'), 0, 64)]);
        if ((string)$crmCheck->fetchColumn() === 'crm') { $crmOwned++; continue; }

        $values = [];
        foreach ($fields as $fn) { $values[] = $fn($row); }
        $up->execute($values);
        $rc = $up->rowCount();
        if ($rc === 1) { $created++; } else { $updated++; }
    }

    if ($created + $updated + $crmOwned === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'V souboru nebyl žádný použitelný řádek (' . $rows . ' řádků, ' . $skipped . ' přeskočeno). Je to správný soubor?']); exit;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('import_products: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Import selhal: ' . $e->getMessage()]); exit;
}

// ── sladění s kasou ──
// 1) soubor sám hlásí prodáno (stock 0) → stav je srovnaný, flag kasy pryč.
//    Mazat smí JEN flagy potvrzené TÍMTO souborem: řádek v něm byl (last_seen_at
//    aktualizován) a soubor vůbec nese sloupec [STOCK]. Podmínka pos_sold_at < start
//    chrání před závodem: prodej běžící souběžně s importem má čerstvý flag a nesmí
//    o něj přijít — jeho stock 0 neřekl soubor, ale kasa.
// 2) kasa kus prodala (celý → flag), ale soubor ho stále hlásí skladem → držet na
//    nule + nahlásit (jinak by denní import „oživil" prodaný kus na e-shopu).
$posConflict = 0;
try {
    if (isset($idx['[STOCK]'])) {
        $pdo->prepare("UPDATE products SET pos_sold_at = NULL
            WHERE pos_sold_at IS NOT NULL AND stock_qty <= 0 AND last_seen_at >= ? AND pos_sold_at < ?")
            ->execute([$startedAt, $startedAt]);
    }
    $posConflict = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE pos_sold_at IS NOT NULL AND stock_qty > 0")->fetchColumn();
    if ($posConflict > 0) {
        $pdo->exec("UPDATE products SET stock_qty = 0 WHERE pos_sold_at IS NOT NULL AND stock_qty > 0");
    }
} catch (Throwable $e) {}

// řádky v DB, které v tomhle souboru nebyly (nemažou se — jen se zvýrazní na stránce)
$stale = 0;
try {
    // source='crm' kusy v souboru appky záměrně nejsou — do „stale" se nepočítají
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE last_seen_at < ? AND source != 'crm'");
    $st->execute([$startedAt]);
    $stale = (int)$st->fetchColumn();
    set_setting('products_last_import_at', $startedAt);
} catch (Throwable $e) {}

crmAuditLog('products.import', [
    'entity_type' => 'products', 'entity_label' => (string)($_FILES['file']['name'] ?? 'CSV'),
    'summary' => 'Import produktů pro e-shop: ' . $created . ' nových, ' . $updated . ' aktualizovaných'
        . ($skipped > 0 ? ', ' . $skipped . ' přeskočeno' : '')
        . ($stale > 0 ? ', ' . $stale . ' v DB chybí v souboru' : ''),
]);

$msg = 'Hotovo: ' . $created . ' nových, ' . $updated . ' aktualizovaných';
if ($skipped > 0) { $msg .= ', ' . $skipped . ' přeskočeno (bez názvu/kódu)'; }
if ($stale > 0) { $msg .= '. ' . $stale . ' dřívějších produktů v souboru nebylo — v seznamu dostanou štítek.'; }
if ($posConflict > 0) { $msg .= ' ' . $posConflict . ' produktů prodaných na kase soubor ještě hlásí skladem — CRM je automaticky drží jako vyprodané.'; }
if ($crmOwned > 0) { $msg .= ' ' . $crmOwned . ' kusů přeskočeno — spravují se přímo v CRM (naskladněno/upraveno tady).'; }
echo json_encode(['success' => true, 'message' => $msg, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'stale' => $stale, 'pos_conflict' => $posConflict, 'crm_owned' => $crmOwned]);
