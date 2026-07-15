/**
 * CRM Main JavaScript
 */

// Global modal instances
let globalPreviewModal = null;
let globalAlertModal = null;
let globalConfirmModal = null;
let activePreviewUrl = null;

// Apple model catalogs for New Order model dropdown (fetched from public release lists)
const CRM_APPLE_MODELS_BY_TYPE = {
    Phone: [
        'iPhone (1st generation)', 'iPhone 3G', 'iPhone 3GS', 'iPhone 4', 'iPhone 4s', 'iPhone 5', 'iPhone 5c', 'iPhone 5s',
        'iPhone 6', 'iPhone 6 Plus', 'iPhone 6s', 'iPhone 6s Plus',
        'iPhone SE (1st generation)',
        'iPhone 7', 'iPhone 7 Plus',
        'iPhone 8', 'iPhone 8 Plus',
        'iPhone X', 'iPhone XR', 'iPhone XS', 'iPhone XS Max',
        'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max',
        'iPhone SE (2nd generation)',
        'iPhone 12 mini', 'iPhone 12', 'iPhone 12 Pro', 'iPhone 12 Pro Max',
        'iPhone 13 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 13 Pro Max',
        'iPhone SE (3rd generation)',
        'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
        'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max',
        'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max', 'iPhone 16e',
        'iPhone 17', 'iPhone Air', 'iPhone 17 Pro', 'iPhone 17 Pro Max', 'iPhone 17e'
    ],
    Notebook: [
        'MacBook (13-inch, A1181 / A1278 / A1342)', 'MacBook (Retina, 12-inch, A1534)',
        'MacBook Air (11-inch, A1370 / A1465)', 'MacBook Air (13-inch, A1237 / A1304 / A1369 / A1466)', 'MacBook Air (Retina, 13-inch, A1932 / A2179)',
        'MacBook Air (M1, 2020, A2337)', 'MacBook Air (M2, 13-inch, A2681)', 'MacBook Air (M2, 15-inch, A2941)',
        'MacBook Air (M3, 13-inch, A3113)', 'MacBook Air (M3, 15-inch, A3114)',
        'MacBook Air (M4, 13-inch, A3402)', 'MacBook Air (M4, 15-inch, A3403)',
        'MacBook Air (M5, 13-inch)', 'MacBook Air (M5, 15-inch)',
        'MacBook Pro (13-inch, A1278)', 'MacBook Pro (15-inch, A1286)', 'MacBook Pro (17-inch, A1151 / A1212 / A1229 / A1261 / A1297)',
        'MacBook Pro (Retina, 13-inch, A1425 / A1502)', 'MacBook Pro (Retina, 15-inch, A1398)',
        'MacBook Pro (13-inch, Touch Bar, A1706 / A1989 / A2159 / A2289 / A2251)', 'MacBook Pro (15-inch, Touch Bar, A1707 / A1990)',
        'MacBook Pro (13-inch, M1, A2338)', 'MacBook Pro (13-inch, M2, A2338)',
        'MacBook Pro (14-inch, M1 Pro/Max, A2442)', 'MacBook Pro (16-inch, M1 Pro/Max, A2485)',
        'MacBook Pro (14-inch, M2 Pro/Max, A2779)', 'MacBook Pro (16-inch, M2 Pro/Max, A2780)',
        'MacBook Pro (14-inch, M3, A2918 / A2992)', 'MacBook Pro (14-inch, M3 Pro/Max, A2918 / A2992)', 'MacBook Pro (16-inch, M3 Pro/Max, A2991)',
        'MacBook Pro (14-inch, M4, A3112 / A3401)', 'MacBook Pro (14-inch, M4 Pro/Max, A3112 / A3401)', 'MacBook Pro (16-inch, M4 Pro/Max, A3185)',
        'MacBook Pro (14-inch, M5 Pro/Max)', 'MacBook Pro (16-inch, M5 Pro/Max)'
    ],
    Tablet: [
        'iPad (1st generation)', 'iPad 2', 'iPad (3rd generation)', 'iPad (4th generation)',
        'iPad (5th generation)', 'iPad (6th generation)', 'iPad (7th generation)', 'iPad (8th generation)',
        'iPad (9th generation)', 'iPad (10th generation)', 'iPad (11th generation)',
        'iPad mini (1st generation)', 'iPad mini 2', 'iPad mini 3', 'iPad mini 4',
        'iPad mini (5th generation)', 'iPad mini (6th generation)', 'iPad mini (7th generation)',
        'iPad Air (1st generation)', 'iPad Air 2', 'iPad Air (3rd generation)',
        'iPad Air (4th generation)', 'iPad Air (5th generation)', 'iPad Air (6th generation)',
        'iPad Air (7th generation)', 'iPad Air (8th generation)',
        'iPad Pro (1st generation)', 'iPad Pro (2nd generation)', 'iPad Pro (3rd generation)',
        'iPad Pro (4th generation)', 'iPad Pro (5th generation)', 'iPad Pro (6th generation)',
        'iPad Pro (7th generation)', 'iPad Pro (8th generation)'
    ],
    PC: [
        'iMac G3', 'iMac G4', 'iMac G5',
        'iMac (Intel)', 'iMac (Retina 4K)', 'iMac (Retina 5K)', 'iMac (24-inch, M1)', 'iMac (24-inch, M3)', 'iMac (24-inch, M4)',
        'iMac Pro',
        'Mac mini (Intel)', 'Mac mini (M1)', 'Mac mini (M2)', 'Mac mini (M2 Pro)', 'Mac mini (M4)', 'Mac mini (M4 Pro)',
        'Mac Studio (M1 Max)', 'Mac Studio (M1 Ultra)', 'Mac Studio (M2 Max)', 'Mac Studio (M2 Ultra)', 'Mac Studio (M4 Max)', 'Mac Studio (M3 Ultra)',
        'Mac Pro (Tower)', 'Mac Pro (2013 Cylinder)', 'Mac Pro (2019 Tower/Rack)', 'Mac Pro (M2 Ultra)'
    ],
    HDD: [
        'AirPort Time Capsule', 'AirPort Extreme'
    ],
    Other: [
        'Apple Watch (1st generation)',
        'Apple Watch Series 1', 'Apple Watch Series 2', 'Apple Watch Series 3', 'Apple Watch Series 4', 'Apple Watch Series 5',
        'Apple Watch Series 6', 'Apple Watch Series 7', 'Apple Watch Series 8', 'Apple Watch Series 9', 'Apple Watch Series 10', 'Apple Watch Series 11',
        'Apple Watch SE (1st generation)', 'Apple Watch SE (2nd generation)', 'Apple Watch SE (3rd generation)',
        'Apple Watch Ultra', 'Apple Watch Ultra 2', 'Apple Watch Ultra 3',
        'AirPods (1st generation)', 'AirPods (2nd generation)', 'AirPods (3rd generation)', 'AirPods (4th generation)',
        'AirPods Pro (1st generation)', 'AirPods Pro (2nd generation)', 'AirPods Pro (3rd generation)',
        'AirPods Max (Lightning)', 'AirPods Max (USB-C)',
        'Apple TV HD', 'Apple TV 4K (1st generation)', 'Apple TV 4K (2nd generation)', 'Apple TV 4K (3rd generation)', 'Apple TV 4K (4th generation)',
        'HomePod (1st generation)', 'HomePod (2nd generation)', 'HomePod mini',
        'iPod touch (7th generation)', 'Apple Vision Pro'
    ]
};

