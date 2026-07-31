<?php
/**
 * SKLAD → UMÍSTĚNÍ — správa fyzické organizace skladu dílů.
 * Strom: regály (R1) → police (R1-P2) → krabičky (K001…).
 * Krabička má trvalý kód; přesun na jinou polici = jen změna tady v CRM,
 * štítek na krabičce se nikdy nepřetiskuje. Štítky: location_labels.php.
 * Obsah umístění: inventory.php?location=<id> (desktop) / sklad.php?loc=<id> (mobil, QR).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureStockLocationsSchema();
ensureInventoryStockedSchema();
ensureSkladBranchSchema();

$locs = stockLocationsAll($pdo, false);   // včetně deaktivovaných

// počty dílů / kusů na umístění
$counts = [];
try {
    foreach ($pdo->query("SELECT location_id, COUNT(*) c, COALESCE(SUM(quantity),0) q FROM inventory WHERE location_id IS NOT NULL GROUP BY location_id") as $r) {
        $counts[(int)$r['location_id']] = ['c' => (int)$r['c'], 'q' => (int)$r['q']];
    }
} catch (Throwable $e) {}
$unplaced = 0;
try { $unplaced = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE location_id IS NULL AND " . inventoryStockedWhereSql())->fetchColumn(); } catch (Throwable $e) {}

$regaly = []; $policeByParent = []; $boxByParent = []; $inactive = [];
$typeTotals = ['regal' => 0, 'police' => 0, 'krabicka' => 0];
foreach ($locs as $l) {
    if (!(int)$l['is_active']) { $inactive[] = $l; continue; }
    $typeTotals[$l['type']] = ($typeTotals[$l['type']] ?? 0) + 1;
    $pid = (int)($l['parent_id'] ?? 0);
    if ($l['type'] === 'regal') { $regaly[] = $l; }
    elseif ($l['type'] === 'police') { $policeByParent[$pid][] = $l; }
    else { $boxByParent[$pid][] = $l; }
}

/** řádek krabičky/police v přehledu */
function locRow(array $l, array $counts): void {
    $cnt = $counts[(int)$l['id']] ?? ['c' => 0, 'q' => 0];
    $isBox = $l['type'] === 'krabicka'; ?>
    <div class="d-flex align-items-center gap-2 py-2 border-bottom border-secondary border-opacity-25 loc-row">
        <span class="badge <?php echo $isBox ? 'bg-info text-dark' : 'bg-secondary'; ?>" style="min-width:56px;"><?php echo e($l['code']); ?></span>
        <div class="flex-grow-1 min-w-0">
            <span class="text-white"><?php echo trim((string)$l['name']) !== '' ? e($l['name']) : '<span class="text-white-75">bez názvu</span>'; ?></span>
            <?php if (trim((string)($l['note'] ?? '')) !== ''): ?><div class="small text-white-75 text-truncate"><?php echo e($l['note']); ?></div><?php endif; ?>
        </div>
        <span class="small text-white-75 text-nowrap"><?php echo $cnt['c']; ?> dílů · <?php echo $cnt['q']; ?> ks</span>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-white border" href="inventory.php?location=<?php echo (int)$l['id']; ?>" title="Obsah umístění"><i class="fas fa-box-open text-info"></i></a>
            <a class="btn btn-white border" href="location_labels.php?id=<?php echo (int)$l['id']; ?>" target="_blank" title="Vytisknout štítek"><i class="fas fa-qrcode text-info"></i></a>
            <button type="button" class="btn btn-white border loc-edit" data-loc="<?php echo (int)$l['id']; ?>" title="Upravit / přesunout"><i class="fas fa-edit text-warning"></i></button>
        </div>
    </div>
<?php }
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-0">Umístění skladu</h2>
        <small class="text-muted"><?php echo $typeTotals['regal']; ?> regálů · <?php echo $typeTotals['police']; ?> polic · <?php echo $typeTotals['krabicka']; ?> krabiček</small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <?php if ($unplaced > 0): ?>
            <a href="inventory.php?location=none" class="badge bg-warning text-dark text-decoration-none me-2" title="Otevře sklad s filtrem na neumístěné díly"><?php echo $unplaced; ?> dílů bez umístění</a>
        <?php endif; ?>
        <a href="location_labels.php?all=1" target="_blank" class="btn btn-outline-info"><i class="fas fa-qrcode me-2"></i> Arch štítků</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLocModal"><i class="fas fa-plus me-2"></i> Přidat umístění</button>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<div class="alert alert-info border-0 mb-4">
    <i class="fas fa-lightbulb me-2"></i>
    Krabička má <b>trvalý kód</b> (K001…) — štítek tiskneš jen jednou. Když ji přestěhuješ na jinou polici, změň jí tady jen <b>pozici</b> (tužtička → Umístit na).
    Obsah krabičky zobrazíš i mobilem: naskenuj <b>QR na jejím štítku</b>.
