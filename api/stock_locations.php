<?php
/**
 * Umístění skladu (regály / police / krabičky) + přiřazování dílů:
 *   op=create    — nové umístění (type, name, parent_id, count 1–50 → hromadně)
 *   op=update    — přejmenování / přesun na jinou polici (parent_id) / poznámka / (de)aktivace
 *   op=delete    — smazání prázdného umístění (jinak poradí deaktivaci)
 *   op=assign    — hromadné přiřazení dílů do umístění (location_id=0 → odebrat umístění)
 *   op=set_model — hromadné nastavení modelu zařízení u dílů (prázdný model → smazat)
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

$op = (string)($_POST['op'] ?? '');

/** id z POST (pole i CSV „1,2,3") → unikátní kladné inty */
function _reqIds($raw): array {
    $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
    $out = [];
    foreach ($ids as $i) { $i = (int)$i; if ($i > 0) { $out[$i] = $i; } }
    return array_values($out);
}

try {
    if ($op === 'create') {
        $type = (string)($_POST['type'] ?? 'krabicka');
        if (!in_array($type, ['regal', 'police', 'krabicka'], true)) { throw new Exception('Neznámý typ umístění.'); }
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $count = max(1, min(50, (int)($_POST['count'] ?? 1)));
        if ($type === 'regal') { $parentId = 0; }
        if ($parentId > 0) {
            $p = $pdo->prepare("SELECT id, type FROM stock_locations WHERE id = ?");
            $p->execute([$parentId]);
            $parent = $p->fetch();
            if (!$parent) { throw new Exception('Nadřazené umístění nenalezeno.'); }
            if ($type === 'police' && $parent['type'] !== 'regal') { throw new Exception('Police patří na regál.'); }
            if ($type === 'krabicka' && $parent['type'] === 'krabicka') { throw new Exception('Krabička nemůže být uvnitř jiné krabičky.'); }
        }
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $code = nextStockLocationCode($pdo, $type, $parentId);
            $rowName = ($count > 1 && $name !== '') ? $name . ' ' . ($i + 1) : $name;
            $pdo->prepare("INSERT INTO stock_locations (code, name, type, parent_id) VALUES (?, ?, ?, ?)")
                ->execute([$code, $rowName, $type, $parentId > 0 ? $parentId : null]);
            $created[] = ['id' => (int)$pdo->lastInsertId(), 'code' => $code];
        }
        $codes = implode(', ', array_column($created, 'code'));
        crmAuditLog('location.create', [
            'entity_type' => 'stock_location', 'entity_id' => $created[0]['id'], 'entity_label' => $codes,
            'summary' => 'Založeno umístění skladu: ' . stockLocationTypeLabel($type) . ' ' . $codes . ($name !== '' ? ' („' . $name . '")' : ''),
        ]);
        echo json_encode(['success' => true, 'created' => $created,
            'message' => count($created) === 1 ? 'Založeno umístění ' . $created[0]['code'] . '.' : 'Založeno ' . count($created) . ' umístění (' . $codes . ').'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM stock_locations WHERE id = ?");
        $st->execute([$id]);
        $loc = $st->fetch();
        if (!$loc) { throw new Exception('Umístění nenalezeno.'); }

        $set = []; $vals = [];
        if (isset($_POST['name'])) { $set[] = 'name = ?'; $vals[] = mb_substr(trim((string)$_POST['name']), 0, 120); }
        if (isset($_POST['note'])) { $set[] = 'note = ?'; $vals[] = ($n = mb_substr(trim((string)$_POST['note']), 0, 255)) !== '' ? $n : null; }
        if (isset($_POST['is_active'])) { $set[] = 'is_active = ?'; $vals[] = (int)!!(int)$_POST['is_active']; }
        if (isset($_POST['parent_id'])) {
            $parentId = (int)$_POST['parent_id'];
            if ($parentId === $id) { throw new Exception('Umístění nemůže být samo sobě rodičem.'); }
            if ($loc['type'] === 'regal') { $parentId = 0; }
            if ($parentId > 0) {
                $p = $pdo->prepare("SELECT id, type FROM stock_locations WHERE id = ?");
                $p->execute([$parentId]);
                $parent = $p->fetch();
                if (!$parent) { throw new Exception('Nadřazené umístění nenalezeno.'); }
                if ($loc['type'] === 'police' && $parent['type'] !== 'regal') { throw new Exception('Police patří na regál.'); }
                if ($loc['type'] === 'krabicka' && $parent['type'] === 'krabicka') { throw new Exception('Krabička nemůže být uvnitř jiné krabičky.'); }
            }
            $set[] = 'parent_id = ?'; $vals[] = $parentId > 0 ? $parentId : null;
        }
        if (!$set) { throw new Exception('Není co měnit.'); }
        $vals[] = $id;
        $pdo->prepare("UPDATE stock_locations SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        crmAuditLog('location.update', [
            'entity_type' => 'stock_location', 'entity_id' => $id, 'entity_label' => (string)$loc['code'],
            'summary' => 'Upraveno umístění ' . $loc['code'] . (isset($_POST['parent_id']) ? ' (přesun/nadřazení)' : '') . (isset($_POST['is_active']) ? ((int)$_POST['is_active'] ? ' (aktivováno)' : ' (deaktivováno)') : ''),
        ]);
        echo json_encode(['success' => true, 'message' => 'Umístění ' . $loc['code'] . ' uloženo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM stock_locations WHERE id = ?");
        $st->execute([$id]);
        $loc = $st->fetch();
        if (!$loc) { throw new Exception('Umístění nenalezeno.'); }
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
        $pdo->prepare("UPDATE inventory SET location_id = ? WHERE id IN ($ph)" . $__invBranchScope)
            ->execute(array_merge([$locId > 0 ? $locId : null], $ids));
        $label = $loc ? $loc['code'] . (trim((string)$loc['name']) !== '' ? ' · ' . $loc['name'] : '') : null;
        crmAuditLog('inventory.assign_location', [
            'entity_type' => 'stock_location', 'entity_id' => $locId, 'entity_label' => $label ?: '—',
            'summary' => $loc
                ? 'Přiřazeno ' . count($ids) . ' dílů do umístění ' . $label
                : 'Odebráno umístění ' . count($ids) . ' dílům',
        ]);
        echo json_encode(['success' => true, 'count' => count($ids),
            'message' => $loc ? ('Přiřazeno ' . count($ids) . ' dílů do ' . $label . '.') : ('Umístění odebráno ' . count($ids) . ' dílům.')], JSON_UNESCAPED_UNICODE);
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

    throw new Exception('Neznámá operace.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
