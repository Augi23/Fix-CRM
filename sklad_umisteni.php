<?php
/**
 * SKLAD → UMÍSTĚNÍ — správa fyzické organizace skladu dílů.
 * Strom: regály (RegK1) → police (RegK1-P2) → krabičky (KrK001…) — kód nese
 * zkratku pobočky, každá provozovna má vlastní řadu od jedničky.
 * Krabička má trvalý kód; přesun na jinou polici = jen změna tady v CRM,
 * štítek na krabičce se nikdy nepřetiskuje. POLICE je jiný případ: její kód
 * obsahuje regál, takže po přesunu na jiný regál se přečísluje (nový štítek). Štítky: location_labels.php.
 * Obsah umístění: inventory.php?location=<id> (desktop) / sklad.php?loc=<id> (mobil, QR).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureStockLocationsSchema();
ensureInventoryStockedSchema();
ensureSkladBranchSchema();

// Sklad je POBOČKOVÝ: každá provozovna má vlastní regály, police i krabičky.
// Vidět je smí každý (přepínač poboček nad záložkami), měnit jen zaměstnanec
// té pobočky — a admin/Boss všude (crmCanModifyBranchStock).
$branchId = (int)skladBranchOrOwn();
$canEdit = crmCanModifyBranchStock($branchId);

$locs = stockLocationsAll($pdo, false, $branchId);   // včetně deaktivovaných

// počty dílů / kusů na umístění (jen díly téhle pobočky)
$counts = [];
try {
    $cq = $pdo->prepare("SELECT location_id, COUNT(*) c, COALESCE(SUM(quantity),0) q FROM inventory
                         WHERE location_id IS NOT NULL AND branch_id = ? GROUP BY location_id");
    $cq->execute([$branchId]);
    foreach ($cq as $r) {
        $counts[(int)$r['location_id']] = ['c' => (int)$r['c'], 'q' => (int)$r['q']];
    }
} catch (Throwable $e) {}
$unplaced = 0;
try {
    $uq = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE location_id IS NULL AND branch_id = ? AND " . inventoryStockedWhereSql());
    $uq->execute([$branchId]);
    $unplaced = (int)$uq->fetchColumn();
} catch (Throwable $e) {}

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
function locRow(array $l, array $counts, bool $canEdit, int $branchId): void {
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
            <a class="btn btn-white border" href="inventory.php?branch=<?php echo $branchId; ?>&amp;location=<?php echo (int)$l['id']; ?>" title="Obsah umístění"><i class="fas fa-box-open text-info"></i></a>
            <a class="btn btn-white border" href="location_labels.php?id=<?php echo (int)$l['id']; ?>&amp;branch=<?php echo $branchId; ?>" target="_blank" title="Vytisknout štítek"><i class="fas fa-qrcode text-info"></i></a>
            <?php if ($canEdit): ?><button type="button" class="btn btn-white border loc-edit" data-loc="<?php echo (int)$l['id']; ?>" title="Upravit / přesunout"><i class="fas fa-edit text-warning"></i></button><?php endif; ?>
        </div>
    </div>
<?php }
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-0">Umístění skladu <span class="fs-6 text-white-50"><?php echo e(skladBranchLabel($branchId)); ?></span></h2>
        <small class="text-muted"><?php echo $typeTotals['regal']; ?> regálů · <?php echo $typeTotals['police']; ?> polic · <?php echo $typeTotals['krabicka']; ?> krabiček</small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <?php if ($unplaced > 0): ?>
            <a href="inventory.php?branch=<?php echo (int)$branchId; ?>&amp;location=none" class="badge bg-warning text-dark text-decoration-none me-2" title="Otevře sklad s filtrem na neumístěné díly"><?php echo $unplaced; ?> dílů bez umístění</a>
        <?php endif; ?>
        <a href="sklad_mapa.php?branch=<?php echo (int)$branchId; ?>" class="btn btn-outline-info" title="3D vizualizace rozložení skladu"><i class="fas fa-cube me-2"></i> 3D mapa</a>
        <?php $__walkIds = skladWalkSequence($pdo, $branchId); ?>
        <?php if ($__walkIds): ?>
        <a href="sklad.php?loc=<?php echo (int)$__walkIds[0]; ?>" class="btn btn-outline-success" title="Naskladňovací kolečko: CRM tě provede police a krabičky jednu po druhé (Předchozí/Další) a v každé rovnou zapíšeš obsah"><i class="fas fa-person-walking me-2"></i> Projít sklad</a>
        <?php endif; ?>
        <a href="location_labels.php?all=1&amp;branch=<?php echo (int)$branchId; ?>" target="_blank" class="btn btn-outline-info"><i class="fas fa-qrcode me-2"></i> Arch štítků</a>
        <?php if ($canEdit): ?>
        <?php
        // šuplíkové boxy (stěna u vchodu) — nabízet založení, jen dokud neexistují
        $__hasDrawers = false;
        foreach ($locs as $__dl) {
            if ($__dl['type'] === 'regal' && (int)$__dl['is_active'] && mb_stripos((string)$__dl['name'], 'uplík') !== false) { $__hasDrawers = true; break; }
        }
        ?>
        <?php if (!$__hasDrawers): ?>
        <button class="btn btn-outline-danger" id="setupDrawersBtn" title="Založí stěnu šuplíkových boxů u vchodu: 6 boxů × 8 šuplíků (regál „Šuplíkové boxy")"><i class="fas fa-inbox me-2"></i> Založit šuplíkové boxy</button>
        <?php endif; ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#setupLocModal"><i class="fas fa-wand-magic-sparkles me-2"></i> Rychlé nastavení skladu</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLocModal"><i class="fas fa-plus me-2"></i> Přidat umístění</button>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<?php if (!$canEdit): ?>
<div class="alert alert-secondary bg-transparent border-secondary py-2 px-3 mb-3 small">
    <i class="fas fa-eye me-1"></i>Sklad <b><?php echo e(skladBranchLabel($branchId)); ?></b> si prohlížíš —
    zakládat a měnit umístění smí jen zaměstnanci téhle pobočky (a vedení).
</div>
<?php endif; ?>

<div class="alert alert-info border-0 mb-4">
    <i class="fas fa-lightbulb me-2"></i>
    <b>Regál → police → krabička.</b> Díl nemusí být v krabičce — u dílu se v poli <b>Umístění</b> dá vybrat i police (nebo rovnou regál), když leží volně.
    Krabička má <b>trvalý kód</b> (Kr<?php echo e(skladBranchShort($branchId)); ?>001…) — štítek tiskneš jen jednou. Když ji přestěhuješ na jinou polici, změň jí tady jen <b>pozici</b> (tužtička → Umístit na).
    Obsah krabičky zobrazíš i mobilem: naskenuj <b>QR na jejím štítku</b>.
</div>

<div class="row g-4">
<?php if (!$regaly && !$boxByParent && !$policeByParent): ?>
    <div class="col-12">
        <div class="glass-panel p-5 border-secondary text-center text-white-75">
            <i class="fas fa-map-location-dot fa-3x mb-3 d-block opacity-25"></i>
            <div class="mb-3">Sklad <b><?php echo e(skladBranchLabel($branchId)); ?></b> zatím nemá žádná umístění.</div>
            <?php if ($canEdit): ?>
            <div class="mb-3 text-white-50">Nejrychlejší je <b>Rychlé nastavení</b>: řekneš, kolik máš regálů a kolik polic je v každém z nich — CRM je založí naráz i s čísly a QR štítky.</div>
            <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#setupLocModal"><i class="fas fa-wand-magic-sparkles me-2"></i>Rozvrhnout sklad</button>
            <?php else: ?>
            <div class="text-white-50">Umístění zakládají pracovníci téhle pobočky (nebo vedení).</div>
            <?php endif; ?>
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
                    <a class="btn btn-white border" href="location_labels.php?id=<?php echo $rid; ?>&amp;branch=<?php echo (int)$branchId; ?>" target="_blank" title="Štítek regálu"><i class="fas fa-qrcode text-info"></i></a>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-white border loc-edit" data-loc="<?php echo $rid; ?>" title="Upravit"><i class="fas fa-edit text-warning"></i></button><?php endif; ?>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-white border loc-add-child" data-parent="<?php echo $rid; ?>" data-type="police" title="Přidat polici na tenhle regál"><i class="fas fa-plus text-success me-1"></i>police</button><?php endif; ?>
                </div>
            </div>
            <?php if ($rcnt['c'] > 0): ?><div class="small text-white-75 mb-2">Přímo na regálu: <?php echo $rcnt['c']; ?> dílů</div><?php endif; ?>

            <?php foreach ($policeByParent[$rid] ?? [] as $p): $pid = (int)$p['id']; ?>
                <div class="ps-2 border-start border-secondary border-opacity-50 mb-2">
                    <?php locRow($p, $counts, $canEdit, $branchId); ?>
                    <div class="ps-4">
                        <?php foreach ($boxByParent[$pid] ?? [] as $b) { locRow($b, $counts, $canEdit, $branchId); } ?>
                        <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-secondary my-2 loc-add-child" data-parent="<?php echo $pid; ?>" data-type="krabicka"><i class="fas fa-plus me-1"></i> krabička na <?php echo e($p['code']); ?></button><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($boxByParent[$rid] ?? [] as $b) { locRow($b, $counts, $canEdit, $branchId); } ?>
        </div>
    </div>
<?php endforeach; ?>

<?php $loosePolice = $policeByParent[0] ?? []; $looseBoxes = $boxByParent[0] ?? []; ?>
<?php if ($loosePolice || $looseBoxes): ?>
    <div class="col-12 col-xl-6">
        <div class="glass-panel p-3 border-secondary h-100">
            <div class="fw-bold text-white mb-2"><i class="fas fa-box me-2 text-warning"></i>Bez pozice (zatím nikam nezařazené)</div>
            <?php foreach ($loosePolice as $p) { locRow($p, $counts, $canEdit, $branchId); } ?>
            <?php foreach ($looseBoxes as $b) { locRow($b, $counts, $canEdit, $branchId); } ?>
            <div class="small text-white-75 mt-2">Tužtičkou u řádku jim přiřaď polici nebo regál. (Police tady být nemají — pokud tu nějaká je, jde o starší data; přiřaď ji na regál, dostane nový kód.)</div>
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
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary loc-reactivate" data-loc="<?php echo (int)$l['id']; ?>">Aktivovat</button>
                    <button type="button" class="btn btn-sm btn-outline-danger loc-delete" data-loc="<?php echo (int)$l['id']; ?>" data-code="<?php echo e($l['code']); ?>">Smazat</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($canEdit): /* modály zakládání/úprav dávají smysl jen s právem měnit */ ?>
