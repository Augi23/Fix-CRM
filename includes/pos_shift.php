<?php
/**
 * Převzetí pokladny (směny) — pos_shifts.
 *
 * Pravidla:
 *  - V jednu chvíli má pokladnu POBOČKY převzatou nejvýš jeden pracovník
 *    (otevřená směna). Bez převzetí nejde markovat (afxPosShiftAssertMine
 *    v api/pos_checkout.php); ostatním pokladna ukáže, kdo ji drží.
 *  - Převzetí: systém ukáže stav hotovosti podle pokladní knihy a obsluha
 *    potvrdí ANO (souhlasí) / NE (napočítala jinak). Při NE se napočítaná
 *    částka zapíše jako počáteční zůstatek pokladny k dnešku
 *    (afxCashSetOpeningBalance) — od ní se ten den odvíjí stav kasy.
 *  - Uzávěrka: obsluha napočítá hotovost, zapíše ji a směnu uzavře; rozdíl
 *    proti systému se uloží na směně a jde do audit logu.
 *
 * Identita drží obě session varianty dual-loginu (users i technicians).
 */

require_once __DIR__ . '/cash_book.php';

function afxEnsurePosShiftTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_shifts (
            id INT NOT NULL AUTO_INCREMENT,
            branch_id INT NULL DEFAULT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            opened_by VARCHAR(100) NOT NULL DEFAULT '',
            opened_by_user INT NULL DEFAULT NULL,
            opened_by_tech INT NULL DEFAULT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            opening_expected DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_counted DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_match TINYINT(1) NOT NULL DEFAULT 1,
            closed_at DATETIME NULL DEFAULT NULL,
            closed_by VARCHAR(100) NULL DEFAULT NULL,
            closing_expected DECIMAL(12,2) NULL DEFAULT NULL,
            closing_counted DECIMAL(12,2) NULL DEFAULT NULL,
            closing_note VARCHAR(255) NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_shift_open (branch_id, status),
            KEY idx_shift_opened (opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('afxEnsurePosShiftTable: ' . $e->getMessage()); }
}

/** Identita přihlášeného pracovníka: [label, user_id|null, tech_id|null]. */
function afxPosShiftIdentity(): array {
    $label = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['tech_name'] ?? ''));
    // PAST DUAL-LOGINU: technikovi se do $_SESSION['user_id'] ukládá pseudo-klíč
    // 't<id>' (login.php), takže (int) z něj udělá 0 — a nula pak „seděla" na
    // každou směnu s prázdným opened_by_user, tedy na směnu kteréhokoli kolegy.
    // Proto: technik má VÝHRADNĚ tech_id, číselné user_id patří jen účtu z users.
    $tid = (int)($_SESSION['tech_id'] ?? 0) ?: null;
    // Číselné user_id zapisujeme VŽDY, i když má člověk zároveň technický profil —
    // v Historii je pak vidět i účet z `users`. Na porovnání „je směna moje" má
    // ale přednost tech_id (viz afxPosShiftIsMine), takže nula nikomu nesedne.
    $uid = is_numeric($_SESSION['user_id'] ?? null) ? ((int)$_SESSION['user_id'] ?: null) : null;
    return [$label !== '' ? $label : 'neznámý', $uid, $tid];
}

/**
 * Smí přihlášený uzavřít CIZÍ směnu? Vedení (admin/Boss) na obou pobočkách,
 * manažer jen na SVÉ pobočce — bez toho stojí kasa na Příkopě, dokud se
 * nepřipojí majitel. Směnu bez pobočky (starý záznam) nechává na vedení,
 * protože u ní nejde spolehlivě určit, které pobočky se týká.
 */
function afxPosShiftCanForceClose(?array $shift): bool {
    if (!$shift) { return false; }
    if (function_exists('crmCanDeleteOrders') && crmCanDeleteOrders()) { return true; }
    if (!function_exists('getCurrentStaffRole') || getCurrentStaffRole() !== 'manager') { return false; }
    $shiftBranch = (int)($shift['branch_id'] ?? 0);
    // Směna bez vyplněné pobočky (starší záznam) se do výpisu propadne OBĚMA
    // pobočkám. Kdyby ji manažer nesměl zavřít, blokovala by kasu napořád a
    // musel by přijet admin — proto ji zavřít smí; pobočku mu doplní uzávěrka.
    if ($shiftBranch <= 0) { return true; }
    return $shiftBranch === (int)getCurrentStaffBranchId();
}

