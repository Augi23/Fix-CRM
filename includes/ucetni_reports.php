<?php
/**
 * PODKLADY PRO ÚČETNÍ — datová a tisková vrstva sestav (stránka ucetni_sestavy.php).
 *
 * Účetní má vlastní účet do CRM a potřebuje si sama stáhnout podklady za období.
 * PDF se tady nedělá knihovnou: každá sestava je samostatná tisková HTML stránka
 * (styl assets/css/print-report.css), kterou si účetní vytiskne do PDF — stejný
 * vzor jako print_invoice.php / print_receipt.php.
 *
 * ZÁSADY, KTERÉ SE TU DRŽÍ (a proč):
 *  • Soubor je ČISTĚ ČTECÍ. Žádné DDL, žádné UPDATE. Sestava, kterou si účetní
 *    otevře desetkrát za sebou, nesmí měnit data v CRM.
 *  • Když tabulka (invoice_payments, pos_sales, bank_transactions…) v databázi
 *    ještě není, sestava se tváří prázdně a řekne proč — nesmí spadnout na
 *    bílou stránku, protože účetní nemá jak zjistit, co se stalo.
 *  • Záloha NENÍ výnos (je to závazek do okamžiku uskutečnění plnění), proto má
 *    vlastní sestavu a do tržeb ani do knihy faktur se nemíchá.
 *  • Dobropis se v CRM ukládá s KLADNOU částkou (kopie původní faktury), takže
 *    na sestavě je vždy vidět věta, že částku je nutné zaúčtovat jako mínus.
 */

// ─────────────────────────────────────────────────────────────────────────────
// PŘÍSTUP
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Kdo smí číst účetní podklady.
 * Role „účetní" vzniká v souběžném balíku — proto se na crmCanAccountingRead()
 * ptáme přes function_exists(). Až funkce přibude, sestavy se jí automaticky
 * začnou řídit; do té doby platí hranice Účetnictví (admin + Boss).
 */
function afxUcetniCanRead(): bool {
    if (function_exists('crmCanAccountingRead')) {
        return (bool)crmCanAccountingRead();
    }
    return function_exists('crmCanManageInvoices') ? (bool)crmCanManageInvoices() : false;
}

// ─────────────────────────────────────────────────────────────────────────────
// DATABÁZOVÉ POJISTKY (schéma se může lišit — starší instalace, rozjetá migrace)
// ─────────────────────────────────────────────────────────────────────────────

/** Existuje tabulka? Cache v rámci requestu (sestava se ptá opakovaně). */
function afxUcetniTableExists(string $table): bool {
    global $pdo;
    static $cache = [];
    if (isset($cache[$table])) { return $cache[$table]; }
    $cache[$table] = false;
    if (!isset($pdo) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) { return false; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        $cache[$table] = ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        error_log('afxUcetniTableExists: ' . $e->getMessage());
    }
    return $cache[$table];
}

/** Existuje sloupec? (invoices.paid_amount přidává až migrace 037.) */
function afxUcetniColumnExists(string $table, string $column): bool {
    global $pdo;
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) { return $cache[$key]; }
    $cache[$key] = false;
    if (!isset($pdo) || !afxUcetniTableExists($table)) { return false; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        $cache[$key] = ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        error_log('afxUcetniColumnExists: ' . $e->getMessage());
    }
    return $cache[$key];
}

/** Dotaz, který nikdy nesmí shodit sestavu — chyba jde do logu, sestava zůstane prázdná. */
function afxUcetniQuery(string $sql, array $params = []): array {
    global $pdo;
    if (!isset($pdo)) { return []; }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('ucetni_reports SQL: ' . $e->getMessage());
        return [];
    }
}

/**
 * SQL výraz „kolik je na faktuře zaplaceno".
 * invoices.paid_amount je udržovaný součet (afxInvoiceRecalcPaid) a je rychlý;
 * když ho DB ještě nemá, dopočítá se z invoice_payments; a když není ani ta,
 * vrátí 0 — sestava pak ukáže vše jako neuhrazené, ale nespadne.
 */
function afxUcetniPaidExpr(string $alias = 'i'): string {
    if (afxUcetniColumnExists('invoices', 'paid_amount')) {
        return 'COALESCE(' . $alias . '.paid_amount, 0)';
    }
    if (afxUcetniTableExists('invoice_payments')) {
        return '(SELECT COALESCE(SUM(p0.amount), 0) FROM invoice_payments p0 WHERE p0.invoice_id = ' . $alias . '.id)';
    }
    return '0';
}

/** Jméno odběratele na faktuře (přepis na faktuře má přednost před kartou klienta). */
function afxUcetniCustNameExpr(string $i = 'i', string $c = 'c'): string {
    return "COALESCE(NULLIF($i.cust_name_override, ''), NULLIF($c.company, ''),
            NULLIF(TRIM(CONCAT(COALESCE($c.first_name,''), ' ', COALESCE($c.last_name,''))), ''), '—')";
}

// ─────────────────────────────────────────────────────────────────────────────
// OBDOBÍ A POBOČKA
// ─────────────────────────────────────────────────────────────────────────────

/** České názvy měsíců (1–12) pro popisky období. */
function afxUcetniMonthName(int $m): string {
    $names = ['', 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
              'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    return $names[$m] ?? '';
}

/**
 * Období z parametrů URL. Vrací from/to (Y-m-d), lidský popisek a normalizované
 * parametry, které se přenášejí na tiskové sestavy.
 *
 * Vstup se VŽDY normalizuje (rok se ořízne do 2000–2100, měsíc do 1–12, prohozené
 * od/do se otočí) — do SQL jde jen to, co projde touhle branou, a špatně zadaný
 * rozsah by jinak vrátil nesmyslně prázdnou sestavu bez vysvětlení.
 */
function afxUcetniResolvePeriod(array $q): array {
    $mode = (string)($q['obdobi'] ?? 'mesic');
    if (!in_array($mode, ['mesic', 'ctvrtleti', 'rok', 'vlastni'], true)) { $mode = 'mesic'; }

    $clampYear = static function ($y): int {
        $y = (int)$y;
        return ($y >= 2000 && $y <= 2100) ? $y : (int)date('Y');
    };

    if ($mode === 'mesic') {
        $raw = (string)($q['m'] ?? date('Y-m'));
        if (!preg_match('/^(\d{4})-(\d{2})$/', $raw, $mm)) { $raw = date('Y-m'); preg_match('/^(\d{4})-(\d{2})$/', $raw, $mm); }
        $y = $clampYear($mm[1]);
        $mo = max(1, min(12, (int)$mm[2]));
        $from = sprintf('%04d-%02d-01', $y, $mo);
        $to = date('Y-m-t', strtotime($from));
        return ['mode' => $mode, 'from' => $from, 'to' => $to,
            'label' => afxUcetniMonthName($mo) . ' ' . $y,
            'query' => ['obdobi' => 'mesic', 'm' => sprintf('%04d-%02d', $y, $mo)]];
    }

    if ($mode === 'ctvrtleti') {
        $y = $clampYear($q['r'] ?? date('Y'));
        $qq = max(1, min(4, (int)($q['q'] ?? ceil((int)date('n') / 3))));
        $from = sprintf('%04d-%02d-01', $y, ($qq - 1) * 3 + 1);
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $qq * 3)));
        return ['mode' => $mode, 'from' => $from, 'to' => $to,
            'label' => $qq . '. čtvrtletí ' . $y,
            'query' => ['obdobi' => 'ctvrtleti', 'r' => $y, 'q' => $qq]];
    }

    if ($mode === 'rok') {
        $y = $clampYear($q['r'] ?? date('Y'));
        return ['mode' => $mode, 'from' => sprintf('%04d-01-01', $y), 'to' => sprintf('%04d-12-31', $y),
            'label' => 'rok ' . $y,
            'query' => ['obdobi' => 'rok', 'r' => $y]];
    }

    // vlastní rozsah
    $from = (string)($q['od'] ?? date('Y-m-01'));
    $to = (string)($q['do'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) { $from = date('Y-m-01'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || !strtotime($to)) { $to = date('Y-m-d'); }
    if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }   // prohozené datum uživatele
    return ['mode' => 'vlastni', 'from' => $from, 'to' => $to,
        'label' => date('j.n.Y', strtotime($from)) . ' – ' . date('j.n.Y', strtotime($to)),
        'query' => ['obdobi' => 'vlastni', 'od' => $from, 'do' => $to]];
}

