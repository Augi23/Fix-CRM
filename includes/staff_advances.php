<?php
/**
 * ZÁLOHY NA VÝPLATY ZAMĚSTNANCŮ (v4.3.0, požadavek majitele 3. 11. 2026)
 *
 * Záloha = hotovost vydaná z kasy zaměstnanci proti budoucí výplatě. Dřív se
 * markovala jen jako volný „Výdaj z kasy“ s textem „záloha Khalil“ — v kase to
 * sedělo, ale nikde nebylo vidět KOMU, KOLIK a jestli už se to z výplaty strhlo.
 * Teď má záloha vlastní evidenci (staff_advances) navázanou na pohyb v pokladním
 * deníku (pos_cash_movements, purpose 'zaloha') a na výdajový doklad (VPD), takže:
 *   · stav kasy sedí stejně jako u výdaje,
 *   · u zaměstnance (Reporty → Statistiky zaměstnanců / jednotlivce) je vidět
 *     součet záloh za období a kolik zbývá vyplatit po jejich odečtení,
 *   · účetní má sestavu „Zálohy zaměstnancům na mzdy“,
 *   · po stržení z výplaty se záloha označí jako VYÚČTOVANÁ (nic se nestrhne dvakrát).
 *
 * Práva: vyplatit smí vedení (admin, Boss, pobočkový manažer — jen své pobočce);
 * vyúčtovat / stornovat smí admin a Boss.
 */

function ensureStaffAdvancesTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    if ($pdo->inTransaction()) return;   // DDL = implicitní COMMIT
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_advances (
            id INT NOT NULL AUTO_INCREMENT,
            technician_id INT NOT NULL,
            branch_id INT NULL DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            note VARCHAR(255) NULL DEFAULT NULL,
            status ENUM('open','settled','cancelled') NOT NULL DEFAULT 'open',
            paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_by VARCHAR(100) NULL DEFAULT NULL,
            cash_movement_id INT NULL DEFAULT NULL,
            cash_doc_id INT NULL DEFAULT NULL,
            settled_at DATETIME NULL DEFAULT NULL,
            settled_by VARCHAR(100) NULL DEFAULT NULL,
            settled_note VARCHAR(255) NULL DEFAULT NULL,
            cancelled_at DATETIME NULL DEFAULT NULL,
            cancelled_by VARCHAR(100) NULL DEFAULT NULL,
            cancel_reason VARCHAR(160) NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_sa_tech (technician_id, status),
            KEY idx_sa_paid (paid_at),
            KEY idx_sa_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('ensureStaffAdvancesTable: ' . $e->getMessage()); }
}

/** Kdo smí VYPLATIT zálohu: vedení (admin, Boss, pobočkový manažer). Účetní ne. */
function crmCanPayStaffAdvance(): bool {
    return function_exists('crmCanTransferCash') ? crmCanTransferCash() : crmIsAdminLevel();
}

/** Kdo smí zálohu VYÚČTOVAT (strženo z výplaty) nebo STORNOVAT: admin a Boss. */
function crmCanSettleStaffAdvance(): bool {
    if (function_exists('crmIsAccountant') && crmIsAccountant()) { return false; }
    return crmIsAdminLevel();
}