function crmIsAppleBrand(value) {
    const normalized = String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    return normalized === 'apple' || normalized.includes('apple');
}

window.crmUpdateAppleModelOptions = function(modalEl) {
    if (!modalEl || typeof window.jQuery === 'undefined') return;

    const $modal = window.jQuery(modalEl);
    const $brand = $modal.find('[name="device_brand"]').first();
    const $type = $modal.find('[name="device_type"]').first();
    const $model = $modal.find('[name="device_model"]').first();
    if (!$model.length) return;

    const brand = String($brand.val() || '').trim();
    const type = String($type.val() || '').trim();
    const isApple = crmIsAppleBrand(brand);
    const models = (isApple && CRM_APPLE_MODELS_BY_TYPE[type]) ? CRM_APPLE_MODELS_BY_TYPE[type] : [];
    const current = String($model.val() || '').trim();
    const placeholder = String($model.attr('data-placeholder') || 'Model');

    const hasOption = (val) => $model.find('option').filter(function() {
        return String(this.value) === String(val);
    }).length > 0;

    $model.empty();
    $model.append(new Option(placeholder, '', false, false));

    if (models.length > 0) {
        models.forEach((m) => $model.append(new Option(m, m, false, false)));
        $model.data('appleLocked', '1');
        if (current && hasOption(current)) {
            $model.val(current);
        } else {
            $model.val('');
        }
    } else {
        $model.data('appleLocked', '0');
        if (current) {
            if (!hasOption(current)) {
                $model.append(new Option(current, current, true, true));
            }
            $model.val(current);
        } else {
            $model.val('');
        }
    }

    if ($model.data('select2')) {
        $model.trigger('change.select2');
    }
};

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
            if (mobileSidebarQuery.matches) {
                // Mobil / iPad na výšku: výsuvný off-canvas panel
                const isActive = sidebar.classList.toggle('active');
                if (content) content.classList.toggle('active', isActive);
                document.body.classList.toggle('sidebar-open', isActive);
            } else {
                // Desktop / iPad na šířku: skrýt celý boční panel a roztáhnout obsah (stav se pamatuje)
                const hidden = document.documentElement.classList.toggle('sidebar-hidden');
                try { localStorage.setItem('crm-sidebar-hidden', hidden ? '1' : '0'); } catch (e) {}
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

    // New Order modal helpers (open from #newOrderModal links across pages)
    const openNewOrderModal = () => {
        const modalEl = document.getElementById('newOrderModal');
        if (!modalEl || typeof bootstrap === 'undefined') return false;
        const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.show();
        return true;
    };

    // If user came via orders.php#newOrderModal, open modal automatically
    if (window.location.hash === '#newOrderModal') {
        setTimeout(() => {
            if (openNewOrderModal()) {
                try {
                    history.replaceState(null, '', window.location.pathname + window.location.search);
                } catch (e) {}
            }
        }, 120);
    }

    // On current page, intercept anchor links to #newOrderModal and open modal directly
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href$="#newOrderModal"]');
        if (!link) return;
        if (document.getElementById('newOrderModal')) {
            e.preventDefault();
            openNewOrderModal();
        }
    });
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
        var nextBtn = modal.querySelector('[data-wizard-next]');
        var subBtn  = modal.querySelector('[data-wizard-submit]');
        var curEl   = modal.querySelector('[data-wizard-current]');
        var cur = 1;
        var total = steps.length || 3;

        function render() {
            steps.forEach(function(s){ s.hidden = (parseInt(s.dataset.step,10) !== cur); });
            segs.forEach(function(s){ s.classList.toggle('active', parseInt(s.dataset.seg,10) <= cur); });
            if (curEl) curEl.textContent = cur;
            if (prevBtn) prevBtn.hidden = (cur === 1);
            if (nextBtn) nextBtn.hidden = (cur === total);
            if (subBtn)  subBtn.hidden  = (cur !== total);
            if (cur === total) fillSummary();
        }

        function fillSummary() {
            var form = modal.querySelector('form');
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
            var prioVal = (fd.get('priority') || 'Normal').toString();
            var prio = prioVal === 'High' ? (window.LANG_PRIORITY_HIGH || 'Urgentní')
                     : prioVal === 'Low'  ? (window.LANG_PRIORITY_LOW || 'Klidná')
                                          : (window.LANG_PRIORITY_NORMAL || 'Normální');
            var set = function(k,v){ var el = modal.querySelector('[data-summary="'+k+'"]'); if(el) el.textContent = v || '—'; };
            set('customer', cname || '—');
            set('device', device);
            set('service', type);
            set('priority', prio);
        }

        if (prevBtn) prevBtn.addEventListener('click', function(e){ 
            e.preventDefault(); 
            e.stopPropagation();
            if(cur>1){ cur--; render(); }
        });
        if (nextBtn) nextBtn.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            
            // Critical check: ensure we don't trigger form submit
            if (this.type === 'submit') {
                this.type = 'button';
            }
                var customerSelect = modal.querySelector('select[name="customer_id"]');
                var customerValue = customerSelect ? String(customerSelect.value || '').trim() : '';

                if ((!customerValue) && customerSelect && typeof window.jQuery !== 'undefined') {
                    try {
                        var $customerSelect = window.jQuery(customerSelect);
                        if ($customerSelect.length && typeof $customerSelect.select2 === 'function') {
                            var selected = $customerSelect.select2('data') || [];
                            if (selected.length && selected[0] && selected[0].id) {
                                customerValue = String(selected[0].id).trim();
                                if (customerValue) {
                                    var option = null;
                                    for (var i = 0; i < customerSelect.options.length; i++) {
                                        if (String(customerSelect.options[i].value) === customerValue) {
                                            option = customerSelect.options[i];
                                            break;
                                        }
                                    }
                                    if (!option) {
                                        var optionLabel = selected[0].text || selected[0].name || customerValue;
                                        option = new Option(optionLabel, customerValue, true, true);
                                        customerSelect.add(option);
                                    }
                                    customerSelect.value = customerValue;
                                    $customerSelect.trigger('change');
                                }
                            }
                        }
                    } catch (e) {}
                }

                if (!customerSelect || !customerValue) {
                    if (customerSelect && typeof window.jQuery !== 'undefined') {
                        try { window.jQuery(customerSelect).select2('open'); } catch (e) {}
                    }
                    return;
                }

            if (cur === 2) {
                var form = modal.querySelector('form');
                var fd = form ? new FormData(form) : null;
                var requiredKeys = ['device_type', 'order_type', 'device_brand', 'device_model', 'pin_code', 'problem_description'];
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
            }

            if (cur < total) { cur++; render(); }
        });

        modal.addEventListener('hidden.bs.modal', function(){ cur = 1; render(); });
        render();
    }

    // Globální potvrzení destruktivních formulářů: tlačítko/form s data-confirm
// zobrazí potvrzovací dotaz PŘED odesláním (mazání zaměstnance, admina…).
// Dřív atribut data-confirm nikdo neobsluhoval a mazalo se na jeden klik.
document.addEventListener('submit', function (e) {
    var btn = e.submitter;
    var msg = (btn && btn.dataset && btn.dataset.confirm) || (e.target && e.target.dataset && e.target.dataset.confirm) || '';
    if (msg && !window.confirm(msg)) { e.preventDefault(); e.stopPropagation(); }
}, true);

