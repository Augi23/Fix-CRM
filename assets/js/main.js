/**
 * CRM Main JavaScript
 */

// Global modal instances
let globalPreviewModal = null;
let globalAlertModal = null;
let globalConfirmModal = null;
let activePreviewUrl = null;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
    const sidebarCollapse = document.getElementById('sidebarCollapse');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const mobileSidebarQuery = window.matchMedia('(max-width: 991.98px)');

    const closeMobileSidebar = function() {
        if (!sidebar || !mobileSidebarQuery.matches) return;
        sidebar.classList.remove('active');
        if (content) content.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    };

    if (sidebarCollapse && sidebar) {
        sidebarCollapse.addEventListener('click', function() {
            const isActive = sidebar.classList.toggle('active');
            if (content) content.classList.toggle('active', isActive);

            if (mobileSidebarQuery.matches) {
                document.body.classList.toggle('sidebar-open', isActive);
            }
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);
    }

    document.querySelectorAll('#sidebar .nav-link').forEach(function(link) {
        link.addEventListener('click', closeMobileSidebar);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileSidebar();
        }
    });

    if (mobileSidebarQuery && typeof mobileSidebarQuery.addEventListener === 'function') {
        mobileSidebarQuery.addEventListener('change', function(e) {
            if (!e.matches) {
                document.body.classList.remove('sidebar-open');
                if (sidebar) sidebar.classList.remove('active');
                if (content) content.classList.remove('active');
            }
        });
    }

    const notificationsToggle = document.getElementById('notificationsToggle');
    const notificationsPanel = document.getElementById('crmNotificationsPanel');
    const notificationsPanelClose = document.getElementById('notificationsPanelClose');

    const closeNotificationsPanel = function() {
        if (!notificationsPanel) return;
        notificationsPanel.classList.remove('is-open');
        notificationsPanel.setAttribute('aria-hidden', 'true');
    };

    if (notificationsToggle && notificationsPanel) {
        notificationsToggle.addEventListener('click', function() {
            const nextOpen = !notificationsPanel.classList.contains('is-open');
            notificationsPanel.classList.toggle('is-open', nextOpen);
            notificationsPanel.setAttribute('aria-hidden', nextOpen ? 'false' : 'true');
        });
    }

    if (notificationsPanelClose) {
        notificationsPanelClose.addEventListener('click', closeNotificationsPanel);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeNotificationsPanel();
        }
    });

    // Keep modal accessibility fix minimal and avoid fighting Bootstrap layering
    $(document).on('show.bs.modal shown.bs.modal', '.modal', function() {
        this.removeAttribute('aria-hidden');
        this.style.pointerEvents = 'auto';
        setTimeout(() => this.removeAttribute('aria-hidden'), 0);
    });

    $(document).on('hidden.bs.modal', '.modal', function() {
        document.body.style.removeProperty('padding-right');
    });

    // Initialize Global Modals
    initGlobalModals();

    // Emergency fallback: ensure modal forms submit normally even after redesign regressions
    $(document).on('click', '.modal button[type="submit"]', function(e) {
        const form = this.closest('form');
        if (!form) return;
        e.preventDefault();
        form.requestSubmit ? form.requestSubmit(this) : form.submit();
    });

    // Fallback for inline new-customer panel inside order modals
    $(document).on('click', '#toggleNewCustomerPanelBtn', function(e) {
        e.preventDefault();
        const panel = document.getElementById('inlineNewCustomerPanel');
        if (!panel) return;
        panel.classList.add('show');
        panel.style.display = 'block';
        panel.style.height = 'auto';
    });

    $(document).on('change', 'input[name="customer_type"]', function() {
        const panel = document.getElementById('inlineNewCustomerPanel');
        if (!panel) return;
        panel.classList.add('show');
        panel.style.display = 'block';
        panel.style.height = 'auto';
    });

    // Select2 Global Initialization
    if (typeof $.fn.select2 === 'function') {
        $('.select2').select2({ width: '100%' });
        
        $('.select2-tags').select2({
            tags: true,
            width: '100%'
        });
    }

    // Fancybox Global
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind("[data-fancybox]", {
            // Your custom options
        });
    }
});

