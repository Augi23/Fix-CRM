<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

$ui_lang = $_REQUEST['ui_lang'] ?? null;
$t = static function(string $key) use ($ui_lang): string {
    return __($key, is_string($ui_lang) ? $ui_lang : null);
};

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => $t('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('csrf_token_invalid')]);
    exit;
}

// Editace zakázky je PROVOZNÍ právo — samotné přihlášení nestačí. Bez téhle
// kontroly mohl zakázku přepsat kdokoli se session (stav, cenu, technika,
// pobočku), včetně role „účetní", která do zakázek nesmí vůbec (její
// hasPermission() vrací vždy false — viz crmAccountantHasPermission()).
// Zaměstnancům dává edit_orders implicitně hasPermission(), takže provoz
// tahle pojistka nijak neomezí.
if (!hasPermission('edit_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('access_denied_msg')]);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => $t('missing_id')]);
    exit;
}

try {
    // DDL checks can trigger implicit commits on MySQL/MariaDB,
    // so run schema/bootstrap guard before starting explicit transaction.
    ensureOrderWorkTrackingSchema();
    ensureOrderWorkLogSchema(); // DDL — must run before beginTransaction()
    ensureOrderPriorityLowValue(); // DDL — ENUM priority musí znát 'Low'
    ensureOrderRepairSolutionColumn(); // DDL — „Provedená oprava"

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new Exception($t('order_not_found'));
    }

    if (!canAccessOrderBranch($current)) {
        throw new Exception($t('access_denied_msg'));
    }

    $new_status = normalizeOrderStatus($_POST['status'] ?? $current['status']);

    // Nezaplacený výdej → „Vydáno - čeká na platbu" (v3.49.0, stejné pravidlo
    // jako v update_order_status.php): platbu zaznamenává výhradně Pokladna a
    // k doplatku nabízí právě tenhle stav. Plné „Vydáno" tu projde jen s už
    // podchycenou platbou nebo u 0 Kč.
    if ((string)$new_status === 'Vydáno' && !isOrderStatusIn((string)$current['status'], 'collected')) {
        // Výslovně poslaná 0 = výdej zdarma (goodwill) — na odhad se NEspadá;
        // stejné pravidlo jako v update_order_status.php. Fallback na odhad je
        // jen pro zakázky, kde finální cena zatím nebyla vyplněná vůbec.
        if (isset($_POST['final_cost']) && (string)$_POST['final_cost'] !== '') {
            $__amountDue = (float)(crmNumOrNull($_POST['final_cost']) ?? 0);
        } else {
            $__amountDue = ($current['final_cost'] !== null && $current['final_cost'] !== '')
                ? (float)$current['final_cost']
                : (float)($current['estimated_cost'] ?? 0);
        }
        if ($__amountDue > 0) {
            $__paid = trim((string)($current['payment_method'] ?? '')) !== '';
            try {
                if (!$__paid) {
                    $__iv = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND status <> 'cancelled' LIMIT 1");
                    $__iv->execute([(int)$order_id]);
                    $__paid = (bool)$__iv->fetchColumn();
                }
                if (!$__paid && function_exists('crmOrderPosSale')) {
                    $__paid = (bool)crmOrderPosSale((int)$order_id);
                }
                if (!$__paid) {
                    // žádné ensure* (DDL) — jsme uvnitř transakce; chybu spolkne catch
                    $__cm = $pdo->prepare("SELECT id FROM pos_cash_movements WHERE ref_type = 'order' AND ref_id = ? LIMIT 1");
                    $__cm->execute([(int)$order_id]);
                    $__paid = (bool)$__cm->fetchColumn();
                }
            } catch (Throwable $e) {
                error_log('update_order_full kontrola platby pred vydejem selhala #' . (int)$order_id . ': ' . $e->getMessage());
            }
            if (!$__paid) { $new_status = 'Vydáno - čeká na platbu'; }
        }
    }

    $technician_id = ($_POST['technician_id'] ?? '') !== '' ? (int)$_POST['technician_id'] : (int)$current['technician_id'];

    // Od 1.6.1: technika smí přeřadit KAŽDÝ zaměstnanec (shodně s
    // update_order_status.php) — omezení „technik si smí zakázku jen převzít"
    // odstraněno, UI přeřazení nabízí všem. Pobočka zakázky se přiřazením
    // technika NEMĚNÍ — zakázka patří pobočce, kde je zařízení (dřívější
    // „zakázka následuje technika" by aktérovi zakázku schovala ze seznamu).
    $branch_id = (int)($current['branch_id'] ?? getCurrentStaffBranchId());
    if (!canAssignTechnicianToOrder($technician_id, $branch_id)) {
        throw new Exception('Vybraný technik neexistuje nebo není aktivní.');
    }
    $is_starting = (isOrderStatusIn($new_status, 'in_progress') && !isOrderStatusIn($current['status'], 'in_progress'));
    $was_finished = isOrderStatusIn($current['status'], 'done');
    $is_finishing = isOrderStatusIn($new_status, 'done');
    $technician_changed = (int)$technician_id !== (int)($current['technician_id'] ?? 0);
    $is_reassigning_in_progress = (isOrderStatusIn($current['status'], 'in_progress') && isOrderStatusIn($new_status, 'in_progress') && $technician_changed);

    if (isOrderStatusIn($new_status, 'in_progress')) {
        if (!$technician_id) {
            throw new Exception($t('in_progress_requires_technician'));
        }

        if ($is_starting || $technician_changed) {
            $active_count = getTechnicianInProgressCount($technician_id, (int)$order_id);
            if ($active_count >= CRM_TECH_IN_PROGRESS_LIMIT && !$was_finished) {
                throw new Exception($t('technician_in_progress_limit_reached'));
            }
        }
    }

    // „Provedená oprava" je povinná pro dokončení/výdej (Připraveno k převzetí / Vydáno).
    // 'Nevyzvednuto' je vynecháno ZÁMĚRNĚ — zařízení může být nevyzvednuté i bez
    // provedené opravy (klient nereagoval na cenový návrh); výdej z něj už hlídaný je.
    $__repairSolution = isset($_POST['repair_solution'])
        ? trim((string)$_POST['repair_solution'])
        : trim((string)($current['repair_solution'] ?? ''));
    if (($__repairSolution === '') && (isOrderStatusIn($new_status, 'completed') || isOrderStatusIn($new_status, 'collected'))) {
        throw new Exception($t('repair_solution_required'));
    }

    // Klient zakázky: změnu smí provést každý s právy k zakázce, ale záměna
    // skutečného vyplněného klienta se v Historii VÝRAZNĚ označí „RUČNĚ ZMĚNĚN"
    // (rozhodnutí 14.7.2026 — místo dřívějšího admin-only zámku auditní stopa).
    // Nový klient musí existovat (pojistka proti tichému defaultu ze selectu).
    $customerIdToSave = (int)$current['customer_id'];
    $postedCustomerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $__custChangeAudit = null;
    if ($postedCustomerId > 0 && $postedCustomerId !== $customerIdToSave) {
        $curCustStmt = $pdo->prepare('SELECT first_name, last_name, phone, email FROM customers WHERE id = ? LIMIT 1');
        $curCustStmt->execute([$customerIdToSave]);
        $curCust = $curCustStmt->fetch() ?: null;
        $newCustStmt = $pdo->prepare('SELECT first_name, last_name, phone FROM customers WHERE id = ? LIMIT 1');
        $newCustStmt->execute([$postedCustomerId]);
        $newCust = $newCustStmt->fetch() ?: null;
        if ($newCust) {
            $customerIdToSave = $postedCustomerId;
            if (!crmCustomerIsPlaceholder($curCust)) {
                // záměna skutečného klienta → připravit výrazný audit (zapíše se po UPDATE)
                $__fmtC = static fn($c) => trim(((string)($c['first_name'] ?? '')) . ' ' . ((string)($c['last_name'] ?? '')))
                    . (trim((string)($c['phone'] ?? '')) !== '' ? ' (' . $c['phone'] . ')' : '');
                $__oc = trim((string)($current['order_code'] ?? '')) !== '' ? (string)$current['order_code'] : ('#' . (int)$order_id);
                $__custChangeAudit = [
                    'entity_type' => 'order', 'entity_id' => (int)$order_id, 'entity_label' => $__oc,
                    'summary' => 'RUČNĚ ZMĚNĚN klient zakázky ' . $__oc . ': „' . $__fmtC($curCust ?: []) . '" → „' . $__fmtC($newCust) . '"',
                    'details' => ['puvodni_klient_id' => (int)$current['customer_id'], 'novy_klient_id' => $postedCustomerId],
                ];
            }
        }
    }

    $sql = "UPDATE orders SET
        customer_id = ?,
        device_model = ?,
        device_brand = ?,
        device_type = ?,
        order_type = ?,
        status = ?,
        technician_id = ?,
        branch_id = ?,
        estimated_cost = ?,
        final_cost = ?,
        extra_expenses = ?,
        problem_description = ?,
        repair_solution = ?,
        technician_notes = ?,
        pin_code = ?,
        appearance = ?,
        priority = ?,
        serial_number = ?,
        serial_number_2 = ?";

    $params = [
        $customerIdToSave,
        isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'],
        isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand'],
        isset($_POST['device_type']) ? $_POST['device_type'] : $current['device_type'],
        isset($_POST['order_type']) ? $_POST['order_type'] : $current['order_type'],
        $new_status,
        $technician_id ?: null,   // 0 = „bez technika" → SQL NULL (FK orders_ibfk_2)
        $branch_id,
        // POZOR: prázdný number input posílá '' — strict MariaDB '' jako DECIMAL odmítne
        // (zakázky z webu mají finální cenu prázdnou → edit padal, dokud se nevyplnila)
        isset($_POST['estimated_cost']) ? crmNumOrNull($_POST['estimated_cost']) : $current['estimated_cost'],
        isset($_POST['final_cost']) ? crmNumOrNull($_POST['final_cost']) : $current['final_cost'],
        isset($_POST['extra_expenses']) ? (crmNumOrNull($_POST['extra_expenses']) ?? 0) : $current['extra_expenses'],
        isset($_POST['problem_description']) ? $_POST['problem_description'] : $current['problem_description'],
        // trim + prázdné → NULL (whitespace-only by v tiscích/portálu vypadal jako vyplněný)
        isset($_POST['repair_solution']) ? (trim((string)$_POST['repair_solution']) !== '' ? trim((string)$_POST['repair_solution']) : null) : ($current['repair_solution'] ?? null),
        isset($_POST['technician_notes']) ? $_POST['technician_notes'] : $current['technician_notes'],
        isset($_POST['pin_code']) ? $_POST['pin_code'] : $current['pin_code'],
        isset($_POST['appearance']) ? $_POST['appearance'] : $current['appearance'],
        isset($_POST['priority']) ? normalizeOrderPriority($_POST['priority']) : $current['priority'],
        isset($_POST['serial_number']) ? $_POST['serial_number'] : $current['serial_number'],
        isset($_POST['serial_number_2']) ? $_POST['serial_number_2'] : $current['serial_number_2']
    ];

    if ($is_starting) {
        $sql .= ", work_started_at = CASE WHEN work_started_at IS NULL OR work_finished_at IS NOT NULL THEN CURRENT_TIMESTAMP ELSE work_started_at END, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL";
        $params[] = $technician_id;
    }

    if ($is_reassigning_in_progress) {
        $sql .= ", work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, CURRENT_TIMESTAMP)) ELSE 0 END, work_started_at = CURRENT_TIMESTAMP, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL";
        $params[] = $technician_id;
    }

    if (isOrderStatusIn($current['status'], 'in_progress') && $is_finishing) {
        $sql .= ", work_finished_at = IFNULL(work_finished_at, CURRENT_TIMESTAMP), work_finished_by = IFNULL(work_finished_by, ?), work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, IFNULL(work_finished_at, CURRENT_TIMESTAMP))) ELSE 0 END";
        $params[] = $technician_id;
    }

    $sql .= " WHERE id = ?";
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Reklamace k zakázce patří k jejímu klientovi: při změně klienta zakázky se
    // přepíšou i ony (jinak by zůstaly na starém záznamu a klient by je v portálu
    // neviděl). Jen ty, které dosud ukazovaly na původního klienta.
    if ($customerIdToSave !== (int)$current['customer_id']) {
        try {
            $pdo->prepare("UPDATE complaints SET customer_id = ? WHERE order_id = ? AND (customer_id = ? OR customer_id IS NULL)")
                ->execute([$customerIdToSave, (int)$order_id, (int)$current['customer_id']]);
        } catch (Throwable $e) { /* starší DB bez complaints.order_id */ }
    }

    if ($__custChangeAudit !== null) { crmAuditLog('order.customer_change', $__custChangeAudit); }
    // PIN z webové rezervace začne platit pro portál, až když ho obsluha sama ZMĚNÍ
    // (pouhé uložení zakázky nestačí — to může být jen triáž bez klienta)
    if (function_exists('crmOrderPinVerified') && isset($_POST['pin_code'])
        && trim((string)$_POST['pin_code']) !== '' && trim((string)$_POST['pin_code']) !== trim((string)($current['pin_code'] ?? ''))) {
        crmOrderPinVerified((int)$order_id);
    }

    // Per-technician work segments (mirror the orders.work_* transitions above).
    if ($is_starting || $is_reassigning_in_progress) {
        workSegmentOpen((int)$order_id, (int)$technician_id);
    }
    if (isOrderStatusIn($current['status'], 'in_progress') && $is_finishing) {
        workSegmentClose((int)$order_id);
    }
    assignmentSegmentSync((int)$order_id, (int)$technician_id ?: null, (string)$new_status);

    if (isset($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $htaccess = $upload_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "# Deny PHP execution in uploads\n" .
                "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
                "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
                "RemoveType .php .phtml .php3 .php4 .php5\n"
            );
        }

        $allowed_mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
        ];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
        if (!is_writable($upload_dir)) {
            throw new Exception($t('upload_dir_not_writable'));
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $uploaded_count = 0;
        $rejected = [];

        foreach ($_FILES['files']['tmp_name'] as $key => $tmp) {
            $err = (int)($_FILES['files']['error'][$key] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $rejected[] = basename((string)($_FILES['files']['name'][$key] ?? 'file')) . " (upload error " . $err . ")";
                continue;
            }

            $name = (string)($_FILES['files']['name'][$key] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $rejected[] = basename($name) . " (temporary upload missing)";
                continue;
            }

            $real_type = strtolower((string)finfo_file($finfo, $tmp));
            if (!isset($allowed_mime_to_ext[$real_type])) {
                $rejected[] = basename($name) . " (unsupported type: " . ($real_type ?: 'unknown') . ")";
                continue;
            }
            if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) {
                $rejected[] = basename($name) . " (image validation failed)";
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) {
                $ext = $allowed_mime_to_ext[$real_type];
            }

            $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $stmt_file = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
                $stmt_file->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($name)]);
                $uploaded_count++;
            } else {
                $rejected[] = basename($name) . " (cannot move uploaded file)";
            }
        }

        finfo_close($finfo);

        if ($uploaded_count === 0) {
            $detail = !empty($rejected) ? (' ' . implode('; ', array_slice($rejected, 0, 3))) : '';
            throw new Exception($t('upload_no_valid_file') . $detail);
        }
    }


    $new_status = normalizeOrderStatus($_POST['status'] ?? $current['status']);
    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    $was_finished = isOrderStatusIn($current['status'], 'done');
    $is_finishing = isOrderStatusIn($new_status, 'done');

    if (isOrderStatusIn($current['status'], 'collected') && !isOrderStatusIn($new_status, 'collected')) {
        throw new Exception($t('status_locked_after_collected'));
    }

    $status_changed = ((string)$current['status'] !== (string)$new_status);
    $technician_changed = ((int)($current['technician_id'] ?? 0) !== (int)$technician_id);

    // Upozornění pro obsluhu (např. díl, který nebyl skladem) — sbírají se
    // za běhu a posílají se s odpovědí, změnu stavu ale nikdy nezastaví.
    $warnings = [];
    // Auto-faktura se vystavuje AŽ PO COMMITU, tady se jen poznamená, že se má.
    // InvoiceManager si otevírá vlastní transakci, takže volání odsud (uvnitř té
    // naší) skončilo výjimkou „There is already an active transaction", model
    // odrolloval NAŠI transakci a uložení celé zakázky spadlo.
    $__issueInvoice = false;

    if ($status_changed) {
        if (!$was_finished && $is_finishing) {
            $warnings = array_merge($warnings, processOrderInventoryChange($order_id, $is_finishing, $was_finished));
            $__issueInvoice = isOrderStatusIn($new_status, 'completed') && get_setting('acc_auto_create_invoice', '0') == '1';
        } elseif ($was_finished && !$is_finishing) {
            $warnings = array_merge($warnings, processOrderInventoryChange($order_id, $is_finishing, $was_finished));
        }
        logOrderStatusChange($order_id, $current['status'], $new_status);
    }

    $pdo->commit();

    if ($__issueInvoice) {
        // Číslo si přidělí sama z maxima řady pod zámkem; 0 = nevystavena
        // (zamčené účetní období nebo chyba) — zakázka je ale už uložená.
        if (crmEnsureOrderInvoice((int)$order_id, 'bank_transfer') <= 0) {
            $warnings[] = 'Zakázka je uložená, ale fakturu se nepodařilo vystavit — vystav ji ručně v Účetnictví.';
        }
    }

    // Auditní historie: seznam změněných polí (kdo/kdy řeší crmAuditLog sám).
    $__chg = [];
    $__cmp = static function ($label, $old, $new) use (&$__chg) { if ((string)$old !== (string)$new) { $__chg[] = $label; } };
    $__newFinal = isset($_POST['final_cost']) ? crmNumOrNull($_POST['final_cost']) : $current['final_cost'];
    $__newEst   = isset($_POST['estimated_cost']) ? crmNumOrNull($_POST['estimated_cost']) : $current['estimated_cost'];
    $__cmp('stav', $current['status'], $new_status);
    $__cmp('klient', (int)$current['customer_id'], (int)$customerIdToSave);
    $__cmp('technik', (int)($current['technician_id'] ?? 0), (int)$technician_id);
    $__cmp('finální cena', $current['final_cost'], $__newFinal);
    $__cmp('předběžná cena', $current['estimated_cost'], $__newEst);
    $__cmp('priorita', $current['priority'], isset($_POST['priority']) ? normalizeOrderPriority($_POST['priority']) : $current['priority']);
    $__cmp('popis závady', $current['problem_description'], isset($_POST['problem_description']) ? $_POST['problem_description'] : $current['problem_description']);
    $__cmp('provedená oprava', $current['repair_solution'] ?? '', isset($_POST['repair_solution']) ? $_POST['repair_solution'] : ($current['repair_solution'] ?? ''));
    $__cmp('poznámka', $current['technician_notes'], isset($_POST['technician_notes']) ? $_POST['technician_notes'] : $current['technician_notes']);
    $__cmp('zařízení', trim(($current['device_brand'] ?? '') . ' ' . ($current['device_model'] ?? '')), trim((isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand']) . ' ' . (isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'])));
    $__oc = trim((string)($current['order_code'] ?? '')) !== '' ? (string)$current['order_code'] : ('#' . (int)$order_id);
    crmAuditLog('order.update', [
        'entity_type' => 'order', 'entity_id' => (int)$order_id, 'entity_label' => $__oc,
        'summary' => 'Upravena zakázka ' . $__oc . (count($__chg) ? ' — změněno: ' . implode(', ', $__chg) : ' (beze změn)'),
        'details' => ['zmeneno' => $__chg, 'stav' => [$current['status'], $new_status], 'customer_id' => [(int)$current['customer_id'], (int)$customerIdToSave]],
        'branch_id' => $current['branch_id'] ?? null,
    ]);

    if ($status_changed || $technician_changed) {
        crmNotifyOrderLifecycleEvent([
            'type' => 'order_status_changed',
            'order_id' => (int)$order_id,
            'old_status' => (string)$current['status'],
            'new_status' => (string)$new_status,
            'technician_id' => (int)$technician_id,
            'previous_technician_id' => (int)($current['technician_id'] ?? 0),
            'final_cost' => $final_cost,
            'actor_role' => (string)($_SESSION['role'] ?? ''),
            'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
            'actor_name' => (string)($_SESSION['full_name'] ?? ''),
        ]);
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => true, 'warnings' => array_values($warnings),
                      'message' => implode(' ', $warnings)], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