</div>

<div class="row g-4">
<?php if (!$regaly && !$boxByParent && !$policeByParent): ?>
    <div class="col-12">
        <div class="glass-panel p-5 border-secondary text-center text-white-75">
            <i class="fas fa-map-location-dot fa-3x mb-3 d-block opacity-25"></i>
            Zatím žádná umístění. Začni tlačítkem <b>Přidat umístění</b> — založ regály, na ně police a do nich krabičky.
        </div>
    </div>
<?php endif; ?>

<?php foreach ($regaly as $r): $rid = (int)$r['id']; $rcnt = $counts[$rid] ?? ['c' => 0, 'q' => 0]; ?>
    <div class="col-12 col-xl-6">
        <div class="glass-panel p-3 border-secondary h-100">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-primary fs-6"><?php echo e($r['code']); ?></span>
                <div class="fw-bold text-white flex-grow-1"><?php echo trim((string)$r['name']) !== '' ? e($r['name']) : 'Regál'; ?></div>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-white border" href="location_labels.php?id=<?php echo $rid; ?>" target="_blank" title="Štítek regálu"><i class="fas fa-qrcode text-info"></i></a>
                    <button type="button" class="btn btn-white border loc-edit" data-loc="<?php echo $rid; ?>" title="Upravit"><i class="fas fa-edit text-warning"></i></button>
                    <button type="button" class="btn btn-white border loc-add-child" data-parent="<?php echo $rid; ?>" data-type="police" title="Přidat polici"><i class="fas fa-plus text-success"></i></button>
                </div>
            </div>
            <?php if ($rcnt['c'] > 0): ?><div class="small text-white-75 mb-2">Přímo na regálu: <?php echo $rcnt['c']; ?> dílů</div><?php endif; ?>

            <?php foreach ($policeByParent[$rid] ?? [] as $p): $pid = (int)$p['id']; ?>
                <div class="ps-2 border-start border-secondary border-opacity-50 mb-2">
                    <?php locRow($p, $counts); ?>
                    <div class="ps-4">
                        <?php foreach ($boxByParent[$pid] ?? [] as $b) { locRow($b, $counts); } ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary my-2 loc-add-child" data-parent="<?php echo $pid; ?>" data-type="krabicka"><i class="fas fa-plus me-1"></i> krabička na <?php echo e($p['code']); ?></button>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($boxByParent[$rid] ?? [] as $b) { locRow($b, $counts); } ?>
        </div>
    </div>
<?php endforeach; ?>

<?php $loosePolice = $policeByParent[0] ?? []; $looseBoxes = $boxByParent[0] ?? []; ?>
<?php if ($loosePolice || $looseBoxes): ?>
    <div class="col-12 col-xl-6">
        <div class="glass-panel p-3 border-secondary h-100">
            <div class="fw-bold text-white mb-2"><i class="fas fa-box me-2 text-warning"></i>Bez pozice (zatím nikam nezařazené)</div>
            <?php foreach ($loosePolice as $p) { locRow($p, $counts); } ?>
            <?php foreach ($looseBoxes as $b) { locRow($b, $counts); } ?>
            <div class="small text-white-75 mt-2">Tužtičkou u řádku jim přiřaď regál/polici.</div>
        </div>
    </div>