/**
 * Initialize global modal objects safely
 */
function initGlobalModals() {
    if (typeof bootstrap === 'undefined') return;

    const previewEl = document.getElementById('universalPreviewModal');
    if (previewEl && !globalPreviewModal) {
        globalPreviewModal = new bootstrap.Modal(previewEl);
        
        // Clean up when hidden
        previewEl.addEventListener('hidden.bs.modal', function() {
            document.getElementById('universalPreviewContent').innerHTML = '';
            activePreviewUrl = null;
            // Reset footer buttons
            const printBtn = document.getElementById('previewPrintBtn');
            if (printBtn) printBtn.disabled = true;
        });
    }

    const alertEl = document.getElementById('globalAlertModal');
    if (alertEl && !globalAlertModal) {
        globalAlertModal = new bootstrap.Modal(alertEl);
    }

    const confirmEl = document.getElementById('globalConfirmModal');
    if (confirmEl && !globalConfirmModal) {
        globalConfirmModal = new bootstrap.Modal(confirmEl);
    }
}

/**
 * Show a global alert
 */
function showAlert(message, title = window.LANG_NOTICE || 'Notice') {
    if (!globalAlertModal) initGlobalModals();
    
    document.getElementById('globalAlertTitle').innerText = title;
    document.getElementById('globalAlertBody').innerHTML = message;
    
    if (globalAlertModal) {
        globalAlertModal.show();
    } else {
        alert(message);
    }
}

/**
 * Show a global confirmation
 */
function showConfirm(message, onConfirm, title = window.LANG_CONFIRM || 'Confirm') {
    if (!globalConfirmModal) initGlobalModals();
    
    document.getElementById('globalConfirmTitle').innerText = title;
    document.getElementById('globalConfirmBody').innerHTML = message;
    
    const okBtn = document.getElementById('globalConfirmOk');
    const cancelBtn = document.getElementById('globalConfirmCancel');
    
    // Remote old listeners
    const newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    
    newOk.addEventListener('click', function() {
        globalConfirmModal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    if (globalConfirmModal) {
        globalConfirmModal.show();
    } else {
        if (confirm(message)) onConfirm();
    }
}

/**
 * Open universal preview modal with an iframe
 */
function openUniversalPreview(url, title = window.LANG_PREVIEW || 'Preview') {
    if (!globalPreviewModal) initGlobalModals();
    
    activePreviewUrl = url;
    const titleEl = document.getElementById('universalPreviewTitle');
    const contentEl = document.getElementById('universalPreviewContent');
    const printBtn = document.getElementById('previewPrintBtn');
    const openTabBtn = document.getElementById('previewOpenTabBtn');
    
    if (titleEl) titleEl.innerText = title;
    if (printBtn) printBtn.disabled = true;
    if (openTabBtn) openTabBtn.href = url;
    
    if (contentEl) {
        contentEl.innerHTML = '';
    }
    
    if (!globalPreviewModal) {
        // Fallback if modal initialization failed
        window.open(url, '_blank');
        return;
    }

    // Determine if this is a thermal/receipt document (narrow) or A4
    const isThermal = url.includes('thermal') || url.includes('reception');
    
    // Create iframe FIRST, add to DOM, THEN set src
    const iframe = document.createElement('iframe');
    iframe.id = 'previewIframe';
    iframe.style.width = '100%';
    iframe.style.minHeight = isThermal ? '60vh' : '80vh';
    iframe.style.height = isThermal ? '60vh' : '80vh';
    iframe.style.border = 'none';
    iframe.style.background = '#fff';
    iframe.style.display = 'none'; // Hidden until loaded
    
    // Add spinner placeholder
    const spinner = document.createElement('div');
    spinner.id = 'previewSpinner';
    spinner.className = 'text-center py-5';
    spinner.innerHTML = '<div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-white-75 small">Загрузка документа...</p>';
    
    contentEl.appendChild(spinner);
    contentEl.appendChild(iframe);
    
    // Handle load event
    iframe.onload = function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl) spinnerEl.remove();
        iframe.style.display = 'block';

        // Auto-resize iframe to fit content
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (doc && doc.body) {
                const h = doc.body.scrollHeight + 40;
                if (h > 200) {
                    iframe.style.height = Math.min(h, window.innerHeight * 0.85) + 'px';
                }
            }
        } catch(e) {
            // cross-origin, ignore
        }

        if (printBtn) printBtn.disabled = false;
    };

    iframe.onerror = function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl) {
            spinnerEl.innerHTML = '<div class="alert alert-warning m-3"><i class="fas fa-exclamation-triangle me-2"></i>Не удалось загрузить превью. <a href="' + url + '" target="_blank" class="alert-link">Открыть в новой вкладке</a></div>';
        }
    };
    
    // Set timeout fallback - if iframe doesn't load in 8 seconds
    const loadTimeout = setTimeout(function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl && iframe.style.display === 'none') {
            spinnerEl.innerHTML = '<div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>Загрузка занимает больше времени... <a href="' + url + '" target="_blank" class="alert-link">Открыть в новой вкладке</a></div>';
        }
    }, 8000);

    // Clean timeout on successful load
    const origOnload = iframe.onload;
    iframe.onload = function() {
        clearTimeout(loadTimeout);
        origOnload.call(this);
    };

    // Now set source - iframe is already in DOM so onload will fire
    // Add parameter to prevent auto-print when inside iframe
    const separator = url.includes('?') ? '&' : '?';
    iframe.src = url + separator + 'embed=1';

    globalPreviewModal.show();
}