document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.crm-wizard-modal').forEach(initWizard);
    });
})();

/* ═══════════════════════════════════════════════════════════
   NEW ORDER MODAL & CUSTOMER HELPERS
   ═══════════════════════════════════════════════════════════ */
let currentCustomerSearch = '';
window.escapeHtml = function(text) {
    return $('<div>').text(text).html();
};
function highlightMatch(text, term) {
    if (!term) return escapeHtml(text);
    const safe = escapeHtml(text);
    const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
    return safe.replace(re, '<span class="match">$1</span>');
}

function initNewOrderModalSelects() {
    const $modal = $('#newOrderModal');
    if (!$modal.length) return;
    const $dropdownParent = $modal;

    const $customerSelect = $modal.find('.select2-customer');
    if ($customerSelect.length) {
        if ($customerSelect.data('select2')) {
            $customerSelect.select2('destroy');
        }

        $customerSelect.select2({
            dropdownParent: $dropdownParent,
            width: '100%',
            placeholder: $customerSelect.data('placeholder') || window.LANG_SEARCH_CLIENT || "Search client...",
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: 'api/search_customers.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    currentCustomerSearch = params.term || '';
                    return { q: params.term, page: params.page || 1 };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return { results: data.results, pagination: { more: data.pagination.more } };
                }
            },
            templateResult: function(item) {
                if (item.loading) return item.text;
                const name = item.name || item.text || '';
                const phone = item.phone || '';
                const title = highlightMatch(name, currentCustomerSearch);
                const meta = phone ? '<span class="meta">' + highlightMatch(phone, currentCustomerSearch) + '</span>' : '';
                return $('<div class="customer-option"><div>' + title + '</div>' + meta + '</div>');
            },
            templateSelection: function(item) {
                return item.text || item.name || '';
            },
            escapeMarkup: function(markup) { return markup; }
        });
    }

    const $brandSelect = $modal.find('.select2-brand');
    if ($brandSelect.length) {
        if ($brandSelect.data('select2')) $brandSelect.select2('destroy');
        $brandSelect.select2({
            dropdownParent: $dropdownParent,
            width: '100%',
            tags: true,
            dropdownAutoWidth: false
        });
    }

    const $deviceTypeSelect = $modal.find('.select2-device-type');
    if ($deviceTypeSelect.length) {
        if ($deviceTypeSelect.data('select2')) $deviceTypeSelect.select2('destroy');
        $deviceTypeSelect.select2({
            dropdownParent: $dropdownParent,
            width: '100%',
            minimumResultsForSearch: Infinity,
            dropdownAutoWidth: false
        });
    }

    const $orderTypeSelect = $modal.find('.select2-order-type');
    if ($orderTypeSelect.length) {
        if ($orderTypeSelect.data('select2')) $orderTypeSelect.select2('destroy');
        $orderTypeSelect.select2({
            dropdownParent: $dropdownParent,
            width: '100%',
            minimumResultsForSearch: Infinity,
            dropdownAutoWidth: false
        });
    }

    const $modelSelect = $modal.find('.select2-model');
    if ($modelSelect.length) {
        if ($modelSelect.data('select2')) $modelSelect.select2('destroy');
        $modelSelect.select2({
            dropdownParent: $dropdownParent,
            width: '100%',
            placeholder: $modelSelect.data('placeholder') || "Model",
            tags: true,
            dropdownAutoWidth: false,
            createTag: function(params) {
                const term = $.trim(params.term || '');
                if (!term) return null;
                if (String($modelSelect.data('appleLocked') || '0') === '1') return null;
                return { id: term, text: term, newTag: true };
            }
        });
    }

    if (typeof window.crmUpdateAppleModelOptions === 'function') {
        window.crmUpdateAppleModelOptions($modal[0]);
        $brandSelect.off('change.crmModel').on('change.crmModel', function() {
            window.crmUpdateAppleModelOptions($modal[0]);
        });
        $deviceTypeSelect.off('change.crmModel').on('change.crmModel', function() {
            window.crmUpdateAppleModelOptions($modal[0]);
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // New Order Modal Init
    const $newOrderModal = $('#newOrderModal');
    if ($newOrderModal.length) {
        $newOrderModal.on('shown.bs.modal', initNewOrderModalSelects);

        $newOrderModal.find('.order-template-select').on('change', function() {
            const value = $(this).val();
            if (!value) return;
            const targetName = $(this).data('target');
            const $area = $(this).closest('form').find('textarea[name="' + targetName + '"]');
            if (!$area.length) return;
            const current = $area.val().trim();
            $area.val(current ? (current + "\n" + value) : value).trigger('input');
            $(this).val('');
        });

        // Inline New Customer: company/private toggle
        $newOrderModal.find('input[name="customer_type"]').on('change', function() {
            if ($(this).val() === 'company') {
                $('#inline_company_fields').removeClass('d-none');
                $('#inline_first_name').val('Firma');
                $('#inline_last_name').val('');
            } else {
                $('#inline_company_fields').addClass('d-none');
                $('#inline_first_name').val('');
                $('#inline_last_name').val('');
            }
        });

        // Inline New Customer: ARES fetch
        $('#inline_btn_fetch_ares').on('click', function() {
            const ico = $('#inline_ico_input').val().trim();
            if (!ico) return typeof showAlert === 'function' ? showAlert('Enter ICO') : alert('Enter ICO');
            
            const btn = $(this);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

            $.ajax({
                url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> ARES');
                    if (data && data.obchodniJmeno) {
                        $('#inline_ares_name').val(data.obchodniJmeno);
                        $('#inline_last_name').val(data.obchodniJmeno);
                        $('#inline_first_name').val('Firma');
                        if (data.dic) $('#inline_ares_dic').val(data.dic);
                        if (data.sidlo) {
                            const s = data.sidlo;
                            const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                            $('#inline_address').val(addr.trim());
                        }
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> ARES');
                }
            });
        });

        // Inline New Customer: AJAX submit — namespace .saveCust: na stránkách
        // s vlastním handlerem (orders.php) se tenhle odregistruje, jinak by
        // JEDNO kliknutí odeslalo klienta DVAKRÁT (duplicitní klienti!)
        $('#saveNewCustomerBtn').off('click.saveCust').on('click.saveCust', function() {
            const $panel = $('#newCustomerInlineForm');
            const firstName = $('#inline_first_name').val().trim();
            const lastName = $('#inline_last_name').val().trim();
            const phone = $('#inline_phone').val().trim();
            const email = ($('#inline_email').val() || '').trim();

            if (!firstName || !lastName || !phone || !email || email.indexOf('@') < 1) {
                const missing = !firstName ? '#inline_first_name' : !lastName ? '#inline_last_name' : !phone ? '#inline_phone' : '#inline_email';
                const el = document.querySelector(missing);
                if (el && el.reportValidity) { el.setCustomValidity(''); el.reportValidity(); el.focus(); }
                return;
            }
            
            const btn = $(this);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

            const formData = {
                first_name: firstName,
                last_name: lastName,
                phone: phone,
                email: $panel.find('input[name="inline_email"]').val() || '',
                address: $('#inline_address').val() || '',
                customer_type: $panel.find('input[name="customer_type"]:checked').val() || 'private',
                language: $('#inline_language').val() || 'cs',
                ico: $('#inline_ico_input').val() || '',
                company_name: $('#inline_ares_name').val() || '',
                dic: $('#inline_ares_dic').val() || '',
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            };

            $.post('api/add_customer.php', formData, function(res) {
                btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i> Save');
                if (res.success) {
                    const id = res.id;
                    const label = (lastName + ' ' + firstName).trim() + (phone ? ' (' + phone + ')' : '');
                    const $select = $('.select2-customer');
                    if ($select.length) {
                        const newOption = new Option(label, id, true, true);
                        $select.append(newOption).trigger('change');
                    }
                    // Reset
                    $('#inline_first_name, #inline_last_name, #inline_phone, #inline_ares_name, #inline_ares_dic, #inline_ico_input').val('');
                    $panel.find('input[name="inline_email"]').val('');
                    $('#inline_address').val('');
                    $('#inline_company_fields').addClass('d-none');
                    $panel.find('#inline_type_private').prop('checked', true);
                    const collapseEl = document.getElementById('inlineNewCustomerPanel');
                    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                        const bsCollapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl);
                        bsCollapse.hide();
                    }
                }
            }, 'json');
        });
    }
});

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