/**
 * Pobočka, na kterou je uživatel natvrdo omezený (0 = smí vidět všechny).
 * Vedení a účetní obvykle vidí celou firmu; kdyby sestavy dostal někdo
 * s pobočkovým omezením, nesmí přes ně obejít izolaci poboček.
 */
function afxUcetniForcedBranchId(): int {
    if (function_exists('isBranchGlobalViewer') && isBranchGlobalViewer()) { return 0; }
    return function_exists('getCurrentStaffBranchId') ? (int)getCurrentStaffBranchId() : 0;
}

/** Vybraná pobočka z URL s respektem k vynucenému omezení. 0 = všechny pobočky. */
function afxUcetniResolveBranch($raw): int {
    $forced = afxUcetniForcedBranchId();
    if ($forced > 0) { return $forced; }
    $id = (int)$raw;
    if ($id <= 0) { return 0; }
    foreach (afxUcetniBranchList() as $b) {
        if ((int)$b['id'] === $id) { return $id; }
    }
    return 0;
}

/** Seznam poboček pro výběr. */
function afxUcetniBranchList(): array {
    if (!function_exists('getBranches')) { return []; }
    $out = [];
    foreach (getBranches(false) as $b) {
        $out[] = ['id' => (int)$b['id'], 'name' => (string)$b['name']];
    }
    return $out;
}

/** Popisek pobočky do hlavičky sestavy. */
function afxUcetniBranchLabel(int $branchId): string {
    if ($branchId <= 0) { return 'všechny provozovny'; }
    if (function_exists('getBranchLabel')) {
        $n = getBranchLabel($branchId);
        if ($n !== '') { return $n; }
    }
    return 'pobočka #' . $branchId;
}

/**
 * Pobočkový filtr pro FAKTURY.
 *
 * POZOR: tabulka invoices nemá branch_id — pobočka se dá zjistit jen přes
 * navázanou zakázku (orders.branch_id). Faktura bez zakázky (ruční faktura,
 * prodej z kasy na fakturu) tedy k žádné pobočce nepatří a při zvolené pobočce
 * ze sestavy VYPADNE. Kdybychom ji tiše přidali do obou poboček, součty poboček
 * by nedaly celek. Proto se počet takových faktur vypisuje v poznámce sestavy.
 */
function afxUcetniInvoiceBranchSql(int $branchId, string $ordersAlias = 'o'): array {
    if ($branchId <= 0) { return ['', []]; }
    return [' AND ' . $ordersAlias . '.branch_id = ?', [$branchId]];
}

// ─────────────────────────────────────────────────────────────────────────────
// FORMÁTOVÁNÍ
// ─────────────────────────────────────────────────────────────────────────────

/** Částka s haléři — účetní chce vidět i desetiny, na rozdíl od formatMoney(). */
function afxUcetniMoney($v): string {
    return number_format((float)$v, 2, ',', "\u{00a0}");
}

/** Datum d.m.rrrr, prázdné → pomlčka. */
function afxUcetniDate($d): string {
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00' || !strtotime($d)) { return '—'; }
    return date('j.n.Y', strtotime($d));
}

/** Český název stavu faktury. */
function afxUcetniInvoiceStatus(string $status): string {
    return [
        'draft' => 'Koncept', 'issued' => 'Vystaveno', 'paid' => 'Zaplaceno',
        'overdue' => 'Po splatnosti', 'cancelled' => 'Stornováno',
    ][$status] ?? $status;
}

/** Štítek stavu faktury (barevný, tiskne se i barevně — nese informaci). */
function afxUcetniStatusTag(string $status): string {
    $cls = ['paid' => 'rp-tag--ok', 'overdue' => 'rp-tag--bad',
            'cancelled' => 'rp-tag--bad', 'draft' => 'rp-tag--warn'][$status] ?? 'rp-tag--info';
    return '<span class="rp-tag ' . $cls . '">' . e(afxUcetniInvoiceStatus($status)) . '</span>';
}

/** Způsob úhrady z invoice_payments.kind. */
function afxUcetniPayKind(string $kind): string {
    return ['bank' => 'Banka', 'cash' => 'Hotovost', 'card' => 'Karta', 'other' => 'Jiné'][$kind] ?? $kind;
}

// ─────────────────────────────────────────────────────────────────────────────
// KATALOG SESTAV
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Definice sestav pro rozcestník i pro router tiskové stránky.
 * 'sirka' => 'landscape' přepne tisk na šířku (široké tabulky).
 */