/**
 * Print the content of the universal preview (directly from iframe)
 */
function printUniversalPreview() {
    if (!activePreviewUrl) return;
    
    const iframe = document.getElementById('previewIframe');
    
    if (iframe && iframe.contentWindow) {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch(e) {
            // Cross-origin fallback: open in new tab for printing
            const w = window.open(activePreviewUrl, '_blank');
            if (w) {
                w.onload = function() { w.print(); };
            }
        }
    } else {
        // No iframe available
        const w = window.open(activePreviewUrl, '_blank');
        if (w) {
            w.onload = function() { w.print(); };
        }
    }
}

/**
 * Open preview URL in a new tab
 */
function openPreviewInNewTab() {
    if (activePreviewUrl) {
        window.open(activePreviewUrl, '_blank');
    }
}

/* ═══════════════════════════════════════════════════════════
   NEW ORDER WIZARD — 3-step navigation
   ═══════════════════════════════════════════════════════════ */
(function() {
    function initWizard(modal) {
        if (!modal || modal.dataset.wizardInit) return;
        modal.dataset.wizardInit = '1';
        var steps = modal.querySelectorAll('.crm-wizard-step');
        var segs  = modal.querySelectorAll('.crm-wizard-seg');
        var prevBtn = modal.querySelector('[data-wizard-prev]');
        var actionBtn = modal.querySelector('[data-wizard-action]');
        var curEl   = modal.querySelector('[data-wizard-current]');
        var form = modal.querySelector('form');
        var cur = 1;
        var total = steps.length || 3;

        function render() {
            steps.forEach(function(s){ s.hidden = (parseInt(s.dataset.step,10) !== cur); });
            segs.forEach(function(s){ s.classList.toggle('active', parseInt(s.dataset.seg,10) <= cur); });
            if (curEl) curEl.textContent = cur;
            if (prevBtn) prevBtn.hidden = (cur === 1);
            if (actionBtn) {
                actionBtn.textContent = (cur === total) ? 'Vytvořit zakázku' : 'Pokračovat →';
            }
            if (cur === total) fillSummary();
        }

        function fillSummary() {
            if (!form) return;
            var fd = new FormData(form);
            var fn = (fd.get('first_name')||'').toString().trim();
            var ln = (fd.get('last_name')||'').toString().trim();
            var cname = (fn+' '+ln).trim();
            if (!cname) {
                var sel = form.querySelector('select[name="customer_id"]');
                if (sel && sel.options[sel.selectedIndex]) cname = sel.options[sel.selectedIndex].text || '—';
            }
            var brand = (fd.get('device_brand')||'').toString();
            var model = (fd.get('device_model')||'').toString();
            var device = (brand+' '+model).trim() || '—';
            var type = (fd.get('device_type')||'').toString() || '—';
            var prio = fd.get('priority') === 'High' ? 'Urgentní' : 'Normální';
            var set = function(k,v){ var el = modal.querySelector('[data-summary="'+k+'"]'); if(el) el.textContent = v || '—'; };
            set('customer', cname || '—');
            set('device', device);
            set('service', type);
            set('priority', prio);
        }

        if (prevBtn) prevBtn.addEventListener('click', function(e){ e.preventDefault(); if(cur>1){ cur--; render(); }});
        if (actionBtn) actionBtn.addEventListener('click', function(e){
            e.preventDefault();

            if (cur === 1) {
                var customerSelect = modal.querySelector('select[name="customer_id"]');
                if (!customerSelect || !customerSelect.value) {
                    if (typeof window.showAlert === 'function') {
                        window.showAlert('Vyber prosím klienta ze seznamu nebo nejdřív ulož nového klienta.');
                    }
                    return;
                }
                cur++; render();
            } else if (cur === 2) {
                var fd = form ? new FormData(form) : null;
                var requiredKeys = ['device_type', 'order_type', 'device_brand', 'device_model', 'problem_description'];
                for (var k = 0; k < requiredKeys.length; k++) {
                    var key = requiredKeys[k];
                    var val = fd ? (fd.get(key) || '').toString().trim() : '';
                    if (!val) {
                        var field = modal.querySelector('[name="' + key + '"]');
                        if (field && typeof field.reportValidity === 'function') {
                            field.reportValidity();
                        }
                        return;
                    }
                }
                cur++; render();
            } else if (cur === total) {
                if (form) form.submit();
            }
        });

        modal.addEventListener('hidden.bs.modal', function(){ cur = 1; render(); });
        render();
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.crm-wizard-modal').forEach(initWizard);
    });
})();

