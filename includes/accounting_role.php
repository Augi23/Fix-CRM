<?php
/**
 * ROLE „ÚČETNÍ" A UZÁVĚRKA ÚČETNÍCH OBDOBÍ (balík C, 31.7.2026)
 * ─────────────────────────────────────────────────────────────────────────────
 * Dvě věci, které spolu úzce souvisí a nikam jinam do CRM nepatří:
 *
 * 1) ROLE ÚČETNÍ (technicians.role = 'accountant')
 *    Účetní je NEPROVOZNÍ role. Vidí faktury, platby, pokladnu a bankovní pohyby
 *    NAPŘÍČ oběma pobočkami (účetnictví se vede za firmu, ne za provozovnu), smí
 *    párovat platby a doplňovat účetní údaje — ale NESMÍ:
 *      · mazat cokoliv (doklad se podle §11 zák. 563/1991 nemaže, jen stornuje),
 *      · sahat na zakázky a sklad,
 *      · vidět klientská data nad rámec fakturačních údajů,
 *      · měnit nastavení systému.
 *    Účet vzniká v tabulce `technicians` (ne `users`) — díky tomu funguje beze
 *    změny stávající dual-login: login.php nastaví role='technician' a
 *    internal_role='accountant', takže getCurrentStaffRole() vrátí 'accountant'.
 *    Kdyby účetní vznikla v `users`, login.php by jí natvrdo dal role='admin'.
 *
 * 2) UZÁVĚRKA OBDOBÍ (tabulka `accounting_periods`)
 *    Po odevzdání přiznání se doklady daného měsíce nesmí měnit — jinak by se
 *    čísla v CRM rozešla s tím, co je odevzdané na úřadě. Zámek je měkký, na
 *    úrovni aplikace: zapisující místo si zavolá afxAccountingAssertOpen($datum)
 *    a při zamčeném měsíci dostane výjimku. ODEMKNOUT smí jen administrátor a
 *    každé odemčení jde VŽDY do auditní historie.
 *
 * POZOR: tenhle soubor se načítá z includes/functions.php (require na začátku),
 * takže funkce zdejších gate() smí volat funkce z functions.php — ale až za běhu,
 * ne při načítání souboru.
 */

// ─────────────────────────────────────────────────────────────────────────────
// SCHÉMA — runtime pojistka (deploy nasadí kód dřív, než doběhne run_migrations)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Založí tabulku accounting_periods a rozšíří technicians.role o 'accountant'.
 * Idempotentní, best-effort — chyba nikdy neshodí stránku.
 */
function afxEnsureAccountingSchema(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) {
        return;
    }
    // DDL uvnitř transakce dělá v MySQL implicitní COMMIT — potvrdilo by rozdělaný
    // checkout kasy nebo párování platby. Raději nic a zkusíme to při dalším requestu.
    if ($pdo->inTransaction()) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_periods (
            id INT NOT NULL AUTO_INCREMENT,
            period_year SMALLINT UNSIGNED NOT NULL,
            period_month TINYINT UNSIGNED NOT NULL,
            locked_at DATETIME NULL DEFAULT NULL,
            locked_by VARCHAR(190) NULL DEFAULT NULL,
            locked_by_type VARCHAR(20) NULL DEFAULT NULL,
            locked_by_id INT NULL DEFAULT NULL,
            unlocked_at DATETIME NULL DEFAULT NULL,
            unlocked_by VARCHAR(190) NULL DEFAULT NULL,
            note VARCHAR(255) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_accounting_period (period_year, period_month),
            KEY idx_accounting_period_locked (locked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('afxEnsureAccountingSchema: ' . $e->getMessage());
    }
    afxEnsureAccountantRoleValue();
}

/**
 * Rozšíří ENUM technicians.role o hodnotu 'accountant', pokud tam ještě není.
 * Kdyby chyběla, MySQL by při zakládání účetní uložilo prázdnou roli a takový
 * účet by se přihlásil jako obyčejný technik — tedy s právy na zakázky a klienty.
 * Seznam hodnot se BERE Z DB, ne natvrdo: jinak by ALTER na instalaci s jiným
 * výčtem tiše smazal existující role (např. 'brigadnik').
 */