<?php endif; ?>

<?php if ($inactive): ?>
    <div class="col-12">
        <div class="glass-panel p-3 border-secondary">
            <div class="fw-semibold text-white-75 mb-2"><i class="fas fa-box-archive me-2"></i>Deaktivovaná umístění</div>
            <?php foreach ($inactive as $l): $cnt = $counts[(int)$l['id']] ?? ['c' => 0, 'q' => 0]; ?>
                <div class="d-flex align-items-center gap-2 py-1 small text-white-75">
                    <span class="badge bg-secondary opacity-50"><?php echo e($l['code']); ?></span>
                    <span class="flex-grow-1"><?php echo e($l['name']); ?> (<?php echo $cnt['c']; ?> dílů)</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary loc-reactivate" data-loc="<?php echo (int)$l['id']; ?>">Aktivovat</button>
                    <button type="button" class="btn btn-sm btn-outline-danger loc-delete" data-loc="<?php echo (int)$l['id']; ?>" data-code="<?php echo e($l['code']); ?>">Smazat</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<?php /* ── modal: nové umístění ── */ ?>
<div class="modal fade" id="newLocModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-map-location-dot me-2 text-info"></i>Přidat umístění</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Typ</label>
                        <select id="newLocType" class="form-select">
                            <option value="krabicka">Krabička (K001…)</option>
                            <option value="police">Police (R1-P1…)</option>
                            <option value="regal">Regál (R1…)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Počet najednou</label>
                        <input type="number" id="newLocCount" class="form-control" value="1" min="1" max="50">
                    </div>
                    <div class="col-12" id="newLocParentWrap">
                        <label class="form-label">Umístit na (regál / police)</label>
                        <select id="newLocParent" class="form-select"></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Název (např. „iPhone 12 – drobné díly")</label>
                        <input type="text" id="newLocName" class="form-control" maxlength="120" placeholder="nepovinné — u více kusů se čísluje">
                    </div>
                    <div class="col-12 small text-muted">Kód se přidělí automaticky (další volný v řadě) a už se nemění.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>
                <button type="button" class="btn btn-primary" id="newLocSave">Založit</button>
            </div>
        </div>
    </div>
</div>

<?php /* ── modal: úprava umístění ── */ ?>
<div class="modal fade" id="editLocModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2 text-warning"></i>Upravit <span id="editLocCode" class="badge bg-info text-dark ms-1"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editLocId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Název</label>
                        <input type="text" id="editLocName" class="form-control" maxlength="120">
                    </div>
                    <div class="col-12" id="editLocParentWrap">
                        <label class="form-label">Umístit na</label>
                        <select id="editLocParent" class="form-select"></select>
                        <div class="form-text">Přestěhovaná krabička = jen tahle změna. Štítek zůstává platný.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Poznámka</label>
                        <input type="text" id="editLocNote" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12 form-check ms-2">
                        <input type="checkbox" id="editLocActive" class="form-check-input">
                        <label class="form-check-label" for="editLocActive">Aktivní (nabízí se při přiřazování)</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger" id="editLocDelete"><i class="fas fa-trash me-1"></i> Smazat</button>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>
                    <button type="button" class="btn btn-primary" id="editLocSave">Uložit</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const LOCS = <?php echo json_encode(array_map(fn($l) => [
    'id' => (int)$l['id'], 'code' => (string)$l['code'], 'name' => (string)$l['name'],
    'type' => (string)$l['type'], 'parent_id' => (int)($l['parent_id'] ?? 0), 'note' => (string)($l['note'] ?? ''),
    'is_active' => (int)$l['is_active'],
], $locs), JSON_UNESCAPED_UNICODE); ?>;
const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

function locPost(data, cb) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fd.append('csrf_token', CSRF);
    fetch('api/stock_locations.php', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(r => r.json())
        .then(d => { if (d.success) { cb ? cb(d) : location.reload(); } else { showAlert(d.message || 'Chyba'); } })
        .catch(() => showAlert('Síťová chyba.'));
}

// nabídka rodičů podle typu: police → regály; krabička → police + regály
function parentOptions(type, selected) {
    const groups = type === 'police'
        ? [['Regály', LOCS.filter(l => l.type === 'regal' && l.is_active)]]
        : [['Police', LOCS.filter(l => l.type === 'police' && l.is_active)],
           ['Regály (přímo)', LOCS.filter(l => l.type === 'regal' && l.is_active)]];
    let html = '<option value="0">— bez pozice —</option>';
    groups.forEach(([label, items]) => {
        if (!items.length) return;
        html += '<optgroup label="' + label + '">';
        items.forEach(l => {
            html += '<option value="' + l.id + '"' + (l.id === selected ? ' selected' : '') + '>' +
                l.code + (l.name ? ' · ' + l.name.replace(/</g, '&lt;') : '') + '</option>';
        });
        html += '</optgroup>';
    });
    return html;
}

// ── nové umístění ──
const newType = document.getElementById('newLocType');
function refreshNewParent() {
    const t = newType.value;
    document.getElementById('newLocParentWrap').style.display = t === 'regal' ? 'none' : '';
    document.getElementById('newLocParent').innerHTML = parentOptions(t, 0);
}
newType.addEventListener('change', refreshNewParent);
refreshNewParent();

document.getElementById('newLocSave').addEventListener('click', function () {
    locPost({
        op: 'create', type: newType.value,
        parent_id: document.getElementById('newLocParent').value || 0,
        name: document.getElementById('newLocName').value,
        count: document.getElementById('newLocCount').value || 1
    });
});

// „+ police / + krabička" přímo z karty regálu/police
document.querySelectorAll('.loc-add-child').forEach(btn => btn.addEventListener('click', function () {
    newType.value = this.dataset.type;
    refreshNewParent();
    document.getElementById('newLocParent').value = this.dataset.parent;
    new bootstrap.Modal(document.getElementById('newLocModal')).show();
}));

// ── úprava ──
document.querySelectorAll('.loc-edit').forEach(btn => btn.addEventListener('click', function () {
    const l = LOCS.find(x => x.id === parseInt(this.dataset.loc, 10));
    if (!l) return;
    document.getElementById('editLocId').value = l.id;
    document.getElementById('editLocCode').textContent = l.code;
    document.getElementById('editLocName').value = l.name;
    document.getElementById('editLocNote').value = l.note;
    document.getElementById('editLocActive').checked = !!l.is_active;
    const wrap = document.getElementById('editLocParentWrap');
    wrap.style.display = l.type === 'regal' ? 'none' : '';
    document.getElementById('editLocParent').innerHTML = parentOptions(l.type, l.parent_id);
    new bootstrap.Modal(document.getElementById('editLocModal')).show();
}));

document.getElementById('editLocSave').addEventListener('click', function () {
    const id = document.getElementById('editLocId').value;
    locPost({
        op: 'update', id: id,
        name: document.getElementById('editLocName').value,
        note: document.getElementById('editLocNote').value,
        parent_id: document.getElementById('editLocParent').value || 0,
        is_active: document.getElementById('editLocActive').checked ? 1 : 0
    });
});

document.getElementById('editLocDelete').addEventListener('click', function () {
    const id = document.getElementById('editLocId').value;
    const l = LOCS.find(x => x.id === parseInt(id, 10));
    showConfirm('Smazat umístění ' + (l ? l.code : '#' + id) + '? Jde to jen u prázdného.', function () {
        locPost({op: 'delete', id: id});
    });
});

document.querySelectorAll('.loc-reactivate').forEach(btn => btn.addEventListener('click', function () {
    locPost({op: 'update', id: this.dataset.loc, is_active: 1});
}));
document.querySelectorAll('.loc-delete').forEach(btn => btn.addEventListener('click', function () {
    const code = this.dataset.code, id = this.dataset.loc;
    showConfirm('Smazat umístění ' + code + '? Jde to jen u prázdného.', function () {
        locPost({op: 'delete', id: id});
    });
}));
</script>

<?php require_once 'includes/footer.php'; ?>
