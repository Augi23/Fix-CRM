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
                <div class="small text-white-75">Žádná nová upozornění</div>
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
        <a href="orders.php" class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-list me-1"></i> Otevřít zakázky</a>
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
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2 text-primary"></i>Zakázkový list <span id="orderDocCode"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-white-75 mb-3">Zakázkový list je připraven. Jak s ním chcete naložit?</p>
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-outline-info btn-lg" id="orderDocPrintBtn"><i class="fas fa-print me-2"></i>Vytisknout</button>
          <button type="button" class="btn btn-outline-primary btn-lg" id="orderDocEmailBtn"><i class="fas fa-envelope me-2"></i>Odeslat e-mailem klientovi</button>
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
</body>
</html>
