<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Účetnictví: vedení (crmCanManageInvoices) + role účetní (crmCanAccountingRead)
if (!(crmCanManageInvoices() || (function_exists('crmCanAccountingRead') && crmCanAccountingRead()))) {
    echo '<div class="alert alert-danger">' . __('access_denied') . '</div>';
    require_once 'includes/footer.php';
    exit;
}

// Handle Settings Update
if (isset($_POST['save_acc_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('<div class="alert alert-danger">' . __('security_token_invalid') . '</div>');
    }
    // Fakturační údaje firmy (vč. ČÍSLA ÚČTU tištěného na faktury a do QR plateb)
    // smí měnit JEN vedení — role účetní má na stránku čtecí přístup, a přepsání
    // účtu je klasický podvodný vektor.
    if (!crmCanManageInvoices()) {
        die('<div class="alert alert-danger">Fakturační údaje firmy smí měnit jen vedení.</div>');
    }
    set_setting('acc_company_name', $_POST['acc_company_name']);
    set_setting('acc_address', $_POST['acc_address']);
    set_setting('acc_ico', $_POST['acc_ico']);
    set_setting('acc_dic', $_POST['acc_dic']);
    set_setting('acc_bank_name', $_POST['acc_bank_name']);
    set_setting('acc_bank_account', $_POST['acc_bank_account']);
    set_setting('acc_iban', $_POST['acc_iban']);
    set_setting('acc_swift', $_POST['acc_swift']);
    set_setting('acc_trade_register', $_POST['acc_trade_register'] ?? '');
    set_setting('acc_invoice_prefix', $_POST['acc_invoice_prefix']);
    set_setting('acc_auto_create_invoice', isset($_POST['acc_auto_create_invoice']) ? 1 : 0);
    set_setting('acc_is_vat_payer', isset($_POST['acc_is_vat_payer']) ? 1 : 0);
    set_setting('acc_vat_rate', $_POST['acc_vat_rate']);
    // druhá fakturační identita — „Faktura IČO" na kase (OSVČ majitele)
    // staré hodnoty PŘED uložením — kvůli dopočtu IBAN při změně účtu níže
    $__oldIcoAcc = trim((string)get_setting('ico_supplier_bank_account', ''));
    $__oldIcoIban = trim((string)get_setting('ico_supplier_iban', ''));
    set_setting('ico_supplier_enabled', isset($_POST['ico_supplier_enabled']) ? 1 : 0);
    foreach (['ico_supplier_name', 'ico_supplier_ico', 'ico_supplier_dic', 'ico_supplier_address',
              'ico_supplier_bank_name', 'ico_supplier_bank_account', 'ico_supplier_iban',
              'ico_supplier_swift', 'ico_supplier_invoice_prefix'] as $__k) {
        set_setting($__k, trim((string)($_POST[$__k] ?? '')));
    }
    set_setting('ico_supplier_is_vat', isset($_POST['ico_supplier_is_vat']) ? 1 : 0);
    // IBAN dopočítat z čísla účtu: (a) když je IBAN prázdný, (b) když se ZMĚNILO
    // číslo účtu a IBAN zůstal ten starý (předvyplněný formulářem) — jinak by na
    // faktuře byl nový účet a IBAN starého účtu (dvě různá čísla na jednom dokladu)
    $__newIcoAcc = trim((string)($_POST['ico_supplier_bank_account'] ?? ''));
    $__postIcoIban = trim((string)($_POST['ico_supplier_iban'] ?? ''));
    if ($__newIcoAcc !== '' && ($__postIcoIban === '' || ($__newIcoAcc !== $__oldIcoAcc && $__postIcoIban === $__oldIcoIban))) {
        require_once __DIR__ . '/includes/kb_api.php';
        $__ib = crmCzAccountToIban($__newIcoAcc);
        if ($__ib !== '') { set_setting('ico_supplier_iban', $__ib); }
    }
    echo '<div class="alert alert-success">' . __('settings_saved') . '</div>';
}

// Fetch Invoices with items
afxEnsureInvoicePayments();
$stmt = $pdo->query("SELECT i.*, c.first_name, c.last_name, c.company,
    (SELECT GROUP_CONCAT(item_name SEPARATOR ', ') FROM invoice_items WHERE invoice_id = i.id) as item_names
    FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id ORDER BY i.created_at DESC");
$invoices = $stmt->fetchAll();

// Klienti se do stránky NEVYPISUJÍ: vyhledávají se přes api/search_customers.php
// (select2 s AJAX). Dřív se sem vysypal celý seznam jako <option> — přes tisíc
// řádků HTML v každém otevření Účetnictví a v modalu se v nich nedalo hledat.
?>

<style>
/* Výběr klienta v input-group: globální `.select2-container { width:100% !important }`
   ze style.css jinak vytlačí tlačítko „přepsat odběratele" na druhý řádek. */
#invoiceModal .input-group > .select2-container { flex: 1 1 auto; width: 1% !important; }
#invoiceModal .input-group > .select2-container .select2-selection--single {
    height: 100%;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-file-invoice-dollar me-2"></i> <?php echo __('accounting'); ?></h2>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" onclick="showNewInvoiceModal()">
            <i class="fas fa-plus me-2"></i> <?php echo __('new_invoice'); ?>
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#accSettingsModal">
            <i class="fas fa-cog"></i>
        </button>
    </div>
</div>

<?php require 'includes/accounting_tabs.php'; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <ul class="nav nav-pills px-4 pt-3 mb-3" id="accTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="list-tab" data-bs-toggle="tab" data-bs-target="#list-pane" type="button">📜 <?php echo __('all_orders'); ?></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats-pane" type="button">📊 <?php echo __('reports'); ?></button>
            </li>
        </ul>
        
        <div class="tab-content p-4 border-top">
            <div class="tab-pane fade show active" id="list-pane">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th><?php echo __('invoice_number'); ?></th>
                                <th><?php echo __('date_issue'); ?></th>
                                <th><?php echo __('customer'); ?></th>
                                <th><?php echo __('item_name'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th><?php echo __('amount'); ?></th>
                                <th class="text-end"><?php echo __('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted"><?php echo __('no_invoices_found'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <a href="javascript:void(0)" onclick="openUniversalPreview('print_invoice.php?id=<?php echo $inv['id']; ?>', '<?php echo __('invoice'); ?> <?php echo $inv['invoice_number']; ?>')" class="text-decoration-underline">
                                            <?php echo htmlspecialchars($inv['invoice_number']); ?>
                                        </a>
                                        <?php if($inv['order_id']): ?>
                                            <div class="small text-muted fw-normal"><i class="fas fa-link me-1"></i><?php echo __('order'); ?> #<?php echo $inv['order_id']; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($inv['date_issue'])); ?></td>
                                    <td><?php echo htmlspecialchars(trim((string)($inv['cust_name_override'] ?: ($inv['company'] ?: trim($inv['first_name'] . ' ' . $inv['last_name'])))) ?: '—'); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($inv['item_names'] ?: '—'); ?></td>
                                    <td><?php echo getInvoiceStatusBadge($inv['status'], $inv); ?></td>
                                    <td class="fw-bold"><?php echo number_format((float)$inv['total_amount'], 0, ',', ' ') . ' ' . $inv['currency']; ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-dark" onclick="openUniversalPreview('print_invoice.php?id=<?php echo $inv['id']; ?>', '<?php echo __('invoice'); ?> <?php echo $inv['invoice_number']; ?>')" title="<?php echo __('preview'); ?>"><i class="fas fa-eye"></i></button>
                                            <button class="btn btn-outline-primary" onclick="editInvoice(<?php echo $inv['id']; ?>)" title="<?php echo __('edit'); ?>"><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-outline-warning" onclick="createCreditNote(<?php echo $inv['id']; ?>)" title="<?php echo __('credit_note'); ?>"><i class="fas fa-undo"></i></button>
                                            <button class="btn btn-outline-info" onclick="exportPohoda(<?php echo $inv['id']; ?>)" title="Pohoda"><i class="fas fa-file-export"></i></button>
                                            <button class="btn btn-outline-secondary" onclick="exportS3(<?php echo $inv['id']; ?>)" title="S3 Money"><i class="fas fa-file-csv"></i></button>
                                            <button class="btn btn-outline-dark" onclick="openUniversalPreview('print_invoice.php?id=<?php echo $inv['id']; ?>', '<?php echo __('print'); ?> <?php echo __('invoice'); ?>')"><i class="fas fa-print"></i></button>
                                            <button class="btn btn-outline-danger" onclick="deleteInvoice(<?php echo $inv['id']; ?>)"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="tab-pane fade" id="stats-pane">
                <?php
                $stats = $pdo->query("SELECT status, COUNT(*) as count, SUM(total_amount) as total FROM invoices GROUP BY status")->fetchAll();
                ?>
                <div class="row g-4">
                    <?php foreach ($stats as $s): ?>
                    <div class="col-md-3">
                        <div class="card bg-dark bg-opacity-25 border-0 text-center p-3">
                            <h6 class="text-muted text-uppercase mb-2"><?php echo __('status_' . $s['status']); ?></h6>
                            <h3 class="mb-0"><?php echo number_format((float)$s['total'], 0, ',', ' '); ?> <small>Kč</small></h3>
                            <div class="text-primary small mt-1"><?php echo $s['count']; ?> <?php echo __('pieces_short'); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Modal (Create/Edit) -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form id="invoiceForm" method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_invoice">
                <input type="hidden" name="id" id="inv_id">
                <input type="hidden" name="order_id" id="inv_order_id">
                <input type="hidden" name="is_vat_payer" id="inv_is_vat_payer">
                <div class="modal-header">
                    <h5 class="modal-title" id="invModalTitle"><?php echo __('new_invoice'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('invoice_number'); ?></label>
                            <input type="text" name="invoice_number" id="inv_number" class="form-control" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label"><?php echo __('customer'); ?></label>
                            <div class="input-group">
                                <select name="customer_id" id="inv_customer" class="form-select">
                                    <option value=""></option>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleCustomerOverride()" title="Vyplnit údaje odběratele ručně"><i class="fas fa-user-edit"></i></button>
                            </div>
                            <div id="inv_customer_fallback" class="form-text text-warning" style="display:none;">
                                Vyhledávání klientů se nenačetlo — obnov stránku (Cmd+R). Do té doby jde odběratel vyplnit ručně tlačítkem s tužkou.
                            </div>
                        </div>
                        
                        <div id="customer_override_fields" style="display:none;" class="row g-3 mt-1 border border-secondary rounded p-3 bg-dark bg-opacity-25">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('customer_name_override'); ?></label>
                                <input type="text" name="cust_name" id="inv_cust_name" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('company_id_override'); ?></label>
                                <input type="text" name="cust_ico" id="inv_cust_ico" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('vat_id_override'); ?></label>
                                <input type="text" name="cust_dic" id="inv_cust_dic" class="form-control">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label"><?php echo __('address_override'); ?></label>
                                <textarea name="cust_address" id="inv_cust_address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-12">
                                <div class="form-text mb-0">
                                    Vyplněné údaje se na faktuře vytisknou <b>místo</b> údajů vybraného klienta.
                                    Faktura pro jednorázového odběratele (není v CRM) = výběr klienta nech prázdný.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('create_from_order_id'); ?></label>
                            <div class="input-group">
                                <input type="number" id="from_order_id" class="form-control" placeholder="<?php echo __('order_id'); ?>">
                                <button type="button" class="btn btn-outline-primary" onclick="loadFromOrder()"><i class="fas fa-download"></i></button>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" id="inv_date_issue" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('date_tax'); ?></label>
                            <input type="date" name="date_tax" id="inv_date_tax" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" id="inv_date_due" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('status'); ?></label>
                            <select name="status" id="inv_status" class="form-select">
                                <option value="draft"><?php echo __('status_draft'); ?></option>
                                <option value="issued" selected><?php echo __('status_issued'); ?></option>
                                <option value="paid"><?php echo __('status_paid'); ?></option>
                                <option value="overdue"><?php echo __('status_overdue'); ?></option>
                                <option value="cancelled"><?php echo __('status_cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('payment_method'); ?></label>
                            <select name="payment_method" id="inv_payment_method" class="form-select">
                                <option value="bank_transfer"><?php echo __('bank_transfer'); ?></option>
                                <option value="cash"><?php echo __('cash'); ?></option>
                                <option value="card"><?php echo __('card'); ?></option>
                                <option value="cod"><?php echo __('cod_payment'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3 d-flex justify-content-between align-items-center">
                        <h6><?php echo __('parts_list'); ?></h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addInvItem()">
                            <i class="fas fa-plus"></i> <?php echo __('add_part'); ?>
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="itemsTable">
                            <thead class="table-dark">
                                <tr>
                                    <th><?php echo __('item_name'); ?></th>
                                    <th style="width: 100px;"><?php echo __('quantity'); ?></th>
                                    <th style="width: 80px;"><?php echo __('unit_label'); ?></th>
                                    <th style="width: 150px;"><?php echo __('price_no_vat'); ?></th>
                                    <th style="width: 100px;"><?php echo __('vat_rate'); ?></th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Items will be added here -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-8">
                            <label class="form-label"><?php echo __('notes_internal_footer'); ?></label>
                            <textarea name="notes" id="inv_notes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-dark bg-opacity-25 p-3 border-secondary">
                                <div class="d-flex justify-content-between mb-2">
                                    <span><?php echo __('subtotal'); ?>:</span>
                                    <span id="subtotal_val">0.00 Kč</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><?php echo __('vat_short'); ?>:</span>
                                    <span id="vat_val">0.00 Kč</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between fw-bold fs-5">
                                    <span><?php echo __('total_amount'); ?>:</span>
                                    <span id="total_val">0.00 Kč</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Accounting Settings Modal -->
<div class="modal fade" id="accSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('acc_settings'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('company_name'); ?></label>
                            <input type="text" name="acc_company_name" class="form-control" value="<?php echo get_setting('acc_company_name'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('ico'); ?></label>
                            <input type="text" name="acc_ico" class="form-control" value="<?php echo get_setting('acc_ico'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('dic'); ?></label>
                            <input type="text" name="acc_dic" class="form-control" value="<?php echo get_setting('acc_dic'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('address'); ?></label>
                            <textarea name="acc_address" class="form-control" rows="2"><?php echo get_setting('acc_address'); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('trade_register'); ?></label>
                            <input type="text" name="acc_trade_register" class="form-control" value="<?php echo get_setting('acc_trade_register'); ?>" placeholder="<?php echo __('trade_register_placeholder'); ?>">
                        </div>
                        <hr>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('bank_label'); ?></label>
                            <input type="text" name="acc_bank_name" class="form-control" value="<?php echo get_setting('acc_bank_name'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('account_number'); ?></label>
                            <input type="text" name="acc_bank_account" class="form-control" value="<?php echo get_setting('acc_bank_account'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('iban'); ?></label>
                            <input type="text" name="acc_iban" class="form-control" value="<?php echo get_setting('acc_iban'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('swift'); ?></label>
                            <input type="text" name="acc_swift" class="form-control" value="<?php echo get_setting('acc_swift'); ?>">
                        </div>
                        <hr>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('invoice_prefix'); ?></label>
                            <input type="text" name="acc_invoice_prefix" class="form-control" value="<?php echo get_setting('acc_invoice_prefix', date('Y')); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('vat_rate'); ?></label>
                            <input type="number" name="acc_vat_rate" class="form-control" value="<?php echo get_setting('acc_vat_rate', '21'); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="acc_is_vat_payer" value="1" id="vatPayerCheck" <?php echo get_setting('acc_is_vat_payer', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="vatPayerCheck"><?php echo __('vat_payer'); ?></label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-text text-muted small mt-0 mb-3">
                                <i class="fas fa-info-circle me-1"></i> <?php echo __('vat_info'); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="acc_auto_create_invoice" value="1" id="autoCreateInv" <?php echo get_setting('acc_auto_create_invoice', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="autoCreateInv">
                                    <?php echo __('auto_invoice_completed'); ?>
                                </label>
                            </div>
                        </div>

                        <?php /* Druhá fakturační identita — „Faktura IČO" na kase (v3.50.0):
                                 doklady vystavené OSVČ majitele vedle AppleFix s.r.o.
                                 Vlastní číselná řada (výchozí 9RRRR…) a vlastní účet pro QR. */ ?>
                        <div class="col-12"><hr>
                            <h6 class="mb-1"><i class="fas fa-file-signature me-2 text-info"></i>Druhá fakturační identita — „Faktura IČO" (OSVČ)</h6>
                            <div class="form-text text-muted small mb-2">Kasa nabízí platbu „Faktura IČO" — doklad vystaví OSVČ níže místo s.r.o. Vlastní číselná řada i účet pro QR platbu. Dokud není blok vyplněný a zapnutý, kasa tento typ platby nepustí.</div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="ico_supplier_enabled" value="1" id="icoSupEnabled" <?php echo get_setting('ico_supplier_enabled', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="icoSupEnabled"><strong>Zapnout „Faktura IČO" na kase</strong></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Jméno / název (OSVČ)</label>
                            <input type="text" name="ico_supplier_name" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_name')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">IČO</label>
                            <input type="text" name="ico_supplier_ico" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_ico')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">DIČ (jen plátce)</label>
                            <input type="text" name="ico_supplier_dic" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_dic')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sídlo</label>
                            <textarea name="ico_supplier_address" class="form-control" rows="2"><?php echo htmlspecialchars(get_setting('ico_supplier_address')); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Banka</label>
                            <input type="text" name="ico_supplier_bank_name" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_bank_name')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Číslo účtu (pro QR platby OSVČ)</label>
                            <input type="text" name="ico_supplier_bank_account" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_bank_account')); ?>" placeholder="123456789/0100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IBAN</label>
                            <input type="text" name="ico_supplier_iban" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_iban')); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SWIFT</label>
                            <input type="text" name="ico_supplier_swift" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_swift')); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prefix číselné řady</label>
                            <input type="text" name="ico_supplier_invoice_prefix" class="form-control" value="<?php echo htmlspecialchars(get_setting('ico_supplier_invoice_prefix', '9' . date('Y'))); ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="ico_supplier_is_vat" value="1" id="icoSupVat" <?php echo get_setting('ico_supplier_is_vat', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="icoSupVat">OSVČ je plátce DPH</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel_btn'); ?></button>
                    <button type="submit" name="save_acc_settings" class="btn btn-primary"><?php echo __('save_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
/** Odznak stavu faktury. Když je faktura zaplacená jen zčásti (došla platba, ale ne
 *  celá částka), přidá se za odznak informace „Zaplaceno X z Y" — stav samotný zůstává
 *  „vystaveno", protože s ním pracují filtry, reporty i exporty. */
function getInvoiceStatusBadge($status, ?array $invoice = null) {
    $extra = '';
    if ($invoice !== null && function_exists('afxInvoicePaymentInfo')) {
        $info = afxInvoicePaymentInfo($invoice);
        if ($info['partial']) {
            $extra = '<div class="small text-warning mt-1" title="Zbývá uhradit ' . htmlspecialchars(formatMoney($info['remaining'])) . '">'
                . htmlspecialchars($info['label']) . '</div>';
        }
    }
    return getInvoiceStatusBadgeBase($status) . $extra;
}

function getInvoiceStatusBadgeBase($status) {
    switch ($status) {
        case 'draft': return '<span class="badge bg-secondary">'.__('status_draft').'</span>';
        case 'issued': return '<span class="badge bg-info">'.__('status_issued').'</span>';
        case 'paid': return '<span class="badge bg-success">'.__('status_paid').'</span>';
        case 'overdue': return '<span class="badge bg-danger">'.__('status_overdue').'</span>';
        case 'cancelled': return '<span class="badge bg-dark">'.__('status_cancelled').'</span>';
        default: return '<span class="badge bg-secondary">'.$status.'</span>';
    }
}
?>

<script>
let invModal;

document.addEventListener('DOMContentLoaded', function() {
    const invModalEl = document.getElementById('invoiceModal');
    if (invModalEl) {
        invModal = new bootstrap.Modal(invModalEl);
    }

    // ── vyhledávání klienta (v3.69.0) ───────────────────────────────────────
    // Dřív pole zapínala globální inicializace $('.select2') v main.js — BEZ
    // dropdownParent, takže se nabídka vykreslila mimo modal: Bootstrap si drží
    // fokus uvnitř modalu, do vyhledávacího řádku tedy nešlo psát (přesně to
    // uživatel hlásil jako „nelze vyhledávat"). Navíc se hledalo jen v tisícovce
    // předem vysypaných <option>. Teď se klienti hledají přes stejné API jako
    // v kase a dropdown patří do modalu.
    if (window.jQuery && jQuery.fn.select2 && invModalEl) {
        jQuery('#inv_customer').select2({
            width: '100%',
            placeholder: 'Hledat klienta (jméno, firma, telefon, e-mail)…',
            allowClear: true,
            minimumInputLength: 0,
            dropdownParent: jQuery('#invoiceModal'),
            language: {
                inputTooShort: function () { return 'Piš dál…'; },
                noResults: function () { return 'Nikdo takový v CRM není'; },
                searching: function () { return 'Hledám…'; },
                errorLoading: function () { return 'Hledání selhalo'; }
            },
            ajax: {
                url: 'api/search_customers.php',
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
                // vypršelá relace se dřív tvářila jako „takový klient neexistuje" —
                // účetní pak založila duplicitního odběratele. Teď vyskočí přihlášení.
                error: function (xhr) {
                    if (xhr && xhr.status === 401 && window.afxReauth && window.afxReauth.open) {
                        window.afxReauth.open();
                    }
                }
            }
        });
    } else {
        // bez select2 (výpadek CDN) by zůstalo prázdné pole bez jediné položky —
        // ať je aspoň vidět proč a že fakturu jde vystavit ručním odběratelem
        var fb = document.getElementById('inv_customer_fallback');
        if (fb) { fb.style.display = ''; }
    }
    
    // UI reaction to VAT payer toggle in settings (if modal is open)
    const vatToggle = document.getElementById('vatPayerCheck');
    if (vatToggle) {
        vatToggle.addEventListener('change', function() {
            // This only affects the settings modal view if needed, 
            // but the main logic is in calcTotals which runs when invoice modal opens
        });
    }

    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        e.preventDefault();
        // doklad musí mít odběratele: klient z CRM, nebo ručně vyplněný název
        // (faktury z kasy na jednorázového odběratele klienta v CRM nemají)
        if (!document.getElementById('inv_customer').value
            && !document.getElementById('inv_cust_name').value.trim()) {
            document.getElementById('customer_override_fields').style.display = 'flex';
            alert('Faktura nemá odběratele — vyber zákazníka, nebo vyplň název odběratele ručně (tlačítko s tužkou u výběru).');
            document.getElementById('inv_cust_name').focus();
            return;
        }
        const formData = new FormData(this);
        
        // Serialize items
        const items = [];
        document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
            const nameEl = tr.querySelector('.item-name');
            const qtyEl = tr.querySelector('.item-qty');
            const unitEl = tr.querySelector('.item-unit');
            const priceEl = tr.querySelector('.item-price');
            const vatEl = tr.querySelector('.item-vat');
            
            if (nameEl && qtyEl) {
                items.push({
                    name: nameEl.value,
                    quantity: qtyEl.value,
                    unit: unitEl ? unitEl.value : 'ks',
                    price: priceEl ? priceEl.value : 0,
                    vat_rate: vatEl ? vatEl.value : 0
                });
            }
        });
        formData.append('items', JSON.stringify(items));
        
        fetch('accounting_actions.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                showAlert(data.error);
            }
        });
    });
});

/** Jméno klienta do políčka. Zástupné pomlčky („-", „–") se v CRM používají
 *  místo prázdné hodnoty — stejně je zahazuje i api/search_customers.php,
 *  jinak by faktura ukazovala odběratele „-". */
function invCustomerLabel(data) {
    var clean = function (v) {
        v = String(v == null ? '' : v).trim();
        return (v === '-' || v === '–' || v === '—') ? '' : v;
    };
    var company = clean(data.company);
    if (company !== '') return company;
    return (clean(data.first_name) + ' ' + clean(data.last_name)).trim();
}

/** Nastaví klienta ve výběru. U vyhledávacího select2 nejsou položky předem
 *  načtené, takže se ta jedna musí vložit ručně — jinak by pole zůstalo
 *  prázdné, i když je customer_id vyplněné (a faktura by se uložila bez klienta). */
function setInvoiceCustomer(id, label) {
    var sel = document.getElementById('inv_customer');
    if (!sel) return;
    sel.innerHTML = '<option value=""></option>';
    id = parseInt(id, 10) || 0;
    if (id > 0) {
        var opt = new Option(String(label || ('Klient #' + id)), String(id), true, true);
        sel.appendChild(opt);
        sel.value = String(id);
    } else {
        sel.value = '';
    }
    if (window.jQuery && jQuery('#inv_customer').data('select2')) {
        jQuery('#inv_customer').trigger('change');
    }
}

function showNewInvoiceModal() {
    document.getElementById('invoiceForm').reset();
    // form.reset() select2 nepřekreslí — v novém dokladu by svítil předchozí klient
    setInvoiceCustomer(0, '');
    ['inv_cust_name', 'inv_cust_ico', 'inv_cust_dic', 'inv_cust_address'].forEach(function (id) {
        var el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('customer_override_fields').style.display = 'none';
    document.querySelector('#invoiceForm [name="action"]').value = 'save_invoice';
    document.getElementById('inv_id').value = '';
    document.getElementById('inv_order_id').value = '';
    document.getElementById('inv_is_vat_payer').value = (document.getElementById('vatPayerCheck')?.checked ? '1' : '0');
    document.getElementById('invModalTitle').innerText = "<?php echo __('new_invoice'); ?>";
    document.querySelector('#itemsTable tbody').innerHTML = '';
    
    // Návrh čísla z MAXIMA řady s.r.o. (afxNextInvoiceNumber) — dřívější count()+1
    // počítalo VŠECHNY faktury (vč. řady OSVČ a storen) a dělalo díry v řadě
    document.getElementById('inv_number').value = "<?php
        try { echo e(afxNextInvoiceNumber($pdo, (string)get_setting('acc_invoice_prefix', date('Y')))); }
        catch (Throwable $e) { echo e(get_setting('acc_invoice_prefix', date('Y')) . str_pad((string)(count($invoices) + 1), 4, '0', STR_PAD_LEFT)); }
    ?>";
    
    addInvItem();
    invModal.show();
}

function addInvItem(data = {}) {
    const tbody = document.querySelector('#itemsTable tbody');
    const tr = document.createElement('tr');
    const isVatPayer = document.getElementById('vatPayerCheck')?.checked || <?php echo get_setting('acc_is_vat_payer', '0'); ?> == '1';
    
    tr.innerHTML = `
        <td><input type="text" class="form-control form-control-sm item-name" value="${data.name || data.item_name || ''}" required></td>
        <td><input type="number" step="0.01" class="form-control form-control-sm item-qty" value="${data.quantity || '1'}" onchange="calcTotals()"></td>
        <td><input type="text" class="form-control form-control-sm item-unit" value="${data.unit || 'ks'}"></td>
        <td><input type="number" step="0.01" class="form-control form-control-sm item-price" value="${data.price || '0'}" onchange="calcTotals()"></td>
        <td style="${isVatPayer ? '' : 'display:none;'}"><input type="number" class="form-control form-control-sm item-vat" value="${data.vat_rate || '<?php echo get_setting('acc_vat_rate', '21'); ?>'}" onchange="calcTotals()"></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); calcTotals()"><i class="fas fa-times"></i></button></td>
    `;
    tbody.appendChild(tr);
    calcTotals();
}

function calcTotals() {
    let subtotal = 0;
    let vatTotal = 0;
    const isVatPayer = document.getElementById('vatPayerCheck')?.checked || <?php echo get_setting('acc_is_vat_payer', '0'); ?> == '1';
    
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.item-price').value) || 0;
        const vatRate = isVatPayer ? (parseFloat(tr.querySelector('.item-vat').value) || 0) : 0;
        
        const lineSub = qty * price;
        const lineVat = lineSub * (vatRate / 100);
        
        subtotal += lineSub;
        vatTotal += lineVat;
    });
    
    document.getElementById('subtotal_val').innerText = subtotal.toFixed(2) + ' Kč';
    document.getElementById('vat_val').innerText = (isVatPayer ? vatTotal.toFixed(2) : '0.00') + ' Kč';
    document.getElementById('total_val').innerText = (subtotal + (isVatPayer ? vatTotal : 0)).toFixed(2) + ' Kč';
    
    // Hide/Show VAT columns in modal
    const vatTh = document.querySelector('#itemsTable thead th:nth-child(5)');
    if (vatTh) vatTh.style.display = isVatPayer ? '' : 'none';
    
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
        const vatTd = tr.querySelector('.item-vat').closest('td');
        if (vatTd) vatTd.style.display = isVatPayer ? '' : 'none';
    });
    
    // Hide VAT rows in total card
    const vatRow = document.getElementById('vat_val').closest('.d-flex');
    if (vatRow) vatRow.style.display = isVatPayer ? 'flex' : 'none';
    const subtotalRow = document.getElementById('subtotal_val').closest('.d-flex');
    if (subtotalRow) subtotalRow.style.display = isVatPayer ? 'flex' : 'none';
}

function editInvoice(id) {
    fetch('accounting_actions.php?action=get_invoice&id=' + id)
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const data = res.data;
            document.getElementById('invoiceForm').reset();
            
            // Re-set action because reset() clears it
            document.querySelector('#invoiceForm [name="action"]').value = 'save_invoice';
            
            document.getElementById('inv_id').value = data.id;
            document.getElementById('inv_order_id').value = data.order_id || '';
            document.getElementById('inv_is_vat_payer').value = data.is_vat_payer || '0';
            document.getElementById('inv_number').value = data.invoice_number;
            // faktura na jednorázového odběratele klienta nemá → prázdný výběr
            setInvoiceCustomer(data.customer_id || 0, invCustomerLabel(data));

            document.getElementById('inv_date_issue').value = data.date_issue;
            document.getElementById('inv_date_tax').value = data.date_tax;
            document.getElementById('inv_date_due').value = data.date_due;
            document.getElementById('inv_status').value = data.status;
            document.getElementById('inv_payment_method').value = data.payment_method || 'bank_transfer';
            
            // Set overrides if any
            document.getElementById('inv_cust_name').value = data.cust_name_override || '';
            document.getElementById('inv_cust_ico').value = data.cust_ico_override || '';
            document.getElementById('inv_cust_dic').value = data.cust_dic_override || '';
            document.getElementById('inv_cust_address').value = data.cust_address_override || '';
            document.getElementById('inv_notes').value = data.notes || '';
            
            if (data.cust_name_override || data.cust_ico_override || data.cust_address_override) {
                document.getElementById('customer_override_fields').style.display = 'flex';
            } else {
                document.getElementById('customer_override_fields').style.display = 'none';
            }

            if (data.order_id) {
                document.getElementById('from_order_id').value = data.order_id;
            } else {
                document.getElementById('from_order_id').value = '';
            }
            
            const tbody = document.querySelector('#itemsTable tbody');
            tbody.innerHTML = '';
            data.items.forEach(item => addInvItem(item));
            
            document.getElementById('invModalTitle').innerText = "<?php echo __('edit_invoice'); ?>";
            invModal.show();
        }
    });
}

