<?php /* Modal „Nová reklamace" — formulář na míru reklamacím: klient (výběr/nový),
   zařízení, popis + požadované řešení a FOTODOKUMENTACE (na mobilu rovnou fotí
   přes capture="environment"). Odesílá multipart POST na api/add_complaint.php. */ ?>
<div class="modal fade" id="newComplaintModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_complaint.php" method="POST" enctype="multipart/form-data" id="newComplaintForm">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-3">
                    <h5 class="modal-title mb-0"><i class="fas fa-rotate-left me-2" style="color:#f97316"></i><?php echo __('new_complaint'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-user me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase"><?php echo __('client'); ?></span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <select name="customer_id" id="complaintCustomerSelect" class="form-select" style="width:100%"
                                    data-placeholder="<?php echo e(__('search_client_placeholder')); ?>">
                                <option></option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="collapse" data-bs-target="#complaintNewCustomer">
                                <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                            </button>
                        </div>
                        <div class="col-12">
                            <div class="collapse" id="complaintNewCustomer">
                                <div class="card border-secondary bg-dark bg-opacity-25 mt-1"><div class="card-body py-3">
                                    <div class="row g-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="nc_first_name" placeholder="<?php echo e(__('first_name_label')); ?>"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="nc_last_name" placeholder="<?php echo e(__('last_name_label')); ?>"></div>
                                        <div class="col-md-3"><input type="tel" class="form-control" name="nc_phone" placeholder="<?php echo e(__('phone')); ?>"></div>
                                        <div class="col-md-3"><input type="email" class="form-control" name="nc_email" placeholder="<?php echo e(__('email_optional')); ?>"></div>
                                    </div>
                                    <div class="small text-white-50 mt-2"><?php echo __('new_client_hint'); ?></div>
                                </div></div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-mobile-screen me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase"><?php echo __('section_device'); ?></span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small"><?php echo __('device_type'); ?></label>
                            <select name="device_type" class="form-select">
                                <option>iPhone</option><option>iPad</option><option>MacBook</option>
                                <option>Apple Watch</option><option>AirPods</option><option>iMac / Mac mini</option>
                                <option value="Telefon (jiná značka)"><?php echo __('device_other_brand_phone'); ?></option><option value="Koloběžka"><?php echo __('device_scooter'); ?></option><option value="Jiné"><?php echo __('Other'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small"><?php echo __('device_model'); ?></label>
                            <input type="text" class="form-control" name="device_model" placeholder="<?php echo e(__('complaint_model_ph')); ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">SN / IMEI</label>
                            <input type="text" class="form-control font-monospace" name="serial_number" placeholder="<?php echo e(__('scan_or_type_ph')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small"><?php echo __('purchase_date'); ?></label>
                            <input type="date" class="form-control" name="purchase_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small"><?php echo __('orig_order_ref'); ?></label>
                            <input type="text" class="form-control" name="orig_ref" placeholder="<?php echo e(__('orig_ref_ph')); ?>">
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-clipboard-list me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase"><?php echo __('complaint'); ?></span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <label class="form-label small"><?php echo __('complaint_reason_label'); ?></label>
                            <textarea class="form-control" name="reason" rows="3" required
                                      placeholder="<?php echo e(__('complaint_reason_ph')); ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small"><?php echo __('requested_resolution'); ?></label>
                            <select name="resolution" class="form-select">
                                <option value="Posouzení technikem"><?php echo __('resolution_assessment'); ?></option><option value="Oprava"><?php echo __('resolution_repair'); ?></option>
                                <option value="Výměna zařízení"><?php echo __('resolution_replacement'); ?></option><option value="Vrácení peněz"><?php echo __('resolution_refund'); ?></option>
                            </select>
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-camera me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase"><?php echo __('photo_documentation'); ?></span>
                    </div>
                    <input type="file" id="complaintPhotos" name="photos[]" accept="image/*" capture="environment" multiple class="d-none">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-warning" onclick="document.getElementById('complaintPhotos').click()">
                            <i class="fas fa-camera me-1"></i> <?php echo __('take_upload_photos'); ?>
                        </button>
                        <div class="small text-white-50 align-self-center"><?php echo __('complaint_photos_hint'); ?></div>
                    </div>
                    <div class="complaint-photo-grid" id="complaintPhotoPreview"></div>

                </div>
                <div class="modal-footer bg-transparent border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn fw-semibold" style="background:#f97316;color:#fff">
                        <i class="fas fa-rotate-left me-1"></i> <?php echo __('create_complaint'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery) return;
    var $ = window.jQuery;

    // select2 hledání klienta (stejné API jako Nová zakázka)
    $('#newComplaintModal').on('shown.bs.modal', function () {
        var $sel = $('#complaintCustomerSelect');
        if ($sel.data('select2')) return;
        $sel.select2({
            dropdownParent: $('#newComplaintModal'),
            width: '100%',
            allowClear: true,
            placeholder: $sel.data('placeholder'),
            minimumInputLength: 0,
            ajax: {
                url: 'api/search_customers.php',
                dataType: 'json',
                delay: 250,
                data: function (p) { return { q: p.term, page: p.page || 1 }; },
                processResults: function (d, p) { p.page = p.page || 1; return { results: d.results, pagination: { more: d.pagination.more } }; }
            }
        });
    });

    // náhledy fotek + možnost jednotlivé odebrat (rebuild FileListu přes DataTransfer)
    var input = document.getElementById('complaintPhotos');
    var grid  = document.getElementById('complaintPhotoPreview');
    if (!input || !grid) return;
    var MAX = 12;
    function redraw() {
        grid.innerHTML = '';
        Array.prototype.forEach.call(input.files, function (f, i) {
            var d = document.createElement('div');
            d.className = 'complaint-photo-thumb';
            var img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.onload = function () { URL.revokeObjectURL(img.src); };
            var x = document.createElement('button');
            x.type = 'button'; x.innerHTML = '&times;'; x.title = '<?php echo __('remove'); ?>';
            x.onclick = function () {
                var dt = new DataTransfer();
                Array.prototype.forEach.call(input.files, function (g, j) { if (j !== i) dt.items.add(g); });
                input.files = dt.files; redraw();
            };
            d.appendChild(img); d.appendChild(x); grid.appendChild(d);
        });
    }
    input.addEventListener('change', function () {
        if (input.files.length > MAX) {
            var dt = new DataTransfer();
            Array.prototype.slice.call(input.files, 0, MAX).forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        }
        redraw();
    });

    // klient musí být vybraný, nebo vyplněný nový (jméno + telefon)
    document.getElementById('newComplaintForm').addEventListener('submit', function (e) {
        var cust = document.getElementById('complaintCustomerSelect').value;
        var nf = this.querySelector('[name="nc_first_name"]').value.trim();
        var np = this.querySelector('[name="nc_phone"]').value.trim();
        if (!cust && !(nf && np)) {
            e.preventDefault();
            alert('<?php echo __('complaint_select_client_alert'); ?>');
        }
    });
});
</script>