<?php /* ── modal: RYCHLÉ NASTAVENÍ — kostra skladu na pár kliknutí ── */ ?>
<div class="modal fade" id="setupLocModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-wand-magic-sparkles me-2 text-success"></i>Rychlé nastavení skladu — <?php echo e(skladBranchLabel($branchId)); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-white-75">Řekni, jak sklad fyzicky vypadá — CRM založí všechna umístění najednou, očísluje je (<b>Reg<?php echo e(skladBranchShort($branchId)); ?>1</b>, <b>Reg<?php echo e(skladBranchShort($branchId)); ?>1-P1</b>, <b>Kr<?php echo e(skladBranchShort($branchId)); ?>001</b>…) a připraví QR štítky k tisku.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Kolik regálů</label>
                        <input type="number" id="setRacks" class="form-control form-control-lg" value="<?php echo $typeTotals['regal'] > 0 ? 0 : 4; ?>" min="0" max="30">
                        <div class="form-text">nových, k těm stávajícím</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Polic v každém</label>
                        <input type="number" id="setShelves" class="form-control form-control-lg" value="5" min="0" max="30">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Krabiček na polici</label>
                        <input type="number" id="setBoxes" class="form-control form-control-lg" value="0" min="0" max="20">
                        <div class="form-text">0 = zatím žádné</div>
                    </div>
                    <?php if ($regaly): ?>
                    <div class="col-12">
                        <label class="form-label">Přidat police i do už založených regálů</label>
                        <select id="setIntoRacks" class="form-select" multiple size="<?php echo min(5, max(2, count($regaly))); ?>">
                            <?php foreach ($regaly as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['code']); ?><?php echo trim((string)$r['name']) !== '' ? ' · ' . e($r['name']) : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Nepovinné — drž Cmd/Ctrl pro víc regálů. Police se jim přidají za ty stávající.</div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <div class="alert alert-secondary bg-transparent border-secondary mb-0 py-2 px-3 small" id="setupPreview"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>
                <button type="button" class="btn btn-success" id="setupLocSave"><i class="fas fa-check me-1"></i>Založit</button>
            </div>
        </div>
    </div>
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
                            <?php $__sh = skladBranchShort($branchId); ?>
                            <option value="krabicka">Krabička (Kr<?php echo e($__sh); ?>001…)</option>
                            <option value="police">Police (Reg<?php echo e($__sh); ?>1-P1…)</option>
                            <option value="regal">Regál (Reg<?php echo e($__sh); ?>1…)</option>
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
                    <div class="col-12 small text-muted">Kód se přidělí automaticky a už se nemění. Nese <b>zkratku pobočky</b>, takže je ze štítku hned poznat, kam patří — <b>Reg<?php echo e($__sh); ?>1</b> / <b>Reg<?php echo e($__sh); ?>1-P2</b> / <b>Kr<?php echo e($__sh); ?>001</b>. Každá pobočka má vlastní řadu od jedničky.</div>
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
                        <div class="form-text" id="editLocParentHint">Přestěhovaná krabička = jen tahle změna. Štítek zůstává platný.</div>
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