function afxUcetniReportDefs(): array {
    return [
        'kniha' => [
            'nazev' => 'Kniha vydaných faktur',
            'ikona' => 'fa-book',
            'popis' => 'Číslo, vystavení, DUZP, splatnost, odběratel s IČO, částka, uhrazeno, zbývá a stav. Dobropisy jsou v samostatné sestavě.',
            'sirka' => 'landscape',
        ],
        'uhrady' => [
            'nazev' => 'Přehled úhrad faktur',
            'ikona' => 'fa-hand-holding-dollar',
            'popis' => 'Jednotlivé došlé platby: datum, faktura, částka, způsob (banka/hotovost/karta) a protistrana.',
            'sirka' => 'landscape',
        ],
        'pohledavky' => [
            'nazev' => 'Neuhrazené pohledávky k datu',
            'ikona' => 'fa-file-invoice',
            'popis' => 'Stav ke konci období s rozpadem podle stáří: do splatnosti, 1–30, 31–60 a více než 60 dní.',
            'sirka' => 'landscape',
        ],
        'dobropisy' => [
            'nazev' => 'Dobropisy za období',
            'ikona' => 'fa-rotate-left',
            'popis' => 'Opravné daňové doklady vystavené v období, s odkazem na původní fakturu.',
            'sirka' => 'portrait',
        ],
        'kasa' => [
            'nazev' => 'Tržby z pokladny po dnech',
            'ikona' => 'fa-cash-register',
            'popis' => 'Denní tržby z kasy rozdělené na hotovost, kartu a prodej na fakturu, včetně počtu dokladů.',
            'sirka' => 'portrait',
        ],
        'zalohy' => [
            'nazev' => 'Přijaté zálohy a jejich zúčtování',
            'ikona' => 'fa-piggy-bank',
            'popis' => 'Peníze přijaté před uskutečněním plnění. Záloha není výnos — dokud plnění nenastane, je to závazek.',
            'sirka' => 'landscape',
        ],
        'banka' => [
            'nazev' => 'Bankovní pohyby za období',
            'ikona' => 'fa-building-columns',
            'popis' => 'Pohyby stažené z bankovního účtu: datum, protistrana, VS, částka a spárovaná faktura.',
            'sirka' => 'landscape',
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// DATA SESTAV
// Každá funkce vrací ['rows' => [...], 'soucty' => [...], 'pozn' => [řetězce]].
// ─────────────────────────────────────────────────────────────────────────────

/** 1) Kniha vydaných faktur. */
function afxUcetniDataKniha(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'soucty' => ['celkem' => 0.0, 'uhrazeno' => 0.0, 'zbyva' => 0.0,
        'pocet' => 0, 'storno_pocet' => 0, 'storno_castka' => 0.0], 'pozn' => []];
    if (!afxUcetniTableExists('invoices')) {
        $out['pozn'][] = 'Tabulka faktur v databázi neexistuje — sestava je prázdná.';
        return $out;
    }

    [$bSql, $bPar] = afxUcetniInvoiceBranchSql($branchId);
    $name = afxUcetniCustNameExpr();

    // „Uhrazeno" se počítá K POSLEDNÍMU DNI OBDOBÍ, ne k dnešku. invoices.paid_amount
    // je stav „teď" — kniha za červenec vytištěná v srpnu by u faktury zaplacené 5. 8.
    // ukázala „zbývá 0", zatímco Pohledávky k 31. 7. ji správně vykazují jako
    // neuhrazenou. Dvě sestavy nad týmž obdobím si musí odpovídat, proto se
    // zaplacenost bere z invoice_payments se stejným pravidlem jako v pohledávkách.
    // Platba bez vyplněného paid_on se datuje dnem zápisu (jinak by nespadla nikam).
    $hasPay = afxUcetniTableExists('invoice_payments');
    $paid = $hasPay
        ? "(SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p
            WHERE p.invoice_id = i.id AND COALESCE(p.paid_on, DATE(p.created_at)) <= ?)"
        : afxUcetniPaidExpr('i');
    $payRows = $hasPay
        ? "(SELECT COUNT(*) FROM invoice_payments p2 WHERE p2.invoice_id = i.id)"
        : '0';

    // Poddotazy s datem jsou v SELECTu, tedy jejich parametry jdou PŘED WHERE.
    $params = $hasPay ? [$to] : [];
    $params = array_merge($params, [$from, $to], $bPar);

    $rows = afxUcetniQuery(
        "SELECT i.id, i.invoice_number, i.date_issue, i.date_tax, i.date_due, i.status,
                i.total_amount, i.currency, i.order_id, i.payment_date, o.branch_id,
                COALESCE(i.supplier, 'sro') AS supplier,
                $paid AS paid_amount,
                $payRows AS pay_rows,
                $name AS cust_name,
                COALESCE(NULLIF(i.cust_ico_override, ''), c.ico, '') AS cust_ico
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN orders o ON o.id = i.order_id
         WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
           AND i.date_issue BETWEEN ? AND ?" . $bSql . "
         ORDER BY i.date_issue ASC, i.invoice_number ASC",
        $params
    );

    $today = date('Y-m-d');
    $toTs = strtotime($to);
    foreach ($rows as $r) {
        $total = (float)$r['total_amount'];
        $paidV = (float)$r['paid_amount'];
        // Starší faktura označená ručně jako zaplacená nemá evidovanou platbu —
        // bez tohohle dorovnání by v knize svítilo „zbývá = celá částka".
        // Dorovnává se ale jen tehdy, když úhrada (payment_date) spadá do konce
        // období — faktura zaplacená až po něm musí v knize zůstat neuhrazená,
        // stejně jako v sestavě pohledávek.
        if ((int)$r['pay_rows'] === 0 && $r['status'] === 'paid' && $paidV <= 0) {
            $pd = (string)($r['payment_date'] ?? '');
            if ($pd === '' || !strtotime($pd) || strtotime($pd) <= $toTs) { $paidV = $total; }
        }
        $rem = round($total - $paidV, 2);

        $r['paid_calc'] = $paidV;
        $r['remaining'] = max(0.0, $rem);
        $r['po_splatnosti'] = ($r['status'] !== 'paid' && $r['status'] !== 'cancelled'
            && $r['date_due'] && $r['date_due'] < $today && $rem > 0.005);
        $out['rows'][] = $r;

        if ($r['status'] === 'cancelled') {
            $out['soucty']['storno_pocet']++;
            $out['soucty']['storno_castka'] += $total;
            continue;   // stornovaná faktura není výnos, do součtů nepatří
        }
        $out['soucty']['pocet']++;
        $out['soucty']['celkem'] += $total;
        $out['soucty']['uhrazeno'] += min($paidV, $total);
        $out['soucty']['zbyva'] += max(0.0, $rem);
    }

    // Účetní musí vědět, k jakému datu jsou sloupce počítané — sestava vytištěná
    // dvakrát s odstupem týdnů musí dát stejná čísla (podklad k přiznání).
    if ($hasPay) {
        $out['pozn'][] = 'Sloupce „Uhrazeno" a „Zbývá" jsou počítány <b>k poslednímu dni období ('
            . afxUcetniDate($to) . ')</b>, ne k dnešku — kniha tak sedí na sestavu „Neuhrazené pohledávky k datu". '
            . 'Platba došlá po tomto datu se tu neprojeví, i když už je faktura dnes zaplacená.';
    }

    // Faktury bez zakázky nejde přiřadit pobočce — účetní musí vědět, že jí
    // v pobočkovém filtru chybí, jinak jí součty poboček nedají celek.
    if ($branchId > 0) {
        $orphan = afxUcetniQuery(
            "SELECT COUNT(*) c, COALESCE(SUM(i.total_amount), 0) s FROM invoices i
             WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
               AND i.date_issue BETWEEN ? AND ?
               AND (i.order_id IS NULL OR NOT EXISTS (SELECT 1 FROM orders o2 WHERE o2.id = i.order_id))",
            [$from, $to]
        );
        $cnt = (int)($orphan[0]['c'] ?? 0);
        if ($cnt > 0) {
            $out['pozn'][] = 'Pobočka se u faktury určuje podle navázané zakázky. V období je '
                . $cnt . ' faktur bez zakázky (celkem ' . afxUcetniMoney($orphan[0]['s'] ?? 0)
                . ' Kč), které do pobočkového výběru nespadají — najdete je ve výběru „všechny provozovny".';
        }
    }
    return $out;
}

/** 2) Přehled úhrad faktur. */
function afxUcetniDataUhrady(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'soucty' => ['celkem' => 0.0, 'pocet' => 0, 'dle_zpusobu' => []], 'pozn' => []];
    if (!afxUcetniTableExists('invoice_payments')) {
        $out['pozn'][] = 'Evidence plateb (invoice_payments) v databázi zatím není — sestava je prázdná.';
        return $out;
    }

    [$bSql, $bPar] = afxUcetniInvoiceBranchSql($branchId);
    $name = afxUcetniCustNameExpr();
    $hasBank = afxUcetniTableExists('bank_transactions');
    // Protistrana: u bankovní platby jméno z výpisu (to je to, co uvidí účetní
    // v bance), jinak odběratel z faktury.
    $bankJoin = $hasBank ? 'LEFT JOIN bank_transactions bt ON bt.id = p.bank_transaction_id' : '';
    $bankCols = $hasBank ? 'bt.counterparty_name AS bank_name, bt.counterparty_account AS bank_acc, bt.vs AS bank_vs,'
                         : "NULL AS bank_name, NULL AS bank_acc, NULL AS bank_vs,";

    // Platby ke STORNOVANÝM fakturám a k dobropisům do součtů NEPATŘÍ — Kniha
    // vydaných faktur i panel cash-flow v Přehledech je vylučují a účetní by
    // jinak měla dvě sestavy, které na sebe nesedí a nedají se srovnat.
    // Nezmizí ale beze stopy: vyčíslí se zvlášť v poznámce pod tabulkou (viz níž),
    // protože platba ke stornu = peníze, které je nutné vrátit nebo dobropisovat.
    // Platba bez vyplněného data úhrady se datuje dnem zápisu (COALESCE) — jinak
    // by nespadla do ŽÁDNÉHO období a součty by nikdy neseděly na paid_amount.
    $out['rows'] = afxUcetniQuery(
        "SELECT p.id, COALESCE(p.paid_on, DATE(p.created_at)) AS paid_on,
                (p.paid_on IS NULL) AS datum_dopocten,
                p.amount, p.kind, p.note, p.created_by,
                $bankCols
                i.invoice_number, i.variable_symbol, i.currency, i.status AS inv_status,
                $name AS cust_name
         FROM invoice_payments p
         JOIN invoices i ON i.id = p.invoice_id
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN orders o ON o.id = i.order_id
         $bankJoin
         WHERE COALESCE(p.paid_on, DATE(p.created_at)) BETWEEN ? AND ?
           AND i.status <> 'cancelled'
           AND COALESCE(i.invoice_type, 'invoice') <> 'credit_note'" . $bSql . "
         ORDER BY paid_on ASC, p.id ASC",
        array_merge([$from, $to], $bPar)
    );

    $dopocteno = 0;
    foreach ($out['rows'] as $r) {
        $out['soucty']['celkem'] += (float)$r['amount'];
        $out['soucty']['pocet']++;
        $k = (string)$r['kind'];
        $out['soucty']['dle_zpusobu'][$k] = ($out['soucty']['dle_zpusobu'][$k] ?? 0) + (float)$r['amount'];
        if (!empty($r['datum_dopocten'])) { $dopocteno++; }
    }

    // Vyloučené platby se vyčíslí pod čarou — jinak by účetní neměla jak zjistit,
    // proč jí sestava nesedí na výpis z banky (peníze fyzicky přišly).
    $excl = afxUcetniQuery(
        "SELECT SUM(CASE WHEN i.status = 'cancelled' THEN 1 ELSE 0 END) storno_pocet,
                COALESCE(SUM(CASE WHEN i.status = 'cancelled' THEN p.amount END), 0) storno_castka,
                SUM(CASE WHEN i.status <> 'cancelled' AND COALESCE(i.invoice_type, 'invoice') = 'credit_note' THEN 1 ELSE 0 END) dobropis_pocet,
                COALESCE(SUM(CASE WHEN i.status <> 'cancelled' AND COALESCE(i.invoice_type, 'invoice') = 'credit_note' THEN p.amount END), 0) dobropis_castka
         FROM invoice_payments p
         JOIN invoices i ON i.id = p.invoice_id
         LEFT JOIN orders o ON o.id = i.order_id
         WHERE COALESCE(p.paid_on, DATE(p.created_at)) BETWEEN ? AND ?
           AND (i.status = 'cancelled' OR COALESCE(i.invoice_type, 'invoice') = 'credit_note')" . $bSql,
        array_merge([$from, $to], $bPar)
    );
    $ex = $excl[0] ?? [];
    if ((int)($ex['storno_pocet'] ?? 0) > 0) {
        $out['pozn'][] = '<b>Mimo součet:</b> ' . (int)$ex['storno_pocet'] . ' plateb za '
            . afxUcetniMoney($ex['storno_castka'] ?? 0) . ' Kč patří k fakturám, které byly NÁSLEDNĚ STORNOVÁNY. '
            . 'Do součtů nevstupují (aby sestava seděla na Knihu vydaných faktur) — peníze je nutné vrátit nebo dobropisovat.';
    }
    if ((int)($ex['dobropis_pocet'] ?? 0) > 0) {
        $out['pozn'][] = '<b>Mimo součet:</b> ' . (int)$ex['dobropis_pocet'] . ' plateb za '
            . afxUcetniMoney($ex['dobropis_castka'] ?? 0) . ' Kč je evidováno u dobropisů — najdete je v sestavě „Dobropisy za období".';
    }
    if ($dopocteno > 0) {
        $out['pozn'][] = 'U ' . $dopocteno . ' plateb chybí datum úhrady — v sestavě jsou datované dnem zápisu do CRM.';
    }
    $out['pozn'][] = 'Sestava obsahuje jen platby EVIDOVANÉ u faktury. Hotovostní tržby z kasy bez faktury najdete v sestavě „Tržby z pokladny po dnech".';
    return $out;
}

