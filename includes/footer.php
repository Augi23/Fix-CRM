    </div><!-- /.crm-main-content -->
</div> <!-- /#content -->

<!-- Universal Preview Modal -->
<div class="modal fade" id="universalPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-secondary py-2">
                <h6 class="modal-title mb-0" id="universalPreviewTitle"><i class="fas fa-file-alt me-2 text-primary"></i>Preview</h6>
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
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
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
        <strong>Upozornění</strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="notificationsPanelClose"><i class="fas fa-times"></i></button>
    </div>
    <div class="crm-notifications-list">
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon warning"><i class="fas fa-clock"></i></span>
            <div>
                <div class="small text-white">Zakázka #8741 čeká déle než SLA.</div>
                <div class="small text-white-75">Před 6 min</div>
            </div>
        </div>
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon success"><i class="fas fa-check"></i></span>
            <div>
                <div class="small text-white">Import katalogu dokončen.</div>
                <div class="small text-white-75">Před 12 min</div>
            </div>
        </div>
        <div class="crm-notifications-item">
            <span class="crm-notifications-icon info"><i class="fas fa-info"></i></span>
            <div>
                <div class="small text-white">Nový zákazník byl přidán.</div>
                <div class="small text-white-75">Před 24 min</div>
            </div>
        </div>
    </div>
    <div class="crm-notifications-foot">
        <button type="button" class="btn btn-sm btn-outline-secondary w-100">Označit vše jako přečtené</button>
    </div>
</div>

</body>
</html>