/** Zaměstnanec, kterému smí aktuální uživatel zálohu vyplatit (manažer jen na své pobočce). */
function afxStaffAdvanceTechnician(int $techId): ?array {
    global $pdo;
    if ($techId <= 0) return null;
    $st = $pdo->prepare("SELECT t.id, t.name, t.branch_id, t.is_active, b.name AS branch_name
        FROM technicians t LEFT JOIN branches b ON b.id = t.branch_id WHERE t.id = ? LIMIT 1");
    $st->execute([$techId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    if (!isBranchGlobalViewer() && (int)($t['branch_id'] ?? 0) !== (int)getCurrentStaffBranchId()) return null;
    return $t;
}

/**
 * Vyplatí zálohu: záznam + výdej z pokladního deníku (purpose 'zaloha') v jedné
 * transakci, pak VPD a audit (best-effort — peníze už fyzicky odešly).
 * Vrací ['ok', 'id', 'doc_number', 'error'].
 */
function afxStaffAdvanceCreate(int $techId, float $amount, string $note, string $by): array {
    global $pdo;
    $amount = round($amount, 2);
    if ($amount <= 0) return ['ok' => false, 'error' => 'Zadej platnou částku zálohy.'];
    if ($amount > 200000) return ['ok' => false, 'error' => 'Částka zálohy je podezřele vysoká (max 200 000 Kč).'];
    $tech = afxStaffAdvanceTechnician($techId);
    if (!$tech) return ['ok' => false, 'error' => 'Zaměstnanec nenalezen nebo patří jiné pobočce.'];
    if ((int)($tech['is_active'] ?? 1) !== 1) return ['ok' => false, 'error' => 'Zaměstnanec je neaktivní — záloha se mu nevyplácí.'];
    if (function_exists('afxAccountingClosedError')) {
        $err = afxAccountingClosedError(date('Y-m-d'), 'záloha na výplatu');
        if ($err !== null) return ['ok' => false, 'error' => $err];
    }
    $note = mb_substr(trim($note), 0, 255);
    // záloha jde ze zásuvky pobočky OBSLUHY (kde se fyzicky vyplácí), ne pobočky zaměstnance
    $branch = (int)getCurrentStaffBranchId() ?: (int)($tech['branch_id'] ?? 0) ?: null;

    ensureStaffAdvancesTable();
    ensurePosCashMovementsTable();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO staff_advances (technician_id, branch_id, amount, note, paid_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$techId, $branch, $amount, $note !== '' ? $note : null, $by !== '' ? mb_substr($by, 0, 100) : null]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
            VALUES (?, 'out', ?, 'zaloha', 'staff_advance', ?, ?, ?, ?)")
            ->execute([$branch, $amount, $id, mb_substr((string)$tech['name'], 0, 40), $note !== '' ? $note : null, $by !== '' ? mb_substr($by, 0, 100) : null]);
        $mvId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE staff_advances SET cash_movement_id = ? WHERE id = ?")->execute([$mvId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('afxStaffAdvanceCreate: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Chyba serveru — záloha se nezapsala.'];
    }

    // VPD k pohybu (ref_type cash_movement → v knize se nesčítá podruhé)
    $docNumber = '';
    try {
        $doc = afxCashDocIssue([
            'branch_id' => (int)$branch, 'type' => 'expense', 'amount' => $amount, 'date' => date('Y-m-d'),
            'purpose' => 'Záloha na výplatu — ' . (string)$tech['name'] . ($note !== '' ? ' — ' . $note : ''),
            'counterparty' => (string)$tech['name'],
            'issued_by' => $by, 'ref_type' => 'cash_movement', 'ref_id' => $mvId, 'note' => $note,
        ]);
        if (!empty($doc['ok'])) {
            $docNumber = (string)$doc['number'];
            $pdo->prepare("UPDATE staff_advances SET cash_doc_id = ? WHERE id = ?")->execute([(int)$doc['id'], $id]);
        }
    } catch (Throwable $e) { error_log('afxStaffAdvanceCreate doc: ' . $e->getMessage()); }

    crmAuditLog('kasa.staff_advance', [
        'entity_type' => 'staff_advance', 'entity_id' => $id, 'entity_label' => (string)$tech['name'],
        'summary' => 'Záloha na výplatu ' . formatMoney($amount) . ' → ' . (string)$tech['name']
            . ($note !== '' ? ' (' . $note . ')' : '') . ($docNumber !== '' ? ', doklad ' . $docNumber : ''),
        'branch_id' => $branch,
    ]);
    return ['ok' => true, 'id' => $id, 'doc_number' => $docNumber, 'technician' => (string)$tech['name'], 'amount' => $amount, 'error' => ''];
}

/** Záloha stržena z výplaty → vyúčtovaná (už se nikdy nestrhne podruhé). */
function afxStaffAdvanceSettle(int $id, string $note, string $by): array {
    global $pdo;
    ensureStaffAdvancesTable();
    $st = $pdo->prepare("SELECT a.*, t.name AS tech_name FROM staff_advances a LEFT JOIN technicians t ON t.id = a.technician_id WHERE a.id = ?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['ok' => false, 'error' => 'Záloha nenalezena.'];
    if ((string)$a['status'] !== 'open') return ['ok' => false, 'error' => 'Záloha už je ' . ((string)$a['status'] === 'settled' ? 'vyúčtovaná' : 'stornovaná') . '.'];
    $note = mb_substr(trim($note), 0, 255);
    $pdo->prepare("UPDATE staff_advances SET status = 'settled', settled_at = NOW(), settled_by = ?, settled_note = ? WHERE id = ? AND status = 'open'")
        ->execute([$by !== '' ? mb_substr($by, 0, 100) : null, $note !== '' ? $note : null, $id]);
    crmAuditLog('kasa.staff_advance_settle', [
        'entity_type' => 'staff_advance', 'entity_id' => $id, 'entity_label' => (string)($a['tech_name'] ?? ''),
        'summary' => 'Záloha ' . formatMoney((float)$a['amount']) . ' (' . (string)($a['tech_name'] ?? '') . ') vyúčtována — stržena z výplaty'
            . ($note !== '' ? ' (' . $note . ')' : ''),
        'branch_id' => (int)($a['branch_id'] ?? 0) ?: null,
    ]);
    return ['ok' => true, 'error' => ''];
}

/** Storno zálohy (omyl): peníze se vrací do kasy opačným pohybem + protidoklad přes storno VPD. */
function afxStaffAdvanceStorno(int $id, string $reason, string $by): array {
    global $pdo;
    ensureStaffAdvancesTable();
    $st = $pdo->prepare("SELECT a.*, t.name AS tech_name FROM staff_advances a LEFT JOIN technicians t ON t.id = a.technician_id WHERE a.id = ?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['ok' => false, 'error' => 'Záloha nenalezena.'];
    if ((string)$a['status'] !== 'open') return ['ok' => false, 'error' => 'Stornovat jde jen nevyúčtovanou zálohu.'];
    $reason = mb_substr(trim($reason), 0, 160);
    if ((int)($a['cash_doc_id'] ?? 0) > 0) {
        try {
            $res = afxCashDocStorno((int)$a['cash_doc_id'], 'storno zálohy' . ($reason !== '' ? ' — ' . $reason : ''), $by);
        } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string)($res['error'] ?? 'Storno dokladu selhalo')];
    } else {
        // bez dokladu (selhal při výplatě) → aspoň opačný pohyb v deníku
        try {
            $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by)
                VALUES (?, 'in', ?, 'storno', 'staff_advance', ?, ?, ?, ?)")
                ->execute([(int)($a['branch_id'] ?? 0) ?: null, (float)$a['amount'], $id, mb_substr('Storno zálohy ' . (string)($a['tech_name'] ?? ''), 0, 40),
                    mb_substr('Vrácení zálohy do kasy' . ($reason !== '' ? ' — ' . $reason : ''), 0, 255), $by !== '' ? mb_substr($by, 0, 100) : null]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Opačný pohyb se nepodařilo založit.']; }
    }
    $pdo->prepare("UPDATE staff_advances SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND status = 'open'")
        ->execute([$by !== '' ? mb_substr($by, 0, 100) : null, $reason !== '' ? $reason : null, $id]);
    crmAuditLog('kasa.staff_advance_storno', [
        'entity_type' => 'staff_advance', 'entity_id' => $id, 'entity_label' => (string)($a['tech_name'] ?? ''),
        'summary' => 'Storno zálohy ' . formatMoney((float)$a['amount']) . ' (' . (string)($a['tech_name'] ?? '') . ')' . ($reason !== '' ? ' — ' . $reason : ''),
        'branch_id' => (int)($a['branch_id'] ?? 0) ?: null,
    ]);
    return ['ok' => true, 'error' => ''];
}

/**
 * Seznam záloh. $f: technician_id, status ('open'|'settled'|'cancelled'|'' = vše),
 * from/to (datum výplaty), branch_id, limit. Řazeno od nejnovější.
 */
function afxStaffAdvancesList(array $f = []): array {
    global $pdo;
    ensureStaffAdvancesTable();
    $w = []; $p = [];
    if (!empty($f['technician_id'])) { $w[] = 'a.technician_id = ?'; $p[] = (int)$f['technician_id']; }
    if (!empty($f['status'])) { $w[] = 'a.status = ?'; $p[] = (string)$f['status']; }
    if (!empty($f['from'])) { $w[] = 'a.paid_at >= ?'; $p[] = (string)$f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $w[] = 'a.paid_at <= ?'; $p[] = (string)$f['to'] . ' 23:59:59'; }
    if (!empty($f['branch_id'])) { $w[] = 'a.branch_id = ?'; $p[] = (int)$f['branch_id']; }
    $limit = (int)($f['limit'] ?? 500);
    try {
        $st = $pdo->prepare("SELECT a.*, t.name AS tech_name, t.branch_id AS tech_branch_id, d.doc_number
            FROM staff_advances a
            LEFT JOIN technicians t ON t.id = a.technician_id
            LEFT JOIN cash_documents d ON d.id = a.cash_doc_id"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY a.paid_at DESC, a.id DESC LIMIT " . max(1, min($limit, 5000)));
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('afxStaffAdvancesList: ' . $e->getMessage()); return []; }
}

/** Součty pro přehled zaměstnance: vyplaceno v období (bez storen) + nevyúčtováno celkem. */
function afxStaffAdvanceSums(int $techId, string $from, string $to): array {
    global $pdo;
    ensureStaffAdvancesTable();
    $out = ['period' => 0.0, 'period_count' => 0, 'open' => 0.0, 'open_count' => 0];
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM staff_advances
            WHERE technician_id = ? AND status <> 'cancelled' AND paid_at BETWEEN ? AND ?");
        $st->execute([$techId, $from . ' 00:00:00', $to . ' 23:59:59']);
        [$s, $c] = $st->fetch(PDO::FETCH_NUM);
        $out['period'] = (float)$s; $out['period_count'] = (int)$c;
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM staff_advances WHERE technician_id = ? AND status = 'open'");
        $st->execute([$techId]);
        [$s, $c] = $st->fetch(PDO::FETCH_NUM);
        $out['open'] = (float)$s; $out['open_count'] = (int)$c;
    } catch (Throwable $e) {}
    return $out;
}

function afxStaffAdvanceStatusLabel(string $s): string {
    return ['open' => 'nevyúčtováno', 'settled' => 'vyúčtováno', 'cancelled' => 'storno'][$s] ?? $s;
}