/**
 * 3) Neuhrazené pohledávky K DATU (konec zvoleného období).
 *
 * „K datu" znamená stav, jaký byl ke konci období — ne dnešní stav. Proto se
 * zaplacenost počítá ze součtu plateb s paid_on <= datum: faktura zaplacená až
 * v srpnu musí být v pohledávkách k 31. 7. pořád vidět, jinak by sestava
 * zpětně „vyčistila" minulost a nesedla by na účetnictví.
 */
function afxUcetniDataPohledavky(string $toDate, int $branchId): array {
    $buckets = ['do' => 'Do splatnosti', 'd30' => '1–30 dní', 'd60' => '31–60 dní', 'd60p' => 'Více než 60 dní'];
    $out = ['rows' => [], 'soucty' => ['celkem' => 0.0, 'pocet' => 0,
        'kose' => array_fill_keys(array_keys($buckets), 0.0),
        'kose_pocet' => array_fill_keys(array_keys($buckets), 0),
        'nazvy_kosu' => $buckets], 'pozn' => []];
    if (!afxUcetniTableExists('invoices')) {
        $out['pozn'][] = 'Tabulka faktur v databázi neexistuje — sestava je prázdná.';
        return $out;
    }

    [$bSql, $bPar] = afxUcetniInvoiceBranchSql($branchId);
    $name = afxUcetniCustNameExpr();
    $hasPay = afxUcetniTableExists('invoice_payments');
    // Platba bez data úhrady se datuje dnem zápisu — stejné pravidlo jako v Knize
    // faktur a Přehledu úhrad, jinak by tatáž platba v jedné sestavě byla a v druhé ne.
    $paidTo = $hasPay
        ? "(SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p
            WHERE p.invoice_id = i.id AND COALESCE(p.paid_on, DATE(p.created_at)) <= ?)"
        : afxUcetniPaidExpr('i');
    $payRows = $hasPay
        ? "(SELECT COUNT(*) FROM invoice_payments p2 WHERE p2.invoice_id = i.id)"
        : '0';
    // Dobropis vystavený k faktuře pohledávku SNIŽUJE — faktura plně pokrytá
    // dobropisem už není pohledávka, i když na ni nikdy nepřišla koruna.
    // Bez odečtu by účetní zaúčtovala pohledávku, kterou už jednou snížila,
    // a v rozvaze by jí zůstal přebytek. Počítá se jen dobropis vystavený
    // do konce období (date_issue <= k datu) — sestava je stavová „k datu".
    $creditTo = "(SELECT COALESCE(SUM(cn.total_amount), 0) FROM invoices cn
                  WHERE cn.parent_id = i.id AND cn.invoice_type = 'credit_note'
                    AND cn.status <> 'cancelled' AND cn.date_issue <= ?)";

    // Pořadí parametrů: poddotazy s datem jsou v SELECTu, tedy PŘED WHERE.
    $params = $hasPay ? [$toDate] : [];
    $params[] = $toDate;   // dobropisy do konce období
    $params[] = $toDate;   // WHERE date_issue <= ?
    $params = array_merge($params, $bPar);

    $rows = afxUcetniQuery(
        "SELECT i.id, i.invoice_number, i.date_issue, i.date_due, i.date_tax, i.status,
                i.total_amount, i.currency, i.payment_date,
                $paidTo AS paid_to_date,
                $creditTo AS dobropisovano,
                $payRows AS pay_rows,
                $name AS cust_name,
                COALESCE(NULLIF(i.cust_ico_override, ''), c.ico, '') AS cust_ico
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN orders o ON o.id = i.order_id
         WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
           AND i.status NOT IN ('cancelled', 'draft')
           AND i.date_issue <= ?" . $bSql . "
         ORDER BY i.date_due ASC, i.invoice_number ASC",
        $params
    );

    $toTs = strtotime($toDate);
    $dobropisPocet = 0;
    $dobropisCastka = 0.0;
    foreach ($rows as $r) {
        $total = round((float)$r['total_amount'], 2);
        $paid = round((float)$r['paid_to_date'], 2);
        $credited = round((float)$r['dobropisovano'], 2);

        // Faktura zaplacená ještě před zavedením evidence plateb nemá žádný řádek
        // v invoice_payments — pak rozhoduje payment_date. Bez toho by se všechny
        // staré zaplacené faktury vrátily do pohledávek.
        if ((int)$r['pay_rows'] === 0 && $r['status'] === 'paid') {
            $pd = (string)($r['payment_date'] ?? '');
            if ($pd === '' || strtotime($pd) <= $toTs) { $paid = $total; }
        }

        // Dobropis snižuje zbývající pohledávku (viz komentář u $creditTo).
        $rem = round($total - $paid - $credited, 2);
        if ($credited > 0.005) { $dobropisPocet++; $dobropisCastka += $credited; }
        if ($rem <= 0.005) { continue; }

        $due = (string)($r['date_due'] ?? '');
        $dueTs = $due !== '' && strtotime($due) ? strtotime($due) : null;
        $daysLate = $dueTs !== null ? (int)floor(($toTs - $dueTs) / 86400) : 0;
        if ($daysLate <= 0) { $key = 'do'; }
        elseif ($daysLate <= 30) { $key = 'd30'; }
        elseif ($daysLate <= 60) { $key = 'd60'; }
        else { $key = 'd60p'; }

        $r['paid_calc'] = $paid;
        $r['remaining'] = $rem;
        $r['dni_po'] = max(0, $daysLate);
        $r['kos'] = $key;
        $out['rows'][] = $r;

        $out['soucty']['celkem'] += $rem;
        $out['soucty']['pocet']++;
        $out['soucty']['kose'][$key] += $rem;
        $out['soucty']['kose_pocet'][$key]++;
    }

    if ($dobropisPocet > 0) {
        $out['pozn'][] = 'U ' . $dobropisPocet . ' faktur je od zbývající částky odečten dobropis vystavený do '
            . afxUcetniDate($toDate) . ' (celkem ' . afxUcetniMoney($dobropisCastka) . ' Kč). '
            . 'Faktura plně pokrytá dobropisem v pohledávkách není — pohledávka zanikla dobropisem, ne úhradou.';
    }
    return $out;
}

