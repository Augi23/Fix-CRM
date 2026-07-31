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
afxEnsurePosGoodsTaxColumns();

// ── get: data pro editační režim modalu ──
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit; }
    $raw = json_decode((string)($p['raw_csv'] ?? ''), true) ?: [];
    $code = (string)$p['product_code'];
    echo json_encode(['success' => true, 'product' => [
        'id' => (int)$p['id'],
        // AFX-/PREVIEW- generované kódy se do pole SN nepropisují (nejsou to sériová čísla)
        'serial' => (str_starts_with($code, 'AFX-') || str_starts_with($code, 'PREVIEW')) ? '' : $code,
        'title' => (string)$p['title'],
        'model' => (string)($p['model'] ?? ''),
        'manufacturer' => (string)($p['manufacturer'] ?? ''),
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
        'stock_key' => (string)($p['stock_key'] ?? ''),
        'image_url' => productImageDisplayUrl((string)($p['image_url'] ?? '')),   // jen naše úložiště — cizí URL z CSV nejde do <img>
        'studio_image_url' => productImageDisplayUrl((string)($p['studio_image_url'] ?? '')),
        'gallery_images'   => (string)($p['gallery_images'] ?? ''),   // JSON, UI si rozparsuje
        'video_360_url'    => productImageDisplayUrl((string)($p['video_360_url'] ?? '')),
        'has_360'          => (int)($p['has_360'] ?? 0),
        'show_studio'      => (int)($p['show_studio'] ?? 1),
        'show_gallery'     => (int)($p['show_gallery'] ?? 1),
        'show_360'         => (int)($p['show_360'] ?? 1),

        'ram' => (string)($raw['[PARAMETER "RAM"]'] ?? ''),
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
    'typ' => trim((string)($_POST['typ'] ?? '')),
    'model' => trim((string)($_POST['model'] ?? '')),
    'cap' => trim((string)($_POST['cap'] ?? '')),
    'color' => trim((string)($_POST['color'] ?? '')),
    'grade' => trim((string)($_POST['grade'] ?? '')),
    'battery' => ($__b = preg_replace('/\D+/', '', (string)($_POST['battery'] ?? ''))) !== '' ? (string)min(100, max(0, (int)$__b)) : '',
    'price' => trim((string)($_POST['price'] ?? '')),
    'purchase_price' => trim((string)($_POST['purchase_price'] ?? '')),
    'ram' => trim((string)($_POST['ram'] ?? '')),
    'cpu' => trim((string)($_POST['cpu'] ?? '')),
    'gpu' => trim((string)($_POST['gpu'] ?? '')),
    'rocnik' => trim((string)($_POST['rocnik'] ?? '')),
    'generace' => trim((string)($_POST['generace'] ?? '')),
    'sold' => !empty($_POST['sold']),
    'stock_key' => in_array((string)($_POST['stock_key'] ?? ''), ['karlin', 'vaclavak'], true) ? (string)$_POST['stock_key'] : 'karlin',
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
$force = !empty($_POST['force']);
$editId = (int)($_POST['id'] ?? 0);

// Pobočka produktu = dle zvolené prodejny (stock_key). Naskladnit smí jen zaměstnanec té pobočky.
$prodBranch = skladBranchIdForStockKey($in['stock_key']);
if (!crmCanModifyBranchStock($prodBranch)) {
    echo json_encode(['success' => false, 'message' => 'Na tuto prodejnu (pobočku) smí naskladňovat jen její zaměstnanci.'], JSON_UNESCAPED_UNICODE); exit;
}

if ($in['model'] === '') {
    echo json_encode(['success' => false, 'message' => 'Vyplň model.']); exit;
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
    $stockQty = $in['sold'] ? 0 : 1;
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
            && (($stockQty > 0) === ((int)$existing['stock_qty'] > 0))
            && $in['stock_key'] === (string)($existing['stock_key'] ?? '')
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
            && $in['ram'] === trim((string)($exRaw['[PARAMETER "RAM"]'] ?? ''))
            && $in['cpu'] === trim((string)($exRaw['CPU_JADRA'] ?? ''))
            && $in['gpu'] === trim((string)($exRaw['GPU_JADRA'] ?? ''))
            && $in['rocnik'] === trim((string)($exRaw['[PARAMETER "Ročník"]'] ?? ''))
            && $in['generace'] === trim((string)($exRaw['[PARAMETER "Generace"]'] ?? ''));

        if ($importGovernedUnchanged) {
            // import-neutrální mikroúprava: nákupní cena + média galerie; title,
            // raw_csv a spol. zůstávají z appky (zdroj pravdy se nemění)
            $pdo->prepare("UPDATE products SET purchase_price = ?, studio_image_url = ?, gallery_images = ?,
                    video_360_url = ?, has_360 = ?, show_studio = ?, show_gallery = ?, show_360 = ?
                WHERE id = ?")
                ->execute([$purchaseNum, $in['studio_image_url'] ?: null, $in['gallery_images'],
                    $in['video_360_url'] ?: null, $in['has_360'],
                    $in['show_studio'], $in['show_gallery'], $in['show_360'], $editId]);
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
        $pdo->prepare("UPDATE products SET product_code = ?, title = ?, manufacturer = ?, category_code = ?,
                model = ?, capacity = ?, color = ?, grade = ?, battery = ?, price = ?, purchase_price = ?, stock_qty = ?, branch_id = ?,
                stock_key = ?, image_url = ?, studio_image_url = ?, gallery_images = ?, video_360_url = ?, has_360 = ?,
                show_studio = ?, show_gallery = ?, show_360 = ?,
                pcr_result = ?, pcr_status = ?, pcr_checked_at = COALESCE(?, pcr_checked_at),
                raw_csv = ?, source = 'crm', created_by = COALESCE(created_by, ?), pos_sold_at = NULL, last_seen_at = NOW()
            WHERE id = ?")
            ->execute([$code, $asm['title'], $asm['manuf'] ?: null, $asm['k'] ?: null,
                $asm['display_model'], $in['cap'] ?: null, $in['color'] ?: null, $asm['grade_token'] ?: null,
                $asm['battery_csv'] ?: null, $priceNum, $purchaseNum, $stockQty, $prodBranch,
                $in['stock_key'], $in['image_url'] ?: null,
                $in['studio_image_url'] ?: null, $in['gallery_images'], $in['video_360_url'] ?: null, $in['has_360'],
                $in['show_studio'], $in['show_gallery'], $in['show_360'],
                $pcr['text'] ?: null,
                $pcr['status'] ?: null, $codeChanged ? $pcrCheckedAt : null,
                json_encode($asm['assoc'], JSON_UNESCAPED_UNICODE), $who ?: null, $editId]);
        $productId = $editId;
        crmAuditLog('products.update', [
            'entity_type' => 'products', 'entity_id' => $productId, 'entity_label' => $asm['title'],
            'summary' => 'Upraven produkt „' . $asm['title'] . '" (' . $code . ') přímo v CRM',
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO products
                (product_code, title, manufacturer, category_code, model, capacity, color, grade, battery,
                 price, purchase_price, stock_qty, branch_id, stock_key, image_url, studio_image_url, gallery_images, video_360_url, has_360,
                 show_studio, show_gallery, show_360,
                 pcr_result, pcr_status, pcr_checked_at,
                 added_at, raw_csv, source, created_by, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'crm', ?, NOW(), NOW())");
        for ($try = 0; $try < 3; $try++) {
            try {
                $ins->execute([$code, $asm['title'], $asm['manuf'] ?: null, $asm['k'] ?: null,
                    $asm['display_model'], $in['cap'] ?: null, $in['color'] ?: null, $asm['grade_token'] ?: null,
                    $asm['battery_csv'] ?: null, $priceNum, $purchaseNum, $stockQty, $prodBranch,
                    $in['stock_key'], $in['image_url'] ?: null,
                    $in['studio_image_url'] ?: null, $in['gallery_images'], $in['video_360_url'] ?: null, $in['has_360'],
                    $in['show_studio'], $in['show_gallery'], $in['show_360'],
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
            'summary' => 'Naskladněn produkt „' . $asm['title'] . '" (' . $code . ', ' . formatMoney($priceNum) . ')'
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
