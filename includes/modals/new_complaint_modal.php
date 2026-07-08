<?php /* Modal „Nová reklamace" — formulář na míru reklamacím: klient (výběr/nový),
   zařízení, popis + požadované řešení a FOTODOKUMENTACE (na mobilu rovnou fotí
   přes capture="environment"). Odesílá multipart POST na api/add_complaint.php. */ ?>
<div class="modal fade" id="newComplaintModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_complaint.php" method="POST" enctype="multipart/form-data" id="newComplaintForm">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-3">
                    <h5 class="modal-title mb-0"><i class="fas fa-rotate-left me-2" style="color:#f97316"></i>Nová reklamace</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-user me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase">Klient</span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <select name="customer_id" id="complaintCustomerSelect" class="form-select" style="width:100%"
                                    data-placeholder="Hledat klienta podle jména nebo telefonu">
                                <option></option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="collapse" data-bs-target="#complaintNewCustomer">
                                <i class="fas fa-user-plus me-1"></i> Nový klient
                            </button>
                        </div>
                        <div class="col-12">
                            <div class="collapse" id="complaintNewCustomer">
                                <div class="card border-secondary bg-dark bg-opacity-25 mt-1"><div class="card-body py-3">
                                    <div class="row g-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="nc_first_name" placeholder="Jméno"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="nc_last_name" placeholder="Příjmení"></div>
                                        <div class="col-md-3"><input type="tel" class="form-control" name="nc_phone" placeholder="Telefon"></div>
                                        <div class="col-md-3"><input type="email" class="form-control" name="nc_email" placeholder="E-mail (nepovinné)"></div>
                                    </div>
                                    <div class="small text-white-50 mt-2">Vyplň, jen když klient ještě není v databázi — založí se automaticky.</div>
                                </div></div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-mobile-screen me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase">Zařízení</span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small">Typ zařízení</label>
                            <select name="device_type" class="form-select">
                                <option>iPhone</option><option>iPad</option><option>MacBook</option>
                                <option>Apple Watch</option><option>AirPods</option><option>iMac / Mac mini</option>
                                <option>Telefon (jiná značka)</option><option>Koloběžka</option><option>Jiné</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Model</label>
                            <input type="text" class="form-control" name="device_model" placeholder="např. iPhone 13 Pro 128 GB Sierra Blue" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">SN / IMEI</label>
                            <input type="text" class="form-control font-monospace" name="serial_number" placeholder="naskenujte nebo zapište">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Datum zakoupení</label>
                            <input type="date" class="form-control" name="purchase_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Původní zakázka / doklad</label>
                            <input type="text" class="form-control" name="orig_ref" placeholder="např. APFAZ2600123 (nepovinné)">
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-clipboard-list me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase">Reklamace</span>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <label class="form-label small">Popis problému / důvod reklamace</label>
                            <textarea class="form-control" name="reason" rows="3" required
                                      placeholder="Co přesně zařízení dělá / nedělá, kdy se vada projevuje…"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Požadované řešení</label>
                            <select name="resolution" class="form-select">
                                <option>Posouzení technikem</option><option>Oprava</option>
                                <option>Výměna zařízení</option><option>Vrácení peněz</option>
                            </select>
                        </div>
                    </div>

                    <hr class="border-secondary">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-camera me-2" style="color:#f97316"></i>
                        <span class="fw-semibold small text-uppercase">Fotodokumentace</span>
                    </div>
                    <input type="file" id="complaintPhotos" name="photos[]" accept="image/*" capture="environment" multiple class="d-none">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-warning" onclick="document.getElementById('complaintPhotos').click()">
                            <i class="fas fa-camera me-1"></i> Vyfotit / nahrát fotky
                        </button>
                        <div class="small text-white-50 align-self-center">Na telefonu či tabletu se rovnou otevře fotoaparát. Max 12 fotek.</div>
                    </div>
                    <div class="complaint-photo-grid" id="complaintPhotoPreview"></div>

                </div>
                <div class="modal-footer bg-transparent border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn fw-semibold" style="background:#f97316;color:#fff">
                        <i class="fas fa-rotate-left me-1"></i> Vytvořit reklamaci
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
            x.type = 'button'; x.innerHTML = '&times;'; x.title = 'Odebrat';
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
            alert('Vyber klienta, nebo vyplň nového (jméno + telefon).');
        }
    });
});
</script>