/** 4) Dobropisy (opravné daňové doklady) za období. */
function afxUcetniDataDobropisy(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'soucty' => ['celkem' => 0.0, 'pocet' => 0], 'pozn' => []];
    if (!afxUcetniTableExists('invoices')) {
        $out['pozn'][] = 'Tabulka faktur v databázi neexistuje — sestava je prázdná.';
        return $out;
    }

    [$bSql, $bPar] = afxUcetniInvoiceBranchSql($branchId);
    $name = afxUcetniCustNameExpr();

    $out['rows'] = afxUcetniQuery(
        "SELECT i.id, i.invoice_number, i.date_issue, i.date_tax, i.status, i.total_amount,
                i.currency, i.notes, i.parent_id,
                pi.invoice_number AS parent_number, pi.date_issue AS parent_issue,
                $name AS cust_name,
                COALESCE(NULLIF(i.cust_ico_override, ''), c.ico, '') AS cust_ico
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN orders o ON o.id = i.order_id
         LEFT JOIN invoices pi ON pi.id = i.parent_id
         WHERE i.invoice_type = 'credit_note'
           AND i.date_issue BETWEEN ? AND ?" . $bSql . "
         ORDER BY i.date_issue ASC, i.invoice_number ASC",
        array_merge([$from, $to], $bPar)
    );

    foreach ($out['rows'] as $r) {
        if ($r['status'] === 'cancelled') { continue; }
        $out['soucty']['celkem'] += (float)$r['total_amount'];
        $out['soucty']['pocet']++;
    }
    return $out;
}