/* ═══════════════════════════════════════════════════════════════════════════
   UI scale (zoom) control — měřítko zobrazení UI, levý dolní roh
   Krok 5 %, rozsah 50–200 %, uloženo v localStorage, klik na hodnotu = 100 %.
   Aplikuje se přes CSS zoom na <html> (uniformní škálování celého rozhraní).
   ═══════════════════════════════════════════════════════════════════════════ */
(function() {
    var KEY = 'crmUiScale';
    var MIN = 50, MAX = 200, STEP = 5, DEF = 100;

    function clamp(v) { return Math.max(MIN, Math.min(MAX, v)); }

    function read() {
        var v = parseInt(localStorage.getItem(KEY), 10);
        if (isNaN(v)) v = DEF;
        return clamp(Math.round(v / STEP) * STEP);
    }

    // Posadí ovladač kousek NAD profilový blok v sidebaru, aby ho nepřekrýval; když je profil
    // při velkém zoomu mimo obraz, spadne zpět do rohu viewportu (a zůstane vždy dostupný).
    function positionZoomCtrl(w) {
        var user = document.querySelector('.crm-v2-user');
        if (!user) return;
        var r = user.getBoundingClientRect();
        var px = Math.max(12, Math.round((window.innerHeight - r.top) + 10));
        w.style.bottom = px + 'px';
    }

    function apply(scale) {
        // zoom na <html> škáluje celé UI uniformně (Chromium)
        document.documentElement.style.zoom = (scale === 100) ? '' : (scale / 100);
        // Ovladač sám drž v konstantní velikosti (kontra-zoom), ať při zvětšení nezmizí ani nenaroste.
        var w = document.getElementById('crmUiZoom');
        if (w) {
            w.style.zoom = (scale === 100) ? '' : (100 / scale);
            positionZoomCtrl(w);
        }
    }

    // Aplikuj uložené měřítko co nejdřív (skript běží v <head>) — minimalizuje probliknutí
    apply(read());

    function build() {
        if (document.getElementById('crmUiZoom')) return;
        var scale = read();

        var wrap = document.createElement('div');
        wrap.className = 'crm-ui-zoom';
        wrap.id = 'crmUiZoom';
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', 'Měřítko zobrazení');
        wrap.innerHTML =
            '<button type="button" class="crm-ui-zoom-btn" data-act="out" title="Zmenšit o 5 %" aria-label="Zmenšit">−</button>' +
            '<button type="button" class="crm-ui-zoom-val" data-act="reset" title="Obnovit na 100 %">' +
                '<i class="fas fa-magnifying-glass"></i><span class="crm-ui-zoom-num">' + scale + '%</span>' +
            '</button>' +
            '<button type="button" class="crm-ui-zoom-btn" data-act="in" title="Zvětšit o 5 %" aria-label="Zvětšit">+</button>';

        var numEl = wrap.querySelector('.crm-ui-zoom-num');
        var outBtn = wrap.querySelector('[data-act="out"]');
        var inBtn  = wrap.querySelector('[data-act="in"]');

        function set(next) {
            next = clamp(next);
            localStorage.setItem(KEY, next);
            apply(next);
            numEl.textContent = next + '%';
            if (outBtn) outBtn.disabled = (next <= MIN);
            if (inBtn)  inBtn.disabled  = (next >= MAX);
        }

        wrap.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-act]');
            if (!btn) return;
            e.preventDefault();
            var act = btn.getAttribute('data-act');
            var cur = read();
            if (act === 'in') set(cur + STEP);
            else if (act === 'out') set(cur - STEP);
            else if (act === 'reset') set(DEF);
        });

        // Vždy fixně ukotvit do rohu viewportu (mimo sidebar, který se při zoomu posune a ovladač
        // by zmizel z dohledu). Tím je tlačítko +/− vždy dostupné.
        wrap.classList.add('crm-ui-zoom--floating');
        document.body.appendChild(wrap);
        // hned aplikuj kontra-zoom + polohu dle aktuálního měřítka
        wrap.style.zoom = (scale === 100) ? '' : (100 / scale);
        positionZoomCtrl(wrap);
        window.addEventListener('resize', function() { positionZoomCtrl(wrap); });

        // počáteční stav disabled tlačítek
        if (outBtn) outBtn.disabled = (scale <= MIN);
        if (inBtn)  inBtn.disabled  = (scale >= MAX);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();

/* ═══════════════════════════════════════════════════════════════════════════
   Select2 + UI zoom: CSS zoom rozhází Select2 pozicování dropdownu (počítá z .offset(),
   které zoom nezohledňuje → dropdown vyskočí mimo pole). Po otevření dropdown
   přepozicujeme přes getBoundingClientRect s korekcí na zoom. Při zoomu 100 % no-op.
   ═══════════════════════════════════════════════════════════════════════════ */
(function() {
    function repositionSelect2Dropdown() {
        var z = parseFloat(document.documentElement.style.zoom) || 1;
        if (Math.abs(z - 1) < 0.001) return;
        var dd = document.querySelector('.select2-container--open .select2-dropdown')
              || document.querySelector('.select2-dropdown');
        if (!dd) return;
        var ddContainer = dd.parentElement;
        var field = document.querySelector('.select2-container--open .select2-selection');
        if (!field || !ddContainer) return;
        var op = ddContainer.offsetParent || document.body;
        var fr = field.getBoundingClientRect();
        var or = op.getBoundingClientRect();
        ddContainer.style.top   = ((fr.bottom - or.top) / z) + 'px';
        ddContainer.style.left  = ((fr.left - or.left) / z) + 'px';
        ddContainer.style.width = (fr.width / z) + 'px';
    }
    if (window.jQuery) {
        jQuery(document).on('select2:open', function() { setTimeout(repositionSelect2Dropdown, 0); });
    }
}());

/* ═══════════════════════════════════════════════════════════════════════════
   QR skener zakázky — tlačítko v topbaru otevře kameru (html5-qrcode, lazy-load),
   po naskenování QR štítku otevře danou zakázku. Knihovna se načte až při použití.
   ═══════════════════════════════════════════════════════════════════════════ */