/* Sidebar avatar: 2 initials */
document.addEventListener('DOMContentLoaded', function() {
    var av = document.querySelector('.crm-v2-avatar');
    var nm = document.querySelector('.crm-v2-user-name');
    if (av && nm) {
        var parts = (nm.textContent||'').trim().split(/\s+/).filter(Boolean);
        if (parts.length >= 2) av.textContent = (parts[0][0] + parts[1][0]).toUpperCase();
        else if (parts.length === 1 && parts[0].length >= 2) av.textContent = parts[0].substring(0,2).toUpperCase();
    }
});

/* Smart topbar "Nová zakázka": open modal if present, else navigate */
document.addEventListener('DOMContentLoaded', function() {
    var top = document.getElementById('crmTopbarNewOrder');
    var modalEl = document.getElementById('newOrderModal');
    if (top && modalEl && typeof bootstrap !== 'undefined') {
        top.addEventListener('click', function(e){
            e.preventDefault();
            var m = bootstrap.Modal.getOrCreateInstance(modalEl);
            m.show();
        });
    }
    /* Auto-open if URL hash points to #newOrderModal */
    if (modalEl && (window.location.hash === '#newOrderModal') && typeof bootstrap !== 'undefined') {
        setTimeout(function(){ bootstrap.Modal.getOrCreateInstance(modalEl).show(); }, 150);
    }
});