/** 5) Tržby z pokladny po dnech. */
function afxUcetniDataKasa(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'soucty' => ['hotovost' => 0.0, 'karta' => 0.0, 'faktura' => 0.0,
        'faktura_ico' => 0.0, 'celkem' => 0.0, 'doklady' => 0, 'storno_pocet' => 0, 'storno_castka' => 0.0], 'pozn' => []];
    if (!afxUcetniTableExists('pos_sales')) {
        $out['pozn'][] = 'Pokladna (pos_sales) v databázi zatím není — sestava je prázdná.';
        return $out;
    }

    $bSql = $branchId > 0 ? ' AND s.branch_id = ?' : '';
    $bPar = $branchId > 0 ? [$branchId] : [];
    $usedExpr = afxUcetniTableExists('pos_sale_items')
        ? "SUM(CASE WHEN EXISTS (SELECT 1 FROM pos_sale_items it WHERE it.sale_id = s.id AND it.is_used_goods = 1) THEN 1 ELSE 0 END)"
        : '0';

    $out['rows'] = afxUcetniQuery(
        "SELECT DATE(s.created_at) AS den,
                SUM(CASE WHEN s.payment_method = 'cash' THEN s.total ELSE 0 END) AS hotovost,
                SUM(CASE WHEN s.payment_method = 'card' THEN s.total ELSE 0 END) AS karta,
                SUM(CASE WHEN s.payment_method = 'invoice' THEN s.total ELSE 0 END) AS faktura,
                SUM(CASE WHEN s.payment_method = 'invoice_ico' THEN s.total ELSE 0 END) AS faktura_ico,
                COUNT(*) AS doklady,
                SUM(s.total) AS celkem,
                $usedExpr AS pouzite_doklady
         FROM pos_sales s
         WHERE s.status = 'completed' AND s.total >= 0 AND s.created_at BETWEEN ? AND ?" . $bSql . "
         GROUP BY DATE(s.created_at)
         ORDER BY den ASC",
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $bPar)
    );

    // Výplatní doklady (výkup zařízení, výdaj z kasy — total < 0) NEJSOU tržba:
    // záloha zaměstnanci nebo nákup drogerie by tržbu dne tiše snižovaly. Ze
    // součtů se vyřazují a vykazují se poznámkou; v pokladní knize jsou jako Výdaje.
    $vp = afxUcetniQuery(
        "SELECT COUNT(*) c, COALESCE(SUM(-s.total), 0) s FROM pos_sales s
         WHERE s.status = 'completed' AND s.total < 0 AND s.created_at BETWEEN ? AND ?" . $bSql,
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $bPar)
    );
    if ((int)($vp[0]['c'] ?? 0) > 0) {
        $out['pozn'][] = 'Výplatní doklady kasy (výkup zařízení / výdaj z kasy) v období: '
            . (int)$vp[0]['c'] . ' ks v hodnotě ' . afxUcetniMoney((float)$vp[0]['s'])
            . ' Kč. Nejsou započtené v tržbách — v pokladní knize jsou vedené jako výdaje.';
    }

    foreach ($out['rows'] as $r) {
        $out['soucty']['hotovost'] += (float)$r['hotovost'];
        $out['soucty']['karta'] += (float)$r['karta'];
        $out['soucty']['faktura'] += (float)$r['faktura'];
        $out['soucty']['faktura_ico'] += (float)($r['faktura_ico'] ?? 0);
        $out['soucty']['celkem'] += (float)$r['celkem'];
        $out['soucty']['doklady'] += (int)$r['doklady'];
    }

    // Stornované doklady se nesmí ztratit — doklad nelze mazat, jen stornovat,
    // a účetní potřebuje vidět, že v období nějaké storno bylo.
    $st = afxUcetniQuery(
        "SELECT COUNT(*) c, COALESCE(SUM(s.total), 0) s FROM pos_sales s
         WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ?" . $bSql,
        array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $bPar)
    );
    $out['soucty']['storno_pocet'] = (int)($st[0]['c'] ?? 0);
    $out['soucty']['storno_castka'] = (float)($st[0]['s'] ?? 0);

    // Prodeje BEZ přiřazené provozovny (branch_id NULL/0 — prodej pod účtem bez
    // pobočky, starší doklady) by při zvolené pobočce tiše vypadly a součet
    // poboček by nedal celek — stejná past jako u faktur bez zakázky výš.
    // JEDNOTNÉ PRAVIDLO sestav: doklady bez pobočky se vykazují ZVLÁŠŤ (vlastní
    // řádka v poznámce), nepřiřazují se tiše žádné pobočce. POZOR: pokladní
    // kniha (includes/cash_book.php) je naopak připočítává výchozí provozovně,
    // aby seděl fyzický zůstatek zásuvky — proto se to tady říká nahlas.
    if ($branchId > 0) {
        $orphan = afxUcetniQuery(
            "SELECT COUNT(*) c, COALESCE(SUM(s.total), 0) s FROM pos_sales s
             WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ?
               AND (s.branch_id IS NULL OR s.branch_id = 0)",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        $cnt = (int)($orphan[0]['c'] ?? 0);
        if ($cnt > 0) {
            $out['pozn'][] = '<b>Bez provozovny:</b> v období je dále ' . $cnt . ' dokladů kasy za '
                . afxUcetniMoney($orphan[0]['s'] ?? 0) . ' Kč bez přiřazené provozovny — do výběru konkrétní '
                . 'pobočky nespadají, najdete je ve výběru „všechny provozovny". Pokladní kniha je '
                . 'připočítává výchozí provozovně (fyzicky prošly její zásuvkou).';
        }
    }

    $out['pozn'][] = 'Prodej „na fakturu" není hotovostní tržba — peníze přijdou až úhradou faktury (viz sestava „Přehled úhrad faktur"), aby se stejná částka nezaúčtovala dvakrát.';
    return $out;
}

/**
 * 6) Přijaté zálohy a jejich zúčtování.
 *
 * CRM zatím nemá samostatný typ „zálohová faktura", takže se zálohy poznávají
 * podle chování a názvosloví:
 *   • platba u faktury došlá PŘED datem uskutečnění plnění (DUZP) = záloha,
 *   • hotovostní pohyb v kase označený jako záloha,
 *   • faktura, jejíž položka se jmenuje „záloha…" (uvádí se jen pro kontrolu,
 *     do součtu přijatých peněz NEvstupuje, aby se táž koruna nepočítala dvakrát).
 * Detekce je nutně odhad — sestava to říká nahlas a účetní ji musí projít.
 *
 * Zúčtování: záloha je závazek do okamžiku uskutečnění plnění. Za zúčtovanou
 * se považuje ta, jejíž faktura má DUZP nejpozději ke konci období.
 */