(function() {
    var SCANNER_LIB = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
    var libPromise = null;
    var scanner = null;

    function loadLib() {
        if (window.Html5Qrcode) return Promise.resolve();
        if (libPromise) return libPromise;
        libPromise = new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = SCANNER_LIB;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return libPromise;
    }

    function navigateToScanned(text) {
        text = (text || '').toString().trim();
        if (!text) return false;
        // celý odkaz na zakázku
        var m = text.match(/view_order\.php\?id=(\d+)/i);
        if (m) { window.location.href = 'view_order.php?id=' + m[1]; return true; }
        try {
            var u = new URL(text);
            if (u.host === window.location.host) { window.location.href = text; return true; }
        } catch (e) {}
        // jinak = číslo zakázky (APFAZ… nebo id) → resolver ho přeloží na zakázku
        window.location.href = 'view_order.php?scan=' + encodeURIComponent(text);
        return true;
    }

    function stopScan() {
        if (!scanner) return;
        try {
            scanner.stop().then(function() { try { scanner.clear(); } catch (e) {} scanner = null; })
                          .catch(function() { scanner = null; });
        } catch (e) { scanner = null; }
    }

    function startScan() {
        var modalEl = document.getElementById('scanOrderModal');
        var msgEl = document.getElementById('qrReaderMsg');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        if (msgEl) msgEl.textContent = '';
        modal.show();
        modalEl.addEventListener('hidden.bs.modal', stopScan, { once: true });

        // Kamera je dostupná jen v zabezpečeném kontextu (HTTPS / localhost).
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            if (msgEl) msgEl.textContent = window.LANG_SCAN_CAMERA_ERROR || 'Kamera není dostupná (nutné HTTPS).';
            return;
        }

        loadLib().then(function() {
            // Read both QR and 1D Code128 — jeden štítek (Code128) pro stolní čtečku i mobil.
            var qrCfg = {};
            if (window.Html5QrcodeSupportedFormats) {
                qrCfg.formatsToSupport = [
                    Html5QrcodeSupportedFormats.QR_CODE,
                    Html5QrcodeSupportedFormats.CODE_128
                ];
            }
            var readerEl = document.getElementById('qrReader');
            if (readerEl) readerEl.innerHTML = '';   // po předchozím skenu ať je element čistý (jinak re-init selže)
            scanner = new Html5Qrcode('qrReader', qrCfg);
            return scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 160 } },
                function onSuccess(decodedText) {
                    if (navigateToScanned(decodedText)) { stopScan(); }
                    else if (msgEl) { msgEl.textContent = window.LANG_SCAN_NOT_FOUND || 'Neznámý kód'; }
                },
                function onError() {}
            );
        }).catch(function(err) {
            var m = ((err && (err.name || err.message)) || '') + '';
            if (msgEl) msgEl.textContent = /NotAllowed|Permission|Denied/i.test(m)
                ? (window.LANG_SCAN_CAMERA_DENIED || 'Přístup ke kameře byl odepřen — povolte kameru v prohlížeči.')
                : (window.LANG_SCAN_CAMERA_ERROR || 'Chyba kamery');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('scanOrderBtn');
        if (!btn) return;
        btn.addEventListener('click', startScan);
        // Přednačti knihovnu skeneru, ať se getUserMedia při kliknutí spustí UVNITŘ user-gesta
        // (jinak — hlavně iOS Safari při prvním použití — se nemusí zobrazit dotaz na kameru).
        var warm = function(){ loadLib().catch(function(){}); };
        if ('requestIdleCallback' in window) requestIdleCallback(warm, { timeout: 4000 });
        else setTimeout(warm, 1500);
    });
}());

/* ═══════════════════════════════════════════════════════════════════════════
   Stolní 1D čtečka (keyboard-wedge, např. X-9100) + české rozložení klávesnice:
   čtečka „napíše" číslice horní řadou → na CZ layoutu z nich vzniknou háčky (+ě š č ř ž ý á í é).
   Globálně zachytíme rychlý sken (mimo editovatelná pole), znaky dekódujeme zpět na čísla
   a otevřeme danou zakázku. Funguje i když čtečka posílá čísla správně (mapuje jen háčky).
   ═══════════════════════════════════════════════════════════════════════════ */
(function() {
    // Číslice horní řady → háčky; navíc CZ QWERTZ prohazuje Y↔Z (proto i písmena v APFAZ…).
    var MAP = {
        '+': '1', 'ě': '2', 'š': '3', 'č': '4', 'ř': '5', 'ž': '6', 'ý': '7', 'á': '8', 'í': '9', 'é': '0',
        'y': 'z', 'z': 'y', 'Y': 'Z', 'Z': 'Y'
    };
    function demangle(s) {
        var out = '';
        for (var i = 0; i < s.length; i++) { out += (MAP[s[i]] || s[i]); }
        return out;
    }
    var buf = '', lastTs = 0, flushTimer = null;

    function flush() {
        if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
        var raw = buf; buf = '';
        if (raw.length < 3) return;
        // Dekóduj jen když je vstup opravdu přepsaný CZ klávesnicí (obsahuje háčky / '+').
        // Když čtečka píše správně (žádné háčky), nech to být — jinak by se Z↔Y chybně prohodilo.
        var mangled = /[+ěščřžýáíé]/.test(raw);
        var value = (mangled ? demangle(raw) : raw).replace(/[^0-9A-Za-z\-]/g, '');
        if (value) { window.location.href = 'view_order.php?scan=' + encodeURIComponent(value); }
    }

    document.addEventListener('keydown', function(e) {
        var ae = document.activeElement;
        var editable = ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' ||
                              ae.tagName === 'SELECT' || ae.isContentEditable);
        if (editable) { buf = ''; return; } // nepleť se do běžného psaní

        var now = Date.now();
        if (now - lastTs > 250) { buf = ''; } // pomalý vstup = nový sken (tolerantnější, ať se dlouhý kód nerozdělí)
        lastTs = now;

        if (e.key === 'Enter') { flush(); return; }
        if (e.key && e.key.length === 1) {
            buf += e.key;
            if (flushTimer) clearTimeout(flushTimer);
            flushTimer = setTimeout(flush, 300); // fallback, když čtečka neposílá Enter
        }
    });
}());

/* ============================================================
   TISK ŠTÍTKU ZAKÁZKY — Brother QL-810W přes lokální můstek
   (print-bridge/stitek_bridge.py na recepčním Macu, port 9110).
   Prohlížeč smí z HTTPS volat http://127.0.0.1 (localhost výjimka).
   ============================================================ */
window.AFX_LABEL_BRIDGE = 'http://127.0.0.1:9110';

window.afxLabelToast = function (msg, ok) {
    var el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:99999;padding:12px 18px;'
        + 'border-radius:12px;font-weight:600;color:#fff;box-shadow:0 8px 32px rgba(0,0,0,.35);'
        + 'background:' + (ok ? 'rgba(52,199,89,.92)' : 'rgba(255,55,95,.92)');
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, ok ? 3500 : 6000);
};

