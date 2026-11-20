<?php
/**
 * ROZPIS SLUŽEB NA PRODEJNĚ.
 *
 * Kdo kdy stojí na prodejně. Zapisuje se SÁM každý zaměstnanec — vedení rozpis
 * nesestavuje, jen do něj vidí a může opravovat.
 *
 * VIDITELNOST kopíruje zbytek CRM (isBranchGlobalViewer): zaměstnanec vidí svou
 * pobočku, admin a Boss všechny a můžou mezi nimi přepínat. Rozpis jiné pobočky
 * se nedá ani přečíst přes URL — kontrola je na serveru, ne jen skrytím odkazu.
 *
 * UZÁVĚRKA (od v4.3.5, rozhodnutí majitele 20. 11. 2026): na celý týden (Po–Ne)
 * se zapisuje nejpozději do SOBOTY PŘEDCHOZÍHO TÝDNE, tedy do půlnoci ze soboty
 * na neděli před tím týdnem. Rozpis je tak hotový o víkendu před začátkem týdne.
 * Dřív se zapisovalo den po dni do půlnoci předchozího dne.
 * Stránka ukazuje dva týdny najednou, aby se šlo zapsat dva týdny dopředu.
 * Po uzávěrce opravuje jen vedení (afxShiftCanEditOthers), aby šlo řešit výpadky.
 */

/** Role, které smí sahat na cizí zápisy a na uzavřené dny. */
const AFX_SHIFT_MANAGER_ROLES = ['boss', 'manager', 'admin'];

function afxShiftEnsureSchema(): void
{
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) { return; }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shift_plan (
            id INT NOT NULL AUTO_INCREMENT,
            branch_id INT NOT NULL,
            tech_id INT NOT NULL,
            work_date DATE NOT NULL,
            time_from TIME NOT NULL,
            time_to TIME NOT NULL,
            note VARCHAR(120) NOT NULL DEFAULT '',
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            /* jeden člověk = jeden zápis na den; druhý zápis ho přepíše, ne zdvojí */
            UNIQUE KEY uq_shift (branch_id, tech_id, work_date),
            KEY idx_branch_date (branch_id, work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('afxShiftEnsureSchema: ' . $e->getMessage());
    }
}

/** Technik přihlášeného uživatele; 0 = účet bez vazby na zaměstnance. */
function afxShiftCurrentTechId(): int
{
    global $pdo;
    $tid = (int)($_SESSION['tech_id'] ?? 0);
    if ($tid > 0) { return $tid; }
    // admin se hlásí přes users; vazba na zaměstnance je users.technician_id
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || !isset($pdo)) { return 0; }
    try {
        $st = $pdo->prepare('SELECT technician_id FROM users WHERE id = ?');
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Smí měnit cizí zápisy a zapisovat i po uzávěrce? */
function afxShiftCanEditOthers(): bool
{
    if (function_exists('hasPermission') && hasPermission('admin_access')) { return true; }
    $role = function_exists('getCurrentStaffRole') ? (string)getCurrentStaffRole() : '';
    return in_array($role, AFX_SHIFT_MANAGER_ROLES, true);
}

/** Pobočky, do kterých přihlášený vidí. */
function afxShiftVisibleBranches(): array
{
    // „Demo servis" (kód DEMO) není prodejna — ostatním se neukazuje. Vlastní
    // pobočku ale vidí i ten, kdo na ní je: účet apple.review, kterým Apple
    // kontroluje buildy iOS appky, by jinak v Rozpisu dostal hlášku „nemáš
    // přidělenou pobočku", a nefunkční obrazovka je důvod k zamítnutí.
    $mine = (int)getCurrentStaffBranchId();
    $all = array_values(array_filter(getBranches(true),
        static fn($b) => strtoupper((string)($b['code'] ?? '')) !== 'DEMO' || (int)$b['id'] === $mine));
    if (function_exists('isBranchGlobalViewer') && isBranchGlobalViewer()) { return $all; }
    $mine = (int)getCurrentStaffBranchId();
    return array_values(array_filter($all, static fn($b) => (int)$b['id'] === $mine));
}

function afxShiftCanSeeBranch(int $branchId): bool
{
    foreach (afxShiftVisibleBranches() as $b) {
        if ((int)$b['id'] === $branchId) { return true; }
    }
    return false;
}

/**
 * Konec zápisu pro týden, do kterého patří den: neděle 00:00 před pondělím toho
 * týdne, tedy půlnoc na konci soboty předchozího týdne.
 */
function afxShiftDeadline(string $date): ?DateTimeImmutable
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day) { return null; }
    return (new DateTimeImmutable(afxShiftMonday($date)))->modify('-1 day')->setTime(0, 0);
}

