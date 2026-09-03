<?php
/**
 * Dokumenty (výkupní listy, zástavní formuláře, …) — sdílený engine.
 *
 * Jedna definice polí (crmDocTypes) řídí interaktivní vyplňovací stránku
 * (dokument.php), statický render pro e-mail i sloupce seznamu (dokumenty.php).
 * Ukládá se do tabulky crm_documents (vytváří se sama, bez migrací); kompletní
 * obsah formuláře je v payload JSON, vyhledávací sloupce jsou vytažené zvlášť.
 */

function ensureCrmDocumentsTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_type VARCHAR(30) NOT NULL,
            doc_number VARCHAR(30) NOT NULL,
            doc_date DATE NULL,
            customer_name VARCHAR(190) NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(190) NULL,
            subject VARCHAR(255) NULL,
            price VARCHAR(60) NULL,
            lang VARCHAR(5) NOT NULL DEFAULT 'cs',
            payload LONGTEXT NULL,
            created_by VARCHAR(100) NULL,
            branch_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_doc_number (doc_type, doc_number),
            KEY idx_doc_type (doc_type, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // výkup → produkt + výplata kasou (v3.48.0): vazby dokument ↔ produkt ↔ prodejka
        if (!$pdo->query("SHOW COLUMNS FROM crm_documents LIKE 'vykup_product_id'")->fetch()) {
            $pdo->exec("ALTER TABLE crm_documents ADD COLUMN vykup_product_id INT NULL DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM crm_documents LIKE 'payout_sale_id'")->fetch()) {
            $pdo->exec("ALTER TABLE crm_documents ADD COLUMN payout_sale_id INT NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { error_log('ensureCrmDocumentsTable: ' . $e->getMessage()); }
}

/** Definice typů dokumentů: prefix čísla, titulky, sekce a pole (label = lang klíč). */
function crmDocTypes(): array {
    return [
        'vykup' => [
            'prefix'     => 'VL',
            // Číselná řada navazuje na papírové výkupní listy: 202600001, 202600002, …
            'numbering'    => 'plain',
            'first_number' => 202600001,
            'title_key'  => 'buyout_title',            // „Výkupní list / Kupní smlouva"
            'kicker_key' => 'cdoc_vykup_kicker',
            'sections' => [
                // Rozsah údajů drží § 5 odst. 1 písm. a) zákona č. 253/2008 Sb. — u výkupu
                // (obchod s použitým zbožím) musíme prodávajícího identifikovat celý, ne jen
                // podle jména a čísla dokladu. Pořadí: kontakt → totožnost → doklad → podnikatel.
                ['h' => 'cdoc_sec_seller', 'fields' => [
                    ['n' => 'customer_name',        'l' => 'cdoc_f_name'],
                    ['n' => 'customer_phone',       'l' => 'cdoc_f_phone'],
                    ['n' => 'customer_email',       'l' => 'cdoc_f_email'],
                    ['n' => 'customer_address',     'l' => 'cdoc_f_address'],
                    // Rodné číslo je primární údaj; datum narození a pohlaví jsou náhrada,
                    // pokud rodné číslo přiděleno nebylo (typicky cizinci).
                    ['n' => 'customer_pid',         'l' => 'cdoc_f_pid'],
                    ['n' => 'customer_birth',       'l' => 'cdoc_f_birth'],
                    ['n' => 'customer_gender',      'l' => 'cdoc_f_gender'],
                    ['n' => 'customer_birthplace',  'l' => 'cdoc_f_birthplace'],
                    ['n' => 'customer_citizenship', 'l' => 'cdoc_f_citizenship'],
                    ['n' => 'customer_id_type',     'l' => 'cdoc_f_idtype'],
                    ['n' => 'customer_id_doc',      'l' => 'cdoc_f_iddoc'],
                    ['n' => 'customer_id_issuer',   'l' => 'cdoc_f_idissuer'],
                    ['n' => 'customer_id_valid',    'l' => 'cdoc_f_idvalid'],
                    // § 8 odst. 2 písm. a) zák. 253/2008 Sb. žádá, aby povinná osoba u identifikace
                    // OVĚŘILA SHODU PODOBY s vyobrazením v dokladu. Bez zaznamenání, kdo ověření
                    // provedl, je identifikace neprůkazná — proto sem patří jméno pracovníka.
                    ['n' => 'customer_id_verified', 'l' => 'cdoc_f_id_verified'],
                    // Jen pro podnikající fyzickou osobu — u běžného klienta zůstane prázdné.
                    ['n' => 'customer_biz_name',    'l' => 'cdoc_f_biz_name'],
                    ['n' => 'customer_biz_address', 'l' => 'cdoc_f_biz_address'],
                    ['n' => 'customer_biz_ico',     'l' => 'cdoc_f_biz_ico'],
                ]],
                ['h' => 'cdoc_sec_item_vykup', 'fields' => [
                    ['n' => 'item_description', 'l' => 'cdoc_f_item_desc'],
                    // Výrobce zvlášť od modelu: dřív se obojí psalo do jednoho pole
                    // („Značka / model"), takže se do skladu i na cenovku dostal jen
                    // ten kousek, který obsluha zapsala — u DJI Osmo Mobile 7 třeba
                    // jen „7". Značka je navíc na štítku i v e-shopu vlastní údaj.
                    ['n' => 'item_brand',       'l' => 'cdoc_f_item_brand'],
                    ['n' => 'item_model',       'l' => 'cdoc_f_item_model'],
                    ['n' => 'item_serial',      'l' => 'cdoc_f_item_serial'],
                    ['n' => 'item_price',       'l' => 'cdoc_f_price'],
                    ['n' => 'item_state',       'l' => 'cdoc_f_item_state', 't' => 'textarea'],
                    ['n' => 'item_accessories', 'l' => 'cdoc_f_item_acc',   't' => 'textarea'],
                ]],
                ['h' => 'cdoc_sec_payout', 'fields' => [
                    ['n' => 'sign_place_date',  'l' => 'cdoc_f_sign_place'],
                    ['n' => 'sign_payment',     'l' => 'cdoc_f_payment'],
                ]],
            ],
            'legal'      => ['buyout_agreement_text', 'cdoc_vykup_legal2', 'cdoc_vykup_legal3', 'cdoc_vykup_aml', 'cdoc_vykup_gdpr'],
            'sign_left'  => 'cdoc_sign_seller',
            'sign_right' => 'cdoc_sign_buyer',
            'subject_fields' => ['item_model', 'item_description'],
            'price_field' => 'item_price',
        ],
        'zastava' => [
            'prefix'     => 'ZF',
            'title_key'  => 'cdoc_zastava_title',
            'kicker_key' => 'cdoc_zastava_kicker',
            'sections' => [
                // Přijímání věcí do zástavy je podle § 2 odst. 1 písm. j) zákona č. 253/2008 Sb.
                // stejná povinná osoba jako výkup — zástavce se proto identifikuje ve stejném
                // rozsahu podle § 5 odst. 1 písm. a) jako prodávající u výkupního listu.
                ['h' => 'cdoc_sec_pledger', 'fields' => [
                    ['n' => 'customer_name',        'l' => 'cdoc_f_name'],
                    ['n' => 'customer_phone',       'l' => 'cdoc_f_phone'],
                    ['n' => 'customer_email',       'l' => 'cdoc_f_email'],
                    ['n' => 'customer_address',     'l' => 'cdoc_f_address'],
                    ['n' => 'customer_pid',         'l' => 'cdoc_f_pid'],
                    ['n' => 'customer_birth',       'l' => 'cdoc_f_birth'],
                    ['n' => 'customer_gender',      'l' => 'cdoc_f_gender'],
                    ['n' => 'customer_birthplace',  'l' => 'cdoc_f_birthplace'],
                    ['n' => 'customer_citizenship', 'l' => 'cdoc_f_citizenship'],
                    ['n' => 'customer_id_type',     'l' => 'cdoc_f_idtype'],
                    ['n' => 'customer_id_doc',      'l' => 'cdoc_f_iddoc'],
                    ['n' => 'customer_id_issuer',   'l' => 'cdoc_f_idissuer'],
                    ['n' => 'customer_id_valid',    'l' => 'cdoc_f_idvalid'],
                    ['n' => 'customer_biz_name',    'l' => 'cdoc_f_biz_name'],
                    ['n' => 'customer_biz_address', 'l' => 'cdoc_f_biz_address'],
                    ['n' => 'customer_biz_ico',     'l' => 'cdoc_f_biz_ico'],
                ]],
                ['h' => 'cdoc_sec_item_zastava', 'fields' => [
                    ['n' => 'item_description', 'l' => 'cdoc_f_item_desc'],
                    ['n' => 'item_model',       'l' => 'cdoc_f_item_model'],
                    ['n' => 'item_serial',      'l' => 'cdoc_f_item_serial'],
                    ['n' => 'item_estimate',    'l' => 'cdoc_f_estimate'],
                    ['n' => 'item_state',       'l' => 'cdoc_f_item_state', 't' => 'textarea'],
                    ['n' => 'item_accessories', 'l' => 'cdoc_f_item_acc',   't' => 'textarea'],
                ]],
                ['h' => 'cdoc_sec_terms', 'fields' => [
                    ['n' => 'loan_amount',      'l' => 'cdoc_f_loan'],
                    ['n' => 'due_date',         'l' => 'cdoc_f_due'],
                    ['n' => 'fee_rate',         'l' => 'cdoc_f_fee'],
                    ['n' => 'sign_place_date',  'l' => 'cdoc_f_sign_place'],
                    ['n' => 'sign_payment',     'l' => 'cdoc_f_payment'],
                    ['n' => 'note',             'l' => 'cdoc_f_note', 't' => 'textarea'],
                ]],
            ],
            'legal'      => ['cdoc_zastava_legal1', 'cdoc_zastava_legal2', 'cdoc_zastava_legal3'],
            'sign_left'  => 'cdoc_sign_pledger',
            'sign_right' => 'cdoc_sign_creditor',
            'subject_fields' => ['item_model', 'item_description'],
            'price_field' => 'loan_amount',
        ],
    ];
}

/** Další číslo dokumentu daného typu: VL-0001, ZF-0001, … */
function crmNextDocNumber(string $type): string {
    global $pdo;
    $cfg = crmDocTypes()[$type] ?? null;
    if (!$cfg) return '';
    ensureCrmDocumentsTable();
    $max = 0;
    try {
        $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(doc_number,'-',-1) AS UNSIGNED)) FROM crm_documents WHERE doc_type = ?");
        $st->execute([$type]);
        $max = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* první dokument */ }
    // „plain" = holé číslo navazující na papírovou řadu (výkupní listy: 202600001…)
    if (($cfg['numbering'] ?? '') === 'plain') {
        return (string)max((int)($cfg['first_number'] ?? 1), $max + 1);
    }
    return sprintf('%s-%04d', $cfg['prefix'], $max + 1);
}

