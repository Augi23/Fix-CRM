<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) die(__('customer_id_missing'));

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) die(__('customer_not_found'));

$success = false;
$error = false;
$notice = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Security token invalid.");
    }

    $customer_type = $_POST['customer_type'] ?? 'private';
    // Ochrana identity: vyplněné jméno/příjmení/telefon/e-mail přepíše jen administrátor.
    [$guarded, $blockedFields] = crmGuardCustomerIdentity($customer, [
        'first_name' => $_POST['first_name'] ?? '',
        'last_name'  => $_POST['last_name'] ?? '',
        'phone'      => $_POST['phone'] ?? '',
        'email'      => $_POST['email'] ?? '',
    ]);
    $first_name = $guarded['first_name'];
    $last_name  = $guarded['last_name'];
    $phone      = $guarded['phone'];
    $email      = $guarded['email'];
    $address = $_POST['address'];
    $ico = $_POST['ico'] ?? '';
    $dic = $_POST['dic'] ?? '';
    $company = $_POST['company'] ?? '';

    try {
        ensureCustomerLanguageColumn();
        $preferred_language = normalizeCustomerLanguage($_POST['preferred_language'] ?? ($customer['preferred_language'] ?? 'cs'));
        $update = $pdo->prepare("UPDATE customers SET customer_type = ?, first_name = ?, last_name = ?, phone = ?, email = ?, address = ?, ico = ?, dic = ?, company = ?, preferred_language = ? WHERE id = ?");
        $update->execute([$customer_type, $first_name, $last_name, $phone, $email, $address, $ico, $dic, $company, $preferred_language, $id]);
        $success = __('customer_updated_success');
        crmAuditLog('customer.update', [
            'entity_type' => 'customer', 'entity_id' => (int)$id,
            'entity_label' => trim($first_name . ' ' . $last_name),
            'summary' => 'Upraven klient ' . trim($first_name . ' ' . $last_name),
            'details' => !empty($blockedFields) ? ['zablokovana_pole' => $blockedFields] : null,
        ]);
        if (!empty($blockedFields)) {
            $notice = 'Jméno, příjmení, telefon nebo e-mail už byly vyplněné — změnit je může jen administrátor. Původní hodnoty byly zachovány, ostatní úpravy uloženy.';
        }
        // Refresh
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
    } catch (Exception $e) {
        $error = __('error') . ": " . $e->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo __('edit'); ?> <?php echo __('client'); ?></h2>
    <a href="customers.php" class="btn btn-outline-secondary"><?php echo __('back'); ?></a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="alert alert-warning"><i class="fas fa-lock me-2"></i><?php echo htmlspecialchars($notice); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="row g-3">
                <div class="col-12 mb-2">
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="customer_type" id="type_private" value="private" <?php echo ($customer['customer_type'] ?? 'private') == 'private' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="type_private"><?php echo __('private_person'); ?></label>

                        <input type="radio" class="btn-check" name="customer_type" id="type_company" value="company" <?php echo ($customer['customer_type'] ?? 'private') == 'company' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="type_company"><?php echo __('company_entity'); ?></label>
                    </div>
                </div>

                <div id="company_fields" class="<?php echo ($customer['customer_type'] ?? 'private') == 'company' ? '' : 'd-none'; ?> border p-3 rounded bg-dark bg-opacity-25 border-secondary mb-3">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('ico'); ?></label>
                            <div class="input-group">
                                <input type="text" name="ico" id="ico_input" class="form-control" value="<?php echo htmlspecialchars($customer['ico'] ?? ''); ?>">
                                <button class="btn btn-info text-white" type="button" id="btn_fetch_ares">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('dic'); ?></label>
                            <input type="text" name="dic" id="ares_dic" class="form-control" value="<?php echo htmlspecialchars($customer['dic'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('company_name'); ?></label>
                            <input type="text" name="company" id="ares_name" class="form-control" value="<?php echo htmlspecialchars($customer['company'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <?php $__lockHint = '<div class="form-text text-warning"><i class="fas fa-lock me-1"></i>Vyplněný údaj mění jen administrátor</div>'; ?>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('client'); ?> (<?php echo __('client_first_name'); ?>)</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($customer['first_name']); ?>" required <?php echo crmCustomerFieldLocked($customer['first_name']) ? 'readonly' : ''; ?>>
                    <?php if (crmCustomerFieldLocked($customer['first_name'])) echo $__lockHint; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('client'); ?> (<?php echo __('client_last_name'); ?>)</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($customer['last_name']); ?>" required <?php echo crmCustomerFieldLocked($customer['last_name']) ? 'readonly' : ''; ?>>
                    <?php if (crmCustomerFieldLocked($customer['last_name'])) echo $__lockHint; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('phone'); ?></label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone']); ?>" required <?php echo crmCustomerFieldLocked($customer['phone']) ? 'readonly' : ''; ?>>
                    <?php if (crmCustomerFieldLocked($customer['phone'])) echo $__lockHint; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($customer['email']); ?>" <?php echo crmCustomerFieldLocked($customer['email']) ? 'readonly' : ''; ?>>
                    <?php if (crmCustomerFieldLocked($customer['email'])) echo $__lockHint; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('customer_language'); ?></label>
                    <?php $__cl = normalizeCustomerLanguage($customer['preferred_language'] ?? 'cs'); ?>
                    <select name="preferred_language" class="form-select">
                        <option value="cs" <?php echo $__cl === 'cs' ? 'selected' : ''; ?>>🇨🇿 Čeština</option>
                        <option value="en" <?php echo $__cl === 'en' ? 'selected' : ''; ?>>🇬🇧 English</option>
                        <option value="uk" <?php echo $__cl === 'uk' ? 'selected' : ''; ?>>🇺🇦 Українська</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label"><?php echo __('address'); ?></label>
                    <textarea name="address" id="address_field" class="form-control" rows="2"><?php echo htmlspecialchars($customer['address']); ?></textarea>
                </div>
                <div class="col-12 mt-4 d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    <button type="button" class="btn btn-outline-danger" onclick="deleteCustomer(<?php echo $id; ?>)"><?php echo __('delete'); ?> <?php echo __('client'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#company_fields').removeClass('d-none');
        } else {
            $('#company_fields').addClass('d-none');
        }
    });

    $('#btn_fetch_ares').on('click', function() {
        const ico = $('#ico_input').val().trim();
        if (!ico) return showAlert('<?php echo __('enter_ico_prompt'); ?>');
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search"></i>');
                if (data && data.obchodniJmeno) {
                    $('#ares_name').val(data.obchodniJmeno);
                    if (data.dic) {
                        $('#ares_dic').val(data.dic);
                    }
                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#address_field').val(addr.trim());
                    }
                } else {
                    showAlert('<?php echo __('ares_data_not_found'); ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search"></i>');
                showAlert('<?php echo __('ares_fetch_error'); ?>');
            }
        });
    });
});

function deleteCustomer(id) {
    showConfirm('<?php echo __('confirm_delete_customer'); ?>', function() {
        $.post('api/delete_customer.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('customer_deleted'); ?>');
                window.location.href = 'customers.php';
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
