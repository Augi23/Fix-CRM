<?php
/**
 * POKLADNÍ KNIHA (analytika účtu 211) — počáteční zůstatek, pokladní doklady
 * PPD/VPD a chronologický výpis pohybů hotovosti za období.
 *
 * PROČ to existuje: kasa (pokladna.php) do teď sčítala jen DNEŠEK a začínala
 * každý den od nuly. Hotovost se ale ze dne na den přenáší a účetně musí mít
 * každé období počáteční i konečný zůstatek (§ 11 zák. 563/1991 Sb.), doklady
 * v souvislé číselné řadě a možnost storna místo mazání.
 *
 * ZDROJE PENĚZ V KNIZE (a proč zrovna tyhle tři):
 *   1) pos_sales   — prodej na kase za hotové (příjem)
 *   2) pos_cash_movements — pokladní deník: vklady, výběry, výdej na výkup,
 *      hotovost přijatá při výdeji zakázky (tuhle tabulku POUZE ČTEME)
 *   3) cash_documents — SAMOSTATNÉ PPD/VPD, ke kterým žádný pohyb výše není
 *
 * Doklad navázaný na prodej nebo na pohyb v deníku (ref_type pos_sale /
 * cash_movement) se do součtu NEPOČÍTÁ — je to jen papír k penězům, které už
 * v knize jednou jsou. Kdyby se počítal, kasa by ukazovala dvojnásobek.
 *
 * Vyžaduje includes/functions.php (getDefaultBranchId, crmAuditLog, formatMoney…).
 */

/** Zákonný limit hotovostní platby (zák. 254/2004 Sb.) — 270 000 Kč.
 *  POZOR na výklad: limit platí na JEDNU platbu mezi týmž plátcem a příjemcem
 *  během jednoho dne, ne na denní tržbu pokladny. Denní součet proto hlásíme
 *  jen jako upozornění „zkontroluj, jestli to nebyla jedna platba". */
if (!defined('AFX_CASH_LIMIT_CZK')) {
    define('AFX_CASH_LIMIT_CZK', 270000.0);
}

/** Typy vazeb, jejichž doklad je jen PAPÍR k už započítanému pohybu peněz. */
function afxCashLinkedRefTypes(): array {
    return ['pos_sale', 'cash_movement'];
}

/** Runtime pojistka pro tabulky migrace 041 — deploy nasadí kód dřív, než
 *  doběhne run_migrations.php. Idempotentní, chyby nikdy neshodí volajícího. */