function afxUcetniDataZalohy(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'faktury' => [], 'soucty' => ['prijato' => 0.0, 'zuctovano' => 0.0,
        'zavazek' => 0.0, 'pocet' => 0], 'pozn' => []];

    $toTs = strtotime($to);
    [$bSql, $bPar] = afxUcetniInvoiceBranchSql($branchId);
    $name = afxUcetniCustNameExpr();

    // (a) platba došlá před DUZP faktury
    if (afxUcetniTableExists('invoice_payments') && afxUcetniTableExists('invoices')) {
        $rows = afxUcetniQuery(
            "SELECT p.id, p.paid_on, p.amount, p.kind, p.note,
                    i.id AS inv_id, i.invoice_number, i.date_tax, i.date_issue, i.status,
                    $name AS cust_name
             FROM invoice_payments p
             JOIN invoices i ON i.id = p.invoice_id
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN orders o ON o.id = i.order_id
             WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
               AND i.status <> 'cancelled'
               AND p.paid_on BETWEEN ? AND ?
               AND i.date_tax IS NOT NULL AND i.date_tax > p.paid_on" . $bSql . "
             ORDER BY p.paid_on ASC, p.id ASC",
            array_merge([$from, $to], $bPar)
        );
        foreach ($rows as $r) {
            $duzp = (string)$r['date_tax'];
            $settled = $duzp !== '' && strtotime($duzp) <= $toTs;
            $out['rows'][] = [
                'datum' => (string)$r['paid_on'],
                'zdroj' => 'Platba faktury před DUZP',
                'popis' => 'Faktura ' . (string)$r['invoice_number'] . ' · ' . afxUcetniPayKind((string)$r['kind'])
                    . ((string)$r['note'] !== '' ? ' · ' . (string)$r['note'] : ''),
                'klient' => (string)$r['cust_name'],
                'castka' => (float)$r['amount'],
                'doklad' => (string)$r['invoice_number'],
                'duzp' => $duzp,
                'zuctovano' => $settled,
            ];
        }
    } else {
        $out['pozn'][] = 'Evidence plateb (invoice_payments) v databázi zatím není — zálohy zaplacené na fakturu nelze dohledat.';
    }

    // (b) hotovostní záloha v kase (pokladní deník)
    if (afxUcetniTableExists('pos_cash_movements')) {
        $cSql = $branchId > 0 ? ' AND branch_id = ?' : '';
        $cPar = $branchId > 0 ? [$branchId] : [];
        $rows = afxUcetniQuery(
            "SELECT id, created_at, amount, purpose, note, ref_type, ref_id, ref_label, created_by
             FROM pos_cash_movements
             WHERE direction = 'in' AND created_at BETWEEN ? AND ?
               AND (purpose LIKE '%áloh%' OR note LIKE '%áloh%')" . $cSql . "
             ORDER BY created_at ASC, id ASC",
            array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $cPar)
        );
        foreach ($rows as $r) {
            $out['rows'][] = [
                'datum' => date('Y-m-d', strtotime((string)$r['created_at'])),
                'zdroj' => 'Hotovost v pokladně',
                'popis' => trim((string)$r['note']) !== '' ? (string)$r['note'] : ('Pokladní pohyb #' . (int)$r['id']),
                'klient' => (string)($r['ref_label'] ?? '') !== '' ? (string)$r['ref_label'] : '—',
                'castka' => (float)$r['amount'],
                'doklad' => (string)($r['ref_label'] ?? ''),
                'duzp' => '',
                'zuctovano' => false,   // bez navázané faktury nevíme, kdy plnění nastalo
            ];
        }
    }

    usort($out['rows'], static function ($a, $b) {
        return strcmp((string)$a['datum'], (string)$b['datum']);
    });
    foreach ($out['rows'] as $r) {
        $out['soucty']['prijato'] += $r['castka'];
        $out['soucty']['pocet']++;
        if ($r['zuctovano']) { $out['soucty']['zuctovano'] += $r['castka']; }
        else { $out['soucty']['zavazek'] += $r['castka']; }
    }

    // ZÁVAZEK KE KONCI OBDOBÍ musí být KUMULATIVNÍ. Řádky výš jsou jen zálohy
    // PŘIJATÉ v období — ale záloha složená v červnu, jejíž DUZP vyjde na září,
    // je závazkem i v sestavě za červenec a srpen. Kdyby se karta „Závazek k datu"
    // plnila jen z plateb období, účetní by podle srpnové sestavy odúčtovala
    // závazek na účtu 324, který nikdy nezanikl. Proto se závazek počítá zvlášť:
    // všechny platby do konce období na faktury, jejichž DUZP je po konci období
    // (nebo chybí), plus hotovostní zálohy z pokladního deníku bez vazby na fakturu.
    $zavazek = null;
    if (afxUcetniTableExists('invoice_payments') && afxUcetniTableExists('invoices')) {
        $rows = afxUcetniQuery(
            "SELECT COALESCE(SUM(p.amount), 0) s FROM invoice_payments p
             JOIN invoices i ON i.id = p.invoice_id
             LEFT JOIN orders o ON o.id = i.order_id
             WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
               AND i.status <> 'cancelled'
               AND COALESCE(p.paid_on, DATE(p.created_at)) <= ?
               AND (i.date_tax IS NULL OR i.date_tax > ?)" . $bSql,
            array_merge([$to, $to], $bPar)
        );
        if (isset($rows[0]['s'])) { $zavazek = (float)$rows[0]['s']; }
    }
    if (afxUcetniTableExists('pos_cash_movements')) {
        $cSql = $branchId > 0 ? ' AND branch_id = ?' : '';
        $cPar = $branchId > 0 ? [$branchId] : [];
        $rows = afxUcetniQuery(
            "SELECT COALESCE(SUM(amount), 0) s FROM pos_cash_movements
             WHERE direction = 'in' AND created_at <= ?
               AND (purpose LIKE '%áloh%' OR note LIKE '%áloh%')" . $cSql,
            array_merge([$to . ' 23:59:59'], $cPar)
        );
        if (isset($rows[0]['s'])) { $zavazek = ($zavazek ?? 0.0) + (float)$rows[0]['s']; }
    }
    // Když se kumulativní dotaz nepovede (chybějící tabulky, chyba DB), zůstane
    // aspoň konzervativní číslo z plateb období — horší, ale ne prázdné.
    if ($zavazek !== null) {
        $out['soucty']['zavazek'] = $zavazek;
        $out['pozn'][] = 'Karta „Závazek k datu" je <b>kumulativní</b> — zahrnuje i zálohy přijaté v dřívějších '
            . 'obdobích, které ke konci období nebyly zúčtované (DUZP faktury je pozdější nebo chybí). '
            . 'Proto se nemusí rovnat rozdílu „Přijaté zálohy − Zúčtováno" za samotné období; '
            . 'tabulka níže ukazuje jen zálohy přijaté v období.';
    }

    // (c) faktury s položkou „záloha" — jen kontrolní seznam
    if (afxUcetniTableExists('invoices') && afxUcetniTableExists('invoice_items')) {
        $paid = afxUcetniPaidExpr('i');
        $out['faktury'] = afxUcetniQuery(
            "SELECT i.id, i.invoice_number, i.date_issue, i.date_tax, i.status, i.total_amount,
                    $paid AS paid_amount, $name AS cust_name
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN orders o ON o.id = i.order_id
             WHERE COALESCE(i.invoice_type, 'invoice') = 'invoice'
               AND i.date_issue BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM invoice_items it WHERE it.invoice_id = i.id AND it.item_name LIKE '%áloh%')" . $bSql . "
             ORDER BY i.date_issue ASC, i.invoice_number ASC",
            array_merge([$from, $to], $bPar)
        );
    }

    $out['pozn'][] = 'CRM zatím nemá samostatný typ dokladu „zálohová faktura". Zálohy se proto rozpoznávají podle pravidel (platba došlá před DUZP, pokladní pohyb označený jako záloha) — <b>seznam je nutné před zaúčtováním projít</b>.';
    $out['pozn'][] = 'Přijatá záloha není výnos, ale závazek. Výnos vzniká až uskutečněním plnění (DUZP), proto je záloha v této sestavě oddělená od tržeb i od knihy faktur.';
    return $out;
}

