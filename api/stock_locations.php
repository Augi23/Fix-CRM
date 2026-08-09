<?php
/**
 * Umístění skladu (regály / police / krabičky) + přiřazování dílů:
 *   op=create    — nové umístění (type, name, parent_id, count 1–50 → hromadně)
 *   op=update    — přejmenování / přesun na jinou polici (parent_id) / poznámka / (de)aktivace
 *   op=delete    — smazání prázdného umístění (jinak poradí deaktivaci)
 *   op=assign    — hromadné přiřazení dílů do umístění (location_id=0 → odebrat umístění)
 *   op=set_model — hromadné nastavení modelu zařízení u dílů (prázdný model → smazat)
 *   op=map_layout — uložení rozmístění regálů v 3D mapě skladu (jen souřadnice, per pobočka)
 * Práva: manage_inventory (jako zbytek správy skladu).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !hasPermission('manage_inventory')) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

ensureStockLocationsSchema();
ensureSkladBranchSchema();
// Hromadné skladové akce smí zaměstnanec provést jen u dílů SVÉ pobočky (admin/Boss všude).
$__invBranchScope = isBranchGlobalViewer() ? '' : ' AND branch_id = ' . (int)getCurrentStaffBranchId();

// POBOČKA: regály/police/krabičky patří konkrétní provozovně — Karlín a Na Příkopě
// mají každý svůj sklad, takže se jejich umístění nesmí míchat ani přepisovat.
$branchId = (int)($_POST['branch_id'] ?? 0);
// jen SKUTEČNÁ pobočka — jinak by admin (vidí všude) založil umístění na pobočce,
// která neexistuje: nikde by nebyla vidět, ale spotřebovala by kódy v řadě
$__branchOk = false;
foreach (getBranches(true) as $__b) { if ((int)$__b['id'] === $branchId) { $__branchOk = true; break; } }
if (!$__branchOk) { $branchId = (int)skladBranchOrOwn(); }

$op = (string)($_POST['op'] ?? '');

/** Zakládat a měnit umístění smí zaměstnanec TÉ pobočky (admin/Boss všude). */
function _locRequireBranch(int $branchId): void {
    if (!crmCanModifyBranchStock($branchId)) {
        throw new Exception('Sklad jiné pobočky můžeš prohlížet, ale ne měnit.');
    }
}

/** Založí jedno umístění a vrátí ['id'=>…, 'code'=>…].
 *  Kód se odvozuje z nejvyššího už použitého v TÉ pobočce (RegK1, RegK1-P2,
 *  KrK001…), takže dva lidé
 *  zakládající naráz by dostali tentýž — proto se celé zakládání dělá pod zámkem
 *  _locLock() (opakování po chybě duplicity by uvnitř transakce nepomohlo: čtení
 *  vidí kvůli REPEATABLE READ pořád stejný snímek dat). */
