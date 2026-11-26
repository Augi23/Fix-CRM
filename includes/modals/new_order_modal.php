<?php
/**
 * Global New Order Modal (3-step wizard)
 * This file is included in footer.php so it's available on all pages.
 */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../product_catalog.php';
$techs_list_modal = getActiveTechnicians();   // technici SVÉ pobočky (admin/Boss všichni) — pobočková izolace
$branches_modal = getBranches();
$order_templates_modal = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)get_setting('order_templates', '')))));
$order_note_templates_modal = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)get_setting('order_note_templates', '')))));
/* Značky i modely = číselník + skladový katalog + hodnoty dopsané v dřívějších
   zakázkách (viz crmCatalogRegisterOrderDevice v product_catalog.php). */
$crm_order_brands_modal = crmOrderBrands();
$crm_order_model_catalog = crmOrderModelCatalog();
?>
<div class="modal fade crm-wizard-modal" id="newOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_order.php" method="POST" enctype="multipart/form-data" id="newOrderForm" data-draft-key="new-order">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-2">
                    <?php /* „Krok 1 ze 3" na středu ve stejném řádku jako nadpis — nižší záhlaví */ ?>
                    <div class="w-100 d-flex align-items-center position-relative">
                        <h5 class="modal-title crm-grad-text mb-0"><?php echo __('new_order'); ?></h5>
                        <div class="crm-wizard-step-label position-absolute start-50 translate-middle-x mb-0"><?php echo __('step'); ?> <span data-wizard-current>1</span> <?php echo __('of_3'); ?></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="crm-wizard-progress">
                        <div class="crm-wizard-seg" data-seg="1"></div>
                        <div class="crm-wizard-seg" data-seg="2"></div>
                        <div class="crm-wizard-seg" data-seg="3"></div>
                    </div>
                    
                    <!-- STEP 1: CLIENT -->
                    <div class="crm-wizard-step" data-step="1">
                        <div class="mb-2">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user text-primary me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('client'); ?></span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <select name="customer_id" class="form-select select2-customer" style="width: 100%;" required>
                                        <option value=""><?php echo __('enter_name_or_phone'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary w-100" id="toggleNewCustomerPanelBtn" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel" aria-expanded="false">
                                        <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                                    </button>
                                    <?php $__internalCust = crmInternalCustomerId(); if ($__internalCust > 0): ?>
                                    <?php /* Interní zakázka (neveřejná, evidence Karlín): vybere interního
                                             klienta a doplní PIN, aby průvodce hladce prošel až k Dokončit. */ ?>
                                    <button type="button" class="btn btn-outline-secondary w-100" id="internalOrderBtn"
                                            data-customer-id="<?php echo (int)$__internalCust; ?>"
                                            data-customer-label="Interní zakázka — AppleFix"
                                            title="Neveřejná zakázka — interní evidence (zařízení mimo běžné klienty)">
                                        <i class="fas fa-screwdriver-wrench me-1 text-warning"></i> Interní zakázka
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <!-- Inline New Customer Panel -->
                                <div class="col-12">
                                    <div class="collapse" id="inlineNewCustomerPanel">
                                        <div class="card border-secondary bg-dark bg-opacity-25 mt-2">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="mb-0 text-white"><i class="fas fa-user-plus me-2 text-primary"></i><?php echo __('add_customer'); ?></h6>
                                                    <?php /* Zákaznický displej: pošle na druhý monitor formulář, klient
                                                             vyplní sám a údaje se sem propíší (main.js, blok displeje). */ ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info" id="cdAskClientBtn"
                                                            title="Poslat formulář na zákaznický displej">
                                                        <i class="fas fa-desktop me-1"></i>Klient vyplní na displeji
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <div id="newCustomerInlineForm">
                                                    <div class="mb-3">
                                                        <div class="btn-group w-100" role="group">
                                                            <input type="radio" class="btn-check" name="customer_type" id="inline_type_private" value="private" checked>
                                                            <label class="btn btn-outline-primary" for="inline_type_private"><?php echo __('private_person'); ?></label>
                                                            <input type="radio" class="btn-check" name="customer_type" id="inline_type_company" value="company">
                                                            <label class="btn btn-outline-primary" for="inline_type_company"><?php echo __('company_entity'); ?></label>
                                                        </div>
                                                    </div>
                                                    <div id="inline_company_fields" class="d-none border border-secondary p-3 rounded bg-transparent mb-3">
                                                        <div class="mb-3">
                                                            <label class="form-label"><?php echo __('ico'); ?></label>
                                                            <div class="input-group">
                                                                <input type="text" name="ico" id="inline_ico_input" class="form-control" placeholder="12345678">
                                                                <button class="btn btn-info text-white" type="button" id="inline_btn_fetch_ares">
                                                                    <i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label"><?php echo __('company_name'); ?></label>
                                                            <input type="text" name="company_name" id="inline_ares_name" class="form-control">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label"><?php echo __('dic'); ?></label>
                                                            <input type="text" name="dic" id="inline_ares_dic" class="form-control" placeholder="CZ12345678">
                                                        </div>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label"><?php echo __('client'); ?> (<?php echo __('name_col'); ?>) <span class="text-danger">*</span></label>
                                                            <input type="text" name="first_name" id="inline_first_name" class="form-control">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label"><?php echo __('client'); ?> (<?php echo __('last_name_label'); ?>) <span class="text-danger">*</span></label>
                                                            <input type="text" name="last_name" id="inline_last_name" class="form-control">
                                                        </div>
                                                        <div class="col-md-7">
                                                            <label class="form-label"><?php echo __('phone'); ?> <span class="text-danger">*</span></label>
                                                            <input type="tel" name="phone" id="inline_phone" class="form-control">
                                                        </div>
                                                        <div class="col-md-5">
                                                            <label class="form-label"><?php echo __('customer_language'); ?></label>
                                                            <select name="inline_language" id="inline_language" class="form-select">
                                                                <option value="cs" selected>🇨🇿 Čeština</option>
                                                                <option value="en">🇬🇧 English</option>
                                                                <option value="uk">🇺🇦 Українська</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label"><?php echo __('email'); ?> <span class="text-danger">*</span></label>
                                                            <?php /* BEZ statického required! Pole je ve sbaleném panelu (collapse) —
                                                                     prázdné+skryté required pole tiše blokovalo odeslání CELÉHO formuláře
                                                                     („Dokončit" nedělalo nic). Povinnost hlídá JS při uložení klienta. */ ?>
                                                            <input type="email" name="inline_email" id="inline_email" class="form-control">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label"><?php echo __('address'); ?></label>
                                                            <textarea name="address" id="inline_address" class="form-control" rows="2"></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <button type="button" class="btn btn-success w-100" id="saveNewCustomerBtn">
                                                                <i class="fas fa-check me-2"></i><?php echo __('save'); ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: DEVICE & PROBLEM -->
                    <div class="crm-wizard-step" data-step="2" hidden>
                        <div class="mb-2">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-laptop text-info me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('section_device'); ?></span>
                            </div>
                            <div class="row g-3">
                                <?php if (crmInternalCustomerId() > 0): ?>
                                <?php /* Interní zakázka (v3.96.0): zařízení přímo ze skladu — vyplní typ, značku,
                                         model, S/N a vzhled; kus se na dobu opravy přestane prodávat. */ ?>
                                <div class="col-12" id="stockDeviceWrap" hidden>
                                    <div class="stock-device-box">
                                        <label class="form-label mb-1"><i class="fas fa-warehouse me-1 text-warning"></i>Zařízení ze skladu <span class="text-white-50 fw-normal">(interní zakázka)</span></label>
                                        <select id="stockDeviceSelect" style="width:100%;"></select>
                                        <input type="hidden" name="stock_product_id" id="stockProductId" value="">
                                        <div class="form-text small" id="stockDeviceHint">Vyber kus ze skladu — doplní se typ, značka, model, sériové číslo a stav. Po dobu opravy se kus nebude prodávat na e-shopu ani v Pokladně.</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('device_type'); ?></label>
                                    <select name="device_type" class="form-select select2-device-type" style="width: 100%;" required>
                                        <option value="Phone">📱 <?php echo __('Phone'); ?></option>
                                        <option value="Notebook">💻 <?php echo __('Notebook'); ?></option>
                                        <option value="PC">🖥️ <?php echo __('PC'); ?></option>
                                        <option value="Tablet">📟 <?php echo __('Tablet'); ?></option>
                                        <option value="HDD">💾 <?php echo __('HDD'); ?></option>
                                        <option value="Other">❓ <?php echo __('Other'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('warranty_type'); ?></label>
                                    <select name="order_type" class="form-select select2-order-type" style="width: 100%;" required>
                                        <option value="Non-Warranty">🛠 <?php echo __('warranty_no'); ?></option>
                                        <option value="Warranty">📜 <?php echo __('warranty_yes'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('device_brand'); ?></label>
                                    <select name="device_brand" class="form-select select2-brand" style="width: 100%;" required>
                                        <option value=""><?php echo __('brand_placeholder'); ?></option>
                                        <?php foreach($crm_order_brands_modal as $brand): ?>
                                            <option value="<?php echo e($brand); ?>"><?php echo e($brand); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('device_model'); ?></label>
                                    <select name="device_model" class="form-select select2-model" data-placeholder="<?php echo __('model_placeholder'); ?>" style="width: 100%;" required>
                                        <option value=""><?php echo __('model_placeholder'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('serial'); ?></label>
                                    <input type="text" name="serial_number" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('serial_2'); ?></label>
                                    <input type="text" name="serial_number_2" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label"><?php echo __('pin'); ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="pin_code" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo __('appearance'); ?></label>
                                    <input type="text" name="appearance" class="form-control">
                                </div>
                                <?php /* priorita vlevo, foto vpravo — jeden řádek (přání majitele) */ ?>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('priority'); ?></label>
                                    <select name="priority" class="form-select" id="priorityHighModal">
                                        <?php foreach (getOrderPriorityOptions() as $prioValue => $prioLabel): ?>
                                            <option value="<?php echo e($prioValue); ?>"<?php echo $prioValue === 'Normal' ? ' selected' : ''; ?>><?php echo $prioValue === 'High' ? '🔥 ' : ''; ?><?php echo e($prioLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2" id="priorityAdjustWrap" style="display:none;"
                                     data-label-high="<?php echo e(__('priority_surcharge_label')); ?>"
                                     data-label-low="<?php echo e(__('priority_discount_label')); ?>">
                                    <label class="form-label" id="priorityAdjustLabel"><?php echo e(__('priority_surcharge_label')); ?></label>
                                    <input type="number" name="priority_adjust" id="priorityAdjust" class="form-control" min="0" step="1" placeholder="0">
                                </div>
                                <div class="col-md">
                                    <label class="form-label"><i class="fas fa-camera me-1 text-info"></i> <?php echo __('intake_photos_label'); ?></label>
                                    <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                    <div class="form-text small"><?php echo __('intake_photos_hint'); ?></div>
                                </div>
                            </div>
                        </div>

                        <hr class="border-secondary my-3 opacity-50">

                        <div class="mb-2">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('section_problem'); ?></span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><i class="fas fa-tags me-1 text-info"></i><?php echo __('pricelist_repair'); ?></label>
                                    <select id="pricelistRepair" class="form-select" disabled style="width:100%;">
                                        <option value=""><?php echo __('pricelist_repair_ph'); ?></option>
                                    </select>
                                    <div id="pricelistChosen" class="d-flex flex-wrap gap-2 mt-2"></div>
                                    <input type="hidden" name="pricelist_items" id="pricelistItems" value="">
                                </div>
                                <?php /* poznámky technika vedle výběru z ceníku (přání majitele) */ ?>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo __('notes'); ?> <?php echo __('comment_suffix'); ?></label>
                                    <textarea name="technician_notes" class="form-control" rows="2" placeholder="<?php echo __('notes_placeholder'); ?>"></textarea>
                                </div>
                                <?php if (!empty($order_templates_modal)): ?>
                                <div class="col-md-<?php echo !empty($order_note_templates_modal) ? '6' : '12'; ?> order-col-tpl">
                                    <label class="form-label"><?php echo __('templates'); ?></label>
                                    <select class="form-select order-template-select" data-target="problem_description">
                                        <option value=""><?php echo __('template_select'); ?></option>
                                        <?php foreach ($order_templates_modal as $tpl): ?>
                                            <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($order_note_templates_modal)): ?>
                                <div class="col-md-<?php echo !empty($order_templates_modal) ? '6' : '12'; ?> order-col-tpl">
                                    <label class="form-label"><?php echo __('templates_notes'); ?></label>
                                    <select class="form-select order-template-select" data-target="technician_notes">
                                        <option value=""><?php echo __('template_select'); ?></option>
                                        <?php foreach ($order_note_templates_modal as $tpl): ?>
                                            <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('problem'); ?></label>
                                    <textarea name="problem_description" class="form-control" rows="3" required></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: FINANCIAL & TECHNICIAN -->
                    <div class="crm-wizard-step" data-step="3" hidden>
                        <div class="crm-wizard-summary">
                            <div class="crm-wizard-summary-label"><?php echo __('order_summary'); ?></div>
                            <div class="crm-wizard-summary-grid">
                                <div><span><?php echo __('customer'); ?>:</span> <strong data-summary="customer">—</strong></div>
                                <div><span><?php echo __('device'); ?>:</span> <strong data-summary="device">—</strong></div>
                                <div><span><?php echo __('repair_type'); ?>:</span> <strong data-summary="service">—</strong></div>
                                <div><span><?php echo __('priority'); ?>:</span> <strong data-summary="priority">—</strong></div>
                            </div>
                        </div>

                        <?php
                        /* Cena při příjmu (v4.5.0): diagnostika a odhad opravy jsou dvě různé věci.
                           Dřív bylo jen pole „odhad" — kdo do něj napsal cenu diagnostiky, způsobil,
                           že se pak opravená zakázka vydala za cenu diagnostiky. Viz includes/order_price.php. */
                        $__feePhone = crmDiagFee('Phone');
                        $__feeOther = crmDiagFee('Other');
                        $__cur = get_setting('currency', 'Kč');
                        ?>
                        <div class="mb-2 afx-price" id="orderPriceBox" data-fee-phone="<?php echo e((string)$__feePhone); ?>" data-fee-other="<?php echo e((string)$__feeOther); ?>">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-coins text-success me-2"></i>
                                <span class="fw-semibold small text-uppercase">Cena</span>
                            </div>
                            <input type="hidden" name="price_mode_required" value="1">
                            <div class="afx-price-opts" role="radiogroup" aria-label="Cena zakázky">
                                <label class="afx-price-opt">
                                    <input type="radio" name="price_mode" value="diag">
                                    <span class="afx-price-opt-t">Jen diagnostika</span>
                                    <span class="afx-price-opt-d">Cenu opravy zatím neznáme. Stanoví se po diagnostice.</span>
                                    <span class="afx-price-opt-fee" data-diag-fee><?php echo e(formatMoney($__feePhone)); ?></span>
                                </label>
                                <label class="afx-price-opt">
                                    <input type="radio" name="price_mode" value="estimate">
                                    <span class="afx-price-opt-t">Známe cenu opravy</span>
                                    <span class="afx-price-opt-d">Klient se dozví cenu opravy hned při příjmu.</span>
                                    <span class="afx-price-opt-fee" data-estimate-echo>zadáš níže</span>
                                </label>
                            </div>

                            <div class="afx-price-detail afx-price-detail--diag">
                                <p>Klient zaplatí za diagnostiku <b data-diag-fee><?php echo e(formatMoney($__feePhone)); ?></b> <span data-diag-kind>(telefon)</span>. Cenu opravy mu sdělíme po diagnostice.</p>
                                <p class="afx-price-muted">Dokud cenu opravy někdo nestanoví, zakázka nepůjde dokončit ani vydat.</p>
                                <p class="afx-price-muted">Ceník diagnostiky: telefony <?php echo e(formatMoney($__feePhone)); ?> · tablety, počítače a ostatní <?php echo e(formatMoney($__feeOther)); ?></p>
                            </div>

                            <div class="afx-price-detail afx-price-detail--estimate">
                                <label class="form-label" for="orderEstimatedCost">Odhad ceny opravy</label>
                                <div class="input-group afx-price-input">
                                    <input type="number" name="estimated_cost" id="orderEstimatedCost" class="form-control" step="1" min="0" inputmode="numeric">
                                    <span class="input-group-text"><?php echo e($__cur); ?></span>
                                </div>
                                <p class="afx-price-muted">Celá cena opravy pro klienta. Cenu diagnostiky sem nepiš.</p>
                                <div class="afx-price-warn" data-price-warn hidden>
                                    <p><b data-price-warn-amount></b> je cena diagnostiky. Jde opravdu o cenu celé opravy?</p>
                                    <div class="afx-price-warn-btns">
                                        <button type="button" class="btn btn-sm btn-warning" data-price-to-diag>Ne, přijímám jen na diagnostiku</button>
                                        <button type="button" class="btn btn-sm btn-outline-light" data-price-confirm>Ano, tolik stojí oprava</button>
                                    </div>
                                </div>
                            </div>

                            <label class="afx-price-free">
                                <input type="radio" name="price_mode" value="free">
                                <span>Bez úhrady — záruční, reklamační nebo interní zakázka</span>
                            </label>
                            <div class="afx-price-err" data-price-err role="alert" hidden></div>
                        </div>

                        <hr class="border-secondary my-3 opacity-50">

                        <div class="mb-0">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user-cog text-secondary me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('section_execution'); ?></span>
                            </div>
                            <div class="row g-3">
                                <?php if (isBranchGlobalViewer()): ?>
                                <div class="col-md-6">
                                    <label class="form-label"><i class="fas fa-store me-1"></i><?php echo __('branch'); ?></label>
                                    <select name="branch_id" class="form-select">
                                        <?php
                                        // předvybrat pobočku, kde uživatel stojí (jinak by se tiše
                                        // vybrala první pobočka v seznamu — špatné dokumenty/filtry)
                                        $__curB = (int)getCurrentStaffBranchId() ?: (int)getDefaultBranchId();
                                        foreach ($branches_modal as $branch): ?>
                                            <option value="<?php echo (int)$branch['id']; ?>" <?php echo (int)$branch['id'] === $__curB ? 'selected' : ''; ?>><?php echo e($branch['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                    <input type="hidden" name="branch_id" value="<?php echo (int)getCurrentStaffBranchId(); ?>">
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('technician'); ?></label>
                                    <select name="technician_id" class="form-select">
                                        <?php /* Technik se NEpředvybírá (dřív se vytvářejícímu technikovi
                                                 předvyplnil on sám → zakázky nechtěně padaly na něj).
                                                 Zakázku lze založit bez technika — přidělí se později,
                                                 nebo si ji technik vezme sám. */ ?>
                                        <option value="" selected>-- <?php echo __('technician'); ?> --</option>
                                        <?php foreach ($techs_list_modal as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?><?php echo isBranchGlobalViewer() && !empty($t['branch_name']) ? ' · ' . e($t['branch_name']) : ''; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (crmInternalCustomerId() > 0): ?>
                <script>
                /* Interní zakázka: 1 klik = vybraný interní klient + předvyplněný PIN (0000),
                   ať průvodce projde všemi kontrolami až k Dokončit. */
                (function () {
                    if (typeof window.jQuery === 'undefined') return;
                    window.jQuery(document).on('click', '#internalOrderBtn', function () {
                        var id = String(this.dataset.customerId || '');
                        var label = this.dataset.customerLabel || 'Interní zakázka';
                        if (!id) return;
                        var $sel = window.jQuery('#newOrderModal select[name="customer_id"]');
                        if (!$sel.find('option[value="' + id + '"]').length) {
                            $sel.append(new Option(label, id, true, true));
                        }
                        $sel.val(id).trigger('change');
                        var pin = document.querySelector('#newOrderModal [name="pin_code"]');
                        if (pin && !pin.value.trim()) { pin.value = '0000'; }
                        var btn = this;
                        btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-warning');
                        setTimeout(function () { btn.classList.add('btn-outline-secondary'); btn.classList.remove('btn-warning'); }, 900);
                    });
                }());
                </script>
                <?php endif; ?>
                <?php if (crmInternalCustomerId() > 0): ?>
                <style>
                #newOrderModal .stock-device-box { border: 1px solid rgba(255,159,10,.45); background: rgba(255,159,10,.08); border-radius: 12px; padding: 10px 12px; }
                #newOrderModal .stock-device-box.is-set { border-color: rgba(48,209,88,.5); background: rgba(48,209,88,.08); }
                </style>
                <script>
                /* Interní zakázka ze skladu (v3.96.0):
                   • výběr kusu ze skladu se ukáže jen u interního klienta,
                   • po výběru vyplní typ, značku, model (nejbližší z katalogu), S/N a vzhled,
                   • window.afxStockReklamace(id) — tlačítko „Reklamace" ve Skladu: otevře průvodce
                     jako interní zakázku s předvybraným kusem. */
                (function () {
                    if (typeof window.jQuery === 'undefined') return;
                    var $ = window.jQuery;
                    var INTERNAL_ID = '<?php echo (int)crmInternalCustomerId(); ?>';
                    var $m = function () { return $('#newOrderModal'); };

                    function setSelect($sel, value) {
                        if (!$sel.length || !value) return;
                        if (!$sel.find('option').filter(function () { return this.value === value; }).length) {
                            $sel.append(new Option(value, value, false, false));
                        }
                        $sel.val(value).trigger('change');
                    }
                    function bestModel(title) {
                        var t = String(title || '').toLowerCase(), best = '';
                        $m().find('[name="device_model"] option').each(function () {
                            var v = String(this.value || ''); if (!v) return;
                            var lv = v.toLowerCase();
                            if ((t === lv || t.indexOf(lv + ' ') === 0) && lv.length > best.length) best = v;
                        });
                        return best || String(title || '');
                    }
                    function applyStock(item) {
                        var f = item && item.prefill; if (!f) return;
                        var $modal = $m();
                        $('#stockProductId').val(item.id);
                        setSelect($modal.find('[name="device_type"]'), f.device_type);
                        setSelect($modal.find('[name="device_brand"]'), f.device_brand);
                        setSelect($modal.find('[name="device_model"]'), bestModel(f.device_model));
                        var sn = $modal.find('[name="serial_number"]'); if (f.serial_number && !sn.val()) sn.val(f.serial_number);
                        var ap = $modal.find('[name="appearance"]'); if (!ap.val()) ap.val(f.appearance || '');
                        var pr = $modal.find('[name="problem_description"]');
                        if (!String(pr.val() || '').trim()) pr.val('Reklamace skladového kusu ' + (item.code ? '(' + item.code + ') ' : '') + '— ');
                        $('#stockDeviceWrap .stock-device-box').addClass('is-set');
                        $('#stockDeviceHint').html('<i class="fas fa-check text-success me-1"></i>Propojeno se skladem: <b>' + $('<span>').text(item.title).html()
                            + '</b> — po dobu opravy se kus nebude prodávat. Po dokončení (Připraveno / Vydáno) se do prodeje vrátí sám.');
                    }
                    function clearStock() {
                        $('#stockProductId').val('');
                        $('#stockDeviceWrap .stock-device-box').removeClass('is-set');
                        var $s = $('#stockDeviceSelect'); if ($s.data('select2')) { $s.val(null).trigger('change.select2'); }
                    }
                    function syncVisible() {
                        var isInt = String($m().find('select[name="customer_id"]').val() || '') === INTERNAL_ID;
                        $('#stockDeviceWrap').prop('hidden', !isInt);
                        if (!isInt) clearStock();
                    }
                    function initSelect() {
                        var $s = $('#stockDeviceSelect');
                        if (!$s.length || $s.data('select2')) return;
                        $s.select2({
                            dropdownParent: $m(), width: '100%', allowClear: true,
                            placeholder: 'Hledat ve skladu — model, IMEI / sériové číslo…', minimumInputLength: 0,
                            ajax: {
                                url: 'api/stock_device_search.php', dataType: 'json', delay: 250,
                                data: function (p) { return { q: p.term || '' }; },
                                processResults: function (d) { return { results: (d && d.results) || [] }; }
                            }
                        }).on('select2:select', function (e) { applyStock(e.params.data); })
                          .on('select2:clear', clearStock);
                    }
                    $(document).on('shown.bs.modal', '#newOrderModal', function () { initSelect(); syncVisible(); });
                    $(document).on('change', '#newOrderModal select[name="customer_id"]', syncVisible);

                    window.afxStockReklamace = function (productId) {
                        var el = document.getElementById('newOrderModal'); if (!el || !window.bootstrap) return;
                        var go = function () {
                            el.removeEventListener('shown.bs.modal', go);
                            var b = document.getElementById('internalOrderBtn'); if (b) b.click();
                            $.getJSON('api/stock_device_search.php', { id: productId }, function (d) {
                                var it = d && d.results && d.results[0];
                                if (!it) { (window.showAlert || alert)('Kus ve skladu nebyl nalezen.'); return; }
                                if (it.in_repair) { (window.showAlert || alert)('Tento kus už je v opravě — zakázka ' + it.in_repair.code + '.'); return; }
                                initSelect();
                                var $s = $('#stockDeviceSelect');
                                $s.append(new Option(it.text, it.id, true, true)).trigger('change.select2');
                                syncVisible();
                                applyStock(it);
                                var next = el.querySelector('[data-wizard-next]');
                                if (next && !next.hidden) next.click();   // rovnou na krok Zařízení a závada
                            });
                        };
                        el.addEventListener('shown.bs.modal', go);
                        bootstrap.Modal.getOrCreateInstance(el).show();
                    };
                }());
                </script>
                <?php endif; ?>
                <style>
                /* Cena při příjmu — dvě velké volby, pod nimi jen to, co k vybrané patří */
                #newOrderModal .afx-price-opts { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
                #newOrderModal .afx-price-opt { position: relative !important; display: grid !important; grid-template-columns: 1fr auto !important; gap: 2px 12px !important; align-items: start !important;
                    margin: 0 !important; padding: 14px 16px !important; border-radius: 14px !important; cursor: pointer !important;
                    border: 1.5px solid rgba(255,255,255,.16) !important; background: rgba(255,255,255,.04) !important; transition: border-color .15s, background .15s !important; }
                #newOrderModal .afx-price-opt:hover { border-color: rgba(255,255,255,.34) !important; }
                #newOrderModal .afx-price-opt input, #newOrderModal .afx-price-free input { position: absolute !important; opacity: 0 !important; pointer-events: none !important; }
                #newOrderModal .afx-price-opt-t { grid-column: 1 !important; font-size: 16px !important; font-weight: 650 !important; color: #fff !important; }
                #newOrderModal .afx-price-opt-d { grid-column: 1 !important; font-size: 13px !important; line-height: 1.35 !important; color: rgba(255,255,255,.66) !important; }
                #newOrderModal .afx-price-opt-fee { grid-column: 2 !important; grid-row: 1 / span 2 !important; align-self: center !important; font-size: 15px !important; font-weight: 650 !important; white-space: nowrap !important; color: rgba(255,255,255,.8) !important; }
                #newOrderModal .afx-price-opt:has(input:checked) { border-color: #34c759 !important; background: rgba(52,199,89,.14) !important; }
                #newOrderModal .afx-price-opt:has(input:checked) .afx-price-opt-fee { color: #7ee79a !important; }
                #newOrderModal .afx-price-opt:has(input:focus-visible), #newOrderModal .afx-price-free:has(input:focus-visible) { outline: 2px solid #0a84ff !important; outline-offset: 2px !important; }
                #newOrderModal .afx-price-detail { display: none !important; margin-top: 12px !important; padding: 12px 16px !important; border-radius: 12px !important; background: rgba(255,255,255,.05) !important; }
                #newOrderModal .afx-price:has(input[value="diag"]:checked) .afx-price-detail--diag,
                #newOrderModal .afx-price:has(input[value="estimate"]:checked) .afx-price-detail--estimate { display: block !important; }
                #newOrderModal .afx-price-detail p { margin: 0 0 4px !important; font-size: 14.5px !important; color: #fff !important; }
                #newOrderModal .afx-price-detail p b { font-size: inherit !important; }
                #newOrderModal .afx-price-detail p span { font-size: inherit !important; }
                #newOrderModal .afx-price-detail p.afx-price-muted { font-size: 13px !important; color: rgba(255,255,255,.62) !important; }
                #newOrderModal .afx-price-detail p:last-child { margin-bottom: 0 !important; }
                #newOrderModal .afx-price-input { max-width: 260px !important; margin-bottom: 6px !important; }
                #newOrderModal .afx-price-warn { margin-top: 10px !important; padding: 10px 12px !important; border-radius: 10px !important; border: 1px solid rgba(255,159,10,.55) !important; background: rgba(255,159,10,.12) !important; }
                #newOrderModal .afx-price-warn[hidden], #newOrderModal .afx-price-err[hidden] { display: none !important; }
                #newOrderModal .afx-price-warn-btns { display: flex !important; flex-wrap: wrap !important; gap: 8px !important; margin-top: 8px !important; }
                #newOrderModal .afx-price-free { display: inline-flex !important; align-items: center !important; gap: 8px !important; position: relative !important; margin: 12px 0 0 !important; padding: 6px 12px !important; border-radius: 999px !important; cursor: pointer !important;
                    border: 1px solid rgba(255,255,255,.14) !important; font-size: 13.5px !important; color: rgba(255,255,255,.7) !important; }
                #newOrderModal .afx-price-free span { font-size: inherit !important; }
                #newOrderModal .afx-price-free:has(input:checked) { border-color: #34c759 !important; background: rgba(52,199,89,.14) !important; color: #fff !important; }
                #newOrderModal .afx-price-err { margin-top: 10px !important; padding: 9px 12px !important; border-radius: 10px !important; background: rgba(255,69,58,.16) !important; border: 1px solid rgba(255,69,58,.5) !important; color: #ffd2cf !important; font-size: 14px !important; }
                #newOrderModal .afx-price.is-missing .afx-price-opt { border-color: rgba(255,69,58,.7) !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt { border-color: rgba(10,20,40,.16) !important; background: rgba(10,20,40,.03) !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt-t, html[data-lg-theme="light"] #newOrderModal .afx-price-detail p { color: #101a2c !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt-d, html[data-lg-theme="light"] #newOrderModal .afx-price-detail p.afx-price-muted,
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt-fee, html[data-lg-theme="light"] #newOrderModal .afx-price-free { color: rgba(16,23,42,.66) !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt:has(input:checked), html[data-lg-theme="light"] #newOrderModal .afx-price-free:has(input:checked) { border-color: #1a7f3c !important; background: #e9fbee !important; color: #101a2c !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-opt:has(input:checked) .afx-price-opt-fee { color: #1a7f3c !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-detail { background: rgba(10,20,40,.04) !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-free { border-color: rgba(10,20,40,.16) !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-err { color: #8a0010 !important; background: #fdebeb !important; }
                html[data-lg-theme="light"] #newOrderModal .afx-price-warn { background: #fef3df !important; }
                @media (max-width: 575px) { #newOrderModal .afx-price-opts { grid-template-columns: 1fr !important; } }
                </style>
                <script>
                /* Cena při příjmu: jen diagnostika × odhad opravy. Stav drží samotná rádia
                   (obnoví je i rozepsaný koncept), skript jen dopočítává částky a hlídá odeslání. */
                (function () {
                    var box = document.getElementById('orderPriceBox');
                    var form = document.getElementById('newOrderForm');
                    if (!box || !form) { return; }
                    var feePhone = Number(box.dataset.feePhone) || 490, feeOther = Number(box.dataset.feeOther) || 990;
                    var est = document.getElementById('orderEstimatedCost');
                    var warn = box.querySelector('[data-price-warn]'), err = box.querySelector('[data-price-err]');
                    var confirmedFor = null;   // částka, u které obsluha potvrdila „ano, tolik stojí oprava"
                    var kc = function (n) { return Number(n).toLocaleString('cs-CZ') + ' Kč'; };
                    var mode = function () { var r = form.querySelector('input[name="price_mode"]:checked'); return r ? r.value : ''; };
                    var setMode = function (v) {
                        var r = form.querySelector('input[name="price_mode"][value="' + v + '"]');
                        if (r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
                    };
                    var devType = function () { var s = form.querySelector('[name="device_type"]'); return s ? String(s.value || '') : ''; };
                    var fee = function () { return devType() === 'Phone' ? feePhone : feeOther; };
                    var kinds = { Phone: 'telefon', Tablet: 'tablet', Notebook: 'notebook', PC: 'počítač', HDD: 'disk', Other: 'ostatní zařízení' };
                    function showErr(msg) { err.textContent = msg; err.hidden = !msg; box.classList.toggle('is-missing', !!msg && !mode()); }
                    function sync() {
                        box.querySelectorAll('[data-diag-fee]').forEach(function (el) { el.textContent = kc(fee()); });
                        var k = box.querySelector('[data-diag-kind]'); if (k) { k.textContent = '(' + (kinds[devType()] || 'ostatní zařízení') + ')'; }
                        var echo = box.querySelector('[data-estimate-echo]');
                        var v = Number(est.value);
                        if (echo) { echo.textContent = (mode() === 'estimate' && v > 0) ? kc(v) : 'zadáš níže'; }
                        if (mode()) { showErr(''); }
                        if (mode() !== 'estimate' || !isDiagAmount(v) || confirmedFor === v) { warn.hidden = true; }
                    }
                    function isDiagAmount(v) { return v > 0 && (v === feePhone || v === feeOther); }
                    function askDiag(v) {
                        box.querySelector('[data-price-warn-amount]').textContent = kc(v);
                        warn.hidden = false;
                        warn.scrollIntoView({ block: 'nearest' });
                    }

                    function onChange(e) {
                        var n = e.target && e.target.name;
                        if (n === 'price_mode') {
                            if (mode() === 'estimate') { setTimeout(function () { try { est.focus(); } catch (x) {} }, 30); }
                            sync();
                        } else if (n === 'device_type') { sync(); }
                    }
                    // Select2 ohlašuje změnu jen přes jQuery (nativní posluchač by ji neslyšel);
                    // jQuery posluchač chytí obojí. Bez jQuery zůstane nativní.
                    if (window.jQuery) { window.jQuery(form).on('change', onChange); } else { form.addEventListener('change', onChange); }
                    est.addEventListener('input', function () { confirmedFor = null; if (est.value !== '' && !mode()) { setMode('estimate'); } sync(); });
                    box.querySelector('[data-price-to-diag]').addEventListener('click', function () { est.value = ''; setMode('diag'); sync(); });
                    box.querySelector('[data-price-confirm]').addEventListener('click', function () { confirmedFor = Number(est.value); warn.hidden = true; });

                    // Výběr z ceníku vyplní odhad (main.js) → přepnout na „Známe cenu opravy".
                    var chosen = document.getElementById('pricelistChosen');
                    if (chosen && window.MutationObserver) {
                        new MutationObserver(function () {
                            setTimeout(function () {
                                var picked = (window.afxPricelistItems || []).length > 0;
                                if (picked && Number(est.value) > 0 && mode() !== 'estimate') { setMode('estimate'); }
                                sync();
                            }, 0);
                        }).observe(chosen, { childList: true });
                    }
                    // Interní zakázka = bez úhrady (tlačítko „Interní zakázka" v 1. kroku).
                    document.addEventListener('click', function (e) {
                        if (e.target && e.target.closest && e.target.closest('#internalOrderBtn')) { setTimeout(function () { if (!mode()) { setMode('free'); } }, 0); }
                    });
                    var modalEl = document.getElementById('newOrderModal');
                    modalEl.addEventListener('shown.bs.modal', sync);
                    modalEl.addEventListener('hidden.bs.modal', function () { confirmedFor = null; warn.hidden = true; showErr(''); });
                    // přechod na poslední krok (tlačítko Další) → přepočítat podle typu zařízení
                    // (capture: průvodce v main.js klik na „Další" zastaví dřív, než by probublal sem)
                    modalEl.addEventListener('click', function (e) { if (e.target && e.target.closest && e.target.closest('[data-wizard-next]')) { setTimeout(sync, 0); } }, true);

                    // Bez volby ceny zakázku nezaložíme. Capture + stopImmediatePropagation, ať se
                    // při zastavení nespustí další obsluha odeslání (zámek tlačítka apod.).
                    form.addEventListener('submit', function (e) {
                        var m = mode(), v = Number(est.value), stop = '';
                        if (!m) { stop = 'Zvol, jestli zakázku přijímáš jen na diagnostiku, nebo znáš cenu opravy.'; }
                        else if (m === 'estimate' && !(v > 0)) { stop = 'Zadej odhad ceny opravy. Když ji zatím neznáš, zvol „Jen diagnostika“.'; }
                        else if (m === 'estimate' && isDiagAmount(v) && confirmedFor !== v) { askDiag(v); stop = ' '; }
                        if (stop) {
                            e.preventDefault(); e.stopImmediatePropagation();
                            // main.js po kliknutí na Dokončit zamkne tlačítko („Ukládám…") — neodesíláme, tak ho hned vrátit
                            setTimeout(function () { if (form.__afxUnlock) { form.__afxUnlock(); } else { form.dataset.afxSubmitting = ''; } }, 0);
                            showErr(stop.trim());
                            if (stop.trim()) { box.scrollIntoView({ block: 'center' }); }
                            if (m === 'estimate' && !(v > 0)) { try { est.focus(); } catch (x) {} }
                            return;
                        }
                        if (m !== 'estimate') { est.value = ''; }   // do odhadu nikdy neputuje nic jiného než cena opravy
                    }, true);
                    sync();
                }());
                </script>
                <script>
                window.CRM_ORDER_MODEL_CATALOG = <?php echo json_encode($crm_order_model_catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                </script>
                <div class="modal-footer bg-transparent border-secondary crm-wizard-footer">
                    <?php /* Storno: záměrné zrušení = zavřít A zahodit rozepsané údaje, ať se
                             nepropíšou do dalšího otevření. Ochrana proti NÁHODNÉMU vyklikání
                             (auto-uložení draftu) zůstává — ta se maže jen tímhle tlačítkem. */ ?>
                    <button type="button" class="btn btn-outline-danger" data-wizard-cancel><?php echo __('storno_discard'); ?></button>
                    <button type="button" class="btn btn-secondary" data-wizard-prev hidden>← <?php echo __('back'); ?></button>
                    <button type="button" class="btn btn-primary" data-wizard-next><?php echo __('wizard_next_btn'); ?> →</button>
                    <button type="submit" class="btn btn-success" data-wizard-submit hidden><?php echo __('wizard_finish_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
