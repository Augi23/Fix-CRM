<?php
/**
 * Naskladnění/úprava produktu PŘÍMO v CRM (náhrada Mac appky).
 * Akce: create | update | get. Server je autorita: title/short_desc/raw_csv
 * skládá VŽDY sám (includes/product_catalog.php — 1:1 port appky, ověřeno
 * paritním testem), PČR kontrolu provádí sám (UI badge je jen orientační).
 * Řádky vzniklé tady mají source='crm' — import CSV z appky je přeskakuje.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/product_catalog.php';
require_once '../includes/pcr.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {
    echo json_encode(['success' => false, 'message' => 'Naskladňovat produkty smí jen vedení (admin, Boss, manažer).']); exit;
}

$action = (string)($_REQUEST['action'] ?? 'create');

/** Runtime pojistka ke sloupcům z migrace 040 (daňový režim + nákupní cena).
 *  Deploy nasadí kód dřív, než doběhne run_migrations.php — bez ní by naskladnění
 *  produktu po nasazení spadlo na neznámý sloupec purchase_price.
 *  Stejná definice je i v api/pos_checkout.php (obě jsou samostatné vstupní body);
 *  function_exists hlídá, aby se nedeklarovala dvakrát, kdyby se soubory potkaly. */
if (!function_exists('afxEnsurePosGoodsTaxColumns')) {
    function afxEnsurePosGoodsTaxColumns(): void {
        global $pdo;
        static $done = false;
        if ($done || !isset($pdo)) return;
        $done = true;
        try {
            $add = [
                ['pos_sale_items', 'grade', "ALTER TABLE pos_sale_items ADD COLUMN grade VARCHAR(16) NULL DEFAULT NULL"],
                ['pos_sale_items', 'purchase_price', "ALTER TABLE pos_sale_items ADD COLUMN purchase_price DECIMAL(12,2) NULL DEFAULT NULL"],
                ['products', 'purchase_price', "ALTER TABLE products ADD COLUMN purchase_price DECIMAL(12,2) NULL DEFAULT NULL"],
            ];
            foreach ($add as [$table, $col, $ddl]) {
                if (!$pdo->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $col . "'")->fetch()) {
                    $pdo->exec($ddl);
                }
            }
        } catch (Throwable $e) { error_log('afxEnsurePosGoodsTaxColumns: ' . $e->getMessage()); }
    }
}

ensureProductsTable();
ensureProductsPosColumn();
ensureProductsCrmColumns();
ensureSkladBranchSchema();
ensureProductsHideEshopColumn();
afxEnsurePosGoodsTaxColumns();