/** Sobota, do které se na týden daného dne zapisuje (pro texty na stránce). */
function afxShiftDeadlineSaturday(string $date): string
{
    $dl = afxShiftDeadline($date);
    return $dl ? $dl->modify('-1 day')->format('Y-m-d') : '';
}

/** První pondělí, na jehož týden se ještě dá zapsat. */
function afxShiftFirstOpenMonday(): string
{
    $m = new DateTimeImmutable(afxShiftMonday(date('Y-m-d')));
    $now = new DateTimeImmutable();
    for ($i = 0; $i < 3; $i++) {
        $m = $m->modify('+7 day');
        if ($now < $m->modify('-1 day')->setTime(0, 0)) { break; }
    }
    return $m->format('Y-m-d');
}

/**
 * Smí se běžný zaměstnanec na den zapsat? Vrací [smí, důvod proč ne].
 */
function afxShiftDayOpen(string $date): array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $deadline = afxShiftDeadline($date);
    if (!$day || !$deadline) { return [false, 'Neplatné datum.']; }
    if (new DateTimeImmutable() < $deadline) { return [true, '']; }
    if ($day < new DateTimeImmutable('today')) { return [false, 'Den už byl.']; }
    $sat = $deadline->modify('-1 day');
    return [false, 'Na týden od ' . date('j. n.', strtotime(afxShiftMonday($date)))
        . ' se zapisovalo nejpozději do soboty ' . $sat->format('j. n.') . ' Změnu teď udělá jen vedení.'];
}