function afxEnsureAccountantRoleValue(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo) || $pdo->inTransaction()) {
        return;
    }
    $done = true;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM technicians LIKE 'role'")->fetch();
        if (!$col) {
            return;
        }
        $type = (string)($col['Type'] ?? '');
        if (stripos($type, 'enum(') !== 0) {
            return;                                   // VARCHAR → není co rozšiřovat
        }
        if (stripos($type, "'accountant'") !== false) {
            return;                                   // už tam je
        }
        $null = (strtoupper((string)($col['Null'] ?? 'NO')) === 'YES') ? ' NULL' : ' NOT NULL';
        $def  = array_key_exists('Default', $col) ? $col['Default'] : null;
        $sql  = 'ALTER TABLE technicians MODIFY role '
              . substr($type, 0, -1) . ",'accountant')" . $null
              . ($def !== null ? ' DEFAULT ' . $pdo->quote((string)$def) : '');
        $pdo->exec($sql);
    } catch (Throwable $e) {
        error_log('afxEnsureAccountantRoleValue: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// KDO JE KDO — oprávnění účetní
// ─────────────────────────────────────────────────────────────────────────────

/** Je přihlášený člověk účetní? (role na kartě zaměstnance = 'accountant') */
function crmIsAccountant(): bool {
    return function_exists('getCurrentStaffRole') && getCurrentStaffRole() === 'accountant';
}

/**
 * Které standardní oprávnění CRM smí mít účetní. Záměrně NIC — účetní se do
 * provozních práv (zakázky, klienti, sklad, nastavení) nesmí dostat ani omylem,
 * kdyby jí někdo zaškrtl práva na kartě zaměstnance. Volá se z hasPermission().
 *
 * Kdyby tahle funkce vracela true, účetní by dostala implicitní edit_orders +
 * edit_customers, které hasPermission() dává všem zaměstnancům.
 */
function crmAccountantHasPermission(string $permission): bool {
    return false;
}

/**
 * Smí VIDĚT účetní data — faktury, platby, pokladnu a bankovní pohyby, a to
 * napříč pobočkami (účetnictví se vede za firmu, ne za provozovnu).
 * Admin a Boss to mají už z crmCanManageInvoices(), účetní se přidává sem.
 */
function crmCanAccountingRead(): bool {
    if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
        return false;
    }
    return (function_exists('crmCanManageInvoices') && crmCanManageInvoices()) || crmIsAccountant();
}

/**
 * Smí účetní data MĚNIT: párovat platby s fakturami, doplnit VS / datum úhrady /
 * způsob platby / poznámku, opravit účetní údaje dokladu.
 * NIKDY nezahrnuje mazání — na to je crmCanAccountingDelete().
 * (Dnes se kryje se čtením; je to samostatná funkce proto, aby šlo časem přidat
 * účetní „jen na nahlédnutí" bez hledání všech volání v CRM.)
 */
function crmCanAccountingEdit(): bool {
    return crmCanAccountingRead();
}

/**
 * Smí MAZAT/stornovat účetní doklady — účetní NIKDY, jen admin a Boss.
 * Doklad je podle §11 zák. 563/1991 součást souvislé číselné řady: nemaže se,
 * jen stornuje. Účetní má proto jen opravné, ne likvidační právo.
 */
function crmCanAccountingDelete(): bool {
    return function_exists('crmCanManageInvoices') && crmCanManageInvoices() && !crmIsAccountant();
}

/**
 * Sloupce klienta, které smí účetní vidět — jen to, co patří na fakturu.
 * Účetní nemá co koukat na zařízení klienta, závady, poznámky techniků,
 * hesla ani doklady totožnosti. Určeno pro SELECT ve fakturačních výpisech.
 * @return string[]
 */
function crmAccountingCustomerFields(): array {
    return ['id', 'customer_type', 'first_name', 'last_name', 'company',
            'ico', 'dic', 'address', 'email', 'phone'];
}

// ─────────────────────────────────────────────────────────────────────────────
// UZÁVĚRKA OBDOBÍ
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Normalizuje libovolné datum na klíč období 'YYYY-MM'. Prázdné/nečitelné → ''.
 * Přijímá DateTimeInterface, UNIX timestamp i řetězec z DB.
 */
function afxAccountingPeriodKey($date): string {
    if ($date instanceof DateTimeInterface) {
        return $date->format('Y-m');
    }
    if (is_int($date) || (is_string($date) && ctype_digit($date) && strlen($date) >= 9)) {
        return date('Y-m', (int)$date);
    }
    $s = trim((string)$date);
    if ($s === '' || $s === '0000-00-00' || strpos($s, '0000-00-00') === 0) {
        return '';
    }
    // Rychlá cesta pro 'YYYY-MM-DD…' z DB. strtotime() se tu nedá použít slepě:
    // u neúplného/rozbitého data vrací dnešek, což by tiše ukázalo na jiný měsíc.
    if (preg_match('~^(\d{4})-(\d{2})~', $s, $m)) {
        return $m[1] . '-' . $m[2];
    }
    $t = strtotime($s);
    return $t ? date('Y-m', $t) : '';
}

/**
 * Mapa uzamčených období: ['2026-06' => řádek accounting_periods, …].
 * Načte se JEDNOU za request (zámek se testuje u každého zápisu dokladu).
 * $refresh = true po zamčení/odemčení, jinak by UI ukazovalo starý stav.
 */
function afxAccountingLockedPeriods(bool $refresh = false): array {
    global $pdo;
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!isset($pdo)) {
        return $cache;
    }
    try {
        afxEnsureAccountingSchema();
        $rows = $pdo->query("SELECT * FROM accounting_periods WHERE locked_at IS NOT NULL")->fetchAll();
        foreach ($rows as $r) {
            $cache[sprintf('%04d-%02d', (int)$r['period_year'], (int)$r['period_month'])] = $r;
        }
    } catch (Throwable $e) {
        // Tabulka ještě neexistuje (běží starší DB) → nic není zamčené.
        // Chyba tady NESMÍ zablokovat zápis dokladu.
        error_log('afxAccountingLockedPeriods: ' . $e->getMessage());
    }
    return $cache;
}

