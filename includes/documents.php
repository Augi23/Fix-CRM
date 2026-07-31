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
                ['h' => 'cdoc_sec_seller', 'fields' => [
                    ['n' => 'customer_name',    'l' => 'cdoc_f_name'],
                    ['n' => 'customer_phone',   'l' => 'cdoc_f_phone'],
                    ['n' => 'customer_email',   'l' => 'cdoc_f_email'],
                    ['n' => 'customer_address', 'l' => 'cdoc_f_address'],
                    ['n' => 'customer_birth',   'l' => 'cdoc_f_birth'],
                    ['n' => 'customer_id_doc',  'l' => 'cdoc_f_iddoc'],
                    ['n' => 'customer_id_valid','l' => 'cdoc_f_idvalid'],
                ]],
                ['h' => 'cdoc_sec_item_vykup', 'fields' => [
                    ['n' => 'item_description', 'l' => 'cdoc_f_item_desc'],
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
            'legal'      => ['buyout_agreement_text', 'cdoc_vykup_legal2', 'cdoc_vykup_legal3', 'cdoc_vykup_gdpr'],
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
                ['h' => 'cdoc_sec_pledger', 'fields' => [
                    ['n' => 'customer_name',    'l' => 'cdoc_f_name'],
                    ['n' => 'customer_phone',   'l' => 'cdoc_f_phone'],
                    ['n' => 'customer_email',   'l' => 'cdoc_f_email'],
                    ['n' => 'customer_address', 'l' => 'cdoc_f_address'],
                    ['n' => 'customer_id_doc',  'l' => 'cdoc_f_iddoc'],
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
            INDEX idx_ds_document (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
 * Výkup placený HOTOVĚ = výdej z kasy. Drží pokladní deník v souladu s dokumentem:
 * po každém uložení výkupního listu založí/aktualizuje výdajový pohyb (vazba
 * ref_type='document'), a když výplata přestane být hotově, pohyb zase odebere.
 * Kasa tak sedí na korunu a u pohybu je dohledatelné číslo výkupního listu.
 */
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

    try {
        $st = $pdo->prepare("SELECT id, amount FROM pos_cash_movements WHERE ref_type = 'document' AND ref_id = ? LIMIT 1");
        $st->execute([$docId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($isCash && $amount > 0) {
            if ($existing) {
                $pdo->prepare("UPDATE pos_cash_movements SET amount = ?, branch_id = ?, ref_label = ?, note = ? WHERE id = ?")
                    ->execute([$amount, (int)($doc['branch_id'] ?? 0) ?: null, $label, mb_substr($note, 0, 255), (int)$existing['id']]);
                if (abs((float)$existing['amount'] - $amount) >= 0.005) {
                    crmAuditLog('kasa.cash_move', [
                        'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                        'summary' => 'Výdej z kasy na výkup ' . $label . ' upraven na ' . formatMoney($amount),
                    ]);
                }
            } else {
                $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
                $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
                               VALUES (?, 'out', ?, 'vykup', 'document', ?, ?, ?, ?)")
                    ->execute([(int)($doc['branch_id'] ?? 0) ?: null, $amount, $docId, $label, mb_substr($note, 0, 255), $by !== '' ? mb_substr($by, 0, 100) : null]);
                crmAuditLog('kasa.cash_move', [
                    'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                    'summary' => 'Výdej z kasy ' . formatMoney($amount) . ' — výkup ' . $label . ($note !== '' ? ' (' . $note . ')' : ''),
                ]);
            }
        } elseif ($existing) {
            $pdo->prepare("DELETE FROM pos_cash_movements WHERE id = ?")->execute([(int)$existing['id']]);
            crmAuditLog('kasa.cash_move', [
                'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => $label,
                'summary' => 'Výdej z kasy na výkup ' . $label . ' zrušen (výplata už není hotově)',
            ]);
        }
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
    $bc = crmOrderBranchContact((int)getCurrentStaffBranchId());
    $companyEmail = $bc['email'] ?: get_setting('smtp_from_email', 'info@applefix.cz');

    $logoFs = __DIR__ . '/../assets/img/logo-black.png';
    $logoData = is_file($logoFs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoFs)) : '';

    $fieldHtml = function (array $f) use ($values, $mode, $L) {
        $name = $f['n'];
        $isArea = ($f['t'] ?? '') === 'textarea';
        $val = (string)($values[$name] ?? '');
        $h = '<div class="dfield' . ($isArea ? ' dfield--wide' : '') . '">';
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
        .body { padding: 18px 34px 30px; }
        .doc-title { font-size: 27px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 16px; }
        .block { margin: 14px 0; border: 1px solid var(--line); border-radius: 14px; padding: 14px 18px 12px; }
        .block h3 { font-size: 14px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink); margin: 0 0 10px; font-weight: 800; }
        .fgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 22px; }
        .dfield--wide { grid-column: 1 / -1; }
        .dfield label { display: block; font-size: 9.5px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-bottom: 1px; }
        .dinput { width: 100%; border: none; border-bottom: 1px dashed #c9cfd8; background: transparent; padding: 2px 0 4px;
                  font: inherit; font-weight: 700; color: var(--ink); outline: none; resize: vertical; }
        .dinput:focus { border-bottom-color: var(--accent); }
        .dinput--date { text-align: right; font-weight: 300; font-size: 11px; color: var(--muted); border-bottom: 1px dashed #d8dde4; width: 130px; }
        .dvalue { min-height: 21px; border-bottom: 1px solid var(--line); padding: 2px 0 4px; font-weight: 700; }
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
        .fineprint { margin-top: 18px; padding-top: 14px; border-top: 2px solid var(--line);
                     font-size: 10px; color: #495059; line-height: 1.55; font-weight: 300; text-align: justify; }
        .fineprint ol { margin: 0; padding-left: 16px; }
        .fineprint li { margin-bottom: 4px; }
        .sign { display: flex; gap: 40px; margin-top: 44px; }
        .sign .slot { flex: 1; border-top: 1.4px solid var(--ink); padding-top: 7px; font-size: 10.5px; color: var(--muted); text-align: center; }
        .sign .slot.signed { border-top: none; padding-top: 0; }
        .sign .slot.signed .sig-img { display: block; max-height: 54px; margin: 0 auto 2px; }
        .sign .slot.signed .sigline { border-top: 1.4px solid var(--ink); padding-top: 7px; }
        .sign .slot.signed .sig-at { margin-top: 3px; font-size: 9px; color: #8a929c; }
        .foot { margin-top: 24px; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; padding-bottom: 26px; }
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