window.printOrderLabel = function (orderId, opts) {
    opts = opts || {};
    // Tisk jde PŘES SERVER (server → tiskárna na pobočce) — funguje z jakéhokoliv
    // zařízení i prohlížeče, bez štítkového můstku na počítači.
    var fd = new FormData();
    fd.append('action', 'print');
    fd.append('id', orderId);
    fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    fetch('api/print_label_server.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) { throw new Error(res.error || 'tisk selhal'); }
            window.afxLabelToast('🏷️ Štítek ' + (res.code || '') + ' odeslán na tiskárnu', true);
        })
        .catch(function (e) {
            if (!opts.silentFail) { window.afxLabelToast('⚠️ Tisk štítku selhal: ' + e.message, false); }
        });
};

/* Zakázkový list — volba tisk / e-mail. Otevře modal #orderDocModal pro danou zakázku. */
window.openOrderDocChoice = function (orderId, code) {
    var el = document.getElementById('orderDocModal');
    if (!el || typeof bootstrap === 'undefined') { window.open('print_order.php?id=' + orderId, '_blank'); return; }
    document.getElementById('orderDocCode').textContent = code ? ('#' + code) : '';
    var msg = document.getElementById('orderDocMsg'); if (msg) msg.textContent = '';
    var printBtn = document.getElementById('orderDocPrintBtn');
    var emailBtn = document.getElementById('orderDocEmailBtn');
    var signBtn = document.getElementById('orderDocSignBtn');
    if (signBtn) {
        signBtn.disabled = false;
        signBtn.onclick = function () {
            signBtn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'create');
            fd.append('order_id', orderId);
            fd.append('sig_type', 'prijem');
            fd.append('email_after', '1');
            fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
            fetch('api/request_signature.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { msg.textContent = j.error || 'Chyba'; signBtn.disabled = false; return; }
                    msg.innerHTML = j.notice === 'no_email'
                        ? '✍️ ' + (window.AFX_DOC_L10N && AFX_DOC_L10N.sentNoEmail || 'Odesláno na tablet. Klient nemá e-mail — list se po podpisu jen uloží s podpisem.')
                        : '✍️ ' + (window.AFX_DOC_L10N && AFX_DOC_L10N.sent || 'Odesláno na podpisový tablet — po podpisu klienta se zakázkový list (i s podpisem) automaticky pošle na jeho e-mail.');
                })
                .catch(function () { msg.textContent = 'Chyba spojení'; signBtn.disabled = false; });
        };
    }
    printBtn.onclick = function () {
        if (typeof openUniversalPreview === 'function') {
            openUniversalPreview('print_order.php?id=' + orderId, 'Zakázkový list');
        } else {
            window.open('print_order.php?id=' + orderId, '_blank');
        }
    };
    emailBtn.onclick = function () {
        emailBtn.disabled = true;
        var old = emailBtn.innerHTML;
        emailBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Odesílám…';
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var fd = new FormData(); fd.append('id', orderId); fd.append('csrf_token', csrf);
        fetch('api/send_order_email.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                emailBtn.disabled = false; emailBtn.innerHTML = old;
                if (d.ok) {
                    msg.className = 'small mt-3 text-success';
                    msg.innerHTML = '<i class="fas fa-check-circle me-1"></i>Odesláno na ' + d.to;
                } else {
                    msg.className = 'small mt-3 text-warning';
                    msg.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>' + (d.error || 'Nepodařilo se odeslat');
                }
            })
            .catch(function () {
                emailBtn.disabled = false; emailBtn.innerHTML = old;
                msg.className = 'small mt-3 text-warning';
                msg.textContent = 'Chyba spojení.';
            });
    };
    bootstrap.Modal.getOrCreateInstance(el).show();
};

// po založení zakázky: štítek na tiskárnu + nabídka zakázkového listu (tisk / e-mail)
(function () {
    var params = new URLSearchParams(window.location.search);
    var createdId = params.get('created_order');
    if (!createdId) { return; }
    params.delete('created_order');
    var clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', clean);
    try { window.printOrderLabel(createdId, {}); } catch (e) {}
    setTimeout(function () { window.openOrderDocChoice(createdId, ''); }, 400);
})();


/* ═══════════════════════════════════════════════════════════
   SKEN ŠTÍTKU DO VYHLEDÁVÁNÍ — oprava „patlanice" ze čtečky
   HW čtečka píše jako klávesnice; s ČESKÝM rozložením vyjdou
   číslice jako +ěščřžýáíé (APFAZ2600485 → APFAZěžééčář).
   Jakmile hodnota po překladu vypadá jako kód zakázky
   (PREFIX+číslice), pole se samo opraví; Enter ze čtečky pak
   formulář odešle a orders.php otevře detail zakázky rovnou.
   Běžné psaní (jména s diakritikou, telefony) se NEmění.
   ═══════════════════════════════════════════════════════════ */