function _locInsert(PDO $pdo, string $type, string $name, int $parentId, int $branchId): array {
    $code = nextStockLocationCode($pdo, $type, $parentId, $branchId);
    $pdo->prepare("INSERT INTO stock_locations (code, name, type, parent_id, branch_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$code, $name, $type, $parentId > 0 ? $parentId : null, $branchId]);
    return ['id' => (int)$pdo->lastInsertId(), 'code' => $code];
}

/** Výhradní zámek na přidělování kódů umístění (stejný postup jako pokladní řady). */
function _locLock(PDO $pdo): void {
    $got = $pdo->query("SELECT GET_LOCK('afx_stock_loc', 5)")->fetchColumn();
    if ((int)$got !== 1) { throw new Exception('Sklad právě upravuje někdo jiný — zkus to za chvíli.'); }
}
function _locUnlock(PDO $pdo): void {
    try { $pdo->query("SELECT RELEASE_LOCK('afx_stock_loc')"); } catch (Throwable $e) {}
}

/** id z POST (pole i CSV „1,2,3") → unikátní kladné inty */
function _reqIds($raw): array {
    $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
    $out = [];
    foreach ($ids as $i) { $i = (int)$i; if ($i > 0) { $out[$i] = $i; } }
    return array_values($out);
}

try {
    if ($op === 'create') {
        _locRequireBranch($branchId);
        $type = (string)($_POST['type'] ?? 'krabicka');
        if (!in_array($type, ['regal', 'police', 'krabicka'], true)) { throw new Exception('Neznámý typ umístění.'); }
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $count = max(1, min(50, (int)($_POST['count'] ?? 1)));
        if ($type === 'regal') { $parentId = 0; }
        // police se zakládá VÝHRADNĚ na regál — jinak by neměla z čeho odvodit kód
        if ($type === 'police' && $parentId <= 0) { throw new Exception('Vyber regál, na který police patří.'); }
        if ($parentId > 0) {
            $p = $pdo->prepare("SELECT id, type, branch_id, is_active FROM stock_locations WHERE id = ?");
            $p->execute([$parentId]);
            $parent = $p->fetch();
            if (!$parent) { throw new Exception('Nadřazené umístění nenalezeno.'); }
            if (empty($parent['is_active'])) { throw new Exception('Nadřazené umístění je deaktivované.'); }
            if ($type === 'police' && $parent['type'] !== 'regal') { throw new Exception('Police patří na regál.'); }
            if ($type === 'krabicka' && $parent['type'] === 'krabicka') { throw new Exception('Krabička nemůže být uvnitř jiné krabičky.'); }
            // regál z Karlína nesmí dostat polici Na Příkopě — sklad by se promíchal
            $branchId = (int)($parent['branch_id'] ?? 0) ?: $branchId;
            _locRequireBranch($branchId);
        }
        $created = [];
        _locLock($pdo);
        try {
            // celá dávka, nebo nic — jinak by po chybě v půlce zůstala umístění,
            // o kterých obsluha neví (a opakování by je založilo podruhé)
            $pdo->beginTransaction();
            for ($i = 0; $i < $count; $i++) {
                $rowName = ($count > 1 && $name !== '') ? $name . ' ' . ($i + 1) : $name;
                $created[] = _locInsert($pdo, $type, $rowName, $parentId, $branchId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } finally { _locUnlock($pdo); }
        $codes = implode(', ', array_column($created, 'code'));
        crmAuditLog('location.create', [
            'entity_type' => 'stock_location', 'entity_id' => $created[0]['id'], 'entity_label' => $codes,
            'summary' => 'Založeno umístění skladu: ' . stockLocationTypeLabel($type) . ' ' . $codes . ($name !== '' ? ' („' . $name . '")' : ''),
        ]);
        echo json_encode(['success' => true, 'created' => $created,
            'message' => count($created) === 1 ? 'Založeno umístění ' . $created[0]['code'] . '.' : 'Založeno ' . count($created) . ' umístění (' . $codes . ').'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'setup') {
        // RYCHLÉ NASTAVENÍ SKLADU: „mám 4 regály, v každém 5 polic, v každé 2 krabičky"
        // → založí celou kostru najednou. Bez toho by obsluha proklikávala desítky
        // dialogů. Přidává VŽDY (existující regály zůstávají), aby šlo sklad rozšířit.
        _locRequireBranch($branchId);
        $racks  = max(0, min(30, (int)($_POST['racks'] ?? 0)));
        $shelves = max(0, min(30, (int)($_POST['shelves_per_rack'] ?? 0)));
        $boxes  = max(0, min(20, (int)($_POST['boxes_per_shelf'] ?? 0)));
        $intoExisting = _reqIds($_POST['into_racks'] ?? '');   // police do UŽ existujících regálů

        if ($racks === 0 && !$intoExisting) { throw new Exception('Zadej, kolik regálů se má založit.'); }
        $total = $racks + ($racks * $shelves) + ($racks * $shelves * $boxes)
               + (count($intoExisting) * $shelves) + (count($intoExisting) * $shelves * $boxes);
        if ($total > 600) {
            throw new Exception('To by bylo ' . $total . ' umístění najednou — rozděl to na menší dávky (limit 600).');
        }
        if ($total === 0) { throw new Exception('Není co zakládat.'); }

        // vybrané „regály" musí být opravdu AKTIVNÍ REGÁLY TÉHLE pobočky — jinak by
        // police vznikly třeba uvnitř krabičky a ve stromu by je nikdo neuviděl
        if ($intoExisting) {
            $ph = implode(',', array_fill(0, count($intoExisting), '?'));
            $rq = $pdo->prepare("SELECT id FROM stock_locations
                                 WHERE id IN ($ph) AND type = 'regal' AND is_active = 1 AND branch_id = ?");
            $rq->execute(array_merge($intoExisting, [$branchId]));
            $okRacks = $rq->fetchAll(PDO::FETCH_COLUMN);
            if (count($okRacks) !== count($intoExisting)) {
                throw new Exception('Vybrané umístění není aktivní regál téhle pobočky.');
            }
        }
        // krabičky bez polic nemají kam — říct to, ne je tiše zahodit
        if ($boxes > 0 && $shelves === 0) {
            throw new Exception('Krabičky se zakládají do nových polic — zadej i počet polic (nebo krabičky přidej u konkrétní police tlačítkem „+ krabička").');
        }

        $madeR = 0; $madeP = 0; $madeK = 0;
        _locLock($pdo);
        try {
            $pdo->beginTransaction();   // uvnitř try — finally pak zámek uvolní vždy
            $rackIds = $intoExisting;
            for ($r = 0; $r < $racks; $r++) {
                $rack = _locInsert($pdo, 'regal', '', 0, $branchId);
                $rackIds[] = $rack['id'];
                $madeR++;
            }
            foreach ($rackIds as $rackId) {
                for ($p = 0; $p < $shelves; $p++) {
                    $shelf = _locInsert($pdo, 'police', '', (int)$rackId, $branchId);
                    $madeP++;
                    for ($b = 0; $b < $boxes; $b++) {
                        _locInsert($pdo, 'krabicka', '', (int)$shelf['id'], $branchId);
                        $madeK++;
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } finally { _locUnlock($pdo); }

        $sum = trim(($madeR ? $madeR . ' regálů, ' : '') . ($madeP ? $madeP . ' polic, ' : '') . ($madeK ? $madeK . ' krabiček' : ''), ', ');
        crmAuditLog('location.create', [
            'entity_type' => 'stock_location', 'entity_label' => skladBranchLabel($branchId),
            'branch_id' => $branchId,
            'summary' => 'Hromadně založena kostra skladu (' . skladBranchLabel($branchId) . '): ' . $sum,
        ]);
        echo json_encode(['success' => true, 'message' => 'Založeno: ' . $sum . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM stock_locations WHERE id = ?");
        $st->execute([$id]);
        $loc = $st->fetch();
        if (!$loc) { throw new Exception('Umístění nenalezeno.'); }
        _locRequireBranch((int)($loc['branch_id'] ?? 0) ?: getDefaultBranchId());

        $set = []; $vals = [];
        if (isset($_POST['name'])) { $set[] = 'name = ?'; $vals[] = mb_substr(trim((string)$_POST['name']), 0, 120); }
        if (isset($_POST['note'])) { $set[] = 'note = ?'; $vals[] = ($n = mb_substr(trim((string)$_POST['note']), 0, 255)) !== '' ? $n : null; }
        if (isset($_POST['is_active'])) { $set[] = 'is_active = ?'; $vals[] = (int)!!(int)$_POST['is_active']; }
        if (isset($_POST['parent_id'])) {
            $parentId = (int)$_POST['parent_id'];
            if ($parentId === $id) { throw new Exception('Umístění nemůže být samo sobě rodičem.'); }
            if ($loc['type'] === 'regal') { $parentId = 0; }
            // police musí zůstat na regálu — jinak by její kód (RegK1-P2) lhal
            if ((string)$loc['type'] === 'police' && $parentId <= 0) {
                throw new Exception('Police musí zůstat na regálu — vyber regál.');
            }
            if ($parentId > 0) {
                $p = $pdo->prepare("SELECT id, type, branch_id, is_active FROM stock_locations WHERE id = ?");
                $p->execute([$parentId]);
                $parent = $p->fetch();
                if (!$parent) { throw new Exception('Nadřazené umístění nenalezeno.'); }
                if (empty($parent['is_active'])) { throw new Exception('Cílové umístění je deaktivované.'); }
                if ((int)($parent['branch_id'] ?? 0) !== (int)($loc['branch_id'] ?? 0)) {
                    throw new Exception('Přesunout jde jen v rámci skladu jedné pobočky.');
                }
                if ($loc['type'] === 'police' && $parent['type'] !== 'regal') { throw new Exception('Police patří na regál.'); }
                if ($loc['type'] === 'krabicka' && $parent['type'] === 'krabicka') { throw new Exception('Krabička nemůže být uvnitř jiné krabičky.'); }
            }
            $set[] = 'parent_id = ?'; $vals[] = $parentId > 0 ? $parentId : null;
        }
        // POLICE PŘESTĚHOVANÁ NA JINÝ REGÁL musí dostat nový kód: kód police obsahuje
        // kód regálu (RegK1-P2), takže po přesunu na RegK3 by štítek lhal o tom, kde
        // police je. U krabičky je naopak trvalý kód záměr (štítek se netiskne znovu).
        $newCode = null;
        $needCode = false;
        if (isset($_POST['parent_id']) && (string)$loc['type'] === 'police') {
            $newParent = (int)$_POST['parent_id'];
            if ($newParent > 0 && $newParent !== (int)($loc['parent_id'] ?? 0)) {
                // Kód police už může cílovému regálu odpovídat (police se jen vrací
                // tam, odkud byla) — pak se nepřečísluje a číslo se zbytečně nespálí.
                $pc = $pdo->prepare("SELECT code FROM stock_locations WHERE id = ?");
                $pc->execute([$newParent]);
                $parentCode = (string)$pc->fetchColumn();
                if ($parentCode !== '' && strpos((string)$loc['code'], $parentCode . '-P') !== 0) {
                    $needCode = true;
                }
            }
        }
        if (!$set && !$needCode) { throw new Exception('Není co měnit.'); }

        // Přidělení kódu i ZÁPIS pod jedním zámkem — kdyby se zámek pustil hned po
        // výpočtu, dva souběžné přesuny na týž regál by dostaly stejný kód a druhý
        // by spadl na UNIQUE se syrovou SQL hláškou.
        if ($needCode) { _locLock($pdo); }
        try {
            if ($needCode) {
                $newCode = nextStockLocationCode($pdo, 'police', (int)$_POST['parent_id'], (int)($loc['branch_id'] ?? 0) ?: getDefaultBranchId());
                $set[] = 'code = ?'; $vals[] = $newCode;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE stock_locations SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        } finally {
            if ($needCode) { _locUnlock($pdo); }
        }
        crmAuditLog('location.update', [
            'entity_type' => 'stock_location', 'entity_id' => $id,
            'entity_label' => $newCode ?? (string)$loc['code'],
            'summary' => 'Upraveno umístění ' . $loc['code']
                . ($newCode !== null ? ' → nový kód ' . $newCode : '')
                . (isset($_POST['parent_id']) ? ' (přesun/nadřazení)' : '')
                . (isset($_POST['is_active']) ? ((int)$_POST['is_active'] ? ' (aktivováno)' : ' (deaktivováno)') : ''),
        ]);
        echo json_encode(['success' => true,
            'message' => $newCode !== null
                ? 'Police přesunuta a má nový kód ' . $newCode . ' — VYTISKNI JÍ NOVÝ ŠTÍTEK (starý už neplatí).'
                : 'Umístění ' . $loc['code'] . ' uloženo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM stock_locations WHERE id = ?");
        $st->execute([$id]);
        $loc = $st->fetch();
        if (!$loc) { throw new Exception('Umístění nenalezeno.'); }
        _locRequireBranch((int)($loc['branch_id'] ?? 0) ?: getDefaultBranchId());
        $kids = $pdo->prepare("SELECT COUNT(*) FROM stock_locations WHERE parent_id = ?");
        $kids->execute([$id]);
        $kidCount = (int)$kids->fetchColumn();
        $parts = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE location_id = ?");
        $parts->execute([$id]);
        $partCount = (int)$parts->fetchColumn();
        if ($kidCount > 0 || $partCount > 0) {
            throw new Exception('Umístění ' . $loc['code'] . ' není prázdné (' . $partCount . ' dílů, ' . $kidCount . ' podřízených) — nejdřív obsah přesuň, nebo umístění jen deaktivuj.');
        }
        $pdo->prepare("DELETE FROM stock_locations WHERE id = ?")->execute([$id]);
        crmAuditLog('location.delete', [
            'entity_type' => 'stock_location', 'entity_id' => $id, 'entity_label' => (string)$loc['code'],
            'summary' => 'Smazáno prázdné umístění ' . $loc['code'] . ($loc['name'] !== '' ? ' („' . $loc['name'] . '")' : ''),
        ]);
        echo json_encode(['success' => true, 'message' => 'Umístění ' . $loc['code'] . ' smazáno.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'assign') {
        $ids = _reqIds($_POST['inventory_ids'] ?? '');
        if (!$ids) { throw new Exception('Nejsou vybrané žádné díly.'); }
        $locId = (int)($_POST['location_id'] ?? 0);
        $loc = null;
        if ($locId > 0) {
            $st = $pdo->prepare("SELECT * FROM stock_locations WHERE id = ? AND is_active = 1");
            $st->execute([$locId]);
            $loc = $st->fetch();
            if (!$loc) { throw new Exception('Cílové umístění nenalezeno (nebo je deaktivované).'); }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Díl se smí uložit jen do umístění SVÉ pobočky — jinak by díl z Karlína
        // „ležel" v krabičce Na Příkopě (a v inventuře by chyběl na obou místech).
        // POZOR: nesmí se to poznávat podle rowCount() — MySQL vrací počet SKUTEČNĚ
        // změněných řádků, takže přiřazení do umístění, kde díly už leží, by vypadalo
        // jako chyba. Proto se pobočka ověří samostatným dotazem PŘED zápisem.
        $locBranchSql = '';
        $locBranchParams = [];
        if ($loc) {
            $locBranch = (int)($loc['branch_id'] ?? 0) ?: getDefaultBranchId();
            $locBranchSql = ' AND branch_id = ?';
            $locBranchParams[] = $locBranch;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE id IN ($ph) AND branch_id = ?" . $__invBranchScope);
            $chk->execute(array_merge($ids, [$locBranch]));
            $fit = (int)$chk->fetchColumn();
            if ($fit === 0) {
                throw new Exception('Vybrané díly patří jiné pobočce než umístění ' . $loc['code'] . '.');
            }
        }
        $upd = $pdo->prepare("UPDATE inventory SET location_id = ? WHERE id IN ($ph)" . $__invBranchScope . $locBranchSql);
        $upd->execute(array_merge([$locId > 0 ? $locId : null], $ids, $locBranchParams));
        if (!$loc) {
            // i odebrání umístění se počítá poctivě — UPDATE má pobočkový filtr,
            // takže „odebráno 5 dílům" by u cizích dílů lhalo
            $chk0 = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE id IN ($ph)" . $__invBranchScope);
            $chk0->execute($ids);
            $fit = (int)$chk0->fetchColumn();
        }
        $done = $fit;
        $skipped = count($ids) - $done;
        $label = $loc ? $loc['code'] . (trim((string)$loc['name']) !== '' ? ' · ' . $loc['name'] : '') : null;
        crmAuditLog('inventory.assign_location', [
            'entity_type' => 'stock_location', 'entity_id' => $locId, 'entity_label' => $label ?: '—',
            'summary' => $loc
                ? 'Přiřazeno ' . $done . ' dílů do umístění ' . $label . ($skipped > 0 ? ' (' . $skipped . ' vynecháno — jiná pobočka)' : '')
                : 'Odebráno umístění ' . $done . ' dílům',
        ]);
        echo json_encode(['success' => true, 'count' => $done,
            'message' => ($loc ? ('Přiřazeno ' . $done . ' dílů do ' . $label . '.') : ('Umístění odebráno ' . $done . ' dílům.'))
                . ($skipped > 0 ? ' ' . $skipped . ' dílů vynecháno — patří jiné pobočce.' : '')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'set_model') {
        $ids = _reqIds($_POST['inventory_ids'] ?? '');
        if (!$ids) { throw new Exception('Nejsou vybrané žádné díly.'); }
        $model = mb_substr(trim((string)($_POST['device_model'] ?? '')), 0, 64);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE inventory SET device_model = ? WHERE id IN ($ph)" . $__invBranchScope)
            ->execute(array_merge([$model !== '' ? $model : null], $ids));
        crmAuditLog('inventory.set_model', [
            'entity_type' => 'inventory', 'entity_id' => $ids[0], 'entity_label' => $model !== '' ? $model : '—',
            'summary' => $model !== ''
                ? 'Model „' . $model . '" nastaven u ' . count($ids) . ' dílů'
                : 'Model odebrán u ' . count($ids) . ' dílů',
        ]);
        echo json_encode(['success' => true, 'count' => count($ids),
            'message' => $model !== '' ? ('Model „' . $model . '" nastaven u ' . count($ids) . ' dílů.') : ('Model odebrán u ' . count($ids) . ' dílů.')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'map_layout') {
        // Rozmístění regálů v 3D mapě (secure/warehouse3d.html) — jen souřadnice/rotace
        // pro vykreslení, strukturu skladu NEmění. Klíčované číslem regálu z kódu (RegK1 → 1).
        _locRequireBranch($branchId);
        $data = json_decode((string)($_POST['layout'] ?? ''), true);
        if (!is_array($data) || !isset($data['racks']) || !is_array($data['racks'])) {
            throw new Exception('Neplatná data rozmístění.');
        }
        $clean = [];
        $i = 0;
        foreach ($data['racks'] as $n => $r) {
            if (++$i > 100 || !is_array($r)) { continue; }
            $n = (int)$n;
            if ($n <= 0) { continue; }
            $clean[$n] = [
                'x' => max(0, min(740, (int)($r['x'] ?? 0))),
                'y' => max(0, min(560, (int)($r['y'] ?? 0))),
                'rot' => !empty($r['rot']) ? 1 : 0,
            ];
        }
        set_setting('warehouse3d_layout_' . $branchId, json_encode(['racks' => $clean], JSON_UNESCAPED_UNICODE));
        crmAuditLog('location.update', [
            'entity_type' => 'stock_location', 'entity_id' => 0, 'entity_label' => '3D mapa',
            'summary' => 'Uloženo rozmístění 3D mapy skladu (' . skladBranchLabel($branchId) . ', ' . count($clean) . ' regálů)',
        ]);
        echo json_encode(['success' => true, 'message' => 'Rozmístění mapy uloženo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Neznámá operace.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
