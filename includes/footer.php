    </div><!-- /.crm-main-content -->
</div> <!-- /#content -->

<!-- Universal Preview Modal -->
<div class="modal fade" id="universalPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-secondary py-2">
                <h6 class="modal-title mb-0" id="universalPreviewTitle"><i class="fas fa-file-alt me-2 text-primary"></i><?php echo __('preview'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="max-height: calc(100vh - 130px); max-height: calc(100dvh - 130px); overflow-y: auto; -webkit-overflow-scrolling: touch; background: #f5f5f5;">
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
<?php require_once __DIR__ . '/modals/customer_display_modal.php'; ?>

<!-- Zakázkový list: volba tisk / e-mail (po vytvoření zakázky i z menu) -->
<div class="modal fade" id="orderDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content glass-card border-secondary text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2 text-primary"></i><span id="orderDocTitleLbl"
            data-order-label="<?php echo e(__('order_sheet')); ?>"
            data-complaint-label="<?php echo e(__('complaint_protocol')); ?>"><?php echo __('order_sheet'); ?></span> <span id="orderDocCode"></span></h5>
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
/* Hláška pro Khalila: JEDNA jediná („Khalil! Less talking, more working."),
   opakuje se každých 15 minut. Stejný zvuk hraje i jako uvítání při přihlášení
   (uploads/greetings/<username>.m4a na serveru). Náhodné hlášky odstraněny
   na přání 16.7.2026. Logika přehrávání je v main.js (afxAmbient…). */
$_afx_staff = mb_strtolower(($_SESSION['username'] ?? '') . ' ' . ($_SESSION['full_name'] ?? ''), 'UTF-8');
if (str_contains($_afx_staff, 'khalil')): ?>
<script>
window.AFX_AMBIENT_SOUNDS = [
    'assets/sounds/khalil_less_talking.m4a'
];
window.AFX_AMBIENT_INTERVAL_MIN = 15;
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

<?php if (function_exists('crmCanDeleteOrders') && crmCanDeleteOrders()): ?>
<?php /* ── NOVÁ OBJEDNÁVKA Z E-SHOPU — celoobrazovkové upozornění pro adminy a Bosse.
         Zavírá se VÝHRADNĚ tlačítkem (žádný klik mimo) a potvrzení je per účet:
         objednávka vyskakuje na každé stránce znovu, dokud ji dotyčný nepotvrdí.
         Funguje i v appce z TestFlightu — je to tatáž webová vrstva CRM. */ ?>
<div id="eshopAlertOverlay" class="eshop-alert-overlay" role="alertdialog" aria-modal="true" aria-hidden="true">
    <div class="eshop-alert-card">
        <div class="eshop-alert-kicker"><i class="fas fa-cart-shopping me-2"></i>Nová objednávka z e-shopu</div>
        <div class="eshop-alert-ref" id="eshopAlertRef">—</div>
        <div class="eshop-alert-total" id="eshopAlertTotal">—</div>
        <div class="eshop-alert-rows">
            <div class="eshop-alert-row"><span class="k">Zákazník</span><span class="v" id="eshopAlertCustomer">—</span></div>
            <div class="eshop-alert-row" id="eshopAlertPhoneRow"><span class="k">Telefon</span><span class="v" id="eshopAlertPhone">—</span></div>
            <div class="eshop-alert-row" id="eshopAlertEmailRow"><span class="k">E-mail</span><span class="v" id="eshopAlertEmail">—</span></div>
            <div class="eshop-alert-row"><span class="k">Položky</span><span class="v" id="eshopAlertLines">—</span></div>
            <div class="eshop-alert-row" id="eshopAlertNoteRow"><span class="k">Poznámka</span><span class="v" id="eshopAlertNote">—</span></div>
            <div class="eshop-alert-row"><span class="k">Čas</span><span class="v" id="eshopAlertTime">—</span></div>
        </div>
        <a href="https://applefix.click/admin/objednavky" target="_blank" rel="noopener" class="eshop-alert-link"><i class="fas fa-arrow-up-right-from-square me-2"></i>Otevřít administraci e-shopu</a>
        <button type="button" id="eshopAlertAck" class="eshop-alert-btn"><i class="fas fa-check me-2"></i>Beru na vědomí — zavřít</button>
    </div>
</div>
<style>
/* z-index 11800 ZÁMĚRNĚ POD zámkem kasy (12000) i LCD platby (12500) — zamčená
   kasa nesmí přes zámek ukazovat údaje zákazníka ani dovolit potvrzení bez hesla.
   Overlay scrolluje (flex-start + margin:auto na kartě) — dlouhá objednávka
   nesmí odsunout tlačítko Beru na vědomí mimo obrazovku. */
.eshop-alert-overlay{position:fixed;inset:0;z-index:11800;display:none;align-items:flex-start;justify-content:center;padding:18px;
    overflow-y:auto;background:rgba(4,6,10,0.62);backdrop-filter:blur(14px) saturate(150%);-webkit-backdrop-filter:blur(14px) saturate(150%);opacity:0;transition:opacity .2s ease;}
.eshop-alert-overlay.show{opacity:1;}
.eshop-alert-card{position:relative;width:min(100%,470px);margin:auto;border-radius:26px;padding:24px 24px 22px;overflow:hidden;
    background:linear-gradient(180deg,rgba(255,255,255,0.12),rgba(255,255,255,0.05));border:1px solid rgba(255,255,255,0.16);
    box-shadow:0 30px 80px rgba(0,0,0,0.55),inset 0 1px 0 rgba(255,255,255,0.22);
    transform:translateY(10px) scale(0.98);transition:transform .22s cubic-bezier(.2,.8,.2,1);}
.eshop-alert-overlay.show .eshop-alert-card{transform:translateY(0) scale(1);}
.eshop-alert-card::before{content:"";position:absolute;top:-40%;left:-20%;width:140%;height:120px;pointer-events:none;
    background:radial-gradient(60% 100% at 50% 0%,rgba(48,209,88,0.38),transparent 70%);}
.eshop-alert-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:#7ce39a;position:relative;}
.eshop-alert-ref{margin-top:10px;font-family:ui-monospace,Menlo,monospace;font-size:1.05rem;font-weight:700;color:#9fe7b6;letter-spacing:.03em;position:relative;}
.eshop-alert-total{font-size:2.1rem;font-weight:800;letter-spacing:-0.02em;color:#fff;line-height:1.15;position:relative;}
.eshop-alert-rows{margin-top:14px;display:grid;gap:8px;}
.eshop-alert-row{display:flex;gap:12px;padding:9px 12px;border-radius:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.09);}
.eshop-alert-row .k{flex:0 0 74px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:rgba(233,238,247,0.5);font-weight:700;padding-top:2px;}
.eshop-alert-row .v{flex:1;min-width:0;color:#eef4ff;font-weight:600;font-size:.95rem;white-space:pre-line;overflow-wrap:anywhere;}
.eshop-alert-link{display:flex;align-items:center;justify-content:center;margin-top:16px;padding:11px 16px;border-radius:13px;
    text-decoration:none;font-weight:700;color:#d9e6ff;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.16);}
.eshop-alert-link:hover{background:rgba(255,255,255,0.12);color:#fff;}
.eshop-alert-btn{display:flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:13px 18px;border-radius:14px;
    border:none;cursor:pointer;font-weight:800;font-size:1rem;color:#04220e;background:linear-gradient(135deg,#30D158,#1f9e41);
    box-shadow:0 10px 26px rgba(48,209,88,0.35);transition:filter .15s ease,transform .15s ease;}
.eshop-alert-btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
.eshop-alert-btn:disabled{opacity:.6;cursor:wait;transform:none;}
@media (prefers-reduced-motion:reduce){.eshop-alert-overlay,.eshop-alert-card{transition:none;}}
</style>
<script>
(function(){
    var overlay = document.getElementById('eshopAlertOverlay');
    if (!overlay) return;
    var queue = [], queuedIds = {}, showing = null;
    var ackBtn = document.getElementById('eshopAlertAck');
    function money(v){
        var d = Math.abs(v - Math.round(v)) > 0.004 ? 2 : 0;   // haléře jen když opravdu jsou
        return new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: d, maximumFractionDigits: d }).format(v) + ' Kč';
    }
    function setRow(rowId, valId, text){
        var row = document.getElementById(rowId);
        if (row) row.style.display = text ? '' : 'none';
        if (text) document.getElementById(valId).textContent = text;
    }
    function open(it){
        showing = it;
        document.getElementById('eshopAlertRef').textContent = it.order_ref || '—';
        document.getElementById('eshopAlertTotal').textContent = money(it.total || 0);
        document.getElementById('eshopAlertCustomer').textContent = it.customer || '—';
        setRow('eshopAlertPhoneRow', 'eshopAlertPhone', it.phone || '');
        setRow('eshopAlertEmailRow', 'eshopAlertEmail', it.email || '');
        setRow('eshopAlertNoteRow', 'eshopAlertNote', it.note || '');
        document.getElementById('eshopAlertLines').textContent = (it.lines && it.lines.length) ? it.lines.join('\n') : '—';
        document.getElementById('eshopAlertTime').textContent = it.time || '—';
        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');
        if (window.afxChime) { window.afxChime('assign'); }
        requestAnimationFrame(function(){ overlay.classList.add('show'); });
    }
    function close(){
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
        setTimeout(function(){ overlay.style.display = 'none'; showing = null; next(); }, 200);
    }
    function next(){ if (showing) return; var it = queue.shift(); if (!it) return; open(it); }
    // ZÁMĚRNĚ žádné zavírání klikem mimo ani Escape — potvrzuje se jen tlačítkem
    ackBtn.addEventListener('click', function(){
        if (!showing) { close(); return; }
        ackBtn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'ack');
        fd.append('order_id', showing.id);
        fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        fetch('api/eshop_order_alerts.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                ackBtn.disabled = false;
                if (d && d.ok) { close(); }
                else { alert('Potvrzení se neuložilo — zkus to znovu.' + (d && d.error ? ' (' + d.error + ')' : '')); }
            })
            .catch(function(){ ackBtn.disabled = false; alert('Potvrzení se neuložilo (síť) — zkus to znovu.'); });
    });
    function poll(){
        fetch('api/eshop_order_alerts.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok || !d.items) return;
                d.items.forEach(function(it){
                    if (queuedIds[it.id]) return;   // objednávka se vrací, dokud není potvrzená — nefrontovat 2×
                    queuedIds[it.id] = true;
                    queue.push(it);
                });
                next();
            })
            .catch(function(){});
    }
    setTimeout(poll, 2500);
    setInterval(poll, 20000);
})();
</script>
<?php endif; ?>
</body>
</html>
