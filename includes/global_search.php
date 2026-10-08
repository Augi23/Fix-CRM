<?php
/**
 * GLOBÁLNÍ VYHLEDÁVÁNÍ (v3.90.0) — jedno pole, které najde cokoli v CRM.
 *
 * Zásady (přání majitele: „aby se nestalo, že fráze nebude fungovat, i když
 * někde existuje"):
 *  • Dotaz se rozdělí na slova a KAŽDÉ slovo se hledá zvlášť, kdekoli ve
 *    všech polích záznamu a v libovolném pořadí. „iphone 13 novák" najde
 *    zakázku, kde je Novák v klientovi a iPhone 13 v zařízení.
 *  • Bez ohledu na diakritiku a velikost písmen (tabulky jsou utf8mb4_unicode_ci;
 *    pro jistotu se porovnává i explicitně v této kolaci).
 *  • Telefony bez ohledu na formát (mezery, +420, 00420), kódy bez ohledu na
 *    mezery a pomlčky („APFAZ 2601577" = „apfaz2601577" = „2601577").
 *  • Když nic: oprava překlepu („Měli jste na mysli …") a pak podobné výsledky
 *    (záznamy, které obsahují aspoň část slov), seřazené podle počtu shod.
 *  • Oblast: všichni hledají všude KROMĚ účetnictví; účetnictví (faktury,
 *    banka) jen ze záložky Účetnictví (scope=accounting). Role „účetní" vidí
 *    jen účetnictví (jinak CRM neotevře).
 */

const GS_COLL = 'utf8mb4_unicode_ci';

/** ASCII „složení" textu pro porovnání ve PHP (návrhy, stránky, řazení). */
function gsFold(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = [
            'á'=>'a','ä'=>'a','à'=>'a','â'=>'a','ã'=>'a','å'=>'a','č'=>'c','ç'=>'c','ć'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','è'=>'e','ê'=>'e',
            'í'=>'i','ï'=>'i','ì'=>'i','î'=>'i','ľ'=>'l','ĺ'=>'l','ł'=>'l','ň'=>'n','ñ'=>'n','ń'=>'n','ó'=>'o','ö'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ő'=>'o',
            'ř'=>'r','ŕ'=>'r','š'=>'s','ś'=>'s','ß'=>'ss','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ù'=>'u','û'=>'u','ű'=>'u','ý'=>'y','ÿ'=>'y','ž'=>'z','ź'=>'z','ż'=>'z',
        ];
    }
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