// ── get: data pro editační režim modalu ──
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit; }
    $raw = json_decode((string)($p['raw_csv'] ?? ''), true) ?: [];
    $rawProcessorFamily = trim((string)($raw['[PARAMETER "Výrobce procesoru"]'] ?? $raw['PROCESOR_TYP'] ?? ''));
    $rawProcessorModel = trim((string)($raw['PROCESOR_MODEL'] ?? ''));
    if ($rawProcessorModel === '') {
        $rawProcessorModel = trim((string)($raw['[PARAMETER "Procesor"]'] ?? ''));
    }
    $rawTyp = trim((string)($raw['[PARAMETER "Typ zařízení"]'] ?? $raw['PRODUKT_TYP'] ?? ''));
    $code = (string)$p['product_code'];
    echo json_encode(['success' => true, 'product' => [
        'id' => (int)$p['id'],
        // AFX-/PREVIEW- generované kódy se do pole SN nepropisují (nejsou to sériová čísla)
        'serial' => (str_starts_with($code, 'AFX-') || str_starts_with($code, 'PREVIEW')) ? '' : $code,
        'title' => (string)$p['title'],
        'model' => (string)($p['model'] ?? ''),
        'manufacturer' => (string)($p['manufacturer'] ?? ''),
        'typ' => $rawTyp,
        'category_code' => (string)($p['category_code'] ?? ''),
        'cap' => (string)($p['capacity'] ?? ''),
        'color' => (string)($p['color'] ?? ''),
        'grade' => (string)($p['grade'] ?? ''),
        'battery' => trim(str_replace('%', '', (string)($p['battery'] ?? ''))),
        'price' => (float)$p['price'] == (int)(float)$p['price'] ? (string)(int)(float)$p['price'] : (string)(float)$p['price'],
        // nákupní cena je nepovinná — nezadaná se vrací jako '' (ne 0), ať ji editace nevyrobí z ničeho
        'purchase_price' => ($p['purchase_price'] ?? null) === null || (string)$p['purchase_price'] === ''
            ? '' : rtrim(rtrim(number_format((float)$p['purchase_price'], 2, '.', ''), '0'), '.'),
        'sold' => (int)$p['stock_qty'] <= 0,
        'stock_qty' => (int)$p['stock_qty'],
        'stock_key' => (string)($p['stock_key'] ?? ''),
        'hide_eshop' => (int)($p['hide_eshop'] ?? 0),
        'image_url' => productImageDisplayUrl((string)($p['image_url'] ?? '')),   // jen naše úložiště — cizí URL z CSV nejde do <img>
        'studio_image_url' => productImageDisplayUrl((string)($p['studio_image_url'] ?? '')),
        'gallery_images'   => (string)($p['gallery_images'] ?? ''),   // JSON, UI si rozparsuje
        'video_360_url'    => productImageDisplayUrl((string)($p['video_360_url'] ?? '')),
        'has_360'          => (int)($p['has_360'] ?? 0),
        'show_studio'      => (int)($p['show_studio'] ?? 1),
        'show_gallery'     => (int)($p['show_gallery'] ?? 1),
        'show_360'         => (int)($p['show_360'] ?? 1),

        'ram' => (string)($raw['[PARAMETER "RAM"]'] ?? ''),
        'accessory_for_model' => (string)($raw['[PARAMETER "Pro model"]'] ?? ''),
        'accessory_property' => (string)($raw['[PARAMETER "Vlastnost"]'] ?? ''),
        'processor_family' => $rawProcessorFamily,
        'processor_model' => $rawProcessorModel,
        'cpu' => (string)($raw['CPU_JADRA'] ?? ''),
        'gpu' => (string)($raw['GPU_JADRA'] ?? ''),
        'rocnik' => (string)($raw['[PARAMETER "Ročník"]'] ?? ''),
        'generace' => (string)($raw['[PARAMETER "Generace"]'] ?? ''),
        'pcr_status' => (string)($p['pcr_status'] ?? ''),
        'pcr_result' => (string)($p['pcr_result'] ?? ''),
        'source' => (string)($p['source'] ?? 'app'),
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── create / update ──
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$in = [
    'manufacturer' => trim((string)($_POST['manufacturer'] ?? '')),
    'typ' => trim((string)($_POST['typ'] ?? '')),
    'model' => trim((string)($_POST['model'] ?? '')),
    'accessory_for_model' => trim((string)($_POST['accessory_for_model'] ?? '')),
    'accessory_property' => trim((string)($_POST['accessory_property'] ?? '')),
    'cap' => trim((string)($_POST['cap'] ?? '')),
    'color' => trim((string)($_POST['color'] ?? '')),
    'grade' => trim((string)($_POST['grade'] ?? '')),
    'battery' => ($__b = preg_replace('/\D+/', '', (string)($_POST['battery'] ?? ''))) !== '' ? (string)min(100, max(0, (int)$__b)) : '',
    'price' => trim((string)($_POST['price'] ?? '')),
    'purchase_price' => trim((string)($_POST['purchase_price'] ?? '')),
    'ram' => trim((string)($_POST['ram'] ?? '')),
    'processor_family' => trim((string)($_POST['processor_family'] ?? '')),
    'processor_model' => trim((string)($_POST['processor_model'] ?? '')),
    'cpu' => trim((string)($_POST['cpu'] ?? '')),
    'gpu' => trim((string)($_POST['gpu'] ?? '')),
    'rocnik' => trim((string)($_POST['rocnik'] ?? '')),
    'generace' => trim((string)($_POST['generace'] ?? '')),
    'sold' => !empty($_POST['sold']),
    'stock_qty' => isset($_POST['stock_qty']) && $_POST['stock_qty'] !== '' ? max(0, min(9999, (int)$_POST['stock_qty'])) : null,
    'stock_qty_raw' => isset($_POST['stock_qty']) ? trim((string)$_POST['stock_qty']) : null,
    'stock_key' => in_array((string)($_POST['stock_key'] ?? ''), ['karlin', 'vaclavak'], true) ? (string)$_POST['stock_key'] : 'karlin',
    'hide_eshop' => ((string)($_POST['hide_eshop'] ?? '0') === '1') ? 1 : 0,
    'image_url' => trim((string)($_POST['image_url'] ?? '')),
    // Galerie média (sekce v modalu): studiová fotka, klasické fotky (JSON pole URL), 360° video
    'studio_image_url' => trim((string)($_POST['studio_url'] ?? '')),
    'gallery_images'   => trim((string)($_POST['gallery_urls'] ?? '')),
    'video_360_url'    => trim((string)($_POST['video360_url'] ?? '')),
    // per-produkt volba sekcí Galerie (checkboxy v UI); nepřítomné pole = zapnuto (výchozí)
    'show_studio'  => isset($_POST['show_studio'])  ? (((string)$_POST['show_studio']  === '1') ? 1 : 0) : 1,
    'show_gallery' => isset($_POST['show_gallery']) ? (((string)$_POST['show_gallery'] === '1') ? 1 : 0) : 1,
    'show_360'     => isset($_POST['show_360'])     ? (((string)$_POST['show_360']     === '1') ? 1 : 0) : 1,
];
$serial = trim((string)($_POST['serial'] ?? ''));
$catalogMode = (string)($_POST['catalog_mode'] ?? 'product');
$in['catalog_mode'] = $catalogMode;
$force = !empty($_POST['force']);
$editId = (int)($_POST['id'] ?? 0);

// Pobočka produktu = dle zvolené prodejny (stock_key). Naskladnit smí jen zaměstnanec té pobočky.
$prodBranch = skladBranchIdForStockKey($in['stock_key']);
if (!crmCanModifyBranchStock($prodBranch)) {
    echo json_encode(['success' => false, 'message' => 'Na tuto prodejnu (pobočku) smí naskladňovat jen její zaměstnanci.'], JSON_UNESCAPED_UNICODE); exit;
}

if ($catalogMode === 'accessory') {
    $knownAccessoryTypes = array_column(afxProductAccessoryTypes(), 'id');
    $knownDeviceTypes = array_unique(array_column(afxProductTypes(), 'id'));
    if ($in['typ'] === '') {
        echo json_encode(['success' => false, 'message' => 'Vyber typ příslušenství.'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (!in_array($in['typ'], $knownAccessoryTypes, true) && in_array($in['typ'], $knownDeviceTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'V režimu Příslušenství nejde uložit běžný typ zařízení. Vyber typ příslušenství nebo použij vlastní doplněk.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $in['manufacturer'] = '';
    $in['model'] = $in['typ'];
    $in['cap'] = '';
    $in['battery'] = '';
    $in['ram'] = '';
    $in['processor_family'] = '';
    $in['processor_model'] = '';
    $in['cpu'] = '';
    $in['gpu'] = '';
    $in['rocnik'] = '';
    $in['generace'] = '';
}

if ($in['model'] === '') {
    echo json_encode(['success' => false, 'message' => 'Vyplň model.']); exit;
}
if (mb_strlen($in['manufacturer']) > 64) {
    echo json_encode(['success' => false, 'message' => 'Výrobce je moc dlouhý. Zkrať ho prosím na max. 64 znaků.'], JSON_UNESCAPED_UNICODE); exit;
}
if (mb_strlen($in['typ']) > 64) {
    echo json_encode(['success' => false, 'message' => 'Typ zařízení je moc dlouhý. Zkrať ho prosím na max. 64 znaků.'], JSON_UNESCAPED_UNICODE); exit;
}
if (mb_strlen($in['accessory_for_model']) > 120) {
    echo json_encode(['success' => false, 'message' => 'Pole „Pro model“ je moc dlouhé. Zkrať ho prosím na max. 120 znaků.'], JSON_UNESCAPED_UNICODE); exit;
}
if (mb_strlen($in['accessory_property']) > 80) {
    echo json_encode(['success' => false, 'message' => 'Vlastnost příslušenství je moc dlouhá. Zkrať ji prosím na max. 80 znaků.'], JSON_UNESCAPED_UNICODE); exit;
}
$processorFamilies = afxProductProcessorFamiliesForType(afxProductTypeById($in['typ'], $in['manufacturer']));
if (!afxProductHasProcessorFields($in['typ'], $in['model'])) {
    $in['processor_family'] = '';
    $in['processor_model'] = '';
} elseif ($in['processor_family'] === '' || !in_array($in['processor_family'], $processorFamilies, true)) {
    $in['processor_family'] = '';
    $in['processor_model'] = '';
} elseif (mb_strlen($in['processor_model']) > 120) {
    echo json_encode(['success' => false, 'message' => 'Model procesoru je moc dlouhý. Zkrať ho prosím na max. 120 znaků.'], JSON_UNESCAPED_UNICODE); exit;
}
$priceNum = (float)str_replace(',', '.', str_replace(' ', '', $in['price']));
if ($in['price'] === '' || !is_finite($priceNum) || $priceNum <= 0 || $priceNum > 10000000) {
    echo json_encode(['success' => false, 'message' => 'Vyplň platnou cenu.']); exit;
}
$in['price'] = rtrim(rtrim(number_format($priceNum, 2, '.', ''), '0'), '.');   // "7990" / "7990.5"
// Nákupní cena je NEPOVINNÁ (u starých kusů ji nikdo nezadal) → prázdné pole = NULL,
// ne nula. Nula by u § 90 znamenala „koupeno zadarmo" a nafoukla by daň z přirážky.
$purchaseNum = null;
if ($in['purchase_price'] !== '') {
    // Tvar validovat PŘED přetypováním — (float)'nevím' je 0.0, prošla by i mez
    // „< 0“ a tiše by se uložila nákupní cena 0 Kč. Nula tu znamená „koupeno
    // zadarmo“ a u § 90 by nafoukla daň z přirážky, proto se nečíselný vstup
    // (i explicitní nula) odmítá chybou místo tichého uložení.
    $ppNorm = str_replace([' ', "\xc2\xa0"], '', $in['purchase_price']);   // mezery vč. nezlomitelné („4 500“)
    if (!preg_match('/^\d+([.,]\d{1,2})?$/', $ppNorm)) {
        echo json_encode(['success' => false, 'message' => 'Nákupní cena musí být kladné číslo (např. 4500 nebo 4500,50), nebo pole nech prázdné.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $purchaseNum = round((float)str_replace(',', '.', $ppNorm), 2);
    if ($purchaseNum <= 0 || $purchaseNum > 10000000) {
        echo json_encode(['success' => false, 'message' => 'Nákupní cena musí být kladné číslo do 10 000 000 Kč, nebo pole nech prázdné.'], JSON_UNESCAPED_UNICODE); exit;
    }
}
if (mb_strlen($in['image_url']) > 500 || ($in['image_url'] !== '' && productImageDisplayUrl($in['image_url']) === '')) {
    $in['image_url'] = '';   // jen fotky z našeho úložiště
}
// Galerie: stejný whitelist (jen naše úložiště) pro studio i video; galerie = JSON pole URL (max 10)
if (mb_strlen($in['studio_image_url']) > 500 || ($in['studio_image_url'] !== '' && productImageDisplayUrl($in['studio_image_url']) === '')) {
    $in['studio_image_url'] = '';
}
if (mb_strlen($in['video_360_url']) > 500 || ($in['video_360_url'] !== '' && productImageDisplayUrl($in['video_360_url']) === '')) {
    $in['video_360_url'] = '';
}
$galIn = json_decode($in['gallery_images'] ?: '[]', true);
$galClean = [];
if (is_array($galIn)) {
    foreach ($galIn as $g) {
        $g = trim((string)$g);
        if ($g !== '' && mb_strlen($g) <= 500 && productImageDisplayUrl($g) !== '') { $galClean[] = $g; }
        if (count($galClean) >= 10) break;
    }
}
$in['gallery_images'] = $galClean ? json_encode($galClean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
$in['has_360'] = ($in['video_360_url'] !== '') ? 1 : 0;

try {
    $existing = null;
    if ($action === 'update') {
        $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $st->execute([$editId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit; }
        if (isset($existing['branch_id']) && (int)$existing['branch_id'] > 0 && !crmCanModifyBranchStock((int)$existing['branch_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tento produkt patří jiné pobočce — upravit ho smí jen její zaměstnanci.'], JSON_UNESCAPED_UNICODE); exit;
        }
    }

    // ── product_code ── (u editace: bez SN zachovat původní kód, i AFX-)
    $existingCode = $existing ? (string)$existing['product_code'] : '';
    if ($serial !== '') {
        $code = mb_substr($serial, 0, 64);
    } elseif ($action === 'update') {
        $code = $existingCode;
    } else {
        $code = 'AFX-' . date('YmdHis');
    }

    // duplicitní SN → nepřidávat, ukázat existující kus (přesně jako appka)
    if ($code !== $existingCode || $action === 'create') {
        $dup = $pdo->prepare("SELECT id, title, added_at FROM products WHERE product_code = ?" . ($editId > 0 ? " AND id != ?" : ""));
        $dup->execute($editId > 0 ? [$code, $editId] : [$code]);
        $d = $dup->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            http_response_code(409);
            echo json_encode(['success' => false, 'duplicate' => true,
                'message' => 'Zařízení se SN/IMEI „' . $code . '" už ve skladové databázi je: '
                    . $d['title'] . ' (přidáno ' . ($d['added_at'] ? date('d.m.Y', strtotime((string)$d['added_at'])) : '—') . '). Nepřidávám ho znovu.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── PČR — autoritativně na serveru ── (u editace jen při změně SN)
    $codeChanged = $existing === null || $code !== $existingCode;
    if ($codeChanged) {
        $pcr = afxPcrCheckImei($serial);
        if ($pcr['status'] === 'stolen' && !$force) {
            echo json_encode(['success' => false, 'needs_confirmation' => true,
                'message' => $pcr['text'],
                'confirm_text' => "⚠️ ODCIZENÉ ZAŘÍZENÍ!\n\n" . $pcr['text']
                    . "\n\nToto zařízení je vedeno jako ODCIZENÉ v databázi Policie ČR.\nOpravdu ho chcete přidat do skladu?"], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $pcr = ['status' => (string)($existing['pcr_status'] ?? ''), 'text' => (string)($existing['pcr_result'] ?? '')];
    }

    // ── složení řádku (title, short_desc, raw_csv) — jediný zdroj pravdy ──
    $added = $existing && !empty($existing['added_at'])
        ? date('Y-m-d H:i', strtotime((string)$existing['added_at']))
        : date('Y-m-d H:i');
    $asm = afxProductAssemble($in + ['code' => $code, 'added' => $added,
        'pcr_status' => $pcr['status'], 'pcr_text' => $pcr['text']]);

    $who = mb_substr(trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')), 0, 64);
    // POČET KUSŮ z formuláře (dřív natvrdo 1 = jeden kus na řádek). Příslušenství se
    // naskladňuje po víc kusech a prodej na kase i e-shop už s počtem umí pracovat
    // (stock_qty se snižuje, pos_sold_at se nastaví až při nule).
    // Počet kusů musí být číslo. Nečíselný vstup ('', 'abc') by přes (int) tiše spadl
    // na 0 a produkt by se založil rovnou jako vyprodaný.
    if ($in['stock_qty_raw'] !== null && $in['stock_qty_raw'] !== '' && !preg_match('/^\d+$/', $in['stock_qty_raw'])) {
        echo json_encode(['success' => false, 'message' => 'Počet kusů zadej celým číslem (např. 8).'], JSON_UNESCAPED_UNICODE); exit;
    }
    $stockQty = $in['stock_qty'] !== null ? (int)$in['stock_qty'] : 1;
    if ($editId <= 0 && !$in['sold'] && $stockQty < 1) {
        echo json_encode(['success' => false, 'message' => 'Zadej počet kusů (alespoň 1) — nebo zaškrtni „Prodáno", pokud kus naskladňuješ jako vyprodaný.'], JSON_UNESCAPED_UNICODE); exit;
    }
    // OPTIMISTICKÝ ZÁMEK na počet kusů: formulář načetl stav v okamžiku otevření modalu,
    // ale kasa nebo e-shop mezitím mohly prodávat. Absolutní přepis by prodané kusy
    // „oživil" (a pos_sold_at = NULL by zahladil stopu). Zapíšeme jen tehdy, když je
    // v DB pořád ta hodnota, kterou obsluha viděla.
    $qtyOrig = isset($_POST['stock_qty_orig']) && $_POST['stock_qty_orig'] !== ''
        ? max(0, (int)$_POST['stock_qty_orig']) : null;
    $qtyGuardSql = ($editId > 0 && $qtyOrig !== null) ? ' AND stock_qty = ?' : '';
    // „Prodáno" = vyprodáno. Explicitní kladný počet ale vyhrává — obsluha, která
    // u vyprodané položky přepíše počet na 5, chce naskladnit, ne vynulovat.
    if ($in['sold'] && ($in['stock_qty'] === null || $stockQty === 0)) { $stockQty = 0; }
    // Kus se sériovým číslem je vždy JEDEN (dva stejné SN by nešly rozlišit).
    // POZOR: sériové číslo je v $serial, ne v $in — dřív tu byl $in['serial'], což je
    // vždy prázdné, takže pojistka nikdy nezabrala.
    if ($stockQty > 1 && $serial !== '') { $stockQty = 1; }
    // „Prodáno" má poslední slovo: kdyby formulář poslal 1 ks u prodaného kusu se SN
    // (viz zámek v UI), vrátil by se prodaný telefon na sklad.
    if ($in['sold'] && $serial !== '') { $stockQty = 0; }
    $pcrCheckedAt = ($pcr['status'] !== '' && $pcr['status'] !== 'notimei') ? date('Y-m-d H:i:s') : null;

    /* ── Ochrana napojení na CSV import z appky ─────────────────────────────
       Plný UPDATE níže překlápí kus na source='crm' a import appky ho pak už
       NIKDY neaktualizuje (api/import_products.php ho přeskakuje). To je správně
       u skutečné úpravy karty, ale doplnění nákupní ceny (kvůli § 90) nebo médií
       galerie NESMÍ kus od importu odpojit — import tyhle sloupce vůbec nespravuje
       (nejsou v jeho $fields), takže mu jejich úprava nijak nekonkuruje.
       Když se tedy nezměnilo NIC z toho, co import řídí (kód, model, cena, stav,
       sklad…), uloží se jen import-neutrální sloupce a source ani pos_sold_at
       (pojistka „prodáno na kase“, kterou import potřebuje vidět) se nesahá.
       Srovnává se se surovými hodnotami z DB, které modal přes action=get dostal —
       nezměněný formulář je tedy pošle zpátky beze změny. Jakákoli nejistota
       (jiný formát, změněné pole) spadne do plné větve = dosavadní chování. */
    if ($action === 'update' && (string)($existing['source'] ?? 'app') !== 'crm') {
        $normBat = static fn($v) => preg_replace('/\D+/', '', (string)$v);   // „89“ vs „89 %“
        $exRaw = json_decode((string)($existing['raw_csv'] ?? ''), true) ?: [];
        $importGovernedUnchanged =
            !$codeChanged
            && abs($priceNum - (float)$existing['price']) < 0.005
            // „prodáno“ se porovnává jako příznak — plný UPDATE by vícekusové
            // příslušenství stáhl na 1 ks, mikroúprava stock_qty nesahá vůbec
            && $stockQty === (int)$existing['stock_qty']   // změna POČTU musí projít plnou větví, jinak se tiše zahodí
            && $in['stock_key'] === (string)($existing['stock_key'] ?? '')
            && (string)($asm['manuf'] ?: '') === (string)($existing['manufacturer'] ?? '')
            && $in['model'] === (string)($existing['model'] ?? '')
            && $in['cap'] === (string)($existing['capacity'] ?? '')
            && $in['color'] === (string)($existing['color'] ?? '')
            && $in['grade'] === (string)($existing['grade'] ?? '')
            && $normBat($in['battery']) === $normBat($existing['battery'] ?? '')
            && (string)($asm['k'] ?: '') === (string)($existing['category_code'] ?? '')
            // prázdné image_url NENÍ změna: cizí (CSV) fotku modal neumí zobrazit
            // ani poslat zpět, vrací se prázdno — plná větev by ji tady smazala
            && ($in['image_url'] === '' || $in['image_url'] === (string)($existing['image_url'] ?? ''))
            // Mac/iPad parametry žijí jen v raw_csv — jejich změna vyžaduje přeskládání řádku
            && (trim((string)($exRaw['[PARAMETER "Typ zařízení"]'] ?? $exRaw['PRODUKT_TYP'] ?? '')) === ''
                || $in['typ'] === trim((string)($exRaw['[PARAMETER "Typ zařízení"]'] ?? $exRaw['PRODUKT_TYP'] ?? '')))
            && $in['ram'] === trim((string)($exRaw['[PARAMETER "RAM"]'] ?? ''))
            && $in['accessory_for_model'] === trim((string)($exRaw['[PARAMETER "Pro model"]'] ?? ''))
            && $in['accessory_property'] === trim((string)($exRaw['[PARAMETER "Vlastnost"]'] ?? ''))
            && $in['processor_family'] === trim((string)($exRaw['[PARAMETER "Výrobce procesoru"]'] ?? $exRaw['PROCESOR_TYP'] ?? ''))
            && $in['processor_model'] === trim((string)($exRaw['PROCESOR_MODEL'] ?? $exRaw['[PARAMETER "Procesor"]'] ?? ''))
            && $in['cpu'] === trim((string)($exRaw['CPU_JADRA'] ?? ''))
            && $in['gpu'] === trim((string)($exRaw['GPU_JADRA'] ?? ''))
            && $in['rocnik'] === trim((string)($exRaw['[PARAMETER "Ročník"]'] ?? ''))
            && $in['generace'] === trim((string)($exRaw['[PARAMETER "Generace"]'] ?? ''));

        if ($importGovernedUnchanged) {
            // import-neutrální mikroúprava: nákupní cena + média galerie; title,
            // raw_csv a spol. zůstávají z appky (zdroj pravdy se nemění)
            $pdo->prepare("UPDATE products SET purchase_price = ?, studio_image_url = ?, gallery_images = ?,
                    video_360_url = ?, has_360 = ?, show_studio = ?, show_gallery = ?, show_360 = ?, hide_eshop = ?
                WHERE id = ?")
                ->execute([$purchaseNum, $in['studio_image_url'] ?: null, $in['gallery_images'],
                    $in['video_360_url'] ?: null, $in['has_360'],
                    $in['show_studio'], $in['show_gallery'], $in['show_360'], $in['hide_eshop'], $editId]);
            crmAuditLog('products.update', [
                'entity_type' => 'products', 'entity_id' => $editId, 'entity_label' => (string)$existing['title'],
                'summary' => 'Doplněna nákupní cena/média u „' . $existing['title'] . '“ (' . $code . ') — kus zůstává napojený na import z appky',
            ]);
            echo json_encode([
                'success' => true,
                'id' => $editId,
                'code' => $code,
                'title' => (string)$existing['title'],
                'pcr_status' => $pcr['status'],
                'pcr_text' => $pcr['text'],
                'hint' => '',
                'today_count' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE DATE(added_at) = CURDATE()")->fetchColumn(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'update') {
        $upd = $pdo->prepare("UPDATE products SET product_code = ?, title = ?, manufacturer = ?, category_code = ?,
                model = ?, capacity = ?, color = ?, grade = ?, battery = ?, price = ?, purchase_price = ?, stock_qty = ?, branch_id = ?,
                stock_key = ?, image_url = ?, studio_image_url = ?, gallery_images = ?, video_360_url = ?, has_360 = ?,
                show_studio = ?, show_gallery = ?, show_360 = ?, hide_eshop = ?,
                pcr_result = ?, pcr_status = ?, pcr_checked_at = COALESCE(?, pcr_checked_at),
                raw_csv = ?, source = 'crm', created_by = COALESCE(created_by, ?), pos_sold_at = NULL, last_seen_at = NOW()
            WHERE id = ?" . $qtyGuardSql);
        $updParams = [$code, $asm['title'], $asm['manuf'] ?: null, $asm['k'] ?: null,
                $asm['display_model'], $in['cap'] ?: null, $in['color'] ?: null, $asm['grade_token'] ?: null,
                $asm['battery_csv'] ?: null, $priceNum, $purchaseNum, $stockQty, $prodBranch,
                $in['stock_key'], $in['image_url'] ?: null,
                $in['studio_image_url'] ?: null, $in['gallery_images'], $in['video_360_url'] ?: null, $in['has_360'],
                $in['show_studio'], $in['show_gallery'], $in['show_360'], $in['hide_eshop'],
                $pcr['text'] ?: null,
                $pcr['status'] ?: null, $codeChanged ? $pcrCheckedAt : null,
                json_encode($asm['assoc'], JSON_UNESCAPED_UNICODE), $who ?: null, $editId];
        // POZOR: parametry se PŘIPOJUJÍ, ne sjednocují. `[...] + [$x]` je u polí sjednocení
        // podle klíčů — index 0 vlevo existuje, takže by se $qtyOrig zahodilo a dotaz by
        // měl o parametr míň (PDOException „Invalid parameter number" u KAŽDÉ editace).
        if ($qtyGuardSql !== '') { $updParams[] = $qtyOrig; }
        $upd->execute($updParams);
        if ($qtyGuardSql !== '' && $upd->rowCount() === 0) {
            // rowCount()=0 znamená „nic se nezměnilo" NEBO „zámek nesedí" — rozlišit,
            // ať uložení beze změny nehlásí falešný poplach.
            $cur = $pdo->prepare("SELECT stock_qty FROM products WHERE id = ?");
            $cur->execute([$editId]);
            $curQty = $cur->fetchColumn();
            if ($curQty !== false && (int)$curQty !== (int)$qtyOrig) {
                echo json_encode(['success' => false,
                    'message' => 'Počet kusů se mezitím změnil (' . (int)$qtyOrig . ' → ' . (int)$curQty
                        . ' — nejspíš prodej na kase nebo e-shopu). Zavři a otevři produkt znovu, ať nepřepíšeš prodané kusy.'],
                    JSON_UNESCAPED_UNICODE); exit;
            }
        }
        $productId = $editId;
        crmAuditLog('products.update', [
            'entity_type' => 'products', 'entity_id' => $productId, 'entity_label' => $asm['title'],
            'summary' => 'Upraven produkt „' . $asm['title'] . '" (' . $code . ') přímo v CRM',
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO products
                (product_code, title, manufacturer, category_code, model, capacity, color, grade, battery,
                 price, purchase_price, stock_qty, branch_id, stock_key, image_url, studio_image_url, gallery_images, video_360_url, has_360,
                 show_studio, show_gallery, show_360, hide_eshop,
                 pcr_result, pcr_status, pcr_checked_at,
                 added_at, raw_csv, source, created_by, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'crm', ?, NOW(), NOW())");
        for ($try = 0; $try < 3; $try++) {
            try {
                $ins->execute([$code, $asm['title'], $asm['manuf'] ?: null, $asm['k'] ?: null,
                    $asm['display_model'], $in['cap'] ?: null, $in['color'] ?: null, $asm['grade_token'] ?: null,
                    $asm['battery_csv'] ?: null, $priceNum, $purchaseNum, $stockQty, $prodBranch,
                    $in['stock_key'], $in['image_url'] ?: null,
                    $in['studio_image_url'] ?: null, $in['gallery_images'], $in['video_360_url'] ?: null, $in['has_360'],
                    $in['show_studio'], $in['show_gallery'], $in['show_360'], $in['hide_eshop'],
                    $pcr['text'] ?: null,
                    $pcr['status'] ?: null, $pcrCheckedAt,
                    json_encode($asm['assoc'], JSON_UNESCAPED_UNICODE), $who ?: null]);
                break;
            } catch (PDOException $e) {
                // AFX- kolize ve stejné sekundě (dva manažeři) → posunout a zkusit znovu
                if ((int)($e->errorInfo[1] ?? 0) === 1062 && $serial === '' && $try < 2) {
                    sleep(1);
                    $code = 'AFX-' . date('YmdHis');
                    $asm['assoc']['[PRODUCT_CODE]'] = $code;
                    continue;
                }
                throw $e;
            }
        }
        $productId = (int)$pdo->lastInsertId();
        crmAuditLog('products.create', [
            'entity_type' => 'products', 'entity_id' => $productId, 'entity_label' => $asm['title'],
            'summary' => 'Naskladněn produkt „' . $asm['title'] . '" (' . $code . ', ' . formatMoney($priceNum)
                . ($stockQty !== 1 ? ', ' . $stockQty . ' ks' : '') . ')'
                . ($pcr['status'] === 'stolen' ? ' — POZOR: PČR hlásí ODCIZENÉ, přidáno po potvrzení' : ''),
        ]);
    }

    // možný duplikát: stejný název skladem pod AFX- kódem (kus dřív přidaný bez SN)
    $hint = '';
    if ($action === 'create' && $serial !== '') {
        $h = $pdo->prepare("SELECT product_code, added_at FROM products
            WHERE title = ? AND stock_qty > 0 AND product_code LIKE 'AFX-%' AND id != ? LIMIT 1");
        $h->execute([$asm['title'], $productId]);
        if ($hr = $h->fetch(PDO::FETCH_ASSOC)) {
            $hint = 'Možný duplikát: stejný kus je skladem i pod kódem ' . $hr['product_code']
                . ' (přidáno ' . date('d.m.Y', strtotime((string)$hr['added_at'])) . ') — zkontroluj, jestli nejde o tentýž telefon.';
        }
    }

    $todayCount = 0;
    try {
        $todayCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE DATE(added_at) = CURDATE()")->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'id' => $productId,
        'code' => $code,
        'title' => $asm['title'],
        'pcr_status' => $pcr['status'],
        'pcr_text' => $pcr['text'],
        'hint' => $hint,
        'today_count' => $todayCount,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('product_create: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Databázová chyba — produkt se neuložil, zkus to znovu.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
