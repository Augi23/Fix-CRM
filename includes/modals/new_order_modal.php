<?php
/**
 * Global New Order Modal (3-step wizard)
 * This file is included in footer.php so it's available on all pages.
 */
require_once __DIR__ . '/../functions.php';
$techs_list_modal = getActiveTechnicians();
$order_templates_modal = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)get_setting('order_templates', '')))));
$order_note_templates_modal = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)get_setting('order_note_templates', '')))));
?>
<div class="modal fade crm-wizard-modal" id="newOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_order.php" method="POST" enctype="multipart/form-data" id="newOrderForm">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-3">
                    <div class="w-100">
                        <h5 class="modal-title crm-grad-text mb-1"><?php echo __('new_order'); ?></h5>
                        <div class="crm-wizard-step-label"><?php echo __('step'); ?> <span data-wizard-current>1</span> <?php echo __('of_3'); ?></div>
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
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-secondary w-100" id="toggleNewCustomerPanelBtn" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel" aria-expanded="false">
                                        <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                                    </button>
                                </div>
                                <!-- Inline New Customer Panel -->
                                <div class="col-12">
                                    <div class="collapse" id="inlineNewCustomerPanel">
                                        <div class="card border-secondary bg-dark bg-opacity-25 mt-2">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="mb-0 text-white"><i class="fas fa-user-plus me-2 text-primary"></i><?php echo __('add_customer'); ?></h6>
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
                                                        <div class="col-12">
                                                            <label class="form-label"><?php echo __('phone'); ?> <span class="text-danger">*</span></label>
                                                            <input type="tel" name="phone" id="inline_phone" class="form-control">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label"><?php echo __('email'); ?></label>
                                                            <input type="email" name="inline_email" class="form-control">
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
                                        <?php foreach(getDeviceBrands() as $brand): ?>
                                            <option value="<?php echo $brand; ?>"><?php echo $brand; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('device_model'); ?></label>
                                    <select name="device_model" class="form-select select2-model" data-placeholder="<?php echo __('model_placeholder'); ?>" style="width: 100%;" required>
                                        <option value=""><?php echo __('model_placeholder'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo __('serial'); ?></label>
                                    <input type="text" name="serial_number" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo __('serial_2'); ?></label>
                                    <input type="text" name="serial_number_2" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo __('pin'); ?></label>
                                    <input type="text" name="pin_code" class="form-control">
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('appearance'); ?></label>
                                    <input type="text" name="appearance" class="form-control">
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
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo __('priority'); ?></label>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="priority" value="High" id="priorityHighModal">
                                        <label class="form-check-label" for="priorityHighModal"><?php echo __('high'); ?></label>
                                    </div>
                                </div>
                                <?php if (!empty($order_templates_modal)): ?>
                                <div class="col-md-<?php echo !empty($order_note_templates_modal) ? '4' : '9'; ?>">
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
                                <div class="col-md-<?php echo !empty($order_templates_modal) ? '5' : '9'; ?>">
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
                                    <textarea name="problem_description" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('notes'); ?> <?php echo __('comment_suffix'); ?></label>
                                    <textarea name="technician_notes" class="form-control" rows="2" placeholder="<?php echo __('notes_placeholder'); ?>"></textarea>
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

                        <div class="mb-2">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-coins text-success me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('section_financial'); ?></span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo __('cost_est'); ?></label>
                                    <div class="input-group">
                                        <input type="number" name="estimated_cost" class="form-control" step="0.01">
                                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="border-secondary my-3 opacity-50">

                        <div class="mb-0">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user-cog text-secondary me-2"></i>
                                <span class="fw-semibold small text-uppercase"><?php echo __('section_execution'); ?></span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo __('technician'); ?></label>
                                    <select name="technician_id" class="form-select">
                                        <option value="">-- <?php echo __('technician'); ?> --</option>
                                        <?php foreach ($techs_list_modal as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (($_SESSION['role'] ?? '') !== 'admin' && $t['id'] == ($_SESSION['tech_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo __('media_files'); ?></label>
                                    <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                    <div class="form-text"><?php echo __('upload_multiple_hint'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-transparent border-secondary crm-wizard-footer">
                    <button type="button" class="btn btn-secondary" data-wizard-prev hidden>← <?php echo __('back'); ?></button>
                    <button type="button" class="btn btn-primary" data-wizard-next><?php echo __('continue_btn'); ?> →</button>
                    <button type="submit" class="btn btn-primary" data-wizard-submit hidden><?php echo __('create_order'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