/** Rozklad dotazu na slova. Vrací ['q' => …, 'tokens' => [...]] */
function gsParseQuery(string $q): array {
    $q = trim(preg_replace('/\s+/u', ' ', mb_substr($q, 0, 120)));
    $parts = preg_split('/[\s,;]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // telefon po trojicích („+420 732 774 546", „732 774 546") = jedno číslo, ne čtyři slova
    $merged = []; $run = [];
    foreach (array_merge($parts, ['']) as $p) {
        if (preg_match('/^\+?\d{3}$/', $p)) { $run[] = $p; continue; }
        if (count($run) >= 3) { $merged[] = implode('', $run); } else { array_push($merged, ...$run); }
        $run = [];
        if ($p !== '') { $merged[] = $p; }
    }
    $parts = $merged;
    $tokens = [];
    foreach ($parts as $p) {
        $p = trim($p, " \t\"'()[]{}");
        if ($p === '') { continue; }
        // jednopísmenná slova jsou šum (kromě čísel a samotného dotazu)
        if (mb_strlen($p) < 2 && !ctype_digit($p) && count($parts) > 1) { continue; }
        $tokens[] = $p;
    }
    $tokens = array_values(array_unique($tokens));
    return ['q' => $q, 'tokens' => array_slice($tokens, 0, 8)];
}

/** LIKE vzor s escapovanými zástupnými znaky. */
function gsLike(string $t): string {
    return '%' . strtr($t, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/** Číslice z tokenu pro porovnání s telefony (bez předvolby 420/00420). */
function gsPhoneDigits(string $t): string {
    $d = preg_replace('/\D+/', '', $t);
    if (strlen($d) > 9 && preg_match('/^(00)?420(\d{9})$/', $d, $m)) { $d = $m[2]; }
    return $d;
}

/** Token bez mezer/pomlček/teček pro porovnání s kódy (zakázky, S/N, IMEI…). */
function gsCompact(string $t): string {
    return preg_replace('/[^0-9A-Za-z]+/', '', gsFold($t));
}

/**
 * Sestaví podmínku pro jeden token nad entitou.
 *   $hay    — SQL výraz s textem všech prohledávaných polí
 *   $phones — SQL výrazy telefonních sloupců
 *   $codes  — SQL výrazy kódů (porovnávají se bez oddělovačů)
 * Vrací [sql, params].
 */
function gsTokenCond(string $t, string $hay, array $phones, array $codes): array {
    $or = ["($hay) COLLATE " . GS_COLL . " LIKE ?"];
    $params = [gsLike($t)];
    $digits = gsPhoneDigits($t);
    if ($phones && strlen($digits) >= 3 && strlen($digits) >= (int)floor(strlen(preg_replace('/\s+/', '', $t)) * 0.6)) {
        foreach ($phones as $p) {
            $or[] = "REGEXP_REPLACE(COALESCE($p,''), '[^0-9]', '') LIKE ?";
            $params[] = '%' . $digits . '%';
        }
    }
    $compact = gsCompact($t);
    if ($codes && $compact !== '' && preg_match('/\d/', $compact) && $compact !== gsFold($t)) {
        foreach ($codes as $c) {
            $or[] = "REGEXP_REPLACE(LOWER(COALESCE($c,'')), '[^0-9a-z]', '') LIKE ?";
            $params[] = '%' . $compact . '%';
        }
    }
    return ['(' . implode(' OR ', $or) . ')', $params];
}

/**
 * Najde záznamy jedné entity.
 *   $mode = 'all' (všechna slova) | 'any' (aspoň jedno, řazeno podle počtu shod)
 * $def: table/from, id, hay (pole sloupců), phones, codes, select, where (extra), order, limit
 */
function gsQueryEntity(PDO $pdo, array $def, array $tokens, string $mode, int $limit): array {
    $hay = 'CONCAT_WS(\' \', ' . implode(', ', array_map(fn($c) => "COALESCE($c,'')", $def['hay'])) . ')';
    $conds = []; $params = []; $scoreParts = []; $scoreParams = [];
    foreach ($tokens as $t) {
        [$sql, $p] = gsTokenCond($t, $hay, $def['phones'] ?? [], $def['codes'] ?? []);
        $conds[] = $sql; $params = array_merge($params, $p);
        $scoreParts[] = "($sql)"; $scoreParams = array_merge($scoreParams, $p);
    }
    if (!$conds) { return [[], 0]; }
    $where = $mode === 'any' ? '(' . implode(' OR ', $conds) . ')' : implode(' AND ', $conds);
    if (!empty($def['where'])) { $where .= ' AND (' . $def['where'] . ')'; }
    $score = $mode === 'any' ? implode(' + ', $scoreParts) : '0';
    $from = $def['from'];
    $total = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM $from WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        if ($total === 0) { return [[], 0]; }
        $order = ($mode === 'any' ? 'gs_score DESC, ' : '') . ($def['order'] ?? ($def['id'] . ' DESC'));
        $sql = "SELECT {$def['select']}, ($score) AS gs_score FROM $from WHERE $where ORDER BY $order LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($mode === 'any' ? array_merge($scoreParams, $params) : $params);
        return [$st->fetchAll(PDO::FETCH_ASSOC), $total];
    } catch (Throwable $e) {
        error_log('global_search ' . ($def['key'] ?? '?') . ': ' . $e->getMessage());
        return [[], 0];
    }
}

/* ── Slovník pro „Měli jste na mysli" ──────────────────────────────────────── */

/** Slova z dat CRM (jména, modely, díly, produkty…) — v mezipaměti na 10 min. */
function gsVocabulary(PDO $pdo, array $sources): array {
    $file = sys_get_temp_dir() . '/afx_gs_vocab_' . md5((string)(defined('DB_NAME') ? DB_NAME : 'crm')) . '.json';
    if (is_file($file) && filemtime($file) > time() - 600) {
        $v = json_decode((string)file_get_contents($file), true);
        if (is_array($v)) { return $v; }
    }
    $freq = [];
    foreach ($sources as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $text) {
                foreach (preg_split('/[^\p{L}\p{N}]+/u', (string)$text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                    if (mb_strlen($w) < 3 || mb_strlen($w) > 30 || ctype_digit($w)) { continue; }
                    $k = gsFold($w);
                    if (!isset($freq[$k])) { $freq[$k] = ['w' => $w, 'n' => 0]; }
                    $freq[$k]['n']++;
                }
            }
        } catch (Throwable $e) { error_log('global_search vocab: ' . $e->getMessage()); }
    }
    $vocab = [];
    foreach ($freq as $k => $v) { $vocab[$k] = [$v['w'], $v['n']]; }
    @file_put_contents($file, json_encode($vocab, JSON_UNESCAPED_UNICODE));
    return $vocab;
}

/** Oprava jednoho slova podle slovníku (Levenshtein), nebo null. */
function gsCorrectToken(string $t, array $vocab): ?string {
    $f = gsFold($t);
    $len = strlen($f);
    if ($len < 3 || ctype_digit($f)) { return null; }
    if (isset($vocab[$f])) { return null; }                 // slovo existuje
    foreach ($vocab as $k => $_) {                           // je částí existujícího slova → není překlep
        if ($len >= 4 && str_contains($k, $f)) { return null; }
    }
    $max = $len <= 4 ? 1 : ($len <= 8 ? 2 : 3);
    $best = null; $bestD = PHP_INT_MAX; $bestN = 0;
    foreach ($vocab as $k => [$w, $n]) {
        if (abs(strlen($k) - $len) > $max) { continue; }
        $d = levenshtein($f, $k);
        if ($d <= $max && ($d < $bestD || ($d === $bestD && $n > $bestN))) {
            $best = $w; $bestD = $d; $bestN = $n;
        }
    }
    return $best;
}

/* ── Stránky, sekce nastavení a návody (statický rejstřík) ────────────────── */

function gsPagesIndex(): array {
    $p = [
        ['Nástěnka', 'index.php', 'fa-home', 'dashboard prehled domu'],
        ['Zakázky', 'orders.php', 'fa-tools', 'opravy servis seznam zakazek'],
        ['Nová zakázka', 'index.php#novazakazka', 'fa-plus', 'pridat zakazku prijem zarizeni', 'newOrderModal'],
        ['Reklamace', 'reklamace.php', 'fa-rotate-left', 'reklamace zaruka stiznost'],
        ['Klienti', 'customers.php', 'fa-users', 'zakaznici kontakty'],
        ['Sklad — servisní díly', 'inventory.php', 'fa-boxes', 'sklad dily soucastky naskladneni'],
        ['Sklad — produkty', 'products.php', 'fa-mobile-screen', 'produkty eshop bazar prodej cenovky'],
        ['Nákupy', 'procurement.php', 'fa-truck', 'objednavky dilu nakup pozadavky'],
        ['Nákupní seznam', 'nakupni-seznam.php', 'fa-cart-shopping', 'nakupni seznam nakup'],
        ['Pokladna', 'pokladna.php', 'fa-cash-register', 'kasa prodej uctenka platba'],
        ['Přehledy — statistiky', 'reports.php', 'fa-chart-line', 'statistiky reporty trzby prehled'],
        ['Historie úprav', 'history.php', 'fa-clock-rotate-left', 'historie audit log kdo co zmenil'],
        ['Chat', 'chat.php', 'fa-comments', 'zpravy tym chat'],
        ['Rozpis služeb', 'rozpis.php', 'fa-calendar-days', 'rozpis smeny dochazka kalendar'],
        ['Dokumenty', 'dokumenty.php', 'fa-file-signature', 'vykupni list zastava smlouva dokumenty'],
        ['Návody', 'navody.php', 'fa-graduation-cap', 'navody postupy napoveda help'],
        ['Účetnictví — faktury', 'accounting.php', 'fa-file-invoice-dollar', 'ucetnictvi faktury fakturace vystavit fakturu dobropis'],
        ['Účetnictví — banka', 'banka.php', 'fa-building-columns', 'banka bankovni pohyby vypis platby parovani'],
        ['Účetnictví — prodej', 'ucetni_prodej.php', 'fa-receipt', 'ucetni prodej trzby doklady'],
        ['Účetnictví — sestavy', 'ucetni_sestavy.php', 'fa-chart-pie', 'sestavy dph prehled export ucetni'],
        ['Nastavení', 'settings.php', 'fa-cog', 'nastaveni konfigurace'],
        ['Nastavení — Údaje o společnosti', 'settings.php?tab=company', 'fa-building', 'firma ico dic adresa pobocky oteviraci doba'],
        ['Nastavení — Věrnostní karta', 'settings.php?tab=loyalty', 'fa-id-card', 'vernostni karta body sleva'],
        ['Nastavení — Banka', 'settings.php?tab=banka', 'fa-building-columns', 'banka ucet kb napojeni'],
        ['Nastavení — Uzávěrka období', 'settings.php?tab=uzaverka', 'fa-lock', 'uzaverka obdobi ucetnictvi'],
        ['Nastavení — Zaměstnanci', 'settings.php?tab=staff', 'fa-users', 'zamestnanci technici prava role'],
        ['Nastavení — Tisk štítků', 'settings.php?tab=tisk', 'fa-print', 'tisk stitku brother tiskarna uctenky xprinter mustek'],
        ['Nastavení — Správa administrátorů', 'settings.php?tab=admins', 'fa-user-shield', 'administratori hesla'],
        ['Nastavení — Integrace', 'settings.php?tab=system&sub=integrace', 'fa-plug', 'integrace api smtp email klic'],
        ['Nastavení — Databáze', 'settings.php?tab=system&sub=databaze', 'fa-database', 'databaze zaloha obnova'],
        ['Nastavení — Aktualizace', 'settings.php?tab=system&sub=aktualizace', 'fa-cloud-download-alt', 'aktualizace verze update git changelog historie uprav'],
        ['Nastavení — Třídění pošty', 'settings.php?tab=system&sub=posta', 'fa-envelope-open-text', 'posta email trideni'],
    ];
    $out = [];
    foreach ($p as $r) {
        $out[] = ['title' => $r[0], 'url' => $r[1], 'icon' => $r[2], 'kw' => $r[3]];
    }
    // návody: vytažené přímo ze zdroje navody.php (je data-driven pole)
    foreach (gsGuides() as $g) { $out[] = $g; }
    return $out;
}

/** Návody z navody.php — id, název, úvod; zakládá se na struktuře pole $guides[...]. */
function gsGuides(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    $src = @file_get_contents(__DIR__ . '/../navody.php');
    if (!$src) { return $cache; }
    $tab = 'crm';
    $tabs = [];
    if (preg_match_all('/\$guides\[\'([a-z]+)\'\]\s*=\s*\[/', $src, $tm, PREG_OFFSET_CAPTURE)) {
        foreach ($tm[1] as $m) { $tabs[] = [$m[1], $m[0]]; }
    }
    if (preg_match_all("/'id'\s*=>\s*'([^']+)'.*?'title'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'(?:.*?'intro'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)')?/s", $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $pos = $g[0][1];
            foreach ($tabs as [$name, $off]) { if ($off < $pos) { $tab = $name; } }
            $title = stripslashes($g[2][0]);
            $intro = isset($g[3]) ? strip_tags(stripslashes($g[3][0])) : '';
            $cache[] = ['title' => 'Návod: ' . $title, 'url' => 'navody.php?tab=' . $tab . '#' . $g[1][0],
                        'icon' => 'fa-graduation-cap', 'kw' => $intro, 'guide' => true];
        }
    }
    return $cache;
}

function gsSearchPages(array $tokens, string $mode): array {
    $res = [];
    foreach (gsPagesIndex() as $p) {
        $hay = gsFold($p['title'] . ' ' . $p['kw']);
        $hits = 0;
        foreach ($tokens as $t) {
            $tf = gsFold($t);
            // české koncovky: „tiskárny" najde „tiskárna", „faktury" → „faktura" (kmen bez posledních 1–2 písmen)
            $stem = mb_strlen($tf) >= 6 ? mb_substr($tf, 0, -2) : (mb_strlen($tf) >= 4 ? mb_substr($tf, 0, -1) : $tf);
            if (str_contains($hay, $tf) || str_contains($hay, $stem)) { $hits++; }
        }
        $ok = $mode === 'any' ? $hits > 0 : $hits === count($tokens);
        if ($ok) { $p['gs_score'] = $hits + (str_contains(gsFold($p['title']), gsFold(implode(' ', $tokens))) ? 5 : 0); $res[] = $p; }
    }
    usort($res, fn($a, $b) => $b['gs_score'] <=> $a['gs_score']);
    return $res;
}

/* ── Definice prohledávaných oblastí ──────────────────────────────────────── */

/** Oblasti hledání. 'acct' = jen v účetnictví (scope=accounting). */
function gsEntities(): array {
    return [
        'orders' => [
            'label' => 'Zakázky', 'icon' => 'fa-tools', 'color' => '#0a84ff', 'id' => 'o.id',
            'from' => 'orders o LEFT JOIN customers c ON c.id = o.customer_id',
            'hay' => ['o.order_code', 'o.legacy_code', 'o.device_brand', 'o.device_model', 'o.device_type', 'o.serial_number', 'o.serial_number_2',
                      'o.problem_description', 'o.repair_solution', 'o.technician_notes', 'o.appearance', 'o.shipping_tracking',
                      'c.first_name', 'c.last_name', 'c.company', 'c.phone', 'c.email'],
            'phones' => ['c.phone'], 'codes' => ['o.order_code', 'o.legacy_code', 'o.serial_number', 'o.serial_number_2'],
            'select' => 'o.id, o.order_code, o.legacy_code, o.device_brand, o.device_model, o.status, o.created_at, o.serial_number, c.first_name, c.last_name, c.company, c.phone',
            'more' => 'orders.php?search=',
        ],
        'customers' => [
            'label' => 'Klienti', 'icon' => 'fa-user', 'color' => '#30b0c7', 'id' => 'c.id',
            'from' => 'customers c',
            'hay' => ['c.first_name', 'c.last_name', 'c.company', 'c.phone', 'c.email', 'c.ico', 'c.dic', 'c.address'],
            'phones' => ['c.phone'], 'codes' => ['c.ico'],
            'select' => 'c.id, c.first_name, c.last_name, c.company, c.phone, c.email, c.ico',
            'more' => 'customers.php?search=',
        ],
        'complaints' => [
            'label' => 'Reklamace', 'icon' => 'fa-rotate-left', 'color' => '#ff9f0a', 'id' => 'k.id',
            'from' => 'complaints k LEFT JOIN customers c ON c.id = k.customer_id',
            'hay' => ['k.complaint_code', 'k.order_code', 'k.device', 'k.serial_number', 'k.complaint_reason', 'k.resolution_text', 'k.phone',
                      'c.first_name', 'c.last_name', 'c.company', 'c.phone', 'c.email'],
            'phones' => ['k.phone', 'c.phone'], 'codes' => ['k.complaint_code', 'k.order_code', 'k.serial_number'],
            'select' => 'k.id, k.complaint_code, k.order_code, k.device, k.complaint_reason, k.complaint_status, k.created_at, c.first_name, c.last_name',
            'more' => 'reklamace.php?search=',
        ],
        'products' => [
            'label' => 'Produkty', 'icon' => 'fa-mobile-screen', 'color' => '#34c759', 'id' => 'p.id',
            'from' => 'products p',
            'hay' => ['p.title', 'p.product_code', 'p.manufacturer', 'p.model', 'p.capacity', 'p.color', 'p.grade', 'p.eshop_note'],
            'phones' => [], 'codes' => ['p.product_code'],
            'select' => 'p.id, p.title, p.product_code, p.price, p.stock_qty, p.color, p.grade, p.branch_id, COALESCE(p.is_vykup,0) AS is_vykup',
            'more' => 'products.php?search=',   // bez pobočky → products.php sám přepne tam, kde kus je
        ],
        'inventory' => [
            'label' => 'Servisní díly', 'icon' => 'fa-boxes', 'color' => '#a2845e', 'id' => 'i.id',
            'from' => 'inventory i',
            'hay' => ['i.part_name', 'i.sku', 'i.device_model', 'i.source_supplier'],
            'phones' => [], 'codes' => ['i.sku'],
            'select' => 'i.id, i.part_name, i.sku, i.quantity, i.device_model, i.branch_id',
            'more' => 'inventory.php?branch=__BRANCH__&search=',   // sklad dílů je pobočkový — bez branch by skončil na rozcestníku
        ],
        'documents' => [
            'label' => 'Dokumenty', 'icon' => 'fa-file-signature', 'color' => '#5e5ce6', 'id' => 'd.id',
            'from' => 'crm_documents d',
            'hay' => ['d.doc_number', 'd.customer_name', 'd.customer_phone', 'd.customer_email', 'd.subject', 'd.payload'],
            'phones' => ['d.customer_phone'], 'codes' => ['d.doc_number'],
            'select' => 'd.id, d.doc_type, d.doc_number, d.doc_date, d.customer_name, d.subject',
            'more' => 'dokumenty.php?q=',
        ],
        'eshop' => [
            'label' => 'E-shop objednávky', 'icon' => 'fa-bag-shopping', 'color' => '#ff375f', 'id' => 'e.id',
            'from' => 'eshop_orders e',
            'hay' => ['e.order_ref', 'e.customer_name', 'e.customer_email', 'e.customer_phone', 'e.items_json', 'e.note', 'e.customer_note', 'e.addr_street', 'e.addr_city'],
            'phones' => ['e.customer_phone'], 'codes' => ['e.order_ref'],
            'select' => 'e.id, e.order_ref, e.status, e.total, e.customer_name, e.created_at',
            'more' => '',
        ],
        'pos' => [
            'both' => true,   // pokladní doklady i v Účetnictví (účetní je dohledává)
            'label' => 'Pokladní doklady', 'icon' => 'fa-receipt', 'color' => '#bf5af2', 'id' => 's.id',
            'from' => 'pos_sales s LEFT JOIN customers c ON c.id = s.customer_id',
            'hay' => ['s.sale_number', 's.seller_name', 's.note', 'c.first_name', 'c.last_name', 'c.company', 'c.phone'],
            'phones' => ['c.phone'], 'codes' => ['s.sale_number'],
            'select' => 's.id, s.sale_number, s.total, s.status, s.created_at, s.seller_name, c.first_name, c.last_name',
            'more' => '',
        ],
        'purchases' => [
            'label' => 'Nákupy dílů', 'icon' => 'fa-truck', 'color' => '#64d2ff', 'id' => 'r.id',
            'from' => 'purchase_requests r',
            'hay' => ['r.item_name', 'r.sku', 'r.notes', 'r.supplier_key', 'r.requested_by'],
            'phones' => [], 'codes' => ['r.sku'],
            'select' => 'r.id, r.item_name, r.sku, r.quantity, r.status, r.created_at',
            'more' => 'procurement.php',
        ],
        'staff' => [
            'label' => 'Zaměstnanci', 'icon' => 'fa-id-badge', 'color' => '#8e8e93', 'id' => 't.id',
            'from' => 'technicians t',
            'hay' => ['t.name', 't.username', 't.phone', 't.email', 't.specialization'],
            'phones' => ['t.phone'], 'codes' => [],
            'select' => 't.id, t.name, t.phone, t.email, t.is_active',
            'more' => 'settings.php?tab=staff',
        ],
        // ── jen v Účetnictví ──
        'invoices' => [
            'acct' => true,
            'label' => 'Faktury', 'icon' => 'fa-file-invoice-dollar', 'color' => '#32d74b', 'id' => 'f.id',
            'from' => 'invoices f LEFT JOIN customers c ON c.id = f.customer_id',
            'hay' => ['f.invoice_number', 'f.variable_symbol', 'f.notes', 'f.cust_name_override', 'f.cust_ico_override', 'f.supplier',
                      'c.first_name', 'c.last_name', 'c.company', 'c.ico', 'c.email'],
            'phones' => [], 'codes' => ['f.invoice_number', 'f.variable_symbol'],
            'select' => 'f.id, f.invoice_number, f.total_amount, f.status, f.date_issue, f.cust_name_override, c.first_name, c.last_name, c.company',
            'more' => 'accounting.php',
        ],
        'bank' => [
            'acct' => true,
            'label' => 'Bankovní pohyby', 'icon' => 'fa-building-columns', 'color' => '#ffd60a', 'id' => 'b.id',
            'from' => 'bank_transactions b',
            'hay' => ['b.counterparty_name', 'b.counterparty_account', 'b.vs', 'b.message', 'b.entry_ref', 'CAST(b.amount AS CHAR)'],
            'phones' => [], 'codes' => ['b.vs', 'b.counterparty_account'],
            'select' => 'b.id, b.booking_date, b.amount, b.direction, b.counterparty_name, b.vs, b.message',
            'more' => 'banka.php',
        ],
    ];
}

/** Řádek z DB → položka výsledku (titulek, podtitulek, odkaz…). */
function gsItem(string $key, array $r): array {
    $name = fn($a) => trim(trim((string)($a['company'] ?? '')) !== '' ? (string)$a['company'] : trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? '')));
    $date = fn($d) => $d ? date('j. n. Y', strtotime((string)$d)) : '';
    $money = fn($v) => number_format((float)$v, 0, ',', ' ') . ' Kč';
    switch ($key) {
        case 'orders':
            $code = orderDisplayCode($r);
            $dev = trim((string)$r['device_brand'] . ' ' . (string)$r['device_model']);
            $st = function_exists('getOrderStatusLabel') ? getOrderStatusLabel((string)$r['status']) : (string)$r['status'];
            return ['title' => $code . ($dev !== '' ? ' — ' . $dev : ''),
                    'subtitle' => trim(implode(' · ', array_filter([$name($r), (string)$r['phone'], $r['legacy_code'] ? 'dřív ' . $r['legacy_code'] : '']))),
                    'meta' => trim($st . ' · ' . $date($r['created_at']), ' ·'), 'url' => 'view_order.php?id=' . (int)$r['id'],
                    'codes' => [(string)$r['order_code'], (string)$r['legacy_code'], (string)$r['serial_number']]];
        case 'customers':
            return ['title' => $name($r) ?: '(bez jména)', 'subtitle' => trim(implode(' · ', array_filter([(string)$r['phone'], (string)$r['email'], $r['ico'] ? 'IČO ' . $r['ico'] : '']))),
                    'meta' => '', 'url' => 'edit_customer.php?id=' . (int)$r['id'], 'codes' => [(string)$r['ico'], (string)$r['phone']]];
        case 'complaints':
            return ['title' => trim((string)$r['complaint_code'] . ' — ' . (string)$r['device'], ' —'),
                    'subtitle' => trim(implode(' · ', array_filter([$name($r), mb_substr((string)$r['complaint_reason'], 0, 80), $r['order_code'] ? 'zakázka ' . $r['order_code'] : '']))),
                    'meta' => $date($r['created_at']), 'url' => 'view_complaint.php?id=' . (int)$r['id'], 'codes' => [(string)$r['complaint_code']]];
        case 'products':
            return ['title' => (string)$r['title'], 'subtitle' => trim(implode(' · ', array_filter([(string)$r['product_code'], (float)$r['price'] > 0 ? $money($r['price']) : '']))),
                    'meta' => ((int)$r['is_vykup'] === 1 ? 'výkup · ' : '') . ((int)$r['stock_qty'] > 0 ? 'skladem ' . (int)$r['stock_qty'] . ' ks' : 'není skladem'),
                    'url' => 'products.php?branch=' . ((int)$r['branch_id'] ?: 1) . ((int)$r['is_vykup'] === 1 ? '&cat=vykupy' : '') . '&search=' . rawurlencode((string)$r['product_code']),
                    'codes' => [(string)$r['product_code']]];
        case 'inventory':
            return ['title' => (string)$r['part_name'], 'subtitle' => trim(implode(' · ', array_filter([(string)$r['sku'], (string)$r['device_model']]))),
                    'meta' => (int)$r['quantity'] . ' ks', 'url' => 'edit_inventory.php?id=' . (int)$r['id'], 'codes' => [(string)$r['sku']]];
        case 'documents':
            $types = ['vykup' => 'Výkupní list', 'zastava' => 'Zástavní smlouva'];
            return ['title' => ($types[$r['doc_type']] ?? 'Dokument') . ' ' . (string)$r['doc_number'],
                    'subtitle' => trim(implode(' · ', array_filter([(string)$r['customer_name'], (string)$r['subject']]))),
                    'meta' => $date($r['doc_date']), 'url' => 'dokument.php?type=' . rawurlencode((string)$r['doc_type']) . '&id=' . (int)$r['id'], 'codes' => [(string)$r['doc_number']]];
        case 'eshop':
            return ['title' => (string)$r['order_ref'], 'subtitle' => trim(implode(' · ', array_filter([(string)$r['customer_name'], $money($r['total'])]))),
                    'meta' => trim((string)$r['status'] . ' · ' . $date($r['created_at']), ' ·'), 'url' => 'index.php#eshop', 'codes' => [(string)$r['order_ref']]];
        case 'pos':
            return ['title' => 'Doklad ' . (string)$r['sale_number'], 'subtitle' => trim(implode(' · ', array_filter([$name($r), $money($r['total']), (string)$r['seller_name']]))),
                    'meta' => $date($r['created_at']), 'url' => 'print_receipt.php?id=' . (int)$r['id'], 'codes' => [(string)$r['sale_number']]];
        case 'purchases':
            return ['title' => (string)$r['item_name'], 'subtitle' => trim(implode(' · ', array_filter([(string)$r['sku'], (int)$r['quantity'] . ' ks', (string)$r['status']]))),
                    'meta' => $date($r['created_at']), 'url' => 'procurement.php', 'codes' => [(string)$r['sku']]];
        case 'staff':
            return ['title' => (string)$r['name'], 'subtitle' => trim(implode(' · ', array_filter([(string)$r['phone'], (string)$r['email']]))),
                    'meta' => (int)$r['is_active'] ? '' : 'neaktivní', 'url' => 'settings.php?tab=staff', 'codes' => []];
        case 'invoices':
            $cn = trim((string)$r['cust_name_override']) ?: $name($r);
            return ['title' => 'Faktura ' . (string)$r['invoice_number'], 'subtitle' => trim(implode(' · ', array_filter([$cn, $money($r['total_amount']), (string)$r['status']]))),
                    'meta' => $date($r['date_issue']), 'url' => 'print_invoice.php?id=' . (int)$r['id'], 'codes' => [(string)$r['invoice_number']]];
        case 'bank':
            return ['title' => trim((string)$r['counterparty_name']) ?: 'Bankovní pohyb', 'subtitle' => trim(implode(' · ', array_filter([($r['direction'] === 'out' ? '−' : '+') . $money($r['amount']), $r['vs'] ? 'VS ' . $r['vs'] : '', mb_substr((string)$r['message'], 0, 60)]))),
                    'meta' => $date($r['booking_date']), 'url' => 'banka.php', 'codes' => [(string)$r['vs']]];
    }
    return ['title' => '?', 'subtitle' => '', 'meta' => '', 'url' => '#', 'codes' => []];
}