<?php endif; ?>

<script>
const LOCS = <?php echo json_encode(array_map(fn($l) => [
    'id' => (int)$l['id'], 'code' => (string)$l['code'], 'name' => (string)$l['name'],
    'type' => (string)$l['type'], 'parent_id' => (int)($l['parent_id'] ?? 0), 'note' => (string)($l['note'] ?? ''),
    'is_active' => (int)$l['is_active'],
], $locs), JSON_UNESCAPED_UNICODE); ?>;
const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
const BRANCH_ID = <?php echo (int)$branchId; ?>;

function locPost(data, cb, onFail) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fd.append('csrf_token', CSRF);
    fetch('api/stock_locations.php', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(r => r.json())
        .then(d => {
            if (d.success) { cb ? cb(d) : location.reload(); }
            else { showAlert(d.message || 'Chyba'); if (onFail) { onFail(); } }
        })
        .catch(() => { showAlert('Síťová chyba.'); if (onFail) { onFail(); } });
}

// nabídka rodičů podle typu: police → regály; krabička → police + regály
function parentOptions(type, selected) {
    const groups = type === 'police'
        ? [['Regály', LOCS.filter(l => l.type === 'regal' && l.is_active)]]
        : [['Police', LOCS.filter(l => l.type === 'police' && l.is_active)],
           ['Regály (přímo)', LOCS.filter(l => l.type === 'regal' && l.is_active)]];
    // police musí mít regál (kód police se z něj odvozuje), krabička může viset volně
    let html = type === 'police' ? '<option value="0">— vyber regál —</option>' : '<option value="0">— bez pozice —</option>';
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

// ── rychlé nastavení skladu ──
(function () {
    const $r = document.getElementById('setRacks');
    if (!$r) { return; }                       // jen ke čtení (cizí pobočka)
    const $p = document.getElementById('setShelves');
    const $b = document.getElementById('setBoxes');
    const $into = document.getElementById('setIntoRacks');
    const $prev = document.getElementById('setupPreview');
    const $btn = document.getElementById('setupLocSave');

    function chosenRacks() {
        return $into ? Array.from($into.selectedOptions).map(o => o.value) : [];
    }
    function preview() {
        // stejné stropy jako server (30/30/20), ať náhled neslibuje víc, než vznikne
        const racks = Math.max(0, Math.min(30, parseInt($r.value, 10) || 0));
        const shelves = Math.max(0, Math.min(30, parseInt($p.value, 10) || 0));
        const boxes = Math.max(0, Math.min(20, parseInt($b.value, 10) || 0));
        const extra = chosenRacks().length;
        const nP = (racks + extra) * shelves;
        const nK = nP * boxes;
        const total = racks + nP + nK;
        const boxesNoShelf = (boxes > 0 && shelves === 0);
        $prev.innerHTML = boxesNoShelf
            ? '<span class="text-warning">Krabičky se zakládají do nových polic — zadej i počet polic.</span>'
            : (total === 0
                ? 'Zatím není co založit — zadej počet regálů (nebo vyber stávající a počet polic).'
                : 'Založí se <b>' + racks + '</b> regálů, <b>' + nP + '</b> polic a <b>' + nK + '</b> krabiček — celkem <b>' + total + '</b> umístění.'
                  + (total > 600 ? ' <span class="text-warning">Najednou jde max. 600 — rozděl to.</span>' : ''));
        $btn.disabled = (boxesNoShelf || total === 0 || total > 600);
    }
    [$r, $p, $b].forEach(el => el.addEventListener('input', preview));
    if ($into) { $into.addEventListener('change', preview); }
    preview();

    $btn.addEventListener('click', function () {
        // Tlačítko zůstává zamčené AŽ DO odpovědi serveru. Časovač by ho u velké
        // kostry (stovky umístění) odemkl dřív, než požadavek doběhne — a druhý
        // klik by založil celý sklad podruhé.
        $btn.disabled = true;
        $btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i>Zakládám…';
        locPost({
            op: 'setup',
            branch_id: BRANCH_ID,
            racks: $r.value || 0,
            shelves_per_rack: $p.value || 0,
            boxes_per_shelf: $b.value || 0,
            into_racks: chosenRacks().join(',')
        },
        function (d) { showAlert(d.message || 'Hotovo'); setTimeout(() => location.reload(), 700); },
        function () { $btn.innerHTML = '<i class="fas fa-check me-1"></i>Založit'; preview(); });
    });
})();

// ── šuplíkové boxy (6 × 8) jedním klikem ──
(function () {
    const b = document.getElementById('setupDrawersBtn');
    if (!b) { return; }
    b.addEventListener('click', function () {
        showConfirm('Založit stěnu šuplíkových boxů? Vznikne regál „Šuplíkové boxy" se 6 boxy (police) po 8 šuplících (krabičky) — celkem 55 umístění s QR štítky.', function () {
            b.disabled = true;
            b.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i>Zakládám…';
            locPost({op: 'setup_drawers', branch_id: BRANCH_ID});
        });
    });
})();

// ── nové umístění ──
const newType = document.getElementById('newLocType');
if (newType) {
function refreshNewParent() {
    const t = newType.value;
    document.getElementById('newLocParentWrap').style.display = t === 'regal' ? 'none' : '';
    document.getElementById('newLocParent').innerHTML = parentOptions(t, 0);
}
newType.addEventListener('change', refreshNewParent);
refreshNewParent();

document.getElementById('newLocSave').addEventListener('click', function () {
    locPost({
        op: 'create', type: newType.value, branch_id: BRANCH_ID,
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

}

// ── úprava (jen když je editační modal na stránce = mám právo měnit) ──
if (document.getElementById('editLocModal')) {
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
    const hint = document.getElementById('editLocParentHint');
    if (hint) {
        hint.innerHTML = l.type === 'police'
            ? '<b>Pozor:</b> přesun na jiný regál polici PŘEČÍSLUJE (kód obsahuje regál) — po uložení jí vytiskni nový štítek.'
            : 'Přestěhovaná krabička = jen tahle změna. Štítek zůstává platný.';
    }
    document.getElementById('editLocParent').innerHTML = parentOptions(l.type, l.parent_id);
    new bootstrap.Modal(document.getElementById('editLocModal')).show();
}));

document.getElementById('editLocSave').addEventListener('click', function () {
    const id = document.getElementById('editLocId').value;
    const btn = this;
    btn.disabled = true;
    locPost({
        op: 'update', id: id,
        name: document.getElementById('editLocName').value,
        note: document.getElementById('editLocNote').value,
        parent_id: document.getElementById('editLocParent').value || 0,
        is_active: document.getElementById('editLocActive').checked ? 1 : 0
    },
    // hláška o PŘEČÍSLOVANÉ POLICI se musí stihnout přečíst — bez callbacku by ji
    // okamžitý reload spolkl a obsluha by nechala na regálu neplatný štítek
    function (d) {
        if (d.message) { showAlert(d.message); setTimeout(() => location.reload(), 2500); }
        else { location.reload(); }
    },
    function () { btn.disabled = false; });
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
}
</script>

<?php require_once 'includes/footer.php'; ?>