/** Je měsíc daného data už účetně uzavřený? Prázdné/nečitelné datum → false. */
function afxAccountingPeriodLocked($date): bool {
    $key = afxAccountingPeriodKey($date);
    return $key !== '' && isset(afxAccountingLockedPeriods()[$key]);
}

/** Detail uzamčení (kdo, kdy, poznámka) nebo null, když je období otevřené. */
function afxAccountingPeriodInfo($date): ?array {
    $key = afxAccountingPeriodKey($date);
    if ($key === '') {
        return null;
    }
    return afxAccountingLockedPeriods()[$key] ?? null;
}

/** Hláška pro uživatele u zamčeného období (stejná v UI i ve výjimce). */
function afxAccountingLockMessage($date, string $what = 'doklad'): string {
    $key = afxAccountingPeriodKey($date);
    $info = afxAccountingPeriodInfo($date);
    $obdobi = $key !== '' ? ((int)substr($key, 5, 2)) . '/' . substr($key, 0, 4) : '?';
    $kdo = trim((string)($info['locked_by'] ?? ''));
    return 'Účetní období ' . $obdobi . ' je uzavřené'
        . ($kdo !== '' ? ' (uzavřel ' . $kdo . ')' : '')
        . ' — ' . $what . ' s datem v tomto měsíci už nejde měnit. '
        . 'Odemknout období může jen administrátor v Nastavení → Uzávěrka období.';
}

/**
 * Tvrdá pojistka pro zapisující místa: uzavřené období = výjimka.
 * Volá se PŘED úpravou dokladu (faktura, platba, doklad kasy, pohyb hotovosti):
 *   afxAccountingAssertOpen($faktura['date_issue'], 'fakturu');
 * Bez ní by šlo po odevzdání přiznání zpětně přepsat částku a čísla v CRM by se
 * rozešla s tím, co je odevzdané na úřadě.
 *
 * @throws RuntimeException když je měsíc uzamčený
 */
function afxAccountingAssertOpen($date, string $what = 'doklad'): void {
    if (afxAccountingPeriodLocked($date)) {
        throw new RuntimeException(afxAccountingLockMessage($date, $what));
    }
}

/** Kdo smí měsíc UZAVŘÍT — vedení i účetní (uzávěrku dělá typicky právě účetní). */
function afxAccountingCanLock(): bool {
    return crmCanAccountingEdit();
}

/**
 * Kdo smí měsíc ODEMKNOUT — jen administrátor. Odemčení je zásah do už odevzdaného
 * účetnictví, takže ho účetní sama udělat nesmí (a Boss projde přes hasPermission,
 * který mu jako majiteli vrací true na cokoliv — to je záměr, ne opomenutí).
 */
function afxAccountingCanUnlock(): bool {
    return function_exists('hasPermission') && hasPermission('admin_access');
}

/** Interní: [rok, měsíc] validované, nebo null. */
function afxAccountingNormalizePeriod($year, $month): ?array {
    $y = (int)$year;
    $m = (int)$month;
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) {
        return null;
    }
    return [$y, $m];
}

/**
 * Uzavře účetní období (měsíc). Vrací ['ok' => bool, 'error' => string].
 * Budoucí měsíce zavřít nejde — nemá to smysl a zablokovalo by to běžný provoz
 * (např. faktura vystavená dopředu k datu příštího měsíce).
 */