function crmGetDocument(int $id): ?array {
    global $pdo;
    if ($id <= 0) return null;
    ensureCrmDocumentsTable();
    try {
        $st = $pdo->prepare("SELECT * FROM crm_documents WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return null;
        $doc['fields'] = json_decode((string)($doc['payload'] ?? ''), true) ?: [];
        return $doc;
    } catch (Throwable $e) { return null; }
}

/** Podpisy dokumentů: sloupec document_id na požadavcích podpisové stanice
 *  (řádky dokumentů mají order_id = 0) + tabulka uložených podpisů. */
function ensureDocumentSignatureSupport(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    ensureSignatureRequestsTable();
    try { $pdo->exec("ALTER TABLE signature_requests ADD COLUMN document_id INT NULL"); } catch (Throwable $e) { /* už existuje */ }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS document_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            signed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            requested_by VARCHAR(100) NULL,
            terms_text TEXT NULL,
            INDEX idx_ds_document (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // doplnění starší tabulky — u podpisu chceme mít i ZNĚNÍ, které klient viděl
        try { $pdo->exec("ALTER TABLE document_signatures ADD COLUMN terms_text TEXT NULL"); }
        catch (Throwable $e) { /* sloupec už existuje */ }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Podpis dokumentu (nejnovější) jako ['img' => data URI, 'at' => čas], jinak null. */
function crmGetDocumentSignature(int $documentId): ?array {
    global $pdo;
    if ($documentId <= 0) return null;
    try {
        ensureDocumentSignatureSupport();
        $st = $pdo->prepare("SELECT file_path, signed_at FROM document_signatures WHERE document_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$documentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $p = __DIR__ . '/../' . ltrim((string)$row['file_path'], '/');
        if (!is_file($p)) return null;
        return ['img' => 'data:image/png;base64,' . base64_encode((string)file_get_contents($p)), 'at' => (string)$row['signed_at']];
    } catch (Throwable $e) { return null; }
}

/** Fotky dokumentu (stav zařízení u výkupu apod.) — uploads/documents/<id>/. */
function ensureDocumentMediaTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS document_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) NULL,
            file_name VARCHAR(255) NULL,
            uploaded_by VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            kind VARCHAR(16) NOT NULL DEFAULT 'photo',
            INDEX idx_dm_document (document_id),
            INDEX idx_dm_kind (document_id, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // dorovnání starší tabulky (migrace 039) — kód se nasazuje dřív než migrace
        try { $pdo->exec("ALTER TABLE document_media ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'photo'"); }
        catch (Throwable $e) { /* sloupec už existuje */ }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Přílohy dokumentu jako [['id'=>, 'src'=>relativní cesta], …].
 *  VÝCHOZÍ druh je 'photo' = fotodokumentace zařízení. Skeny dokladu totožnosti
 *  ('id_front'/'id_back') se tak do tisku ani e-mailu NIKDY nedostanou — musí se
 *  o ně říct výslovně (crmGetDocumentIdScans). */
function crmGetDocumentMedia(int $documentId, string $kind = 'photo'): array {
    global $pdo;
    if ($documentId <= 0) return [];
    try {
        ensureDocumentMediaTable();
        $st = $pdo->prepare("SELECT id, file_path, file_type FROM document_media
            WHERE document_id = ? AND COALESCE(kind, 'photo') = ? ORDER BY id ASC");
        $st->execute([$documentId, $kind]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (str_starts_with((string)($r['file_type'] ?? ''), 'image/')) {
                $out[] = ['id' => (int)$r['id'], 'src' => (string)$r['file_path']];
            }
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Úložiště skenů dokladu totožnosti — SCHVÁLNĚ MIMO WEB.
 *
 * `uploads/` leží pod webovým kořenem a server běží na Caddy, kde soubor .htaccess
 * nic neblokuje — kdokoli s odkazem by si občanku stáhl. Skeny proto ukládáme o úroveň
 * výš (mimo dosah webu) a ven je pouští jen api/document_id_scan.php po ověření, že jde
 * o vedení. Cestu lze přebít nastavením `id_scan_dir` (např. na šifrovaný disk).
 */
function crmIdScanRoot(): string {
    $dir = trim((string)get_setting('id_scan_dir', ''));
    if ($dir === '') { $dir = dirname(__DIR__, 2) . '/crm-private/id_scans'; }
    $dir = rtrim($dir, '/');
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir;
}

/** Skeny dokladu totožnosti u výkupu — ['id_front' => […]|null, 'id_back' => […]|null].
 *  Používá se JEN v interním formuláři; do tištěného ani e-mailového dokladu nepatří. */
function crmGetDocumentIdScans(int $documentId): array {
    $out = ['id_front' => null, 'id_back' => null];
    foreach (['id_front', 'id_back'] as $kind) {
        $rows = crmGetDocumentMedia($documentId, $kind);
        if ($rows) {
            $last = end($rows);
            // obrázek se nesmí servírovat přímo — jen přes hlídaný endpoint
            $out[$kind] = ['id' => (int)$last['id'], 'src' => 'api/document_id_scan.php?id=' . (int)$last['id']];
        }
    }
    return $out;
}

/**
 * Výkup placený HOTOVĚ = výdej z kasy. Drží pokladní deník v souladu s dokumentem
 * (vazba ref_type='document'), ale HISTORII NIKDY nepřepisuje: pohyb ze staršího
 * dne je součástí už uzavřeného dne pokladní knihy (konečný zůstatek dne navazuje
 * na počáteční zůstatek dne dalšího) a jeho UPDATE/DELETE by knihu měnil zpětně —
 * fakticky by šlo doklad smazat, což § 11 zák. 563/1991 Sb. zakazuje. Oprava
 * částky nebo zrušení hotovostní výplaty se proto zapisuje jako NOVÝ rozdílový
 * (resp. opačný) pohyb s dnešním datem. Jen DNEŠNÍ osamocený pohyb se smí
 * upravit na místě — dnešek ještě uzavřený není a deník zůstane bez balastu
 * při běžném doladění listu před podpisem.
 */
/**
 * VÝKUP → PRODUKT NA SKLAD (kategorie „Výkupy"): po uložení výkupního listu se
 * z jeho údajů rovnou založí produkt — 1 ks, schovaný před e-shopem (hide_eshop),
 * kód = sériové číslo (jinak VYK-<číslo listu>), nákupní cena = výkupní částka,
 * pobočka podle dokumentu. Idempotence: crm_documents.vykup_product_id
 * (další uložení jen aktualizuje název/kód/nákupku, kusů se nedotýká).
 * Vrací id produktu (0 = nic nevzniklo).
 */
/**
 * Výkupní list → údaje skladové položky (název, výrobce, model, stav).
 *
 * Dřív se název skládal jako „model — popis", takže z DJI Osmo Mobile 7 vznikl
 * titulek „7 — Dji Osmo mobile" a na cenovku se dostal jen „7“ (štítek bere
 * primárně model). Skládáme proto lidsky: výrobce + popis + model, bez
 * zdvojování toho, co už v popisu je.
 *
 * Čistá funkce (bez DB) — jde otestovat i samostatně.
 */
function crmVykupProductFields(array $f): array {
    $norm = static function (string $v): string { return trim(preg_replace('/\s+/u', ' ', $v) ?? $v); };
    $brand = $norm((string)($f['item_brand'] ?? ''));
    $descr = $norm((string)($f['item_description'] ?? ''));
    $model = $norm((string)($f['item_model'] ?? ''));

    // Starší doklady mají značku i model v jednom poli („Apple iPhone 13“) —
    // pokud výrobce chybí, zkusíme ho vyčíst z prvního slova modelu nebo popisu.
    if ($brand === '') {
        $znamé = ['apple', 'samsung', 'dji', 'sony', 'lenovo', 'dell', 'hp', 'asus', 'acer', 'huawei',
                  'xiaomi', 'google', 'microsoft', 'jbl', 'bose', 'canon', 'nikon', 'gopro', 'garmin', 'lg'];
        foreach ([$model, $descr] as $zdroj) {
            $prvni = mb_strtolower((string)(explode(' ', $zdroj)[0] ?? ''));
            if ($prvni !== '' && in_array($prvni, $znamé, true)) {
                $brand = mb_convert_case($prvni, MB_CASE_TITLE, 'UTF-8');
                if ($prvni === 'dji' || $prvni === 'hp' || $prvni === 'jbl' || $prvni === 'lg') { $brand = mb_strtoupper($prvni, 'UTF-8'); }
                break;
            }
        }
    }

    // Název: skládá se z popisu a modelu tak, aby nevznikly patvary typu
    // „telefon Apple iPhone 13" (obecné slovo + celý název) ani „7 — Dji Osmo".
    $obsahuje = static function (string $hay, string $needle): bool {
        if ($needle === '') return true;
        return mb_stripos($hay, $needle) !== false;
    };
    if ($model === '')                        { $nazev = $descr; }
    elseif ($descr === '')                    { $nazev = $model; }
    elseif ($obsahuje($model, $descr))        { $nazev = $model; }   // model je konkrétnější
    elseif ($obsahuje($descr, $model))        { $nazev = $descr; }
    elseif (mb_strpos($model, ' ') !== false) { $nazev = $model; }   // model je celý název věci
    else                                      { $nazev = trim($descr . ' ' . $model); }
    if ($brand !== '' && !$obsahuje($nazev, $brand)) { $nazev = trim($brand . ' ' . $nazev); }

    // Stav „Stav A“ / „A“ / „grade B“ → jednopísmenný grade skladu.
    $grade = '';
    if (preg_match('/\b([ABCD])\b/u', mb_strtoupper($norm((string)($f['item_state'] ?? ''))), $mm)) {
        $grade = $mm[1];
    }

    return [
        'title' => mb_substr($nazev !== '' ? $nazev : 'Výkup', 0, 255),
        'manufacturer' => $brand !== '' ? mb_substr($brand, 0, 64) : null,
        'model' => $model !== '' ? mb_substr($model, 0, 128) : null,
        'grade' => $grade,
    ];
}

function crmSyncVykupProduct(int $docId): int {
    global $pdo;
    $doc = crmGetDocument($docId);
    if (!$doc || (string)$doc['doc_type'] !== 'vykup') return 0;
    ensureProductsTable();
    ensureProductsCrmColumns();
    ensureProductsVykupColumns();
    ensureProductsHideEshopColumn();
    ensureSkladBranchSchema();

    $f = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];
    $serial = trim((string)($f['item_serial'] ?? ''));
    $pf = crmVykupProductFields($f);
    $title = $pf['title'] !== 'Výkup' ? $pf['title'] : ('Výkup ' . (string)$doc['doc_number']);
    $model = (string)($pf['model'] ?? '');
    $buyPrice = crmParseAmountCzk((string)($doc['price'] ?? ''));
    $branch = (int)($doc['branch_id'] ?? 0) ?: (int)getDefaultBranchId();
    $stockKey = skladBranchCode($branch) === 'prikope' ? 'vaclavak' : 'karlin';
    $code = $serial !== '' ? mb_substr($serial, 0, 64) : ('VYK-' . (string)$doc['doc_number']);
    $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'výkup'));

    try {
        $existingId = (int)($doc['vykup_product_id'] ?? 0);
        if ($existingId > 0) {
            // oprava listu → jen srovnat údaje produktu; počet kusů nechat být
            $pdo->prepare("UPDATE products SET title = ?, manufacturer = ?, model = ?, grade = ?, product_code = ?, purchase_price = ?, branch_id = ?, stock_key = ? WHERE id = ? AND COALESCE(is_vykup, 0) = 1")
                ->execute([$title, $pf['manufacturer'], $pf['model'], (string)$pf['grade'], $code,
                    $buyPrice > 0 ? $buyPrice : null, $branch, $stockKey, $existingId]);
            return $existingId;
        }
        // kolize kódu (stejné sériovko už ve skladu) → jen provázat, nezakládat druhý kus
        $chk = $pdo->prepare("SELECT id FROM products WHERE product_code = ?");
        $chk->execute([$code]);
        if ($dupe = $chk->fetch()) {
            $pid = (int)$dupe['id'];
            $pdo->prepare("UPDATE crm_documents SET vykup_product_id = ? WHERE id = ?")->execute([$pid, $docId]);
            return $pid;
        }
        $pdo->prepare("INSERT INTO products (product_code, title, manufacturer, model, grade, price, stock_qty, stock_key, branch_id, purchase_price, source, created_by, is_vykup, vykup_document_id, hide_eshop, added_at, first_seen_at, last_seen_at)
                       VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?, ?, 'crm', ?, 1, ?, 1, NOW(), NOW(), NOW())")
            ->execute([$code, $title, $pf['manufacturer'], $pf['model'], (string)$pf['grade'], $stockKey, $branch,
                $buyPrice > 0 ? $buyPrice : null, $by, $docId]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE crm_documents SET vykup_product_id = ? WHERE id = ?")->execute([$pid, $docId]);
        crmAuditLog('products.create', [
            'entity_type' => 'product', 'entity_id' => $pid, 'entity_label' => $title,
            'summary' => 'Výkup → naskladněn produkt „' . $title . '" (kód ' . $code . ', list ' . $doc['doc_number'] . ($buyPrice > 0 ? ', výkupní cena ' . number_format($buyPrice, 0, ',', ' ') . ' Kč' : '') . ')',
        ]);
        return $pid;
    } catch (Throwable $e) { error_log('crmSyncVykupProduct: ' . $e->getMessage()); return 0; }
}

function crmSyncVykupCashMovement(int $docId): void {
    global $pdo;
    $doc = crmGetDocument($docId);
    if (!$doc || (string)$doc['doc_type'] !== 'vykup') return;
    ensurePosCashMovementsTable();

    $payment = (string)($doc['fields']['sign_payment'] ?? '');
    $isCash = $payment !== '' && mb_stripos($payment, 'hotov') !== false;
    $amount = crmParseAmountCzk((string)($doc['price'] ?? ''));
    $label = (string)$doc['doc_number'];
    $note = trim((string)($doc['customer_name'] ?? '') . ' · ' . (string)($doc['subject'] ?? ''), ' ·');
    $branch = (int)($doc['branch_id'] ?? 0) ?: null;
    $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

    // Cílový čistý výdej z kasy podle aktuální podoby dokumentu.
    $target = ($isCash && $amount > 0) ? $amount : 0.0;
    // Výplata přes POKLADNU (payout_sale_id): peníze drží záporná prodejka —
    // dokument sám už výdej negenerovat, jinak by se výkup odepsal dvakrát.
    if ((int)($doc['payout_sale_id'] ?? 0) > 0) { $target = 0.0; }

    try {
        // Pohybů může být k dokumentu víc (původní + rozdílové opravy) —
        // porovnává se proto ČISTÝ výdej (out − in), ne jeden řádek.
        $st = $pdo->prepare("SELECT id, direction, amount, (DATE(created_at) = CURDATE()) AS is_today
                             FROM pos_cash_movements WHERE ref_type = 'document' AND ref_id = ? ORDER BY id");
        $st->execute([$docId]);
        $moves = $st->fetchAll(PDO::FETCH_ASSOC);

        $net = 0.0;
        foreach ($moves as $m) {
            $net += ((string)$m['direction'] === 'out' ? 1 : -1) * (float)$m['amount'];
        }
        $diff = round($target - $net, 2);
        if (abs($diff) < 0.005) { return; }   // deník už sedí — nic nezapisovat

        // UZÁVĚRKA až TADY — hlídá se jen skutečný zápis (pohyb vzniká k dnešku;
        // historické pohyby se nemění nikdy). No-op re-save projít smí.
        if (function_exists('afxAccountingAssertOpen')) {
            afxAccountingAssertOpen(date('Y-m-d'), 'pohyb hotovosti z výkupu');
        }

        // První zápis hotovostní výplaty — založit výdaj jako dřív.
        if (!$moves && $target > 0) {
            $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
                           VALUES (?, 'out', ?, 'vykup', 'document', ?, ?, ?, ?)")
                ->execute([$branch, $target, $docId, $label, mb_substr($note, 0, 255), $by !== '' ? mb_substr($by, 0, 100) : null]);
            crmAuditLog('kasa.cash_move', [
                'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                'summary' => 'Výdej z kasy ' . formatMoney($target) . ' — výkup ' . $label . ($note !== '' ? ' (' . $note . ')' : ''),
            ]);
            return;
        }

        // Jediný pohyb z DNEŠKA: úprava na místě je bezpečná (den není uzavřený).
        if (count($moves) === 1 && !empty($moves[0]['is_today'])) {
            if ($target > 0) {
                $pdo->prepare("UPDATE pos_cash_movements SET amount = ?, branch_id = ?, ref_label = ?, note = ? WHERE id = ?")
                    ->execute([$target, $branch, $label, mb_substr($note, 0, 255), (int)$moves[0]['id']]);
                crmAuditLog('kasa.cash_move', [
                    'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                    'summary' => 'Výdej z kasy na výkup ' . $label . ' upraven na ' . formatMoney($target) . ' (dnešní pohyb)',
                ]);
            } else {
                $pdo->prepare("DELETE FROM pos_cash_movements WHERE id = ?")->execute([(int)$moves[0]['id']]);
                crmAuditLog('kasa.cash_move', [
                    'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                    'summary' => 'Výdej z kasy na výkup ' . $label . ' zrušen (výplata už není hotově, dnešní pohyb)',
                ]);
            }
            return;
        }

        // Historický pohyb → ROZDÍLOVÝ pohyb s dnešním datem: kladný rozdíl =
        // doplatit z kasy (out), záporný = vrácení do kasy (in) — snížení ceny
        // nebo přepnutí výplaty na převod.
        $dir = $diff > 0 ? 'out' : 'in';
        $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
                       VALUES (?, ?, ?, 'vykup', 'document', ?, ?, ?, ?)")
            ->execute([$branch, $dir, abs($diff), $docId, $label,
                       mb_substr('Oprava výkupu ' . $label . ($target <= 0 ? ' — výplata už není hotově' : ' — nová cena ' . formatMoney($target)), 0, 255),
                       $by !== '' ? mb_substr($by, 0, 100) : null]);
        crmAuditLog('kasa.cash_move', [
            'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
            'summary' => ($dir === 'out' ? 'Doplatek z kasy ' : 'Vrácení do kasy ') . formatMoney(abs($diff))
                . ' — oprava výkupu ' . $label . ' (historický pohyb se nemění, zapsán rozdíl k dnešku)',
        ]);
    } catch (AfxAccountingClosedException $e) {
        // uzávěrka NESMÍ skončit v error_logu — save_document ji hlásí obsluze
        throw $e;
    } catch (Throwable $e) { error_log('crmSyncVykupCashMovement: ' . $e->getMessage()); }
}

/** Jazyk dokumentů: povolené cs/en/ru (uk → en řeší crmCustomerDocLang jinde). */
function crmDocLangOrDefault(?string $lang): string {
    $lang = strtolower(trim((string)$lang));
    return in_array($lang, ['cs', 'en', 'ru'], true) ? $lang : 'cs';
}

/**
 * Vykreslí A4 „sheet" dokumentu ve stylu klientských dokladů (zakázkový list /
 * reklamační protokol). $mode: 'form' = interaktivní inputy (dokument.php),
 * 'static' = hodnoty jako text (e-mail, případně náhled).
 * Vrací HTML VNITŘKU stránky (bez <html>/<head>) — CSS dodává volající.
 */
function crmRenderDocumentSheet(string $type, array $values, string $lang, string $mode, string $docNumber, string $docDate, int $docId = 0, ?array $overridePhotos = null): string {
    $cfg = crmDocTypes()[$type] ?? null;
    if (!$cfg) return '';
    $L = function (string $key) use ($lang) { return __($key, $lang); };
    $company = get_setting('company_name', 'AppleFix s.r.o.');
    $companyIco = get_setting('company_ico', '');
    $companyDic = trim((string)get_setting('company_dic', ''));
    // Provozovna se bere z DOKLADU, ne z přihlášeného člověka: kdo tiskne výkupní
    // list pořízený na jiné pobočce (typicky ze skladu), měl by dřív v patičce
    // adresu i telefon své vlastní prodejny — tedy nepravdivý údaj o tom, kde
    // obchod proběhl. Bez uloženého dokladu (nový formulář) zůstává vlastní.
    $docBranchId = (int)getCurrentStaffBranchId();
    if ($docId > 0) {
        try {
            global $pdo;
            $bst = $pdo->prepare("SELECT branch_id FROM crm_documents WHERE id = ? LIMIT 1");
            $bst->execute([$docId]);
            $bidDoc = (int)($bst->fetchColumn() ?: 0);
            if ($bidDoc > 0) { $docBranchId = $bidDoc; }
        } catch (Throwable $e) { /* zůstane pobočka přihlášeného */ }
    }
    $bc = crmOrderBranchContact($docBranchId);
    $companyEmail = $bc['email'] ?: get_setting('smtp_from_email', 'info@applefix.cz');

    $logoFs = __DIR__ . '/../assets/img/logo-black.png';
    $logoData = is_file($logoFs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoFs)) : '';

    // Šířka pole ve dvanáctisloupcové mřížce. Krátké údaje (telefon, rodné číslo,
    // pohlaví, datum a místo narození, občanství, číslo a platnost dokladu, částky)
    // si berou čtvrtinu řádku, delší texty polovinu. Co tu není, dostane třetinu.
    $sirkaPole = [
        'customer_name' => 5, 'customer_phone' => 3, 'customer_email' => 4, 'customer_address' => 6,
        'customer_pid' => 3, 'customer_birth' => 3, 'customer_gender' => 3, 'customer_birthplace' => 3,
        'customer_citizenship' => 3, 'customer_id_type' => 3, 'customer_id_doc' => 3,
        'customer_id_issuer' => 4, 'customer_id_valid' => 2, 'customer_id_verified' => 3,
        'customer_biz_name' => 4, 'customer_biz_address' => 5, 'customer_biz_ico' => 3,
        'item_description' => 6, 'item_brand' => 3, 'item_model' => 3, 'item_serial' => 3,
        'item_price' => 3, 'item_estimate' => 3,
        'loan_amount' => 3, 'due_date' => 3, 'fee_rate' => 3,
        'sign_place_date' => 6, 'sign_payment' => 6,
    ];
    $fieldHtml = function (array $f) use ($values, $mode, $L, $sirkaPole) {
        $name = $f['n'];
        $isArea = ($f['t'] ?? '') === 'textarea';
        $val = (string)($values[$name] ?? '');
        $sirka = $isArea ? ' dfield--wide' : ' dfield--c' . ($sirkaPole[$name] ?? 4);
        $h = '<div class="dfield' . $sirka . '">';
        $h .= '<label>' . e($L($f['l'])) . '</label>';
        if ($mode === 'form') {
            $h .= $isArea
                ? '<textarea class="dinput" name="' . e($name) . '" rows="2">' . e($val) . '</textarea>'
                : '<input type="text" class="dinput" name="' . e($name) . '" value="' . e($val) . '">';
        } else {
            $h .= '<div class="dvalue">' . ($val !== '' ? nl2br(e($val)) : '&nbsp;') . '</div>';
        }
        return $h . '</div>';
    };

    $h = '<div class="sheet">';
    $h .= '<div class="accent-bar"></div>';
    $h .= '<div class="doc-head">';
    $h .= $logoData !== '' ? '<img src="' . $logoData . '" alt="' . e($company) . '">' : '<div></div>';
    $h .= '<div class="doc-meta">';
    $h .= '<div class="doc-kicker">' . e($L($cfg['kicker_key'])) . '</div>';
    $h .= '<div class="doc-code">' . e($docNumber !== '' ? $docNumber : '—') . '</div>';
    if ($mode === 'form') {
        $h .= '<div class="doc-date"><input type="text" class="dinput dinput--date" name="doc_date" value="' . e($docDate) . '"></div>';
    } else {
        $h .= '<div class="doc-date">' . e($docDate) . '</div>';
    }
    $h .= '</div></div>';
    $h .= '<div class="head-sep"></div>';
    $h .= '<div class="body">';
    $h .= '<div class="doc-title">' . e($L($cfg['title_key'])) . '</div>';

    foreach ($cfg['sections'] as $sec) {
        $h .= '<div class="block"><h3>' . e($L($sec['h'])) . '</h3><div class="fgrid">';
        foreach ($sec['fields'] as $f) { $h .= $fieldHtml($f); }
        $h .= '</div></div>';
    }

    // Doklad totožnosti prodávajícího — JEN u výkupu a JEN ve formuláři.
    // Zákon o opatřeních proti legalizaci výnosů z trestné činnosti (253/2008 Sb.)
    // ukládá u obchodu s použitým zbožím identifikovat prodávajícího. Sken je citlivý
    // údaj: neukazuje se na tištěném dokladu ani v e-mailu klientovi (a nesmí se tam
    // dostat ani omylem — proto vlastní druh přílohy, ne fotodokumentace).
    if ($type === 'vykup' && $mode === 'form') {
        $scans = $docId > 0 ? crmGetDocumentIdScans($docId) : ['id_front' => null, 'id_back' => null];
        $slot = function (string $kind, string $label) use ($scans) {
            $cur = $scans[$kind] ?? null;
            $h = '<div class="idscan" data-kind="' . e($kind) . '">';
            $h .= '<div class="idscan-label">' . e($label) . '</div>';
            if ($cur) {
                $h .= '<span class="idscan-thumb"><img src="' . e((string)$cur['src']) . '" alt="' . e($label) . '">'
                    . '<button type="button" class="photo-del" data-media-id="' . (int)$cur['id'] . '" title="Smazat">&times;</button></span>';
            } else {
                $h .= '<label class="idscan-add"><input type="file" class="idscan-input" data-kind="' . e($kind) . '"'
                    . ' accept="image/*" capture="environment" style="display:none;"><span>+</span></label>';
            }
            return $h . '</div>';
        };
        $h .= '<div class="block block--internal"><h3>' . e($L('cdoc_idscan_title'))
            . ' <span class="internal-tag">' . e($L('cdoc_internal_only')) . '</span></h3>';
        $h .= '<div class="idscans">' . $slot('id_front', $L('cdoc_idscan_front')) . $slot('id_back', $L('cdoc_idscan_back')) . '</div>';
        $h .= '<div class="idscan-note">' . e($L('cdoc_idscan_note')) . '</div>';
        $h .= '</div>';
    }

    // Fotodokumentace (stav zařízení): uložené fotky + ve formuláři tlačítko
    // „Přidat fotky / vyfotit" (na telefonu otevře rovnou foťák).
    $photos = $overridePhotos !== null ? $overridePhotos : ($docId > 0 ? crmGetDocumentMedia($docId) : []);
    if ($photos || $mode === 'form') {
        $h .= '<div class="block"><h3>' . e($L('photo_documentation')) . '</h3><div class="photos">';
        foreach ($photos as $ph) {
            $h .= '<span class="photo-item">';
            $h .= '<img src="' . e((string)$ph['src']) . '" alt="foto">';
            if ($mode === 'form' && !empty($ph['id'])) {
                $h .= '<button type="button" class="photo-del" data-media-id="' . (int)$ph['id'] . '" title="Smazat">&times;</button>';
            }
            $h .= '</span>';
        }
        if ($mode === 'form') {
            $h .= '<label class="photo-add" id="docPhotoAdd" title="Přidat fotky / vyfotit">'
                . '<input type="file" id="docPhotoInput" accept="image/*" capture="environment" multiple style="display:none;">'
                . '<span>+</span></label>';
        }
        $h .= '</div></div>';
    }

    $h .= '<div class="fineprint"><ol>';
    foreach ($cfg['legal'] as $lk) { $h .= '<li>' . e($L($lk)) . '</li>'; }
    $h .= '</ol></div>';

    // Elektronický podpis klienta z podpisové stanice — nad levou podpisovou
    // linkou (klient = prodávající / zástavce), stejně jako u zakázkového listu.
    $sig = $docId > 0 ? crmGetDocumentSignature($docId) : null;
    $h .= '<div class="sign">';
    if ($sig) {
        $h .= '<div class="slot signed">'
            . '<img class="sig-img" src="' . $sig['img'] . '" alt="podpis">'
            . '<div class="sigline">' . e($L($cfg['sign_left'])) . '</div>'
            . '<div class="sig-at">' . e($L('ord_signed_electronically')) . ' ' . e(date('j. n. Y H:i', strtotime((string)$sig['at']))) . '</div>'
            . '</div>';
    } else {
        $h .= '<div class="slot">' . e($L($cfg['sign_left'])) . '</div>';
    }
    $h .= '<div class="slot">' . e($L($cfg['sign_right'])) . ' ' . e($company) . '</div>';
    $h .= '</div>';

    $h .= '<div class="foot"><div class="foot-name">' . e($company) . '</div><div class="foot-line">'
        . e(trim((string)$bc['address_inline']))
        . ($companyIco !== '' ? ' · IČO: ' . e($companyIco) : '')
        . ($companyDic !== '' ? ' · DIČ: ' . e($companyDic) : '')
        . ' · Tel.: ' . e(trim((string)$bc['phone']))
        . ($companyEmail !== '' ? ' · ' . e($companyEmail) : '')
        . '</div></div>';

    $h .= '</div></div>';
    return $h;
}

/** Sdílené CSS dokumentového sheetu (jednotný vizuál klientských dokladů). */
function crmDocumentSheetCss(): string {
    return <<<'CSS'
        :root { --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0;
                --accent:#0a84ff; --accent-ink:#0a5bd6; --soft:#f6f8fb; }
        * { box-sizing: border-box; }
        .sheet { max-width: 840px; margin: auto; background: #fff; border-radius: 18px; overflow: hidden;
                 box-shadow: 0 24px 64px rgba(17,20,24,0.12); color: var(--ink);
                 font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; font-size: 13px; line-height: 1.55; }
        .accent-bar { height: 5px; background: linear-gradient(90deg, #0a84ff, #5ac8fa 55%, #64d2ff); }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding: 28px 34px 0; }
        .doc-head img { height: 34px; width: auto; }
        .doc-meta { text-align: right; }
        .doc-kicker { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: var(--accent-ink); font-weight: 800; }
        .doc-code { font-size: 24px; font-weight: 800; letter-spacing: -0.03em; margin: 3px 0 0; font-family: ui-monospace, Menlo, monospace; }
        .doc-date { font-size: 11px; color: var(--muted); margin-top: 5px; font-weight: 300; }
        .head-sep { margin: 16px 34px 0; border-bottom: 1px solid var(--line); }
        .body { padding: 16px 34px 16px; }
        .doc-title { font-size: 26px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 12px; }
        .block { margin: 9px 0; border: 1px solid var(--line); border-radius: 14px; padding: 12px 18px 10px; }
        .block h3 { font-size: 13.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink); margin: 0 0 7px; font-weight: 800; }
        /* Dvanáctisloupcová mřížka: sekce prodávajícího má kvůli zákonnému rozsahu
           identifikace 16 polí a ve dvou sloupcích doklad přetékal na druhou stranu A4.
           Každé pole si bere jen tolik místa, kolik potřebuje — krátké údaje (telefon,
           rodné číslo, pohlaví, datum a místo narození, občanství, platnost dokladu)
           čtvrtinu řádku, jméno a adresa polovinu, textová pole celý řádek. */
        .fgrid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 7px 18px; }
        .dfield--wide { grid-column: 1 / -1; }
        .dfield--c2 { grid-column: span 2; }
        .dfield--c3 { grid-column: span 3; }
        .dfield--c4 { grid-column: span 4; }
        .dfield--c5 { grid-column: span 5; }
        .dfield--c6 { grid-column: span 6; }
        /* Na telefonu se mřížka smrskne: krátké údaje zůstanou po dvou vedle sebe,
           všechno ostatní přes celou šířku (jinak by span 3 vyrobil implicitní sloupce). */
        @media screen and (max-width: 760px) {
            .fgrid { grid-template-columns: repeat(4, 1fr); }
            .fgrid > .dfield { grid-column: span 4; }
            .fgrid > .dfield--c2,
            .fgrid > .dfield--c3 { grid-column: span 2; }
        }
        .dfield label { display: block; font-size: 9.5px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-bottom: 1px; }
        .dinput { width: 100%; border: none; border-bottom: 1px dashed #c9cfd8; background: transparent; padding: 2px 0 4px;
                  font: inherit; font-weight: 700; color: var(--ink); outline: none; resize: vertical; }
        .dinput:focus { border-bottom-color: var(--accent); }
        .dinput--date { text-align: right; font-weight: 300; font-size: 11px; color: var(--muted); border-bottom: 1px dashed #d8dde4; width: 130px; }
        .dvalue { min-height: 19px; border-bottom: 1px solid var(--line); padding: 1px 0 3px; font-weight: 700; }
        .photos { display: flex; flex-wrap: wrap; gap: 8px; }
        .photo-item { position: relative; display: inline-block; }
        .photos img { width: 110px; height: 110px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); display: block; }
        .photo-del { position: absolute; top: -6px; right: -6px; width: 22px; height: 22px; border-radius: 50%;
                     border: none; background: #ff375f; color: #fff; font-size: 14px; line-height: 1; cursor: pointer;
                     box-shadow: 0 2px 6px rgba(0,0,0,.25); }
        .photo-add { width: 110px; height: 110px; border: 2px dashed #c9cfd8; border-radius: 10px; display: flex;
                     align-items: center; justify-content: center; cursor: pointer; color: #949aa4; font-size: 34px;
                     font-weight: 300; transition: border-color .15s, color .15s; }
        .photo-add:hover { border-color: var(--accent); color: var(--accent); }
        /* doklad totožnosti — interní blok, na tisku se nikdy neobjeví */
        .block--internal { border: 1px dashed #f0a500; border-radius: 12px; padding: 10px 12px; background: #fffdf6; }
        .internal-tag { font-size: 10.5px; font-weight: 700; color: #a86a00; background: #ffefc2;
                        border-radius: 6px; padding: 2px 7px; margin-left: 6px; vertical-align: middle; }
        .idscans { display: flex; gap: 14px; flex-wrap: wrap; }
        .idscan-label { font-size: 11px; font-weight: 700; color: var(--muted); margin-bottom: 4px; }
        .idscan-thumb { position: relative; display: inline-block; }
        .idscan-thumb img { width: 150px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); display: block; }
        .idscan-add { width: 150px; height: 100px; border: 2px dashed #c9cfd8; border-radius: 8px; display: flex;
                      align-items: center; justify-content: center; font-size: 24px; color: #9aa3ae; cursor: pointer; }
        .idscan-add:hover { border-color: var(--accent); color: var(--accent); }
        .idscan-note { font-size: 10.5px; color: var(--muted); margin-top: 8px; line-height: 1.45; }
        @media print { .block--internal { display: none !important; } }
        @media print { .photo-add, .photo-del { display: none !important; } }
        .fineprint { margin-top: 14px; padding-top: 12px; border-top: 2px solid var(--line);
                     font-size: 10px; color: #495059; line-height: 1.55; font-weight: 300; text-align: justify; }
        .fineprint ol { margin: 0; padding-left: 16px; }
        .fineprint li { margin-bottom: 3px; }
        .sign { display: flex; gap: 40px; margin-top: 20px; }
        .sign .slot { flex: 1; border-top: 1.4px solid var(--ink); padding-top: 7px; font-size: 10.5px; color: var(--muted); text-align: center; }
        .sign .slot.signed { border-top: none; padding-top: 0; }
        .sign .slot.signed .sig-img { display: block; max-height: 54px; margin: 0 auto 2px; }
        .sign .slot.signed .sigline { border-top: 1.4px solid var(--ink); padding-top: 7px; }
        .sign .slot.signed .sig-at { margin-top: 3px; font-size: 9px; color: #8a929c; }
        .foot { margin-top: 14px; padding-top: 9px; border-top: 1px solid var(--line); text-align: center; padding-bottom: 4px; }
        .foot .foot-name { font-size: 12px; font-weight: 800; letter-spacing: 0.02em; color: var(--ink); }
        .foot .foot-line { font-size: 10px; color: var(--muted); font-weight: 300; margin-top: 4px; letter-spacing: 0.02em; }
        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 210mm; }
            .dinput { border-bottom: 1px solid #9aa1ab; }
        }
CSS;
}

/** Statický HTML dokumentu pro e-mail (kompletní <html> s inline CSS). */
function crmRenderDocumentEmailHtml(array $doc): string {
    $type = (string)$doc['doc_type'];
    $lang = crmDocLangOrDefault($doc['lang'] ?? 'cs');
    $date = !empty($doc['doc_date']) ? date('d.m.Y', strtotime((string)$doc['doc_date'])) : date('d.m.Y');
    // Fotky do e-mailu jako data: URI s rozpočtem (max 4 fotky / ~6 MB base64),
    // ať e-mail projde běžnými SMTP limity.
    $emailPhotos = [];
    $budget = 6 * 1024 * 1024;
    foreach (crmGetDocumentMedia((int)$doc['id']) as $ph) {
        if (count($emailPhotos) >= 4 || $budget <= 0) break;
        $p = __DIR__ . '/../' . ltrim((string)$ph['src'], '/');
        if (!is_file($p)) continue;
        $bin = (string)file_get_contents($p);
        $b64 = base64_encode($bin);
        if (strlen($b64) > $budget) continue;
        $budget -= strlen($b64);
        $mime = function_exists('mime_content_type') ? (string)mime_content_type($p) : 'image/jpeg';
        $emailPhotos[] = ['id' => 0, 'src' => 'data:' . $mime . ';base64,' . $b64];
    }
    $sheet = crmRenderDocumentSheet($type, $doc['fields'] ?? [], $lang, 'static', (string)$doc['doc_number'], $date, (int)$doc['id'], $emailPhotos);
    $css = crmDocumentSheetCss();
    return '<!DOCTYPE html><html lang="' . e($lang) . '"><head><meta charset="UTF-8"><style>'
        . 'body { margin:0; padding:24px 12px; background:#eceff3; }' . $css
        . '</style></head><body>' . $sheet . '</body></html>';
}

/* ── ONLINE VYPLNĚNÍ VÝKUPNÍHO LISTU KLIENTEM (v3.49.2) ─────────────────────
   E-mail s výkupním listem nese odkaz s tajným tokenem (vykup_online.php?t=…).
   Klient doma doplní své údaje a sériové číslo, odesláním se dokument uloží
   a crmSyncVykupProduct založí/doplní vykoupený produkt — stejně jako při
   vyplnění na prodejně. Cena a služební pole jsou klientovi zamčená. */

function ensureDocPublicTokenColumn(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    ensureCrmDocumentsTable();
    try { $pdo->exec("ALTER TABLE crm_documents ADD COLUMN public_token VARCHAR(64) NULL"); } catch (Throwable $e) { /* už existuje */ }
    try { $pdo->exec("ALTER TABLE crm_documents ADD INDEX idx_doc_token (public_token)"); } catch (Throwable $e) { /* už existuje */ }
}

/** Vrátí (a při prvním použití vygeneruje) tajný token dokumentu pro online vyplnění. */
function crmDocPublicToken(int $docId): string {
    global $pdo;
    ensureDocPublicTokenColumn();
    $st = $pdo->prepare("SELECT public_token FROM crm_documents WHERE id = ? LIMIT 1");
    $st->execute([$docId]);
    $t = (string)($st->fetchColumn() ?: '');
    if ($t !== '') { return $t; }
    $t = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE crm_documents SET public_token = ? WHERE id = ? AND (public_token IS NULL OR public_token = '')")
        ->execute([$t, $docId]);
    // souběh dvou odeslání: platí ten, kdo zapsal první
    $st->execute([$docId]);
    return (string)($st->fetchColumn() ?: $t);
}

/**
 * Předvyplnění nového dokladu (v3.67.0).
 * Místo a datum podpisu se dosud psalo ručně u každého listu, přestože obojí
 * CRM ví: místo = provozovna, na které se doklad vystavuje, datum = dnešek.
 */
function crmDocDefaultValues(string $type): array {
    $out = [];
    $misto = '';
    try {
        $bid = function_exists('getCurrentStaffBranchId') ? (int)getCurrentStaffBranchId() : 0;
        if ($bid > 0) {
            global $pdo;
            $st = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
            $st->execute([$bid]);
            $misto = trim((string)($st->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) { /* bez pobočky se doplní aspoň datum */ }
    if ($misto === '') { $misto = trim((string)get_setting('company_city', '')); }
    $datum = date('j. n. Y');
    $out['sign_place_date'] = $misto !== '' ? ($misto . ', ' . $datum) : $datum;
    // Způsob výplaty zůstával prázdný, přestože se drtivá většina výkupů platí
    // hotově z kasy — a doklad bez toho, JAK byly peníze předány, je neúplný.
    // Kdo platí převodem, přepíše (pole je normální text).
    if ($type === 'vykup') { $out['sign_payment'] = 'Hotově'; }
    return $out;
}

/**
 * Údaje, bez kterých není výkupní list úplný.
 *
 * Živnostenský zákon (§ 31 odst. 6 zák. č. 455/1991 Sb.) ukládá u obchodu
 * s použitým zbožím identifikovat nejen prodávajícího, ale i PŘEDMĚT obchodu —
 * u elektroniky k tomu slouží sériové číslo / IMEI. Vrací popisky prázdných
 * polí, ať obsluha ví, co doplnit, než doklad vytiskne a nechá podepsat.
 */
function crmDocMissingImportant(string $type, array $values): array {
    if ($type !== 'vykup') { return []; }
    $need = [
        'customer_name'        => 'jméno a příjmení prodávajícího',
        'customer_address'     => 'adresa prodávajícího',
        'customer_id_doc'      => 'číslo dokladu totožnosti',
        'customer_id_verified' => 'kdo ověřil totožnost',
        'item_description'     => 'popis zařízení',
        'item_brand'           => 'výrobce / značka (jde na cenovku i do skladu)',
        'item_serial'          => 'sériové číslo / IMEI zařízení',
        'item_price'           => 'výkupní cena',
        'sign_payment'         => 'způsob výplaty',
    ];
    $out = [];
    foreach ($need as $field => $label) {
        if (trim((string)($values[$field] ?? '')) === '') { $out[$field] = $label; }
    }
    return $out;
}

/**
 * Částka na dokladu vždy s měnou: „5000" → „5 000 Kč".
 * Text bez čísla (např. „dohodou") se nechává být — dopsat k němu měnu
 * by vyrobilo nesmysl.
 */
function crmDocFormatAmount(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') { return ''; }
    $mena = trim((string)get_setting('currency', 'Kč')) ?: 'Kč';
    if (!preg_match('/\d/', $raw)) { return $raw; }
    // už měnu má (v jakémkoli tvaru) → jen ořezat mezery navíc
    if (mb_stripos($raw, $mena) !== false) { return trim(preg_replace('/\s{2,}/u', ' ', $raw) ?? $raw); }
    $amount = crmParseAmountCzk($raw);
    if ($amount <= 0) { return $raw; }
    $whole = abs($amount - round($amount)) < 0.005;
    // haléře se nechávají celé („12 490,50 Kč"), ořezaná nula vypadá jako chyba
    $num = number_format($amount, $whole ? 0 : 2, ',', "\u{00a0}");
    return $num . ' ' . $mena;
}

/** Pole, která klient při online vyplnění NESMÍ měnit (cena a služební údaje). */
function crmDocOnlineLockedFields(): array {
    return ['item_price', 'customer_id_verified', 'sign_place_date', 'sign_payment', 'doc_date'];
}