(function () {
    // stejné mapování jako globální wedge výše: číslice horní řady → háčky
    // a CZ QWERTZ prohazuje Y↔Z (APFAZ → APFAY). Aplikuje se JEN když vstup
    // opravdu obsahuje háčky (= přepsáno českou klávesnicí) — běžné psaní nemění.
    var MAP = { '+':'1', 'ě':'2', 'š':'3', 'č':'4', 'ř':'5', 'ž':'6', 'ý':'7', 'á':'8', 'í':'9', 'é':'0',
                'y':'z', 'z':'y', 'Y':'Z', 'Z':'Y' };
    function demangle(s) {
        var out = '';
        for (var i = 0; i < s.length; i++) { out += (MAP[s[i]] || s[i]); }
        return out;
    }
    var PATTERN = /^[A-Za-z]{2,10}\d{4,}$/;
    function attach(inp) {
        if (!inp || inp.__scanFix) return;
        inp.__scanFix = true;
        var lastT = 0, burst = 0, nav = null;
        inp.addEventListener('input', function () {
            var now = Date.now();
            burst = (now - lastT < 90) ? burst + 1 : 0;   // čtečka sype znaky <90 ms po sobě
            lastT = now;
            var v = inp.value;
            if (/[+ěščřžýáíé]/.test(v)) {                  // přepsáno CZ klávesnicí -> přeložit
                var f = demangle(v);
                if (PATTERN.test(f)) { inp.value = f; v = f; }
            }
            if (nav) { clearTimeout(nav); nav = null; }
            // rychlá dávka + tvar kódu zakázky => po 200 ms klidu rovnou otevřít detail
            // (resolver view_order.php?scan= při neshodě spadne do běžného hledání)
            if (burst >= 4 && PATTERN.test(v)) {
                nav = setTimeout(function () {
                    window.location.href = 'view_order.php?scan=' + encodeURIComponent(inp.value);
                }, 200);
            }
        });
    }
    function init() {
        document.querySelectorAll('.crm-navbar-search input[name="search"], form input[name="search"]').forEach(attach);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/* ═══════════════════════════════════════════════════════════════════════════
   AMBIENTNÍ ZVUKY ZAMĚSTNANCE (např. Khalil): každých ~N minut náhodná hláška,
   dokud je přihlášen. Konfiguraci vkládá footer.php (window.AFX_AMBIENT_SOUNDS,
   AFX_AMBIENT_INTERVAL_MIN). Kadence se drží v localStorage, takže přežívá
   přechody mezi stránkami. Když prohlížeč zablokuje autoplay, hláška se
   přehraje při nejbližším kliknutí/klávese.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    // POZOR: main.js se načítá v <head>, ale konfiguraci (AFX_AMBIENT_SOUNDS)
    // vypisuje až footer → inicializovat AŽ po načtení DOM. Okamžitý start
    // viděl vždy undefined a funkce se nikdy nespustila (oprava 2026-07).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAmbient);
    } else {
        initAmbient();
    }

    function initAmbient() {
        var SOUNDS = window.AFX_AMBIENT_SOUNDS;
        if (!SOUNDS || !SOUNDS.length) { return; }
        var EVERY_MS = (window.AFX_AMBIENT_INTERVAL_MIN || 10) * 60 * 1000;
        var KEY = 'afx_ambient_next';
        var pending = false;

        function next(ts) { try { localStorage.setItem(KEY, String(ts)); } catch (e) {} }
        function getNext() {
            try { var v = parseInt(localStorage.getItem(KEY) || '0', 10); return isNaN(v) ? 0 : v; }
            catch (e) { return 0; }
        }
        if (!getNext()) { next(Date.now() + EVERY_MS); }   // první hláška ~10 min po přihlášení

        function playRandom() {
            var url = SOUNDS[Math.floor(Math.random() * SOUNDS.length)];
            var a = new Audio(url);
            a.volume = 1.0;
            a.play().then(function () { pending = false; })
                    .catch(function () { pending = true; });   // autoplay blok -> počkej na interakci
        }

        // po první interakci přehraj případnou čekající hlášku
        ['click', 'keydown', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, function () {
                if (pending) { pending = false; playRandom(); }
            }, { passive: true });
        });

        setInterval(function () {
            if (Date.now() >= getNext()) {
                next(Date.now() + EVERY_MS);   // rezervuj další slot hned (víc otevřených karet = 1 přehrání)
                playRandom();
            }
        }, 20000);
    }
}());


// Wizard: příplatek (Urgentní) / sleva (Klidná) k prioritě — vstup se ukáže dle volby
$(document).on('change', '#priorityHighModal', function () {
    const v = $(this).val();
    const $w = $('#priorityAdjustWrap');
    if (!$w.length) return;
    if (v === 'High') {
        $('#priorityAdjustLabel').text($w.data('label-high'));
        $w.show();
    } else if (v === 'Low') {
        $('#priorityAdjustLabel').text($w.data('label-low'));
        $w.show();
    } else {
        $w.hide();
        $('#priorityAdjust').val('');
    }
});

// ── Ceník oprav z applefix.cz: navázáno na EXISTUJÍCÍ pole Značka+Model ──
// Po výběru modelu se nabídnou opravy s cenami; výběr předvyplní závadu a cenu.
function afxLoadPricelistRepairs() {
    var $modal = $('#newOrderModal');
    var $r = $('#pricelistRepair');
    if (!$r.length) return;
    var brand = $modal.find('select[name="device_brand"]').val() || '';
    var model = $modal.find('select[name="device_model"]').val() || '';
    if (!brand || !model) {
        $r.prop('disabled', true).empty().append(new Option($r.data('ph-empty') || 'Nejdřív vyber model', ''));
        return;
    }
    fetch('api/pricelist.php?op=repairs&brand=' + encodeURIComponent(brand) + '&model=' + encodeURIComponent(model))
        .then(function (x) { return x.json(); })
        .then(function (j) {
            var rows = j.results || [];
            $r.empty();
            if (!rows.length) {
                $r.prop('disabled', true).append(new Option($r.data('ph-nomatch') || 'Model není v ceníku z webu', ''));
                return;
            }
            $r.append(new Option($r.data('ph-pick') || '— vybrat opravu z ceníku —', ''));
            rows.forEach(function (row) {
                var label = row.repair_name + (row.variant ? ' — ' + row.variant : '');
                var priceTxt = row.price !== null ? Number(row.price).toLocaleString('cs-CZ') + ' Kč' : 'cena na dotaz';
                var o = new Option(label + '  ·  ' + priceTxt, label);
                o.dataset.price = row.price !== null ? row.price : '';
                $r.append(o);
            });
            $r.prop('disabled', false);
        })
        .catch(function () { $r.prop('disabled', true); });
}
$(document).on('change', '#newOrderModal select[name="device_model"], #newOrderModal select[name="device_brand"]', afxLoadPricelistRepairs);
$(document).on('shown.bs.modal', '#newOrderModal', afxLoadPricelistRepairs);

// Výběr opravy PŘIDÁVÁ položku (jedno zařízení může mít víc oprav) — chips + součet
window.afxPricelistItems = window.afxPricelistItems || [];
function afxRenderPricelistItems() {
    var items = window.afxPricelistItems;
    var $box = $('#pricelistChosen');
    $box.empty();
    items.forEach(function (it, idx) {
        var priceTxt = it.price !== null ? Number(it.price).toLocaleString('cs-CZ') + ' Kč' : 'na dotaz';
        $('<span class="badge bg-dark border border-secondary d-inline-flex align-items-center" style="gap:6px;font-weight:500;">')
            .append(document.createTextNode(it.label + ' · ' + priceTxt))
            .append($('<button type="button" class="btn-close btn-close-white" style="font-size:9px;" aria-label="×">').on('click', function () {
                window.afxPricelistItems.splice(idx, 1);
                afxRenderPricelistItems();
            }))
            .appendTo($box);
    });
    // hidden input + předvyplnění popisu závady a ceny
    $('#pricelistItems').val(items.length ? JSON.stringify(items) : '');
    if (items.length) {
        $('#newOrderModal textarea[name="problem_description"]').val(items.map(function (i) { return i.label; }).join(', '));
        var sum = items.reduce(function (a, i) { return a + (i.price !== null ? Number(i.price) : 0); }, 0);
        $('#newOrderModal input[name="estimated_cost"]').val(sum > 0 ? sum : '');
    }
}
$(document).on('hidden.bs.modal', '#newOrderModal', function () {
    window.afxPricelistItems = [];
    afxRenderPricelistItems();
});
$(document).on('change', '#pricelistRepair', function () {
    var o = this.options[this.selectedIndex];
    if (!o || !o.value) return;
    var label = o.value;
    var price = o.dataset.price !== '' ? Number(o.dataset.price) : null;
    var dupe = window.afxPricelistItems.some(function (i) { return i.label === label; });
    if (!dupe) {
        window.afxPricelistItems.push({ label: label, price: price });
        afxRenderPricelistItems();
    }
    this.selectedIndex = 0;   // připraveno na další položku
});


/* ═══════════════════════════════════════════════════════════════════════════
   ZVUKOVÁ UPOZORNĚNÍ — decentní syntetizované tóny (WebAudio, žádné soubory).
   'order' = nová zakázka (vzestupná kvinta), 'status' = změna stavu (krátké
   ťuknutí), 'assign' = přidělení technikovi (výraznější trojtón s popupem).
   Prohlížeč pouští zvuk až po první interakci se stránkou — do té doby se
   poslední upozornění podrží a přehraje při prvním kliknutí.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    var pendingKind = null;
    function ctx() {
        try {
            window.__afxAC = window.__afxAC || new (window.AudioContext || window.webkitAudioContext)();
            return window.__afxAC;
        } catch (e) { return null; }
    }
    window.afxChime = function (kind) {
        var ac = ctx();
        if (!ac) return;
        if (ac.state === 'suspended') {
            ac.resume().catch(function () {});
            if (ac.state === 'suspended') { pendingKind = kind; return; }
        }
        var notes = kind === 'assign' ? [[784, 0], [988, 0.11], [1319, 0.22]]
                  : kind === 'chat'   ? [[988, 0], [1319, 0.10], [988, 0.20], [1319, 0.30]]
                  : kind === 'status' ? [[880, 0]]
                  : [[659, 0], [988, 0.13]];
        var t0 = ac.currentTime + 0.02;
        notes.forEach(function (n) {
            var o = ac.createOscillator(), g = ac.createGain();
            o.type = 'sine'; o.frequency.value = n[0];
            var t = t0 + n[1];
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime((kind === 'assign' || kind === 'chat') ? 0.6 : 0.4, t + 0.018);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.55);
            o.connect(g); g.connect(ac.destination);
            o.start(t); o.stop(t + 0.6);
        });
    };
    ['click', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            var ac = window.__afxAC;
            if (ac && ac.state === 'suspended') { ac.resume().catch(function () {}); }
            if (pendingKind) { var k = pendingKind; pendingKind = null; setTimeout(function () { window.afxChime(k); }, 60); }
        }, { passive: true });
    });
}());

/* Poller: nová zakázka / změna stavu → zvuk + živá počítadla v doku.
   Stav v localStorage (afx_notify_state) — první karta, která změnu uvidí,
   ji „zarezervuje", takže víc otevřených karet nehraje vícekrát. */
(function () {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }

    function init() {
        if (!document.querySelector('.afx-dock')) return;   // jen stránky s dokem (přihlášený personál)
        var KEY = 'afx_notify_state';

        function getState() {
            try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
        }
        function setState(st) {
            try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {}
        }
        function setBadge(href, count, warn) {
            var cell = document.querySelector('.afx-dock a[href="' + href + '"]');
            if (!cell) return;
            var b = cell.querySelector('.afx-badge');
            if (count > 0) {
                if (!b) {
                    b = document.createElement('span');
                    b.className = 'afx-badge' + (warn ? ' afx-badge--warn' : '');
                    cell.prepend(b);
                }
                b.textContent = count;
            } else if (b) { b.remove(); }
        }

        function tick() {
            var chatSeen = 0;
            try { chatSeen = parseInt(localStorage.getItem('afx_chat_seen') || '0', 10) || 0; } catch (e) {}
            fetch('api/notify_poll.php?chat_seen=' + chatSeen, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) return;
                    setBadge('orders.php', d.orders_badge, false);
                    setBadge('reklamace.php', d.complaints_badge, true);
                    setBadge('procurement.php', d.procurement_badge, false);
                    setBadge('chat.php', d.chat_unread || 0, false);
                    var prev = getState();
                    var now = { o: d.last_order_id, l: d.last_status_log_id, c: d.last_chat_other_id || 0 };
                    if (!prev) { setState(now); return; }          // první běh: jen zapamatovat
                    var chatNew = now.c > (prev.c || 0);
                    if (now.o === prev.o && now.l === prev.l && !chatNew) return;
                    setState(now);                                  // rezervace: další karty už nehrají
                    if (window.afxChime) {
                        // zprávu na otevřené stránce chatu ohlásí chat sám
                        if (chatNew && location.pathname.indexOf('chat.php') === -1) {
                            window.afxChime('chat');
                        } else if (now.o !== prev.o || now.l !== prev.l) {
                            window.afxChime(now.o > prev.o ? 'order' : 'status');
                        }
                    }
                })
                .catch(function () {});
        }
        setTimeout(tick, 4000);
        setInterval(tick, 20000);
    }
}());