function afxAccountingLockPeriod($year, $month, string $note = ''): array {
    global $pdo;
    if (!afxAccountingCanLock()) {
        return ['ok' => false, 'error' => 'Na uzavření období nemáš oprávnění.'];
    }
    $p = afxAccountingNormalizePeriod($year, $month);
    if ($p === null) {
        return ['ok' => false, 'error' => 'Neplatné období.'];
    }
    [$y, $m] = $p;
    if (sprintf('%04d-%02d', $y, $m) > date('Y-m')) {
        return ['ok' => false, 'error' => 'Budoucí měsíc uzavřít nelze — počkej, až skončí.'];
    }
    if (isset(afxAccountingLockedPeriods()[sprintf('%04d-%02d', $y, $m)])) {
        return ['ok' => false, 'error' => 'Tohle období už je uzavřené.'];
    }
    try {
        afxEnsureAccountingSchema();
        [$aType, $aId, $aName] = crmAuditResolveActor([]);
        $note = mb_substr(trim($note), 0, 255);
        // ON DUPLICATE KEY: měsíc už mohl být jednou zavřený a pak odemčený —
        // řádek zůstává a jen se znovu orazítkuje (historie je v audit_log).
        $pdo->prepare("INSERT INTO accounting_periods
                (period_year, period_month, locked_at, locked_by, locked_by_type, locked_by_id, note)
            VALUES (?, ?, NOW(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                locked_at = NOW(), locked_by = VALUES(locked_by),
                locked_by_type = VALUES(locked_by_type), locked_by_id = VALUES(locked_by_id),
                note = VALUES(note)")
            ->execute([$y, $m, $aName, $aType, $aId, ($note !== '' ? $note : null)]);
        afxAccountingLockedPeriods(true);
        crmAuditLog('accounting.period_lock', [
            'entity_type' => 'accounting_period',
            'entity_label' => $m . '/' . $y,
            'summary' => 'Uzavřeno účetní období ' . $m . '/' . $y,
            'details' => ['rok' => $y, 'mesic' => $m, 'poznamka' => $note],
        ]);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('afxAccountingLockPeriod: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Uzavření období se nepovedlo (chyba databáze).'];
    }
}

/**
 * Odemkne účetní období. Jen administrátor a VŽDY se to zapíše do auditní
 * historie — je to zásah do už uzavřeného účetnictví a musí být dohledatelný.
 */
function afxAccountingUnlockPeriod($year, $month, string $reason = ''): array {
    global $pdo;
    if (!afxAccountingCanUnlock()) {
        return ['ok' => false, 'error' => 'Odemknout uzavřené období smí jen administrátor.'];
    }
    $p = afxAccountingNormalizePeriod($year, $month);
    if ($p === null) {
        return ['ok' => false, 'error' => 'Neplatné období.'];
    }
    [$y, $m] = $p;
    if (!isset(afxAccountingLockedPeriods()[sprintf('%04d-%02d', $y, $m)])) {
        return ['ok' => false, 'error' => 'Tohle období není uzavřené.'];
    }
    try {
        afxEnsureAccountingSchema();
        [, , $aName] = crmAuditResolveActor([]);
        $reason = mb_substr(trim($reason), 0, 255);
        $pdo->prepare("UPDATE accounting_periods
            SET locked_at = NULL, unlocked_at = NOW(), unlocked_by = ?, note = ?
            WHERE period_year = ? AND period_month = ?")
            ->execute([$aName, ($reason !== '' ? $reason : null), $y, $m]);
        afxAccountingLockedPeriods(true);
        crmAuditLog('accounting.period_unlock', [
            'entity_type' => 'accounting_period',
            'entity_label' => $m . '/' . $y,
            'summary' => 'ODEMČENO účetní období ' . $m . '/' . $y
                . ($reason !== '' ? ' — ' . $reason : ''),
            'details' => ['rok' => $y, 'mesic' => $m, 'duvod' => $reason],
        ]);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('afxAccountingUnlockPeriod: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Odemčení období se nepovedlo (chyba databáze).'];
    }
}

/** Přehled všech evidovaných období (i odemčených) — pro tabulku v Nastavení. */
function afxAccountingPeriodsAll(): array {
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        afxEnsureAccountingSchema();
        return $pdo->query("SELECT * FROM accounting_periods
            ORDER BY period_year DESC, period_month DESC")->fetchAll();
    } catch (Throwable $e) {
        error_log('afxAccountingPeriodsAll: ' . $e->getMessage());
        return [];
    }
}

/** Historie zamykání/odemykání z auditní stopy (kdo, kdy, proč). */
function afxAccountingPeriodsHistory(int $limit = 50): array {
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $limit = max(1, min(200, $limit));
        $stmt = $pdo->query("SELECT created_at, actor_name, actor_role, action, entity_label, summary
            FROM audit_log
            WHERE action IN ('accounting.period_lock', 'accounting.period_unlock')
            ORDER BY id DESC LIMIT " . $limit);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];   // audit_log ještě nemusí existovat — historie je bonus
    }
}
