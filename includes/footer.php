    </div><!-- /.crm-main-content -->
</div> <!-- /#content -->

<!-- Universal Preview Modal -->
<div class="modal fade" id="universalPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-secondary py-2">
                <h6 class="modal-title mb-0" id="universalPreviewTitle"><i class="fas fa-file-alt me-2 text-primary"></i><?php echo __('preview'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="max-height: 85vh; overflow-y: auto; background: #f5f5f5;">
                <div id="universalPreviewContent"></div>
            </div>
            <div class="modal-footer border-secondary py-2">
                <a href="#" id="previewOpenTabBtn" target="_blank" class="btn btn-outline-secondary btn-sm me-auto" onclick="openPreviewInNewTab()">
                    <i class="fas fa-external-link-alt me-1"></i><?php echo __('open_full_view'); ?>
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="previewPrintBtn" disabled onclick="printUniversalPreview()">
                    <i class="fas fa-print me-1"></i><?php echo __('print'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Global Alert Modal -->
<div class="modal fade" id="globalAlertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="globalAlertTitle"><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="globalAlertBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php echo __('ok'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Global Confirm Modal -->
<div class="modal fade" id="globalConfirmModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="globalConfirmTitle"><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="globalConfirmBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="globalConfirmCancel"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="globalConfirmOk"><?php echo __('confirm'); ?></button>
            </div>
        </div>
    </div>
</div>


<div id="crmNotificationsPanel" class="crm-notifications-panel" aria-hidden="true">
    <div class="crm-notifications-head d-flex justify-content-between align-items-center">
        <strong><?php echo __('notifications'); ?></strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="notificationsPanelClose"><i class="fas fa-times"></i></button>
    </div>
    <div class="crm-notifications-list">
        <?php $crm_notifs = function_exists('getCrmNotifications') ? getCrmNotifications(15) : []; ?>
        <?php if (empty($crm_notifs)): ?>
            <div class="crm-notifications-empty text-center py-5">
                <i class="fas fa-bell-slash fa-lg mb-2 d-block text-white-50"></i>
                <div class="small text-white-75"><?php echo __('no_new_notifications'); ?></div>
            </div>
        <?php else: foreach ($crm_notifs as $n): ?>
            <a class="crm-notifications-item text-decoration-none" href="<?php echo e($n['url'] ?? '#'); ?>">
                <span class="crm-notifications-icon <?php echo e($n['type']); ?>"><i class="fas <?php echo e($n['icon']); ?>"></i></span>
                <div class="min-w-0">
                    <div class="small text-white text-truncate"><?php echo e($n['title']); ?></div>
                    <div class="small text-white-75 text-truncate">
                        <?php echo e(trim($n['sub'] ?? '')); ?><?php if (!empty($n['sub']) && !empty($n['ts'])) echo ' · '; ?><?php echo e($n['ts'] ? crmTimeAgo($n['ts']) : ''); ?>
                    </div>
                </div>
            </a>
        <?php endforeach; endif; ?>
    </div>
    <div class="crm-notifications-foot">
        <a href="orders.php" class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-list me-1"></i> <?php echo __('open_orders'); ?></a>
    </div>
</div>

<!-- QR skener zakázky -->
<div class="modal fade" id="scanOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-qrcode me-2"></i><?php echo __('scan_order_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
            </div>
            <div class="modal-body">
                <div class="small text-white-75 mb-2"><?php echo __('scan_hint'); ?></div>
                <div id="qrReader" style="width:100%; border-radius:10px; overflow:hidden;"></div>
                <div id="qrReaderMsg" class="small mt-2 text-warning"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/modals/new_order_modal.php'; ?>
<?php require_once __DIR__ . '/modals/new_complaint_modal.php'; ?>

<!-- Zakázkový list: volba tisk / e-mail (po vytvoření zakázky i z menu) -->
<div class="modal fade" id="orderDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content glass-card border-secondary text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2 text-primary"></i><?php echo __('order_sheet'); ?> <span id="orderDocCode"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-white-75 mb-3"><?php echo __('order_sheet_ready'); ?></p>
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-success btn-lg" id="orderDocSignBtn"><i class="fas fa-pen-nib me-2"></i><?php echo __('order_doc_sign_email'); ?></button>
          <button type="button" class="btn btn-outline-info btn-lg" id="orderDocPrintBtn"><i class="fas fa-print me-2"></i><?php echo __('print'); ?></button>
          <button type="button" class="btn btn-outline-primary btn-lg" id="orderDocEmailBtn"><i class="fas fa-envelope me-2"></i><?php echo __('send_email_to_client'); ?></button>
        </div>
        <div id="orderDocMsg" class="small mt-3"></div>
      </div>
    </div>
  </div>
</div>
<?php
/* Ambientní zvuky pro Khalila: přihlášenému zaměstnanci se jménem khalil se
   každých ~10 minut přehraje náhodná hláška (úvodní zvuk při loginu zůstává).
   Logika přehrávání je v main.js (afxAmbient…), kadence přežívá přechody stránek. */
$_afx_staff = mb_strtolower(($_SESSION['username'] ?? '') . ' ' . ($_SESSION['full_name'] ?? ''), 'UTF-8');
if (str_contains($_afx_staff, 'khalil')): ?>
<script>
window.AFX_AMBIENT_SOUNDS = [
    'assets/sounds/khalil_ambient_1.mp3',
    'assets/sounds/khalil_ambient_2.mp3',
    'assets/sounds/khalil_ambient_3.mp3'
];
window.AFX_AMBIENT_INTERVAL_MIN = 10;
</script>
<?php endif; ?>

<?php /* Popup „nová přidělená zakázka" — jen pro přihlášeného technika */ ?>
<?php if (!empty($_SESSION['tech_id'])): ?>
<div id="assignPopupOverlay" class="assign-popup-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="assign-popup-card">
        <div class="assign-popup-head">
            <span class="assign-popup-kicker"><i class="fas fa-user-check me-2"></i><?php echo __('new_assigned_order'); ?></span>
            <button type="button" class="assign-popup-x" onclick="afxAssignClose()" aria-label="<?php echo e(__('close')); ?>">&times;</button>
        </div>
        <div class="assign-popup-device" id="assignPopupDevice">—</div>
        <div class="assign-popup-codeline">
            <span class="assign-popup-code" id="assignPopupCode">—</span>
            <span class="assign-popup-prio" id="assignPopupPriority" style="display:none;"><i class="fas fa-bolt me-1"></i><?php echo __('high_priority'); ?></span>
        </div>
        <div class="assign-popup-rows">
            <div class="assign-popup-row"><span class="k"><?php echo __('client'); ?></span><span class="v" id="assignPopupClient">—</span></div>
            <div class="assign-popup-row"><span class="k"><?php echo __('issue'); ?></span><span class="v" id="assignPopupProblem">—</span></div>
        </div>
        <a href="#" id="assignPopupOpen" class="assign-popup-btn"><i class="fas fa-arrow-right me-2"></i><?php echo __('open_order'); ?></a>
    </div>
</div>
<style>
.assign-popup-overlay{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:18px;
    background:rgba(4,6,10,0.55);backdrop-filter:blur(14px) saturate(150%);-webkit-backdrop-filter:blur(14px) saturate(150%);opacity:0;transition:opacity .2s ease;}
.assign-popup-overlay.show{opacity:1;}
.assign-popup-card{position:relative;width:min(100%,440px);border-radius:26px;padding:24px 24px 22px;overflow:hidden;
    background:linear-gradient(180deg,rgba(255,255,255,0.12),rgba(255,255,255,0.05));border:1px solid rgba(255,255,255,0.16);
    box-shadow:0 30px 80px rgba(0,0,0,0.55),inset 0 1px 0 rgba(255,255,255,0.22);
    transform:translateY(10px) scale(0.98);transition:transform .22s cubic-bezier(.2,.8,.2,1);}
.assign-popup-overlay.show .assign-popup-card{transform:translateY(0) scale(1);}
.assign-popup-card::before{content:"";position:absolute;top:-40%;left:-20%;width:140%;height:120px;pointer-events:none;
    background:radial-gradient(60% 100% at 50% 0%,rgba(10,132,255,0.35),transparent 70%);}
.assign-popup-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;position:relative;}
.assign-popup-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:#7ab8ff;}
.assign-popup-x{background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.7rem;line-height:1;cursor:pointer;padding:0 4px;}
.assign-popup-device{font-size:1.5rem;font-weight:800;letter-spacing:-0.02em;color:#fff;line-height:1.2;position:relative;}
.assign-popup-codeline{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px;}
.assign-popup-code{font-family:ui-monospace,Menlo,monospace;font-size:.95rem;font-weight:700;color:#8fc0ff;letter-spacing:.03em;}
.assign-popup-prio{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;
    background:linear-gradient(180deg,rgba(255,69,58,0.24),rgba(255,69,58,0.12));border:1px solid rgba(255,69,58,0.5);color:#ff9aa2;}
.assign-popup-rows{margin-top:16px;display:grid;gap:8px;}
.assign-popup-row{display:flex;gap:12px;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.09);}
.assign-popup-row .k{flex:0 0 74px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:rgba(233,238,247,0.5);font-weight:700;padding-top:2px;}
.assign-popup-row .v{flex:1;min-width:0;color:#eef4ff;font-weight:600;font-size:.95rem;}
.assign-popup-btn{display:flex;align-items:center;justify-content:center;margin-top:18px;padding:13px 18px;border-radius:14px;
    text-decoration:none;font-weight:800;color:#fff;background:linear-gradient(135deg,#0A84FF,#0060df);
    box-shadow:0 10px 26px rgba(10,132,255,0.35);transition:filter .15s ease,transform .15s ease;}
.assign-popup-btn:hover{filter:brightness(1.07);transform:translateY(-1px);color:#fff;}
@media (prefers-reduced-motion:reduce){.assign-popup-overlay,.assign-popup-card{transition:none;}}
</style>
<script>
(function(){
    var overlay = document.getElementById('assignPopupOverlay');
    if (!overlay) return;
    var queue = [], showing = false;
    function open(it){
        document.getElementById('assignPopupDevice').textContent = it.device || "<?php echo __('device'); ?>";
        document.getElementById('assignPopupCode').textContent = it.order_code || ('#' + it.order_id);
        var pr = document.getElementById('assignPopupPriority');
        pr.style.display = it.priority_high ? '' : 'none';
        document.getElementById('assignPopupClient').textContent = it.customer || '—';
        document.getElementById('assignPopupProblem').textContent = it.problem || '—';
        document.getElementById('assignPopupOpen').href = 'view_order.php?id=' + encodeURIComponent(it.order_id);
        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');
        if (window.afxChime) { window.afxChime('assign'); }   // zvuk k popupu přidělené zakázky
        requestAnimationFrame(function(){ overlay.classList.add('show'); });
    }
    window.afxAssignClose = function(){
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
        setTimeout(function(){ overlay.style.display = 'none'; showing = false; next(); }, 200);
    };
    function next(){ if (showing) return; var it = queue.shift(); if (!it) return; showing = true; open(it); }
    overlay.addEventListener('click', function(e){ if (e.target === overlay) afxAssignClose(); });
    function poll(){
        fetch('api/tech_popups.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d && d.ok && d.items && d.items.length){ d.items.forEach(function(it){ queue.push(it); }); next(); } })
            .catch(function(){});
    }
    setTimeout(poll, 3000);
    setInterval(poll, 20000);
})();
</script>
<?php endif; ?>
</body>
</html>