/** 7) Bankovní pohyby za období. */
function afxUcetniDataBanka(string $from, string $to, int $branchId): array {
    $out = ['rows' => [], 'soucty' => ['prijem' => 0.0, 'vydaj' => 0.0, 'pocet' => 0,
        'sparovano' => 0, 'nesparovano' => 0], 'pozn' => []];
    if (!afxUcetniTableExists('bank_transactions')) {
        $out['pozn'][] = 'Bankovní pohyby (bank_transactions) v databázi zatím nejsou — účet možná ještě není napojený.';
        return $out;
    }

    // Stejné omezení jako modul Banka: jen aktuální prostředí a napojený účet,
    // aby se do účetních podkladů nikdy nedostala sandboxová testovací data.
    $where = ['t.booking_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    $accId = (string)get_setting('kb_account_id', '');
    if ($accId !== '') {
        $env = get_setting('kb_env', 'sandbox') === 'prod' ? 'prod' : 'sandbox';
        $where[] = 't.env = ?';
        $where[] = 't.account_id = ?';
        $params[] = $env;
        $params[] = $accId;
        if ($env === 'sandbox') {
            $out['pozn'][] = '<b>Pozor:</b> banka je přepnutá do režimu SANDBOX — pohyby jsou testovací, ne skutečné.';
        }
    }

    // t.* schválně: sloupce is_reversal / tx_status přidává až migrace 036 a na
    // starší databázi by jmenný výčet shodil celou sestavu na prázdnou tabulku.
    $out['rows'] = afxUcetniQuery(
        "SELECT t.*, i.invoice_number
         FROM bank_transactions t
         LEFT JOIN invoices i ON i.id = t.matched_invoice_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY t.booking_date ASC, t.id ASC",
        $params
    );

    foreach ($out['rows'] as $r) {
        $out['soucty']['pocet']++;
        if ((string)$r['direction'] === 'out') { $out['soucty']['vydaj'] += (float)$r['amount']; }
        else { $out['soucty']['prijem'] += (float)$r['amount']; }
        if (!empty($r['matched_invoice_id'])) { $out['soucty']['sparovano']++; }
        elseif ((string)$r['direction'] === 'in') { $out['soucty']['nesparovano']++; }
    }

    if ($branchId > 0) {
        $out['pozn'][] = 'Bankovní účet je společný pro celou firmu — pohyby se nedají rozdělit podle provozovny, výběr pobočky se v této sestavě neuplatní.';
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// TISKOVÁ STRÁNKA
// ─────────────────────────────────────────────────────────────────────────────

/** Údaje o firmě do hlavičky sestavy (fallback na obecná firemní nastavení). */
function afxUcetniCompany(): array {
    $name = trim((string)get_setting('acc_company_name', ''));
    if ($name === '') { $name = trim((string)get_setting('company_name', 'AppleFix s.r.o.')); }
    $ico = trim((string)get_setting('acc_ico', '')) ?: trim((string)get_setting('company_ico', ''));
    $dic = trim((string)get_setting('acc_dic', '')) ?: trim((string)get_setting('company_dic', ''));
    $addr = trim((string)get_setting('acc_address', ''));
    return [
        'name' => $name,
        'ico' => $ico,
        'dic' => $dic,
        'address' => trim(preg_replace('/\s*[\r\n]+\s*/u', ', ', $addr)),
        'vat_payer' => (int)get_setting('acc_is_vat_payer', '0') === 1,
    ];
}

/** Otevře tiskovou stránku sestavy (hlavička firmy, období, datum vytvoření). */
function afxUcetniPrintOpen(string $title, array $period, int $branchId, string $orientation = 'portrait', string $datumPopis = ''): void {
    $co = afxUcetniCompany();
    $land = $orientation === 'landscape';
    $who = trim((string)($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? $_SESSION['tech_name'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> — <?php echo e($period['label']); ?></title>
<link rel="stylesheet" href="assets/css/sf-pro.css">
<link rel="stylesheet" href="assets/css/print-report.css">
</head>
<body class="report-body<?php echo $land ? ' report-landscape' : ''; ?>">
<div class="report-toolbar no-print">
    <span class="rp-hint">Sestava se ukládá do PDF přes tisk → cíl „Uložit jako PDF“.</span>
    <a class="report-btn report-btn--ghost" href="ucetni_sestavy.php?<?php echo e(http_build_query(array_merge($period['query'], ['pobocka' => $branchId]))); ?>">← Zpět na výběr</a>
    <button type="button" class="report-btn" onclick="window.print()">Vytisknout / uložit PDF</button>
</div>
<div class="report-sheet">
    <div class="report-head">
        <div>
            <div class="rp-co-name"><?php echo e($co['name']); ?></div>
            <?php if ($co['address'] !== ''): ?><div class="rp-co-line"><?php echo e($co['address']); ?></div><?php endif; ?>
            <div class="rp-co-line">
                <?php if ($co['ico'] !== ''): ?>IČO: <?php echo e($co['ico']); ?><?php endif; ?>
                <?php if ($co['dic'] !== ''): ?> · DIČ: <?php echo e($co['dic']); ?><?php endif; ?>
                <?php if (!$co['vat_payer']): ?> · neplátce DPH<?php endif; ?>
            </div>
        </div>
        <div class="rp-doc">
            <div class="rp-doc-kicker">Podklad pro účetní</div>
            <div class="rp-doc-title"><?php echo e($title); ?></div>
            <div class="rp-doc-meta"><?php echo e($period['label']); ?></div>
        </div>
    </div>

    <div class="report-params">
        <div><span><?php echo e($datumPopis !== '' ? $datumPopis : 'Období'); ?>:</span> <b><?php echo e(afxUcetniDate($period['from'])); ?> – <?php echo e(afxUcetniDate($period['to'])); ?></b></div>
        <div><span>Provozovna:</span> <b><?php echo e(afxUcetniBranchLabel($branchId)); ?></b></div>
        <div><span>Vytvořeno:</span> <b><?php echo date('j.n.Y H:i'); ?></b></div>
        <?php if ($who !== ''): ?><div><span>Vytvořil(a):</span> <b><?php echo e($who); ?></b></div><?php endif; ?>
    </div>
    <?php
}

/** Poznámky nad tabulkou (mohou obsahovat jednoduché HTML — texty píšeme my). */
function afxUcetniPrintNotes(array $notes, string $variant = ''): void {
    foreach ($notes as $n) {
        echo '<div class="report-note' . ($variant !== '' ? ' report-note--' . $variant : '') . '">' . $n . '</div>';
    }
}

/** Prázdná sestava — ať je jasné, že to není chyba zobrazení. */
function afxUcetniPrintEmpty(string $text = 'Za zvolené období nejsou žádné záznamy.'): void {
    echo '<div class="report-empty">' . e($text) . '</div>';
}

/** Uzavře tiskovou stránku (patička s identifikací dokumentu). */
function afxUcetniPrintClose(string $title, array $period, int $branchId): void {
    $co = afxUcetniCompany();
    ?>
    <div class="report-foot">
        <?php echo e($co['name']); ?><?php if ($co['ico'] !== ''): ?> · IČO <?php echo e($co['ico']); ?><?php endif; ?>
        · <?php echo e($title); ?> · <?php echo e($period['label']); ?>
        (<?php echo e(afxUcetniDate($period['from'])); ?>–<?php echo e(afxUcetniDate($period['to'])); ?>)
        · <?php echo e(afxUcetniBranchLabel($branchId)); ?>
        · vytvořeno <?php echo date('j.n.Y H:i'); ?> z CRM AppleFix<br>
        Sestava je pracovní podklad z informačního systému, ne účetní doklad. Čísla stran doplní tiskový dialog prohlížeče.
    </div>
</div>
</body>
</html>
    <?php
}