/** Otevřená směna pobočky, nebo null. */
function afxPosShiftCurrent(int $branchId): ?array {
    global $pdo;
    afxEnsurePosShiftTable();
    $st = $pdo->prepare("SELECT * FROM pos_shifts WHERE status = 'open'"
        . ($branchId > 0 ? " AND (branch_id = ? OR branch_id IS NULL)" : "")
        . " ORDER BY id DESC LIMIT 1");
    $st->execute($branchId > 0 ? [$branchId] : []);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Patří otevřená směna přihlášenému pracovníkovi? */
function afxPosShiftIsMine(?array $shift): bool {
    if (!$shift) { return false; }
    [, $uid, $tid] = afxPosShiftIdentity();
    $shiftUid = (int)($shift['opened_by_user'] ?? 0);
    $shiftTid = (int)($shift['opened_by_tech'] ?? 0);
    // Porovnávají se jen VYPLNĚNÉ identity na obou stranách. Prázdný sloupec
    // (0/NULL u starších směn) nesmí sednout nikomu — za zásuvku musí odpovídat
    // konkrétní člověk, jinak se rozdíl při uzávěrce přiřkne komukoli.
    if ($tid !== null) { return $shiftTid > 0 && $shiftTid === $tid; }
    if ($uid !== null) { return $shiftUid > 0 && $shiftUid === $uid; }
    return false;
}

/** Tvrdý guard pro markování: bez vlastní otevřené směny prodej neprojde. */
function afxPosShiftAssertMine(int $branchId): void {
    $shift = afxPosShiftCurrent($branchId);
    if (!$shift) {
        throw new RuntimeException('Pokladna není převzatá — nejdřív ji na stránce Pokladna převezmi (kontrola hotovosti ANO/NE).');
    }
    if (!afxPosShiftIsMine($shift)) {
        throw new RuntimeException('Pokladnu má převzatou ' . $shift['opened_by'] . ' (od '
            . date('H:i', strtotime((string)$shift['opened_at'])) . ') — markovat smí jen on/ona, nebo ji musí uzavřít.');
    }
}

/**
 * Převzetí pokladny. $match = hotovost souhlasí se systémem; při false je
 * $counted povinné a zapíše se jako počáteční zůstatek pokladny k dnešku.
 * Vrací ['ok'=>bool, 'error'=>?, 'shift_id'=>?].
 */
function afxPosShiftOpen(int $branchId, bool $match, ?float $counted): array {
    // UZÁVĚRKA: převzetí kasy zapisuje počáteční zůstatek k dnešku — při zamčeném
    // aktuálním měsíci se musí odmítnout PŘED INSERTem směny, jinak směna zůstane
    // viset otevřená a další převzetí hlásí „pokladnu už má převzatou…"
    if (function_exists('afxAccountingClosedError')) {
        $lockErr = afxAccountingClosedError(date('Y-m-d'), 'převzetí pokladny');
        if ($lockErr !== null) { return ['ok' => false, 'error' => $lockErr]; }
    }
    global $pdo;
    afxEnsurePosShiftTable();
    [$label, $uid, $tid] = afxPosShiftIdentity();

    // zámek proti dvojkliku/dvěma prohlížečům — jinak vzniknou dvě otevřené směny
    $lock = 'afx_pos_shift_' . $branchId;
    $got = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 5)")->fetchColumn();
    if ((int)$got !== 1) { return ['ok' => false, 'error' => 'Pokladnu právě přebírá někdo jiný — zkus to za chvíli.']; }
    try {
        $cur = afxPosShiftCurrent($branchId);
        if ($cur) {
            return ['ok' => false, 'error' => 'Pokladnu už má převzatou ' . $cur['opened_by'] . '.'];
        }
        $expected = afxCashBalanceAt($branchId, date('Y-m-d'));
        if ($match) {
            $counted = $expected;
        } else {
            if ($counted === null || !is_finite($counted) || $counted < 0 || $counted > 100000000) {
                return ['ok' => false, 'error' => 'Zadej napočítanou hotovost.'];
            }
            $counted = round($counted, 2);
        }

        $ins = $pdo->prepare("INSERT INTO pos_shifts
                (branch_id, status, opened_by, opened_by_user, opened_by_tech, opening_expected, opening_counted, opening_match)
            VALUES (?, 'open', ?, ?, ?, ?, ?, ?)");
        $ins->execute([$branchId ?: null, $label, $uid, $tid, $expected, $counted, $match ? 1 : 0]);
        $shiftId = (int)$pdo->lastInsertId();

        if (!$match && abs($counted - $expected) >= 0.005) {
            // od napočítané částky se dnes odvíjí stav pokladny
            afxCashSetOpeningBalance($branchId, $counted, date('Y-m-d'), $label,
                'Převzetí pokladny — přepočet (systém ' . number_format($expected, 2, ',', ' ') . ' Kč)');
        }

        crmAuditLog('kasa.shift_open', [
            'entity_type' => 'pos_shift', 'entity_id' => $shiftId,
            'summary' => 'Převzetí pokladny: systém ' . number_format($expected, 2, ',', ' ') . ' Kč, '
                . ($match ? 'souhlasí' : 'napočítáno ' . number_format($counted, 2, ',', ' ') . ' Kč (rozdíl '
                    . number_format($counted - $expected, 2, ',', ' ') . ' Kč)'),
            'branch_id' => $branchId,
        ]);
        return ['ok' => true, 'shift_id' => $shiftId];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
    }
}

/**
 * Uzávěrka směny: napočítaná hotovost + poznámka. Uzavřít smí držitel směny,
 * nebo ($force) ten, kdo na to má právo podle afxPosShiftCanForceClose() —
 * vedení kdekoli, manažer na své pobočce (zapomenutá směna kolegy).
 */
function afxPosShiftClose(int $shiftId, float $counted, string $note, bool $force = false): array {
    global $pdo;
    afxEnsurePosShiftTable();
    [$label, $myUid, $myTid] = afxPosShiftIdentity();

    $st = $pdo->prepare("SELECT * FROM pos_shifts WHERE id = ? AND status = 'open'");
    $st->execute([$shiftId]);
    $shift = $st->fetch(PDO::FETCH_ASSOC);
    if (!$shift) { return ['ok' => false, 'error' => 'Směna už je uzavřená nebo neexistuje.']; }
    // Právo na cizí směnu se ověřuje TADY, proti skutečně načtenému řádku — volající
    // nesmí stačit „chtěl force"; jinak by pobočkový guard šel obejít jiným vstupem.
    $mine = afxPosShiftIsMine($shift);
    if (!$mine) {
        if (!$force) {
            return ['ok' => false, 'error' => 'Směnu může uzavřít jen ' . $shift['opened_by'] . ' nebo vedení.'];
        }
        if (!afxPosShiftCanForceClose($shift)) {
            return ['ok' => false, 'error' => 'Cizí směnu smí uzavřít jen vedení nebo manažer téhle pobočky.'];
        }
    }
    if (!is_finite($counted) || $counted < 0 || $counted > 100000000) {
        return ['ok' => false, 'error' => 'Zadej napočítanou hotovost.'];
    }
    $counted = round($counted, 2);
    $expected = afxCashBalanceAt((int)($shift['branch_id'] ?? 0), date('Y-m-d'));

    $up = $pdo->prepare("UPDATE pos_shifts
        SET status = 'closed', closed_at = NOW(), closed_by = ?, closing_expected = ?, closing_counted = ?, closing_note = ?
        WHERE id = ? AND status = 'open'");
    $up->execute([$label, $expected, $counted, $note !== '' ? mb_substr($note, 0, 255) : null, $shiftId]);
    if ($up->rowCount() === 0) { return ['ok' => false, 'error' => 'Směnu právě uzavřel někdo jiný.']; }

    $diff = round($counted - $expected, 2);
    crmAuditLog('kasa.shift_close', [
        'entity_type' => 'pos_shift', 'entity_id' => $shiftId,
        'summary' => ($mine ? 'Uzávěrka pokladny: ' : 'Uzávěrka CIZÍ směny (držel/a ji ' . $shift['opened_by'] . '): ')
            . 'napočítáno ' . number_format($counted, 2, ',', ' ') . ' Kč, systém '
            . number_format($expected, 2, ',', ' ') . ' Kč' . (abs($diff) >= 0.005
                ? ', ROZDÍL ' . number_format($diff, 2, ',', ' ') . ' Kč' : ', souhlasí')
            . ($mine ? '' : ' — uzavřel/a ' . $label),
        // Jmenovitě obě strany včetně id, ať je i po přejmenování člověka jasné,
        // komu zásuvka patřila a kdo ji zavřel.
        'details' => [
            'drzitel' => (string)$shift['opened_by'],
            'drzitel_user_id' => ((int)($shift['opened_by_user'] ?? 0)) ?: null,
            'drzitel_tech_id' => ((int)($shift['opened_by_tech'] ?? 0)) ?: null,
            'uzavrel' => $label,
            'uzavrel_user_id' => $myUid,
            'uzavrel_tech_id' => $myTid,
            'cizi_smena' => $mine ? 0 : 1,
            'napocitano' => $counted,
            'system' => round($expected, 2),
            'rozdil' => $diff,
        ],
        'branch_id' => (int)($shift['branch_id'] ?? 0),
    ]);
    return ['ok' => true, 'expected' => $expected, 'diff' => $diff];
}

/** Poslední uzavřená směna pobočky (pro hlášku „při poslední uzávěrce bylo…"). */
function afxPosShiftLastClosed(int $branchId): ?array {
    global $pdo;
    afxEnsurePosShiftTable();
    $st = $pdo->prepare("SELECT * FROM pos_shifts WHERE status = 'closed'"
        . ($branchId > 0 ? " AND (branch_id = ? OR branch_id IS NULL)" : "")
        . " ORDER BY closed_at DESC LIMIT 1");
    $st->execute($branchId > 0 ? [$branchId] : []);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