/** Zaměstnanci pobočky, kteří se můžou zapsat (aktivní, bez servisních účtů). */
function afxShiftStaff(int $branchId): array
{
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT id, name, IFNULL(role,'engineer') AS role
                             FROM technicians
                             WHERE branch_id = ? AND IFNULL(is_active,1) = 1
                             ORDER BY name");
        $st->execute([$branchId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Pondělí týdne, ve kterém leží dané datum. */
function afxShiftMonday(string $date): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable('today');
    return $d->modify('monday this week')->format('Y-m-d');
}

/** Sedm dní týdne od pondělí. */
function afxShiftWeekDays(string $monday): array
{
    $d = new DateTimeImmutable($monday);
    $out = [];
    for ($i = 0; $i < 7; $i++) { $out[] = $d->modify("+$i day")->format('Y-m-d'); }
    return $out;
}

/** Zápisy pobočky v daném rozsahu, seskupené podle dne. */
function afxShiftEntries(int $branchId, string $from, string $to): array
{
    global $pdo;
    afxShiftEnsureSchema();
    $out = [];
    try {
        $st = $pdo->prepare("SELECT s.*, t.name AS tech_name, IFNULL(t.role,'engineer') AS tech_role
                             FROM shift_plan s
                             LEFT JOIN technicians t ON t.id = s.tech_id
                             WHERE s.branch_id = ? AND s.work_date BETWEEN ? AND ?
                             ORDER BY s.work_date, s.time_from, t.name");
        $st->execute([$branchId, $from, $to]);
        foreach ($st as $r) { $out[(string)$r['work_date']][] = $r; }
    } catch (Throwable $e) { /* prázdný rozpis */ }
    return $out;
}

/** Barva člověka — stabilní podle id, ať je po celém rozpisu stejná. */
function afxShiftColor(int $techId): string
{
    $hue = ($techId * 47) % 360;      // 47 je nesoudělné se 360 → sousední id se barevně liší
    return 'hsl(' . $hue . ' 62% 52%)';
}

const AFX_SHIFT_ROLE_LABEL = [
    'boss' => 'vedení', 'manager' => 'manažer', 'engineer' => 'technik',
    'brigadnik' => 'brigádník', 'accountant' => 'účetní',
];

/**
 * Otvírací doba pobočky pro den v týdnu (0 = pondělí).
 * `branches.opening_hours` je volný text po řádcích, např.
 *   „Po – Út: 10:00 – 20:00" nebo „So: zavřeno".
 * Když se řádek nepodaří přečíst, vrátí se prázdno — rozpis funguje i bez toho.
 */
function afxShiftOpeningHours(int $branchId): array
{
    global $pdo;
    static $cache = [];
    if (isset($cache[$branchId])) { return $cache[$branchId]; }
    // Čte se z DB napřímo: getBranches() sloupec opening_hours NEVYBÍRÁ,
    // takže přes něj by tu vždy vyšlo prázdno.
    $raw = '';
    try {
        $st = $pdo->prepare('SELECT opening_hours FROM branches WHERE id = ?');
        $st->execute([$branchId]);
        $raw = (string)$st->fetchColumn();
    } catch (Throwable $e) { $raw = ''; }
    $days = ['po' => 0, 'út' => 1, 'ut' => 1, 'st' => 2, 'čt' => 3, 'ct' => 3,
             'pá' => 4, 'pa' => 4, 'so' => 5, 'ne' => 6];
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) { continue; }
        [$left, $right] = explode(':', $line, 2);
        $right = trim($right);
        $left = mb_strtolower(trim($left), 'UTF-8');
        // „Po – Út" = rozsah, „Po, St" = výčet, „Po" = jeden den
        $idx = [];
        if (preg_match('/^(\S+)\s*[–-]\s*(\S+)$/u', $left, $m)
            && isset($days[$m[1]], $days[$m[2]])) {
            for ($i = $days[$m[1]]; ; $i = ($i + 1) % 7) {
                $idx[] = $i;
                if ($i === $days[$m[2]] || count($idx) > 7) { break; }
            }
        } else {
            foreach (preg_split('/[,\s]+/u', $left) as $w) {
                if (isset($days[$w])) { $idx[] = $days[$w]; }
            }
        }
        foreach ($idx as $i) { if (!isset($out[$i])) { $out[$i] = $right; } }
    }
    return $cache[$branchId] = $out;
}

/**
 * Uloží (nebo přepíše) zápis. Vrací [ok, zpráva].
 * Kontroly jsou TADY, ne v UI — na endpoint se dá poslat cokoli.
 */
function afxShiftSave(int $branchId, int $techId, string $date, string $from, string $to, string $note): array
{
    global $pdo;
    afxShiftEnsureSchema();

    if (!afxShiftCanSeeBranch($branchId)) { return [false, 'Do rozpisu téhle pobočky nevidíš.']; }
    $me = afxShiftCurrentTechId();
    if ($techId !== $me && !afxShiftCanEditOthers()) { return [false, 'Zapsat můžeš jen sám sebe.']; }
    if ($techId <= 0) { return [false, 'Tvůj účet nemá vazbu na zaměstnance — ozvi se vedení.']; }

    [$open, $why] = afxShiftDayOpen($date);
    if (!$open && !afxShiftCanEditOthers()) { return [false, $why]; }

    // zaměstnanec musí patřit na tu pobočku (jinak by si šlo přidat kohokoli)
    $ok = false;
    foreach (afxShiftStaff($branchId) as $s) { if ((int)$s['id'] === $techId) { $ok = true; break; } }
    if (!$ok) { return [false, 'Tenhle člověk na téhle pobočce není.']; }

    $t = static function (string $v): ?string {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($v)) ? trim($v) . ':00' : null;
    };
    $f = $t($from); $u = $t($to);
    if ($f === null || $u === null) { return [false, 'Zadej čas ve tvaru 10:00.']; }
    if ($u <= $f) { return [false, 'Konec musí být po začátku.']; }

    try {
        $pdo->prepare('INSERT INTO shift_plan (branch_id, tech_id, work_date, time_from, time_to, note, created_by)
                       VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE time_from=VALUES(time_from), time_to=VALUES(time_to),
                                               note=VALUES(note), updated_at=NOW()')
            ->execute([$branchId, $techId, $date, $f, $u, mb_substr(trim($note), 0, 120),
                       (string)($_SESSION['full_name'] ?? '')]);
    } catch (Throwable $e) {
        error_log('afxShiftSave: ' . $e->getMessage());
        return [false, 'Uložení selhalo.'];
    }
    return [true, 'Zapsáno.'];
}

/** Smaže zápis. Vrací [ok, zpráva]. */
function afxShiftDelete(int $id): array
{
    global $pdo;
    afxShiftEnsureSchema();
    try {
        $st = $pdo->prepare('SELECT * FROM shift_plan WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $row = null; }
    if (!$row) { return [false, 'Zápis nenalezen.']; }

    if (!afxShiftCanSeeBranch((int)$row['branch_id'])) { return [false, 'Do rozpisu téhle pobočky nevidíš.']; }
    if ((int)$row['tech_id'] !== afxShiftCurrentTechId() && !afxShiftCanEditOthers()) {
        return [false, 'Smazat můžeš jen svůj zápis.'];
    }
    [$open, $why] = afxShiftDayOpen((string)$row['work_date']);
    if (!$open && !afxShiftCanEditOthers()) { return [false, $why]; }

    try { $pdo->prepare('DELETE FROM shift_plan WHERE id = ?')->execute([$id]); }
    catch (Throwable $e) { return [false, 'Smazání selhalo.']; }
    return [true, 'Zápis smazán.'];
}