function afxEnsureCashBookTables(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    // DDL uvnitř transakce dělá v MySQL implicitní COMMIT — rozbilo by to
    // volajícího (např. checkout kasy). Raději nic a zkusit to při dalším volání
    // mimo transakci; $done proto zůstává false.
    if ($pdo->inTransaction()) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_registers (
            id INT NOT NULL AUTO_INCREMENT,
            branch_id INT NOT NULL DEFAULT 0,
            name VARCHAR(80) NOT NULL DEFAULT 'Hlavní pokladna',
            currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_date DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL DEFAULT NULL,
            updated_by VARCHAR(100) NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_cash_register_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_documents (
            id INT NOT NULL AUTO_INCREMENT,
            register_id INT NOT NULL DEFAULT 0,
            branch_id INT NOT NULL DEFAULT 0,
            doc_type ENUM('income','expense') NOT NULL,
            doc_number VARCHAR(20) NOT NULL,
            doc_year SMALLINT NOT NULL,
            doc_seq INT NOT NULL,
            doc_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            purpose VARCHAR(255) NOT NULL DEFAULT '',
            counterparty VARCHAR(160) NULL DEFAULT NULL,
            issued_by VARCHAR(100) NULL DEFAULT NULL,
            ref_type VARCHAR(20) NULL DEFAULT NULL,
            ref_id INT NULL DEFAULT NULL,
            ref_label VARCHAR(40) NULL DEFAULT NULL,
            storno_of INT NULL DEFAULT NULL,
            storned_by INT NULL DEFAULT NULL,
            storned_at DATETIME NULL DEFAULT NULL,
            note VARCHAR(255) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_cash_doc_number (branch_id, doc_number),
            KEY idx_cash_doc_series (branch_id, doc_type, doc_year, doc_seq),
            KEY idx_cash_doc_date (doc_date),
            KEY idx_cash_doc_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // $done až PO úspěchu: přechodná chyba (metadata lock, výpadek spojení)
        // by jinak pojistku vypnula do konce requestu a kniha by se ukázala
        // prázdná s nulovým zůstatkem — nerozeznatelné od pravdy.
        $done = true;
    } catch (Throwable $e) { error_log('afxEnsureCashBookTables: ' . $e->getMessage()); }
}

/**
 * Podmínka pobočky pro tabulky s hotovostí.
 *
 * Pohyby BEZ pobočky (NULL / 0 — staré importy, doklady vzniklé před zavedením
 * poboček) spadají do knihy VÝCHOZÍ pobočky (Karlín). Bez toho by ta hotovost
 * v žádné knize nebyla vidět a konečný zůstatek by neseděl na obsah zásuvky.
 * Nárokuje si je záměrně jen výchozí pobočka — jinak by se započítaly dvakrát.
 */
function afxCashBranchSql(string $column, int $branchId): string {
    $branchId = (int)$branchId;
    if ($branchId === getDefaultBranchId() || $branchId <= 0) {
        return ' AND (' . $column . ' = ' . $branchId . ' OR ' . $column . ' IS NULL OR ' . $column . ' = 0)';
    }
    return ' AND ' . $column . ' = ' . $branchId;
}

/** Pokladna pobočky; při prvním použití ji založí (počáteční zůstatek 0 k dnešku).
 *  Vrací vždy pole — i když se DB nepodaří, ať volající nespadne. */
function afxCashRegisterForBranch(int $branchId, bool $autoCreate = true): array {
    global $pdo;
    afxEnsureCashBookTables();
    $branchId = max(0, (int)$branchId);
    $fallback = [
        'id' => 0, 'branch_id' => $branchId, 'name' => 'Pokladna', 'currency' => 'CZK',
        'opening_balance' => 0.0, 'opening_date' => date('Y-m-d'), 'is_active' => 1,
        'note' => null, 'updated_by' => null, 'updated_at' => null,
    ];
    try {
        $st = $pdo->prepare("SELECT * FROM cash_registers WHERE branch_id = ? LIMIT 1");
        $st->execute([$branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) { return $row; }
        if (!$autoCreate) { return $fallback; }

        $name = 'Pokladna';
        foreach (getBranches(false) as $b) {
            if ((int)$b['id'] === $branchId) { $name = 'Pokladna ' . (string)$b['name']; break; }
        }
        // Počátek k DNEŠKU se nulou je záměrně neutrální: kdyby se datum dalo do
        // minulosti, sečetly by se k němu i staré prodeje a kasa by hlásila víc
        // peněz, než v ní je. Skutečný stav zapíše vedení tlačítkem v kase.
        $ins = $pdo->prepare("INSERT INTO cash_registers (branch_id, name, currency, opening_balance, opening_date)
                              VALUES (?, ?, 'CZK', 0, CURDATE())");
        try {
            $ins->execute([$branchId, mb_substr($name, 0, 80)]);
        } catch (PDOException $e) {
            // Souběh: dvě kasy zakládají pokladnu téže pobočky ve stejnou chvíli —
            // druhý INSERT narazí na UNIQUE(branch_id). To není chyba, řádek už
            // existuje; bez tohohle odchycení by druhá kasa dostala $fallback
            // s nulovým zůstatkem a ukázala prázdnou knihu.
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        }
        $st->execute([$branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: $fallback;
    } catch (Throwable $e) {
        error_log('afxCashRegisterForBranch: ' . $e->getMessage());
        return $fallback;
    }
}

/** Zápis počátečního zůstatku (inventarizace hotovosti). Vrací ['ok'=>bool,'error'=>string]. */
function afxCashSetOpeningBalance(int $branchId, float $balance, string $openingDate, string $by = '', string $note = ''): array {
    // počáteční zůstatek datovaný do uzamčeného měsíce by přepsal základ knihy
    // za odevzdané období
    if (function_exists('afxAccountingAssertOpen')) {
        afxAccountingAssertOpen($openingDate, 'počáteční zůstatek pokladny');
    }
    global $pdo;
    $d = date_create_from_format('Y-m-d', $openingDate);
    if (!$d || $d->format('Y-m-d') !== $openingDate) {
        return ['ok' => false, 'error' => 'Neplatné datum počátečního zůstatku'];
    }
    if ($balance < 0) { return ['ok' => false, 'error' => 'Počáteční zůstatek nemůže být záporný'] ; }
    if ($balance > 99999999) { return ['ok' => false, 'error' => 'Částka je mimo rozsah'] ; }
    if ($openingDate > date('Y-m-d')) { return ['ok' => false, 'error' => 'Datum nesmí být v budoucnosti'] ; }

    $reg = afxCashRegisterForBranch($branchId);

    // Posun počátku DO MINULOSTI za existující pohyby by knihu nafoukl: napočítaná
    // hotovost v zásuvce ty pohyby už OBSAHUJE a součty knihy by je přičetly
    // podruhé (např. zůstatek k dnešku zapsaný s datem 1. 1. = leden–dnešek 2×).
    // Datum před dosavadní počátek se proto povolí, jen když je mezidobí prázdné.
    $curOpen = (string)($reg['opening_date'] ?? '');
    if ((int)($reg['id'] ?? 0) > 0 && $curOpen !== '' && $openingDate < $curOpen) {
        $s = afxCashSums($branchId, $openingDate, date('Y-m-d', strtotime($curOpen . ' -1 day')));
        if ($s['in'] > 0 || $s['out'] > 0) {
            return ['ok' => false, 'error' => 'Mezi ' . date('j. n. Y', strtotime($openingDate))
                . ' a dosavadním počátkem pokladny (' . date('j. n. Y', strtotime($curOpen)) . ') už existují pohyby'
                . ' (příjmy ' . formatMoney($s['in']) . ', výdaje ' . formatMoney($s['out']) . ') — započítaly by se dvakrát.'
                . ' Zvol pozdější datum, ideálně den skutečné inventarizace.'];
        }
    }

    try {
        if ((int)($reg['id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE cash_registers SET opening_balance = ?, opening_date = ?, note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([round($balance, 2), $openingDate, ($note !== '' ? mb_substr($note, 0, 255) : null),
                           ($by !== '' ? mb_substr($by, 0, 100) : null), (int)$reg['id']]);
        } else {
            $pdo->prepare("INSERT INTO cash_registers (branch_id, name, currency, opening_balance, opening_date, note, updated_by, updated_at)
                           VALUES (?, ?, 'CZK', ?, ?, ?, ?, NOW())")
                ->execute([max(0, (int)$branchId), 'Pokladna', round($balance, 2), $openingDate,
                           ($note !== '' ? mb_substr($note, 0, 255) : null), ($by !== '' ? mb_substr($by, 0, 100) : null)]);
        }
        crmAuditLog('kasa.opening_balance', [
            'entity_type' => 'cash_register', 'entity_id' => (int)($reg['id'] ?? 0),
            'summary' => 'Počáteční zůstatek pokladny nastaven na ' . formatMoney($balance) . ' k ' . date('j. n. Y', strtotime($openingDate)),
            'branch_id' => $branchId,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('afxCashSetOpeningBalance: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Zůstatek se nepodařilo uložit'];
    }
}

/** Číslo dokladu v řadě: P2026-0001 (příjmový) / V2026-0001 (výdajový). */
function afxCashDocNumber(string $docType, int $year, int $seq): string {
    return ($docType === 'income' ? 'P' : 'V') . $year . '-' . str_pad((string)max(1, $seq), 4, '0', STR_PAD_LEFT);
}

/** Lidský název typu dokladu pro UI a auditní log. */
function afxCashDocTypeLabel(string $docType): string {
    return $docType === 'income' ? 'Příjmový pokladní doklad' : 'Výdajový pokladní doklad';
}

/**
 * VYSTAVENÍ pokladního dokladu (PPD/VPD).
 *
 * $p: branch_id, type ('income'|'expense'), amount, purpose, counterparty,
 *     date (Y-m-d, default dnes), ref_type/ref_id/ref_label, note, issued_by,
 *     storno_of (interní, vyplňuje afxCashDocStorno).
 * Vrací ['ok'=>bool, 'id'=>int, 'number'=>string, 'error'=>string].
 *
 * Číslo se přiděluje atomicky: pojistný GET_LOCK (aby dvě kasy nečetly stejné
 * MAX) + UNIQUE klíč na (branch_id, doc_number) s opakováním při chybě 1062.
 * Samotný zámek by po timeoutu nestačil, samotný UNIQUE by zas dělal zbytečné
 * kolize — spolu drží řadu souvislou i při souběhu.
 *
 * POZOR: voláno UVNITŘ cizí transakce, která pak rollbackne, vznikne v řadě
 * díra (číslo je spotřebované). Proto se doklady vystavují až po commitu.
 */
function afxCashDocIssue(array $p): array {
    global $pdo;
    afxEnsureCashBookTables();

    $type = (string)($p['type'] ?? '');
    if (!in_array($type, ['income', 'expense'], true)) {
        return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Neplatný typ dokladu'];
    }
    $amount = round((float)($p['amount'] ?? 0), 2);
    if ($amount <= 0) { return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Částka musí být kladná']; }
    if ($amount > 99999999) { return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Částka je mimo rozsah']; }

    $date = (string)($p['date'] ?? '');
    $d = $date !== '' ? date_create_from_format('Y-m-d', $date) : false;
    if (!$d || $d->format('Y-m-d') !== $date) { $date = date('Y-m-d'); }

    // UZÁVĚRKA OBDOBÍ — až PO normalizaci data: nečitelné/prázdné datum spadne na
    // dnešek a assert by se jinak dal obejít prázdným řetězcem (nález prověrky).
    if (function_exists('afxAccountingAssertOpen')) {
        afxAccountingAssertOpen($date, 'pokladní doklad');
    }

    $branchId = max(0, (int)($p['branch_id'] ?? 0));
    $reg = afxCashRegisterForBranch($branchId);

    // Datum mimo vedení knihy se odmítá: doklad datovaný PŘED počátek pokladny
    // by se v knize nikdy neobjevil (rozsah se ořezává na opening_date) — číslo
    // v řadě by se spotřebovalo, ale peníze by ve výpisu ani v zůstatku nebyly.
    // Budoucí datum by zas rozešlo hlavičku kasy (stav k dnešku) s výpisem.
    if ($date > date('Y-m-d')) {
        return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Doklad nelze datovat do budoucnosti'];
    }
    $regOpen = (string)($reg['opening_date'] ?? '');
    if ((int)($reg['id'] ?? 0) > 0 && $regOpen !== '' && $date < $regOpen) {
        return ['ok' => false, 'id' => 0, 'number' => '',
                'error' => 'Pokladna je vedena až od ' . date('j. n. Y', strtotime($regOpen))
                    . ' — doklad se starším datem by se v knize nikdy neobjevil. Nejdřív uprav počáteční zůstatek.'];
    }
    $year = (int)date('Y', strtotime($date));

    $purpose = mb_substr(trim((string)($p['purpose'] ?? '')), 0, 255);
    if ($purpose === '') { $purpose = afxCashDocTypeLabel($type); }
    $counterparty = mb_substr(trim((string)($p['counterparty'] ?? '')), 0, 160);
    $issuedBy = mb_substr(trim((string)($p['issued_by'] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))), 0, 100);
    $refType = mb_substr(trim((string)($p['ref_type'] ?? '')), 0, 20);
    $refId = (int)($p['ref_id'] ?? 0);
    $refLabel = mb_substr(trim((string)($p['ref_label'] ?? '')), 0, 40);
    $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 255);
    $stornoOf = (int)($p['storno_of'] ?? 0);

    $lockName = 'afx_cash_doc_' . $branchId . '_' . $type . '_' . $year;
    $haveLock = false;
    try {
        $haveLock = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 5)")->fetchColumn() === 1;
    } catch (Throwable $e) { /* bez zámku to zvládne retry níže */ }

    $ins = null;
    try {
        // FOR UPDATE = zamykající čtení: vidí čerstvě commitnutá data i pod
        // REPEATABLE READ (afxCashDocStorno volá vystavení uvnitř transakce)
        // a zamkne konec indexu řady, takže se číslo přiděluje atomicky.
        $seqStmt = $pdo->prepare("SELECT COALESCE(MAX(doc_seq), 0) FROM cash_documents
                                  WHERE branch_id = ? AND doc_type = ? AND doc_year = ? FOR UPDATE");
        $ins = $pdo->prepare("INSERT INTO cash_documents
                (register_id, branch_id, doc_type, doc_number, doc_year, doc_seq, doc_date, amount,
                 purpose, counterparty, issued_by, ref_type, ref_id, ref_label, storno_of, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        for ($try = 0; $try < 8; $try++) {
            $seqStmt->execute([$branchId, $type, $year]);
            // Žádný posun o $try: FOR UPDATE výše čte vždy aktuální MAX, takže po
            // kolizi 1062 stačí číst znovu. Dřívější „+ $try" dělal v souvislé
            // řadě díry (P…-0006 a P…-0008 bez P…-0007), což je při kontrole
            // účetní nedoložitelné jako „doklad nebyl vytržen".
            $seq = (int)$seqStmt->fetchColumn() + 1;
            $number = afxCashDocNumber($type, $year, $seq);
            try {
                $ins->execute([
                    (int)($reg['id'] ?? 0), $branchId, $type, $number, $year, $seq, $date, $amount,
                    $purpose, ($counterparty !== '' ? $counterparty : null), ($issuedBy !== '' ? $issuedBy : null),
                    ($refType !== '' ? $refType : null), ($refId > 0 ? $refId : null),
                    ($refLabel !== '' ? $refLabel : null), ($stornoOf > 0 ? $stornoOf : null),
                    ($note !== '' ? $note : null),
                ]);
                $id = (int)$pdo->lastInsertId();
                crmAuditLog('kasa.cash_doc', [
                    'entity_type' => 'cash_document', 'entity_id' => $id, 'entity_label' => $number,
                    'summary' => afxCashDocTypeLabel($type) . ' ' . $number . ' — ' . formatMoney($amount)
                        . ($purpose !== '' ? ' (' . $purpose . ')' : ''),
                    'branch_id' => $branchId,
                ]);
                return ['ok' => true, 'id' => $id, 'number' => $number, 'error' => ''];
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
            }
        }
        return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Nepodařilo se přidělit číslo dokladu — zkus to znovu'];
    } catch (Throwable $e) {
        error_log('afxCashDocIssue: ' . $e->getMessage());
        return ['ok' => false, 'id' => 0, 'number' => '', 'error' => 'Doklad se nepodařilo vystavit'];
    } finally {
        if ($haveLock) { try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")"); } catch (Throwable $e) {} }
    }
}

/** Jeden doklad podle id (nebo null). */
function afxCashDocGet(int $docId): ?array {
    global $pdo;
    afxEnsureCashBookTables();
    try {
        $st = $pdo->prepare("SELECT * FROM cash_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * STORNO dokladu — pokladní doklad se NIKDY nemaže (§ 11 zák. 563/1991 Sb.),
 * chyba se opravuje vystavením storno dokladu OPAČNÉHO typu na stejnou částku.
 * Původní doklad zůstává v řadě i v knize a nový ho vyruší, takže zůstatek sedí
 * a je vidět, co se stalo (na rozdíl od tichého smazání).
 *
 * Doklad NAVÁZANÝ na pohyb v deníku (ref_type 'cash_movement') se do součtů
 * knihy nepočítá — samotný protidoklad by tedy nevrátil ani korunu. Proto se
 * u něj NEJDŘÍV založí OPAČNÝ pohyb v pos_cash_movements (purpose 'storno',
 * ref na původní pohyb) a protidoklad se vystaví jako papír k němu. Když pohyb
 * založit nejde, storno se ODMÍTNE — dřív se tiše vrátilo ok a zůstatek knihy
 * zůstal špatně, bez jakékoli cesty k opravě.
 *
 * Doklad k prodeji na kase (ref_type 'pos_sale') se stornuje stornem PRODEJE
 * (api/pos_cancel.php — vrací i zboží na sklad), ne stornem papíru.
 *
 * Celé v jedné transakci: SELECT … FOR UPDATE na originál + UPDATE s podmínkou
 * storned_by IS NULL (kontrola rowCount) — dva současné kliky na Storno jinak
 * oba prošly kontrolou a vystavily dva protidoklady (dvojitý výdaj z kasy).
 */
function afxCashDocStorno(int $docId, string $reason = '', string $by = ''): array {
    // storno vzniká VŽDY s dnešním datem (kompenzační pohyb i protidoklad) — hlídá
    // se tedy dnešek; původní měsíc zůstává číselně nezměněn
    if (function_exists('afxAccountingAssertOpen')) {
        afxAccountingAssertOpen(date('Y-m-d'), 'storno pokladního dokladu');
    }
    global $pdo;
    // DDL pojistky PŘED transakcí — uvnitř by ALTER/CREATE udělal implicitní COMMIT.
    afxEnsureCashBookTables();
    ensurePosCashMovementsTable();
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'error' => 'Storno nelze spustit uvnitř jiné transakce'];
    }

    $reason = mb_substr(trim($reason), 0, 160);
    try {
        $pdo->beginTransaction();

        // Zámek originálu drží kontrolu storned_by a vystavení protidokladu pohromadě.
        $st = $pdo->prepare("SELECT * FROM cash_documents WHERE id = ? LIMIT 1 FOR UPDATE");
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Doklad nenalezen']; }
        if (!empty($doc['storned_by'])) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Doklad už je stornovaný']; }
        if (!empty($doc['storno_of'])) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Storno doklad nelze stornovat']; }

        $refType = (string)($doc['ref_type'] ?? '');
        if ($refType === 'pos_sale') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Doklad patří k prodeji na kase — použij storno prodeje, to vrátí i zboží na sklad.'];
        }

        // U dokladu navázaného na pohyb vrací peníze OPAČNÝ pohyb v deníku.
        $moveId = 0;
        if (in_array($refType, afxCashLinkedRefTypes(), true)) {
            $orig = null;
            if ((int)($doc['ref_id'] ?? 0) > 0) {
                // Zámky vždy v pořadí cash_documents → pos_cash_movements (prevence deadlocku).
                $ms = $pdo->prepare("SELECT direction, amount FROM pos_cash_movements WHERE id = ? LIMIT 1 FOR UPDATE");
                $ms->execute([(int)$doc['ref_id']]);
                $orig = $ms->fetch(PDO::FETCH_ASSOC);
            }
            // Směr podle původního pohybu (spolehlivější než typ dokladu); když
            // pohyb už nedohledáme, odvodí se z typu: PPD (income) → výdej.
            $moveDir = ((string)$doc['doc_type'] === 'income') ? 'out' : 'in';
            if ($orig && in_array((string)$orig['direction'], ['in', 'out'], true)) {
                $moveDir = ((string)$orig['direction'] === 'in') ? 'out' : 'in';
            }
            $mi = $pdo->prepare("INSERT INTO pos_cash_movements
                    (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
                VALUES (?, ?, ?, 'storno', 'cash_movement', ?, ?, ?, ?)");
            $mi->execute([
                ((int)$doc['branch_id'] > 0 ? (int)$doc['branch_id'] : null),
                $moveDir, (float)$doc['amount'],
                ((int)($doc['ref_id'] ?? 0) > 0 ? (int)$doc['ref_id'] : null),
                mb_substr('Storno ' . (string)$doc['doc_number'], 0, 40),
                mb_substr('Opačný pohyb ke stornu dokladu ' . (string)$doc['doc_number']
                    . ($reason !== '' ? ' — ' . $reason : ''), 0, 255),
                ($by !== '' ? mb_substr($by, 0, 100) : null),
            ]);
            $moveId = (int)$pdo->lastInsertId();
            if ($moveId <= 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Nepodařilo se založit opačný pohyb v deníku — storno neprovedeno'];
            }
        }

        $opposite = ((string)$doc['doc_type'] === 'income') ? 'expense' : 'income';
        $res = afxCashDocIssue([
            'branch_id' => (int)$doc['branch_id'],
            'type' => $opposite,
            'amount' => (float)$doc['amount'],
            'date' => date('Y-m-d'),
            'purpose' => 'STORNO dokladu ' . (string)$doc['doc_number'] . ($reason !== '' ? ' — ' . $reason : ''),
            'counterparty' => (string)($doc['counterparty'] ?? ''),
            'issued_by' => $by,
            // Papír ke KOMPENZAČNÍMU pohybu (ať se v knize přilepí k němu a
            // nesčítá se dvakrát); u samostatného dokladu se vazba dědí.
            'ref_type' => $moveId > 0 ? 'cash_movement' : $refType,
            'ref_id' => $moveId > 0 ? $moveId : (int)($doc['ref_id'] ?? 0),
            'ref_label' => $moveId > 0 ? '' : (string)($doc['ref_label'] ?? ''),
            'storno_of' => $docId,
            'note' => 'Storno k ' . (string)$doc['doc_number'],
        ]);
        if (!$res['ok']) { $pdo->rollBack(); return ['ok' => false, 'error' => $res['error']]; }

        // storned_by IS NULL + rowCount: kdyby zámek z jakéhokoli důvodu selhal,
        // druhé storno se tady zastaví místo vystavení dalšího protidokladu.
        $upd = $pdo->prepare("UPDATE cash_documents SET storned_by = ?, storned_at = NOW()
                              WHERE id = ? AND storned_by IS NULL");
        $upd->execute([(int)$res['id'], $docId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Doklad už byl mezitím stornován'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        error_log('afxCashDocStorno: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Storno se nepodařilo provést'];
    }

    // Audit až po commitu — rollback by jinak nechal v historii zápis o stornu,
    // které neproběhlo.
    if ($moveId > 0) {
        crmAuditLog('kasa.cash_move', [
            'entity_type' => 'pos_cash_movement', 'entity_id' => $moveId,
            'summary' => 'Opačný pohyb ' . formatMoney((float)$doc['amount']) . ' ke stornu dokladu ' . (string)$doc['doc_number'],
            'branch_id' => (int)$doc['branch_id'],
        ]);
    }
    crmAuditLog('kasa.cash_doc_storno', [
        'entity_type' => 'cash_document', 'entity_id' => $docId, 'entity_label' => (string)$doc['doc_number'],
        'summary' => 'Doklad ' . (string)$doc['doc_number'] . ' stornován dokladem ' . $res['number']
            . ($reason !== '' ? ' (' . $reason . ')' : ''),
        'branch_id' => (int)$doc['branch_id'],
    ]);
    return ['ok' => true, 'id' => (int)$res['id'], 'number' => $res['number'], 'error' => ''];
}

/**
 * Součty hotovosti za období z VŠECH tří zdrojů (viz hlavička souboru).
 * Vrací ['in' => float, 'out' => float].
 */
function afxCashSums(int $branchId, string $from, string $to): array {
    global $pdo;
    afxEnsureCashBookTables();
    // EXISTS na pos_cash_movements je nově i v dotazu na prodeje — bez tabulky
    // by spadl celý součet, ne jen deník.
    ensurePosCashMovementsTable();
    $in = 0.0; $out = 0.0;
    $linked = "'" . implode("','", afxCashLinkedRefTypes()) . "'";
    try {
        // 1) prodej na kase za hotové. Prodej stornovaný AŽ PO uzavření dne v knize
        //    ZŮSTÁVÁ, pokud k němu existuje kompenzační pohyb (purpose 'storno'):
        //    peníze v den prodeje v zásuvce fyzicky byly a odešly až v den storna
        //    — jinak by se historie knihy zpětně přepsala a konečný zůstatek dne
        //    prodeje by přestal sedět na počáteční zůstatek dne dalšího.
        //    Starší storna BEZ kompenzačního pohybu se nepočítají (jako dřív),
        //    aby se kniha zpětně nenafoukla o výdaje, které v ní nikdy nebyly.
        //    Rozsah přes created_at bez funkce → použije se index (idx_pos_created).
        $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM pos_sales s
            WHERE s.payment_method = 'cash'
              AND (s.status = 'completed' OR (s.status = 'cancelled' AND EXISTS (
                    SELECT 1 FROM pos_cash_movements m
                    WHERE m.ref_type = 'pos_sale' AND m.ref_id = s.id AND m.purpose = 'storno')))
              AND s.created_at >= ? AND s.created_at < DATE_ADD(?, INTERVAL 1 DAY)"
            . afxCashBranchSql('s.branch_id', $branchId));
        $st->execute([$from, $to]);
        $in += (float)$st->fetchColumn();

        // 2) pokladní deník (vklady/výběry/výkupy/storna/hotovost ze zakázek) — jen ČTEME
        $st = $pdo->prepare("SELECT direction, COALESCE(SUM(amount), 0) s FROM pos_cash_movements
            WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)"
            . afxCashBranchSql('branch_id', $branchId) . " GROUP BY direction");
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            if ((string)$r['direction'] === 'in') { $in += (float)$r['s']; } else { $out += (float)$r['s']; }
        }

        // 3) samostatné PPD/VPD (doklad navázaný na prodej/pohyb je jen papír → nesčítat znovu)
        $st = $pdo->prepare("SELECT doc_type, COALESCE(SUM(amount), 0) s FROM cash_documents
            WHERE doc_date BETWEEN ? AND ?
              AND (ref_type IS NULL OR ref_type NOT IN ($linked))" . afxCashBranchSql('branch_id', $branchId) . "
            GROUP BY doc_type");
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            if ((string)$r['doc_type'] === 'income') { $in += (float)$r['s']; } else { $out += (float)$r['s']; }
        }
    } catch (Throwable $e) { error_log('afxCashSums: ' . $e->getMessage()); }
    return ['in' => round($in, 2), 'out' => round($out, 2)];
}

/**
 * Stav pokladny k danému dni (včetně) = počáteční zůstatek + příjmy − výdaje
 * od data počátku. Pohyby PŘED datem počátku se ignorují schválně: počáteční
 * zůstatek je napočítaná skutečnost, přičíst k němu ještě starou historii by
 * kasu nafouklo na dvojnásobek.
 */
function afxCashBalanceAt(int $branchId, string $date): float {
    $reg = afxCashRegisterForBranch($branchId);
    $openDate = (string)($reg['opening_date'] ?? date('Y-m-d'));
    if ($date < $openDate) { return 0.0; }   // před počátkem kniha neexistuje
    $s = afxCashSums($branchId, $openDate, $date);
    return round((float)$reg['opening_balance'] + $s['in'] - $s['out'], 2);
}

/**
 * Jednotlivé řádky pokladní knihy za období, chronologicky.
 * Řádek: date, time, dir (in|out), amount, title, detail, doc_number, doc_id,
 *        source (pos_sale|movement|document), ref_id, by, counts (bool),
 *        storno (doklad byl stornován → v UI přeškrtnout),
 *        is_storno (řádek JE storno doklad — peníze reálně vrací zpět).
 */
function afxCashBookRows(int $branchId, string $from, string $to): array {
    global $pdo;
    afxEnsureCashBookTables();
    ensurePosCashMovementsTable();   // EXISTS v dotazu na prodeje ji potřebuje vždy
    $rows = [];
    $linked = "'" . implode("','", afxCashLinkedRefTypes()) . "'";
    try {
        // Stejná pravidla jako v afxCashSums: stornovaný prodej s kompenzačním
        // pohybem v knize zůstává (příjem v den prodeje, výdej v den storna);
        // rozsah přes created_at bez funkce kvůli indexům.
        $st = $pdo->prepare("SELECT s.id, s.sale_number, s.order_id, s.total, s.seller_name, s.created_at, s.status FROM pos_sales s
            WHERE s.payment_method = 'cash'
              AND (s.status = 'completed' OR (s.status = 'cancelled' AND EXISTS (
                    SELECT 1 FROM pos_cash_movements m
                    WHERE m.ref_type = 'pos_sale' AND m.ref_id = s.id AND m.purpose = 'storno')))
              AND s.created_at >= ? AND s.created_at < DATE_ADD(?, INTERVAL 1 DAY)"
            . afxCashBranchSql('s.branch_id', $branchId));
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            $cancelled = (string)$r['status'] === 'cancelled';
            $rows[] = [
                'sort' => (string)$r['created_at'], 'date' => date('Y-m-d', strtotime((string)$r['created_at'])),
                'time' => date('H:i', strtotime((string)$r['created_at'])),
                'dir' => 'in', 'amount' => (float)$r['total'],
                'title' => (int)($r['order_id'] ?? 0) > 0 ? 'Úhrada zakázky' : 'Prodej na kase',
                'detail' => (string)$r['sale_number'] . ($cancelled ? ' · stornováno (výdej v den storna)' : ''),
                'source' => 'pos_sale', 'ref_id' => (int)$r['id'],
                'by' => (string)$r['seller_name'], 'doc_number' => '', 'doc_id' => 0,
                'storno' => false, 'is_storno' => false, 'doc_storno' => false,
                'doc_is_storno' => false, 'counts' => true,
            ];
        }

        $st = $pdo->prepare("SELECT id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by, created_at
            FROM pos_cash_movements
            WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)" . afxCashBranchSql('branch_id', $branchId));
        $st->execute([$from, $to]);
        $purposeLabels = ['vykup' => 'Výkup zařízení', 'vklad' => 'Vklad do pokladny',
                          'vyber' => 'Výběr z pokladny', 'zakazka' => 'Úhrada zakázky',
                          'storno' => 'Storno / vratka hotovosti'];
        foreach ($st as $r) {
            $detail = trim((string)($r['ref_label'] ?? '') . ' ' . (string)($r['note'] ?? ''));
            $rows[] = [
                'sort' => (string)$r['created_at'], 'date' => date('Y-m-d', strtotime((string)$r['created_at'])),
                'time' => date('H:i', strtotime((string)$r['created_at'])),
                'dir' => (string)$r['direction'] === 'in' ? 'in' : 'out', 'amount' => (float)$r['amount'],
                'title' => $purposeLabels[(string)$r['purpose']] ?? ucfirst((string)$r['purpose']),
                'detail' => $detail, 'source' => 'movement', 'ref_id' => (int)$r['id'],
                'by' => (string)($r['created_by'] ?? ''), 'doc_number' => '', 'doc_id' => 0,
                'storno' => false, 'is_storno' => false, 'doc_storno' => false,
                'doc_is_storno' => false, 'counts' => true,
            ];
        }

        // Samostatné doklady jsou vlastní pohyb; navázané slouží jen k doplnění
        // čísla dokladu k řádku prodeje/pohybu (counts = false → nesčítat).
        $st = $pdo->prepare("SELECT * FROM cash_documents
            WHERE doc_date BETWEEN ? AND ?" . afxCashBranchSql('branch_id', $branchId) . "
            ORDER BY doc_date ASC, id ASC");
        $st->execute([$from, $to]);
        $linkedDocs = [];
        foreach ($st as $r) {
            $standalone = !in_array((string)($r['ref_type'] ?? ''), afxCashLinkedRefTypes(), true);
            if (!$standalone) {
                // klíč = zdroj + id pohybu, ať se číslo dokladu přilepí ke správnému řádku.
                // K řádku patří PŮVODNÍ doklad (storno_of prázdné): starší storno
                // doklady sdílely klíč s originálem a přepisovaly mu číslo — řádek
                // vkladu pak ukazoval výdajové číslo a odznak „storno" zmizel.
                $key = (string)$r['ref_type'] . ':' . (int)$r['ref_id'];
                if (!isset($linkedDocs[$key])
                    || (!empty($linkedDocs[$key]['storno_of']) && empty($r['storno_of']))) {
                    $linkedDocs[$key] = $r;
                }
                continue;
            }
            $rows[] = [
                'sort' => (string)$r['doc_date'] . ' ' . date('H:i:s', strtotime((string)$r['created_at'])),
                'date' => (string)$r['doc_date'], 'time' => date('H:i', strtotime((string)$r['created_at'])),
                'dir' => (string)$r['doc_type'] === 'income' ? 'in' : 'out', 'amount' => (float)$r['amount'],
                'title' => (string)$r['purpose'],
                'detail' => trim((string)($r['counterparty'] ?? '') . ' ' . (string)($r['note'] ?? '')),
                'source' => 'document', 'ref_id' => (int)$r['id'],
                'by' => (string)($r['issued_by'] ?? ''), 'doc_number' => (string)$r['doc_number'],
                'doc_id' => (int)$r['id'],
                // storno = doklad byl zrušen protidokladem → přeškrtnout
                // is_storno = tenhle řádek JE ten protidoklad (peníze skutečně vrací)
                'storno' => !empty($r['storned_by']), 'is_storno' => !empty($r['storno_of']),
                'doc_storno' => false, 'doc_is_storno' => false, 'counts' => true,
            ];
        }
        foreach ($rows as &$row) {
            $key = ($row['source'] === 'pos_sale' ? 'pos_sale' : 'cash_movement') . ':' . $row['ref_id'];
            if ($row['source'] !== 'document' && isset($linkedDocs[$key])) {
                $row['doc_number'] = (string)$linkedDocs[$key]['doc_number'];
                $row['doc_id'] = (int)$linkedDocs[$key]['id'];
                // Zrušený PAPÍR k prodeji/pohybu peníze z kasy nebere (ty tam pořád
                // jsou) — proto se řádek NEpřeškrtává, jen se označí doklad.
                // Peníze vrací kompenzační pohyb, který storno založilo zvlášť.
                $row['doc_storno'] = !empty($linkedDocs[$key]['storned_by']);
                // Doklad řádku je sám STORNO dokladem (papír ke kompenzačnímu
                // pohybu) → nenabízet na něm další storno.
                $row['doc_is_storno'] = !empty($linkedDocs[$key]['storno_of']);
            }
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            return $a['sort'] <=> $b['sort'] ?: ($a['ref_id'] <=> $b['ref_id']);
        });
    } catch (Throwable $e) { error_log('afxCashBookRows: ' . $e->getMessage()); }
    return $rows;
}

/**
 * Celá pokladní kniha za období: počáteční zůstatek, řádky s průběžným
 * zůstatkem, součty a konečný zůstatek.
 * Vrací ['register','from','to','opening','rows','sum_in','sum_out','closing','limit_over'].
 */
function afxCashBook(int $branchId, string $from, string $to): array {
    global $pdo;
    $reg = afxCashRegisterForBranch($branchId);
    $openDate = (string)($reg['opening_date'] ?? date('Y-m-d'));
    // Kniha nemůže začínat dřív než pokladna sama — ale ořez se hlásí volajícímu
    // (range_clipped), aby uživatel věděl, že vidí kratší období, než si zvolil.
    $rangeClipped = $from < $openDate;
    if ($from < $openDate) { $from = $openDate; }
    if ($to < $from) { $to = $from; }

    // Samostatné doklady datované PŘED počátek pokladny by z výpisu tiše zmizely
    // (každé zvolené období začíná nejdřív na opening_date) — číslo v řadě mají,
    // ale peníze by nikde nebyly vidět. Nezahazovat, spočítat a ohlásit.
    $orphans = ['count' => 0, 'in' => 0.0, 'out' => 0.0];
    try {
        $linked = "'" . implode("','", afxCashLinkedRefTypes()) . "'";
        $st = $pdo->prepare("SELECT doc_type, COUNT(*) c, COALESCE(SUM(amount), 0) s FROM cash_documents
            WHERE doc_date < ? AND (ref_type IS NULL OR ref_type NOT IN ($linked))"
            . afxCashBranchSql('branch_id', $branchId) . " GROUP BY doc_type");
        $st->execute([$openDate]);
        foreach ($st as $r) {
            $orphans['count'] += (int)$r['c'];
            if ((string)$r['doc_type'] === 'income') { $orphans['in'] += (float)$r['s']; }
            else { $orphans['out'] += (float)$r['s']; }
        }
    } catch (Throwable $e) { error_log('afxCashBook (doklady mimo rozsah): ' . $e->getMessage()); }

    // Počáteční zůstatek období = stav ke konci předchozího dne.
    $opening = afxCashBalanceAt($branchId, date('Y-m-d', strtotime($from . ' -1 day')));
    if ($from === $openDate) { $opening = (float)$reg['opening_balance']; }

    $rows = afxCashBookRows($branchId, $from, $to);
    $bal = $opening; $sumIn = 0.0; $sumOut = 0.0;
    $limitOver = [];
    $dayIn = [];
    foreach ($rows as $i => $r) {
        if (!empty($r['counts'])) {
            if ($r['dir'] === 'in') { $bal += $r['amount']; $sumIn += $r['amount']; }
            else { $bal -= $r['amount']; $sumOut += $r['amount']; }
        }
        $rows[$i]['balance'] = round($bal, 2);
        // JEDNA hotovostní platba nad limit = porušení zák. 254/2004 Sb.
        // Limit platí pro OBĚ strany — poskytnutá platba (výkup za 310 000 Kč
        // hotově) je stejné porušení jako přijatá, proto se hlídají oba směry.
        if (!empty($r['counts']) && $r['amount'] > AFX_CASH_LIMIT_CZK) { $limitOver[] = $rows[$i]; }
        if ($r['dir'] === 'in' && !empty($r['counts'])) {
            $dayIn[$r['date']] = ($dayIn[$r['date']] ?? 0.0) + $r['amount'];
        }
    }
    $daysOver = [];
    foreach ($dayIn as $day => $sum) {
        if ($sum > AFX_CASH_LIMIT_CZK) { $daysOver[$day] = round($sum, 2); }
    }

    return [
        'register' => $reg, 'from' => $from, 'to' => $to,
        'opening' => round($opening, 2), 'rows' => $rows,
        'sum_in' => round($sumIn, 2), 'sum_out' => round($sumOut, 2),
        'closing' => round($bal, 2),
        'limit_over' => $limitOver,     // jednotlivé platby nad 270 000 Kč (příjmy i výdeje)
        'days_over' => $daysOver,       // dny, kdy hotovostní příjem v součtu překročil limit
        'range_clipped' => $rangeClipped,   // zvolený rozsah začínal před počátkem pokladny
        'orphan_docs' => $orphans,      // doklady datované před počátek — v knize nejsou
    ];
}

/** Denní hotovostní příjem pobočky + největší jednotlivá platba (kontrola limitu). */
function afxCashDayIncome(int $branchId, string $date): array {
    $rows = afxCashBookRows($branchId, $date, $date);
    $total = 0.0; $max = 0.0;
    foreach ($rows as $r) {
        if ($r['dir'] !== 'in' || empty($r['counts'])) { continue; }
        $total += (float)$r['amount'];
        if ((float)$r['amount'] > $max) { $max = (float)$r['amount']; }
    }
    return ['total' => round($total, 2), 'max_single' => round($max, 2), 'limit' => AFX_CASH_LIMIT_CZK];
}