/* ═══════════════════════════════════════════════════════════════════════════
   PODPISOVÝ PAD — celoobrazovkové podepisování prstem/perem (iPad u pultu).
   Znovupoužitelný: volá ho detail zakázky i podpisová stanice.
   afxSignaturePad({ title, subtitle, terms, onSave(dataUrl), onCancel })
   ═══════════════════════════════════════════════════════════════════════════ */
window.afxSignaturePad = function (opts) {
    opts = opts || {};
    var ov = document.createElement('div');
    ov.className = 'afx-signpad-overlay';
    ov.innerHTML =
        '<div class="afx-signpad">' +
        '  <div class="afx-signpad-head">' +
        '    <div><div class="afx-signpad-title"></div><div class="afx-signpad-sub"></div></div>' +
        '  </div>' +
        '  <div class="afx-signpad-canvaswrap"><canvas></canvas><div class="afx-signpad-line"></div></div>' +
        '  <div class="afx-signpad-terms"></div>' +
        '  <div class="afx-signpad-actions">' +
        '    <button type="button" class="btn btn-outline-secondary btn-lg" data-act="clear"></button>' +
        '    <div class="flex-grow-1"></div>' +
        '    <button type="button" class="btn btn-outline-secondary btn-lg" data-act="cancel"></button>' +
        '    <button type="button" class="btn btn-success btn-lg px-4" data-act="save" disabled></button>' +
        '  </div>' +
        '</div>';
    document.body.appendChild(ov);

    var L = window.AFX_SIGN_L10N || {};
    ov.querySelector('.afx-signpad-title').textContent = opts.title || L.title || 'Podpis klienta';
    ov.querySelector('.afx-signpad-sub').textContent = opts.subtitle || '';
    ov.querySelector('.afx-signpad-terms').textContent = opts.terms || '';
    ov.querySelector('[data-act="clear"]').textContent = L.clear || 'Smazat';
    ov.querySelector('[data-act="cancel"]').textContent = L.cancel || 'Zrušit';
    ov.querySelector('[data-act="save"]').textContent = L.save || 'Uložit podpis';

    var canvas = ov.querySelector('canvas');
    var wrap = ov.querySelector('.afx-signpad-canvaswrap');
    var ctx, dpr = Math.max(1, window.devicePixelRatio || 1);
    var drawing = false, last = null, strokes = 0;
    var saveBtn = ov.querySelector('[data-act="save"]');

    function fit() {
        var r = wrap.getBoundingClientRect();
        canvas.width = Math.round(r.width * dpr);
        canvas.height = Math.round(r.height * dpr);
        canvas.style.width = r.width + 'px';
        canvas.style.height = r.height + 'px';
        ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        ctx.lineWidth = 2.6;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#101418';
    }
    requestAnimationFrame(fit);

    function pos(e) {
        var r = canvas.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }
    canvas.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        canvas.setPointerCapture(e.pointerId);
        drawing = true; last = pos(e);
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!drawing) return;
        e.preventDefault();
        var p = pos(e);
        // vyhlazení: čára do středu úsečky přes quadratic curve
        var mid = { x: (last.x + p.x) / 2, y: (last.y + p.y) / 2 };
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        strokes++;
        if (strokes > 6) { saveBtn.disabled = false; }
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
        canvas.addEventListener(ev, function () { drawing = false; last = null; });
    });

    function close() { ov.remove(); }
    ov.querySelector('[data-act="clear"]').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        strokes = 0; saveBtn.disabled = true;
    });
    ov.querySelector('[data-act="cancel"]').addEventListener('click', function () {
        close();
        if (opts.onCancel) opts.onCancel();
    });
    saveBtn.addEventListener('click', function () {
        if (strokes <= 6) return;
        saveBtn.disabled = true;
        var dataUrl = canvas.toDataURL('image/png');
        close();
        if (opts.onSave) opts.onSave(dataUrl);
    });
    return { close: close };
};
