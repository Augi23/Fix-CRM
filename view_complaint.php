<?php
/* Detail reklamace (v2.10.0) — práce s reklamací jako se zakázkou:
   převzetí technikem, řešení/závěr, přílohy průběhu řešení a vyrozumění
   pro klienta (tisk / e-mail). API: complaint_assign, save_complaint_resolution,
   complaint_media, send_complaint_result, update_complaint_status. */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) { header('Location: login.php'); exit; }

ensureComplaintsClientColumns($pdo);
ensureComplaintsWorkflowColumns($pdo);
ensureComplaintMediaTable($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cust_phone, cu.email AS cust_email,
                              t.name AS tech_name
                       FROM complaints c
                       LEFT JOIN customers cu ON cu.id = c.customer_id
                       LEFT JOIN technicians t ON t.id = c.technician_id
                       WHERE c.id = ?");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { header('Location: reklamace.php'); exit; }

// Pobočková izolace: reklamaci k zakázce JINÉ pobočky ne-globální divák (manažer/technik)
// neotevře. Reklamace bez zakázky (e-shopové) jsou přístupné všem.
if (!isBranchGlobalViewer() && (int)($c['order_id'] ?? 0) > 0) {
    $__ob = $pdo->prepare("SELECT branch_id FROM orders WHERE id = ?");
    $__ob->execute([(int)$c['order_id']]);
    $__ordBranch = (int)$__ob->fetchColumn();
    if ($__ordBranch > 0 && $__ordBranch !== getCurrentStaffBranchId()) {
        header('Location: reklamace.php'); exit;
    }
}

$media = crmGetComplaintMedia($pdo, $id);
$canManage = crmComplaintCanManage();
$myTechId = (int)($_SESSION['tech_id'] ?? 0);
$curTechId = (int)($c['technician_id'] ?? 0);
$custName = trim(((string)($c['first_name'] ?? '')) . ' ' . ((string)($c['last_name'] ?? '')));

$techs = [];
if ($canManage) {
    try { $techs = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll(); } catch (Throwable $e) {}
}

$statusOptions = ['Přijato', 'V řešení', 'Čeká na zákazníka', 'Vyřízeno', 'Zamítnuto'];
$curStatus = (string)($c['complaint_status'] ?? 'Přijato');
if ($curStatus !== '' && !in_array($curStatus, $statusOptions, true)) { array_unshift($statusOptions, $curStatus); }

require_once 'includes/header.php';
?>

<style>
/* Panely v detailu reklamace mají PŘIROZENOU výšku — globální pravidlo
   .row > [col] > .glass-panel { height:100% } (dlaždice Nástěnky) je tady
   natahovalo na výšku celého sloupce (638 px při pár řádcích obsahu). */
.cmpl-detail .row > [class*="col-"] > .glass-panel { height: auto !important; }
</style>
<div class="container-fluid cmpl-detail" style="max-width: 1200px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="mb-0 text-white">
            <i class="fas fa-rotate-left me-2" style="color:#f97316"></i><?php echo __('cmpl_detail'); ?>
            <span class="font-monospace"><?php echo e((string)$c['complaint_code']); ?></span>
            <?php if (($c['source'] ?? '') === 'client'): ?><span class="badge ms-2" style="background:#f97316;color:#fff">Klient</span><?php endif; ?>
        </h2>
        <a href="reklamace.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i><?php echo __('back'); ?></a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="glass-panel p-4 border-secondary mb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <h5 class="mb-0 text-white"><i class="fas fa-circle-info me-2 text-info"></i><?php echo __('cmpl_status'); ?></h5>
                    <select id="cmplStatus" class="form-select form-select-sm w-auto bg-dark text-white border-secondary">
                        <?php foreach ($statusOptions as $so): ?>
                            <option value="<?php echo e($so); ?>" <?php echo $so === $curStatus ? 'selected' : ''; ?>><?php echo e($so); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-white-75"><?php echo __('customer_col'); ?></div>
                        <div class="fw-semibold text-white"><?php echo e($custName ?: '—'); ?></div>
                        <?php if (!empty($c['cust_phone']) || !empty($c['phone'])): ?><div class="small text-white-75 mt-1"><i class="fas fa-phone me-1"></i><?php echo e((string)($c['cust_phone'] ?? $c['phone'])); ?></div><?php endif; ?>
                        <?php if (!empty($c['cust_email'])): ?><div class="small text-white-75"><i class="fas fa-envelope me-1"></i><?php echo e((string)$c['cust_email']); ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-white-75"><?php echo __('device'); ?></div>
                        <div class="fw-semibold text-white"><?php echo e((string)($c['device'] ?? '—') ?: '—'); ?></div>
                        <?php if (!empty($c['serial_number'])): ?><div class="small text-white-75 mt-1">SN/IMEI: <span class="font-monospace"><?php echo e((string)$c['serial_number']); ?></span></div><?php endif; ?>
                        <?php if (!empty($c['order_id'])): ?>
                            <div class="small mt-1"><a class="text-info" href="view_order.php?id=<?php echo (int)$c['order_id']; ?>"><i class="fas fa-link me-1"></i><?php echo __('cmpl_original_order'); ?>: <?php echo e((string)($c['order_code'] ?: ('#' . (int)$c['order_id']))); ?></a></div>
                        <?php endif; ?>
                        <div class="small text-white-50 mt-1"><i class="far fa-calendar me-1"></i><?php echo !empty($c['created_at']) ? e(date('d.m.Y H:i', strtotime((string)$c['created_at']))) : '—'; ?></div>
                    </div>
                </div>
                <hr class="border-secondary">
                <div class="small text-white-75 mb-1"><?php echo __('cmpl_original_issue'); ?></div>
                <div class="text-white" style="white-space:pre-wrap;"><?php echo e((string)($c['complaint_reason'] ?? '')); ?></div>
            </div>

            <div class="glass-panel p-4 border-secondary">
                <h5 class="mb-1 text-white"><i class="fas fa-clipboard-check me-2 text-success"></i><?php echo __('cmpl_resolution_label'); ?></h5>
                <div class="small text-white-75 mb-3" id="cmplResolvedMeta">
                    <?php if (!empty($c['resolved_at'])): ?>
                        <?php echo __('cmpl_resolved_at'); ?>: <?php echo e(date('d.m.Y H:i', strtotime((string)$c['resolved_at']))); ?><?php if (!empty($c['resolved_by'])): ?> · <?php echo e((string)$c['resolved_by']); ?><?php endif; ?>
                    <?php endif; ?>
                </div>
                <textarea id="cmplResolution" class="form-control mb-3" rows="6" placeholder="<?php echo e(__('cmpl_resolution_ph')); ?>"><?php echo e((string)($c['resolution_text'] ?? '')); ?></textarea>
                <button type="button" id="cmplResolutionSave" class="btn btn-success"><i class="fas fa-save me-2"></i><?php echo __('cmpl_resolution_save'); ?></button>
                <span id="cmplResolutionMsg" class="small ms-2"></span>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="glass-panel p-4 border-secondary mb-3">
                <h5 class="mb-3 text-white"><i class="fas fa-user-cog me-2 text-info"></i><?php echo __('cmpl_assigned_tech'); ?></h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold text-white" id="cmplTechName"><?php echo $curTechId > 0 ? e((string)($c['tech_name'] ?: ('#' . $curTechId))) : __('cmpl_unassigned'); ?></span>
                    <?php if ($myTechId > 0 && $curTechId !== $myTechId): ?>
                        <button type="button" id="cmplTakeBtn" class="btn btn-sm btn-primary"><i class="fas fa-hand me-1"></i><?php echo __('cmpl_take'); ?></button>
                    <?php endif; ?>
                </div>
                <?php if ($canManage): ?>
                <div class="input-group input-group-sm mt-3">
                    <select id="cmplAssignSelect" class="form-select bg-dark text-white border-secondary">
                        <option value="0"><?php echo __('cmpl_unassigned'); ?></option>
                        <?php foreach ($techs as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$t['id'] === $curTechId ? 'selected' : ''; ?>><?php echo e((string)$t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="cmplAssignBtn" class="btn btn-outline-info"><?php echo __('save'); ?></button>
                </div>
                <?php endif; ?>
            </div>

            <div class="glass-panel p-4 border-secondary mb-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0 text-white"><i class="fas fa-paperclip me-2 text-warning"></i><?php echo __('cmpl_attachments'); ?></h5>
                    <label class="btn btn-sm btn-outline-primary mb-0">
                        <i class="fas fa-upload me-1"></i><?php echo __('cmpl_upload_files'); ?>
                        <input type="file" id="cmplFiles" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.pdf" hidden>
                    </label>
                </div>
                <div id="cmplMediaGrid" class="d-flex flex-wrap gap-2">
                    <?php if (!$media): ?>
                        <div class="text-white-50 small"><?php echo __('cmpl_no_attachments'); ?></div>
                    <?php else: foreach ($media as $m):
                        $mp = e(ltrim((string)$m['file_path'], '/'));
                        $isImg = str_starts_with((string)($m['file_type'] ?? ''), 'image/');
                    ?>
                        <div class="position-relative" style="width:96px;">
                            <a href="<?php echo $mp; ?>" target="_blank" rel="noopener" class="d-block">
                                <?php if ($isImg): ?>
                                    <img src="<?php echo $mp; ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.15);">
                                <?php else: ?>
                                    <span class="d-flex align-items-center justify-content-center" style="width:96px;height:96px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);"><i class="fas fa-file-pdf fa-2x text-danger"></i></span>
                                <?php endif; ?>
                            </a>
                            <div class="small text-white-50 text-truncate" title="<?php echo e((string)($m['file_name'] ?? '')); ?>"><?php echo e((string)($m['file_name'] ?? basename((string)$m['file_path']))); ?></div>
                            <?php if ($canManage): ?>
                                <button type="button" class="btn btn-sm btn-danger cmpl-media-del position-absolute" style="top:-6px;right:-6px;padding:0 6px;border-radius:999px;" data-mid="<?php echo (int)$m['id']; ?>" data-src="<?php echo e((string)$m['src']); ?>" title="<?php echo e(__('delete')); ?>">×</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div id="cmplUploadMsg" class="small mt-2"></div>
            </div>

            <div class="glass-panel p-4 border-secondary">
                <h5 class="mb-3 text-white"><i class="fas fa-file-lines me-2 text-primary"></i><?php echo __('cmpl_documents'); ?></h5>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-light" onclick="openUniversalPreview('print_complaint.php?id=<?php echo (int)$c['id']; ?>', '<?php echo e(__('complaint_protocol')); ?>')">
                        <i class="fas fa-file-alt me-2"></i><?php echo __('complaint_protocol'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-info" onclick="openUniversalPreview('print_complaint_result.php?id=<?php echo (int)$c['id']; ?>', '<?php echo e(__('cmpl_result_doc_title')); ?>')">
                        <i class="fas fa-file-circle-check me-2"></i><?php echo __('cmpl_result_doc_title'); ?>
                    </button>
                    <button type="button" id="cmplEmailBtn" class="btn btn-primary">
                        <i class="fas fa-envelope me-2"></i><?php echo __('cmpl_send_result_email'); ?>
                    </button>
                </div>
                <div id="cmplEmailMsg" class="small mt-3"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var CID = <?php echo (int)$c['id']; ?>;
    function post(url, data) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
    }

    // změna stavu (stejné API jako v seznamu reklamací)
    var st = document.getElementById('cmplStatus');
    if (st) st.addEventListener('change', function(){
        var el = this; el.disabled = true;
        post('api/update_complaint_status.php', { id: CID, status: el.value })
            .then(function(d){ el.disabled = false; if (!d.ok) alert('<?php echo __('complaint_status_change_failed'); ?>'); })
            .catch(function(){ el.disabled = false; alert('<?php echo __('connection_error'); ?>'); });
    });

    // převzetí / přeřazení
    var take = document.getElementById('cmplTakeBtn');
    if (take) take.addEventListener('click', function(){
        take.disabled = true;
        post('api/complaint_assign.php', { id: CID, action: 'claim' })
            .then(function(d){ if (d.ok) location.reload(); else { take.disabled = false; alert(d.error || 'Chyba'); } })
            .catch(function(){ take.disabled = false; alert('<?php echo __('connection_error'); ?>'); });
    });
    var assignBtn = document.getElementById('cmplAssignBtn');
    if (assignBtn) assignBtn.addEventListener('click', function(){
        assignBtn.disabled = true;
        post('api/complaint_assign.php', { id: CID, action: 'assign', technician_id: document.getElementById('cmplAssignSelect').value })
            .then(function(d){ if (d.ok) location.reload(); else { assignBtn.disabled = false; alert(d.error || 'Chyba'); } })
            .catch(function(){ assignBtn.disabled = false; alert('<?php echo __('connection_error'); ?>'); });
    });

    // uložení řešení
    var saveBtn = document.getElementById('cmplResolutionSave');
    if (saveBtn) saveBtn.addEventListener('click', function(){
        var msg = document.getElementById('cmplResolutionMsg');
        saveBtn.disabled = true; msg.textContent = '';
        post('api/save_complaint_resolution.php', { id: CID, resolution_text: document.getElementById('cmplResolution').value })
            .then(function(d){
                saveBtn.disabled = false;
                if (d.ok) {
                    msg.className = 'small ms-2 text-success';
                    msg.textContent = '✓ <?php echo __('saved_ok'); ?>';
                    var meta = document.getElementById('cmplResolvedMeta');
                    if (meta) meta.textContent = d.resolved_at_h ? ('<?php echo __('cmpl_resolved_at'); ?>: ' + d.resolved_at_h + (d.resolved_by ? ' · ' + d.resolved_by : '')) : '';
                } else { msg.className = 'small ms-2 text-danger'; msg.textContent = d.error || 'Chyba'; }
            })
            .catch(function(){ saveBtn.disabled = false; msg.className = 'small ms-2 text-danger'; msg.textContent = '<?php echo __('connection_error'); ?>'; });
    });

    // nahrání příloh
    var files = document.getElementById('cmplFiles');
    if (files) files.addEventListener('change', function(){
        if (!files.files.length) return;
        var msg = document.getElementById('cmplUploadMsg');
        msg.className = 'small mt-2 text-white-75'; msg.textContent = '⏳ …';
        var fd = new FormData();
        fd.append('csrf_token', CSRF); fd.append('action', 'upload'); fd.append('complaint_id', CID);
        for (var i = 0; i < files.files.length; i++) fd.append('files[]', files.files[i]);
        fetch('api/complaint_media.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.ok) location.reload(); else { msg.className = 'small mt-2 text-danger'; msg.textContent = d.error || 'Chyba'; } })
            .catch(function(){ msg.className = 'small mt-2 text-danger'; msg.textContent = '<?php echo __('connection_error'); ?>'; });
    });

    // mazání příloh (vedení)
    document.querySelectorAll('.cmpl-media-del').forEach(function(btn){
        btn.addEventListener('click', function(){
            if (typeof showConfirm === 'function') {
                showConfirm('<?php echo __('confirm_delete_file'); ?>', function(){ doDel(btn); });
            } else if (confirm('<?php echo __('confirm_delete_file'); ?>')) { doDel(btn); }
        });
    });
    function doDel(btn) {
        post('api/complaint_media.php', { action: 'delete', media_id: btn.dataset.mid, src: btn.dataset.src })
            .then(function(d){ if (d.ok) location.reload(); else alert(d.error || 'Chyba'); })
            .catch(function(){ alert('<?php echo __('connection_error'); ?>'); });
    }

    // vyrozumění e-mailem — ukázat skutečného příjemce („Odesláno na …")
    var em = document.getElementById('cmplEmailBtn');
    if (em) em.addEventListener('click', function(){
        var msg = document.getElementById('cmplEmailMsg');
        em.disabled = true;
        var old = em.innerHTML;
        em.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>…';
        post('api/send_complaint_result.php', { id: CID })
            .then(function(d){
                em.disabled = false; em.innerHTML = old;
                if (d.ok) {
                    msg.className = 'small mt-3 text-success';
                    msg.innerHTML = '<i class="fas fa-check-circle me-1"></i><?php echo __('cmpl_sent_to_prefix'); ?> <b>' + (window.escapeHtml ? escapeHtml(d.to || '—') : (d.to || '—')) + '</b>';
                } else {
                    msg.className = 'small mt-3 text-warning';
                    msg.textContent = d.error || 'Chyba';
                }
            })
            .catch(function(){ em.disabled = false; em.innerHTML = old; msg.className = 'small mt-3 text-danger'; msg.textContent = '<?php echo __('connection_error'); ?>'; });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
