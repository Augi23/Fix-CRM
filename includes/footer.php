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
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon warning"><i class="fas fa-clock"></i></span>
            <div>
                <div class="small text-white"><?php echo __('sample_sla_waiting'); ?></div>
                <div class="small text-white-75"><?php echo __('sample_6_min_ago'); ?></div>
            </div>
        </div>
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon success"><i class="fas fa-check"></i></span>
            <div>
                <div class="small text-white"><?php echo __('sample_import_done'); ?></div>
                <div class="small text-white-75"><?php echo __('sample_12_min_ago'); ?></div>
            </div>
        </div>
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon info"><i class="fas fa-info"></i></span>
            <div>
                <div class="small text-white"><?php echo __('sample_new_customer_added'); ?></div>
                <div class="small text-white-75"><?php echo __('sample_24_min_ago'); ?></div>
            </div>
        </div>
    </div>
    <div class="crm-notifications-foot">
        <button type="button" class="btn btn-sm btn-outline-secondary w-100"><?php echo __('mark_all_read'); ?></button>
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
</body>
</html>