function loadFromOrder() {
    const orderId = document.getElementById('from_order_id').value;
    if (!orderId) return;
    
    fetch('accounting_actions.php?action=get_order_data&order_id=' + orderId)
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            setInvoiceCustomer(res.data.customer_id, res.data.customer_display || '');
            document.getElementById('inv_order_id').value = orderId;
            document.getElementById('inv_is_vat_payer').value = res.data.is_vat_payer ? '1' : '0';
            

            const tbody = document.querySelector('#itemsTable tbody');
            tbody.innerHTML = '';
            res.data.items.forEach(item => addInvItem({
                item_name: item.name,
                quantity: item.quantity,
                unit: item.unit,
                price: item.price,
                vat_rate: item.vat_rate
            }));
        } else {
            showAlert(res.error);
        }
    });
}

function deleteInvoice(id) {
    showConfirm('<?php echo __('confirm_delete_invoice'); ?>', function() {
        const formData = new FormData();
        formData.append('action', 'delete_invoice');
        formData.append('id', id);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('accounting_actions.php', { method: 'POST', body: formData }).then(() => location.reload());
    });
}

function createCreditNote(id) {
    showConfirm('<?php echo __('confirm_create_credit_note'); ?>', function() {
        const formData = new FormData();
        formData.append('action', 'create_credit_note');
        formData.append('id', id);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('accounting_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                showAlert(res.error);
            }
        });
    });
}

function toggleCustomerOverride() {
    const div = document.getElementById('customer_override_fields');
    div.style.display = (div.style.display === 'none') ? 'flex' : 'none';
}

function exportPohoda(id) {
    fetch('accounting_actions.php?action=export_pohoda&id=' + id)
    .then(r => r.json())
    .then(res => {
        if (res.success) triggerDownload('temp/exports/' + res.file);
    });
}

function exportS3(id) {
    fetch('accounting_actions.php?action=export_s3money&id=' + id)
    .then(r => r.json())
    .then(res => {
        if (res.success) triggerDownload('temp/exports/' + res.file);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>