/** Slovník pro opravy překlepů — zdroje (bez účetnictví). */
function gsVocabSources(): array {
    return [
        "SELECT CONCAT_WS(' ', first_name, last_name, company) FROM customers ORDER BY id DESC LIMIT 6000",
        "SELECT CONCAT_WS(' ', device_brand, device_model) FROM orders ORDER BY id DESC LIMIT 6000",
        "SELECT part_name FROM inventory ORDER BY id DESC LIMIT 9000",
        "SELECT CONCAT_WS(' ', title, manufacturer, model, color) FROM products ORDER BY id DESC LIMIT 3000",
        "SELECT CONCAT_WS(' ', device, complaint_reason) FROM complaints ORDER BY id DESC LIMIT 2000",
        "SELECT name FROM technicians",
        "SELECT CONCAT_WS(' ', customer_name, subject) FROM crm_documents ORDER BY id DESC LIMIT 2000",
        "SELECT item_name FROM purchase_requests ORDER BY id DESC LIMIT 2000",
    ];
}

/**
 * Hlavní hledání. Vrací ['ok','q','tokens','mode','suggestion','total','groups'].
 *   mode: 'exact' (všechna slova) | 'suggestion' (opravený dotaz) | 'similar' (část slov) | 'none'
 */
function gsRun(PDO $pdo, string $q, string $scope, int $perGroup): array {
    $parsed = gsParseQuery($q);
    $tokens = $parsed['tokens'];
    $out = ['ok' => true, 'q' => $parsed['q'], 'tokens' => $tokens, 'mode' => 'none', 'suggestion' => null, 'total' => 0, 'groups' => []];
    if (!$tokens) { return $out; }
    $acct = ($scope === 'accounting');

    $run = function (array $toks, string $mode) use ($pdo, $acct, $perGroup) {
        $groups = []; $total = 0;
        foreach (gsEntities() as $key => $def) {
            if (empty($def['both']) && !empty($def['acct']) !== $acct) { continue; }
            $def['key'] = $key;
            [$rows, $cnt] = gsQueryEntity($pdo, $def, $toks, $mode, max($perGroup * 4, 20));
            if (!$rows) { continue; }
            $items = [];
            $compactQ = gsCompact(implode('', $toks));
            foreach ($rows as $r) {
                $it = gsItem($key, $r);
                foreach (['title', 'subtitle', 'meta'] as $f) { $it[$f] = trim(preg_replace('/\s+/u', ' ', (string)($it[$f] ?? ''))); }
                $score = (float)($r['gs_score'] ?? 0);
                foreach ($it['codes'] as $c) {                       // přesná shoda kódu = nahoru
                    if ($c !== '' && gsCompact($c) === $compactQ) { $score += 100; }
                }
                $titleF = gsFold($it['title']);
                foreach ($toks as $t) { if (str_contains($titleF, gsFold($t))) { $score += 2; } }
                $it['gs_score'] = $score;
                unset($it['codes']);
                $items[] = $it;
            }
            usort($items, fn($a, $b) => $b['gs_score'] <=> $a['gs_score']);
            $best = $items[0]['gs_score'] ?? 0;
            $groups[] = ['key' => $key, 'label' => $def['label'], 'icon' => $def['icon'], 'color' => $def['color'],
                         'total' => $cnt, 'items' => array_slice($items, 0, $perGroup), 'best' => $best,
                         'more_url' => ($def['more'] ?? '') !== ''
                             ? str_replace('__BRANCH__', (string)(function_exists('getCurrentStaffBranchId') ? (int)getCurrentStaffBranchId() : 1), $def['more'])
                               . (str_ends_with($def['more'], '=') ? rawurlencode(implode(' ', $toks)) : '')
                             : ''];
            $total += $cnt;
        }
        {
            $pages = gsSearchPages($toks, $mode);
            if ($acct) {   // v Účetnictví jen jeho stránky a návody
                $pages = array_values(array_filter($pages, fn($p) => preg_match('~^(accounting|banka|ucetni_|navody)~', $p['url'])));
            }
            if ($pages) {
                $items = array_map(fn($p) => ['title' => $p['title'], 'subtitle' => !empty($p['guide']) ? mb_substr($p['kw'], 0, 90) : '', 'meta' => '',
                                              'url' => $p['url'], 'icon' => $p['icon'], 'gs_score' => $p['gs_score']], array_slice($pages, 0, $perGroup));
                $groups[] = ['key' => 'pages', 'label' => 'Stránky a návody', 'icon' => 'fa-compass', 'color' => '#636366',
                             'total' => count($pages), 'items' => $items, 'best' => (($pages[0]['gs_score'] ?? 0) >= 5 ? 100 : 0), 'more_url' => ''];   // název stránky obsahuje celý dotaz → nahoru
                $total += count($pages);
            }
        }
        // skupina s přesnou shodou (kód, název stránky) jde nahoru, jinak pevné pořadí
        usort($groups, fn($a, $b) => (($b['best'] >= 100) <=> ($a['best'] >= 100)) ?: 0);
        return [$groups, $total];
    };

    [$groups, $total] = $run($tokens, 'all');
    if ($total > 0) { return array_merge($out, ['mode' => 'exact', 'groups' => $groups, 'total' => $total]); }

    // 2) oprava překlepů
    $vocab = gsVocabulary($pdo, gsVocabSources());
    $fixed = []; $changed = false;
    foreach ($tokens as $t) {
        $c = gsCorrectToken($t, $vocab);
        if ($c !== null && gsFold($c) !== gsFold($t)) { $fixed[] = $c; $changed = true; } else { $fixed[] = $t; }
    }
    if ($changed) {
        [$groups, $total] = $run($fixed, 'all');
        if ($total > 0) {
            return array_merge($out, ['mode' => 'suggestion', 'suggestion' => implode(' ', $fixed), 'tokens' => $fixed, 'groups' => $groups, 'total' => $total]);
        }
    }
    // 3) podobné — aspoň část slov (původní i opravená)
    $any = array_values(array_unique(array_merge($tokens, $changed ? $fixed : [])));
    [$groups, $total] = $run($any, 'any');
    if ($total > 0) {
        return array_merge($out, ['mode' => 'similar', 'suggestion' => $changed ? implode(' ', $fixed) : null, 'tokens' => $any, 'groups' => $groups, 'total' => $total]);
    }
    return array_merge($out, ['suggestion' => $changed ? implode(' ', $fixed) : null]);
}
