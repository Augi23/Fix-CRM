<?php
/**
 * SKLAD → PŘESUNY — přesun zboží mezi pobočkami se schválením a historií.
 * Rozpracovaný seznam (draft) z aktuální pobočky na druhou; potvrdí zaměstnanec
 * ZDROJOVÉ pobočky → zboží se fyzicky přesune (se stejným obrázkem). Dole historie.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/stock_transfers.php';
require_once 'includes/header.php';
ensureSkladBranchSchema();
ensureStockTransfersSchema();

$skladBranch = skladBranchOrOwn();
$canModifyStock = crmCanModifyBranchStock($skladBranch);
$csrf = $_SESSION['csrf_token'] ?? '';

// druhá pobočka (cíl)
$__otherBranch = 0;
foreach (getBranches(true) as $__b) { if ((int)$__b['id'] !== $skladBranch) { $__otherBranch = (int)$__b['id']; break; } }

// otevřený draft z této pobočky (NEzakládat při prohlížení)
$draftId = 0;
try { $draftId = (int)$pdo->query("SELECT id FROM stock_transfers WHERE from_branch_id = " . (int)$skladBranch . " AND status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
$draftItems = $draftId ? afxTransferItems($draftId) : [];
$history = afxTransferHistory($skladBranch, 100);

/** bezpečné zobrazení obrázku položky přesunu (jen naše úložiště). */
function afxTransferImg(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (function_exists('productImageDisplayUrl')) { $d = productImageDisplayUrl($url); if ($d !== '') return $d; }
    // fallback: povolit jen relativní/naši doménu
    if (preg_match('#^(/|uploads/|https://admin\.applefix\.cloud/)#', $url)) return $url;
    return '';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="fas fa-right-left me-2 text-info"></i>Přesuny zboží</h2>
        <small class="text-muted">Přesun mezi pobočkami se schválením — zdroj: <?php echo e(skladBranchLabel($skladBranch)); ?></small>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<!-- ── Rozpracovaný přesun (draft) ─────────────────────────────────────────── -->
<div class="card glass-card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0"><i class="fas fa-dolly me-2 text-warning"></i>Rozpracovaný přesun
                <?php echo e(skladBranchLabel($skladBranch)); ?> <i class="fas fa-arrow-right mx-1 small"></i> <?php echo $__otherBranch ? e(skladBranchLabel($__otherBranch)) : '—'; ?>
                <span class="badge bg-secondary ms-2" id="trDraftCount"><?php echo count($draftItems); ?></span>
            </h5>
            <?php if ($canModifyStock && !empty($draftItems)): ?>
            <button type="button" class="btn btn-success" id="trConfirmBtn" data-id="<?php echo (int)$draftId; ?>">
                <i class="fas fa-check-double me-2"></i>Potvrdit a přesunout (<?php echo count($draftItems); ?>)
            </button>
            <?php endif; ?>
        </div>

        <?php if (!$canModifyStock): ?>
            <div class="alert alert-secondary border-0 mb-0"><i class="fas fa-eye me-2"></i>Přesouvat z pobočky <b><?php echo e(skladBranchLabel($skladBranch)); ?></b> a potvrzovat smí jen její zaměstnanci. Ty máš jen náhled.</div>
        <?php elseif (empty($draftItems)): ?>
            <div class="text-muted py-3"><i class="fas fa-inbox me-2 opacity-50"></i>Zatím nic k přesunu. Ve Skladu (Servis / Produkty / Příslušenství) klikni u zboží na ikonu <i class="fas fa-right-left mx-1"></i> a přidej ho sem.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark"><tr><th class="ps-3">Foto</th><th>Položka</th><th>Typ</th><th>Počet</th><th class="text-end pe-3">Akce</th></tr></thead>
                    <tbody>
                        <?php foreach ($draftItems as $it): $img = afxTransferImg($it['image_url'] ?? ''); ?>
                        <tr id="trItem<?php echo (int)$it['id']; ?>">
                            <td class="ps-3">
                                <?php if ($img !== ''): ?><img src="<?php echo e($img); ?>" class="rounded shadow-sm" style="width:40px;height:40px;object-fit:cover;">
                                <?php else: ?><div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center border border-secondary" style="width:40px;height:40px;"><i class="fas fa-image text-muted opacity-25"></i></div><?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?php echo e((string)$it['name']); ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo $it['item_type'] === 'product' ? 'Produkt' : 'Díl'; ?></span></td>
                            <td><?php echo (int)$it['qty']; ?> ks</td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-danger tr-remove-btn" data-id="<?php echo (int)$it['id']; ?>" title="Odebrat z přesunu"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Historie potvrzených přesunů ────────────────────────────────────────── -->
<div class="card glass-card border-0 shadow-sm">
    <div class="card-body">
        <h5 class="mb-3"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Historie přesunů</h5>
        <?php if (empty($history)): ?>
            <div class="text-muted py-2">Zatím žádné potvrzené přesuny.</div>
        <?php else: ?>
            <div class="accordion" id="trHistory">
                <?php foreach ($history as $h): $items = afxTransferItems((int)$h['id']); $hid = (int)$h['id']; ?>
                <div class="accordion-item bg-transparent border-secondary">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed bg-transparent text-white" type="button" data-bs-toggle="collapse" data-bs-target="#trH<?php echo $hid; ?>">
                            <span class="me-3"><i class="far fa-clock me-1 text-white-50"></i><?php echo e(crmDateTime($h['confirmed_at'] ?? $h['created_at'], true)); ?></span>
                            <span class="badge me-2" style="background:rgba(85,212,255,.18);color:#7fe3ff;"><?php echo e(skladBranchLabel((int)$h['from_branch_id'])); ?> → <?php echo e(skladBranchLabel((int)$h['to_branch_id'])); ?></span>
                            <span class="text-white-75 small me-2"><i class="fas fa-user-check me-1"></i>potvrdil: <b><?php echo e((string)($h['confirmed_by_name'] ?? '—')); ?></b></span>
                            <span class="badge bg-secondary ms-auto"><?php echo count($items); ?> položek</span>
                        </button>
                    </h2>
                    <div id="trH<?php echo $hid; ?>" class="accordion-collapse collapse" data-bs-parent="#trHistory">
                        <div class="accordion-body">
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($items as $it): ?>
                                <li class="d-flex align-items-center gap-2 py-1 border-bottom border-secondary border-opacity-25">
                                    <span class="badge bg-info text-dark"><?php echo $it['item_type'] === 'product' ? 'Produkt' : 'Díl'; ?></span>
                                    <span class="fw-semibold"><?php echo e((string)$it['name']); ?></span>
                                    <span class="text-white-50 ms-auto"><?php echo (int)$it['qty']; ?> ks</span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var CSRF = '<?php echo e($csrf); ?>';
    function post(url, data, cb) {
        var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); fd.append('csrf_token', CSRF);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(cb).catch(function () { showAlert('Síťová chyba.'); });
    }
    // odebrat položku
    document.querySelectorAll('.tr-remove-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            post('api/transfer_remove.php', { item_id: b.dataset.id }, function (d) {
                if (d.success) { var row = document.getElementById('trItem' + b.dataset.id); if (row) row.remove(); location.reload(); }
                else showAlert(d.message || 'Chyba');
            });
        });
    });
    // potvrdit přesun
    var cb = document.getElementById('trConfirmBtn');
    if (cb) cb.addEventListener('click', function () {
        showConfirm('Opravdu potvrdit a fyzicky přesunout toto zboží na druhou pobočku? Zásoba se hned přesune.', function () {
            cb.disabled = true;
            post('api/transfer_confirm.php', { transfer_id: cb.dataset.id }, function (d) {
                if (d.success) { location.reload(); } else { cb.disabled = false; showAlert(d.message || 'Chyba'); }
            });
        });
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
