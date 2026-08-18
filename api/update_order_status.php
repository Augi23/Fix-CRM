<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
ob_clean();
header('Content-Type: application/json');

checkApiRateLimit('order_status', 30, 60);

$ui_lang = $_REQUEST['ui_lang'] ?? null;
$t = static function(string $key) use ($ui_lang): string {
    return __($key, is_string($ui_lang) ? $ui_lang : null);
};

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => $t('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $t('csrf_token_invalid')]);
    exit;
}

$order_id = $_REQUEST['order_id'] ?? null;
$requested_status = $_REQUEST['status'] ?? null;
$new_status = $requested_status !== null ? normalizeOrderStatus($requested_status) : null;
$final_cost = $_REQUEST['final_cost'] ?? null;
$technician_id = $_REQUEST['technician_id'] ?? null;

ensureOrderWorkTrackingSchema();
ensureOrderWorkLogSchema(); // DDL — must run before beginTransaction()
ensureOrderRepairSolutionColumn(); // DDL — „Provedená oprava" (povinná před dokončením)

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => $t('missing_data')]);
    exit;
}

// Platba se zakázce od v3.49.0 NEZAPISUJE tady — jediné místo záznamu platby
// je Pokladna (pos_checkout: hotově / kartou / na fakturu). Parametr
// payment_method se záměrně ignoruje, aby starší otevřené záložky nemohly
// platbu zapsat mimo kasu. Výdej bez zaznamenané platby projde (reklamace,
// záruka, 0 Kč), ale u nenulové částky vrací viditelné varování níže.

try {
    $pdo->beginTransaction();

    ensureOrderPaymentMethodColumn();
    $stmt = $pdo->prepare('SELECT order_code, status, technician_id, branch_id, estimated_cost, final_cost, repair_solution, payment_method, work_started_at, work_finished_at, work_duration_seconds FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();

    if (!$order_data) {
        throw new Exception($t('order_not_found'));
    }

    // Od 1.6.0 (požadavek 15.7.2026): změnu stavu zakázky smí provést KAŽDÝ
    // přihlášený zaměstnanec a technika smí přeřadit na kohokoliv (dřívější
    // pobočková brána canAccessOrderBranch a omezení „technik smí jen převzít
    // nepřiřazenou zakázku" hlásily technikům „Přístup odepřen").

    $current_status = $order_data['status'];
    $current_tech_id = $order_data['technician_id'];
    $current_estimated = $order_data['estimated_cost'];
    $current_final = $order_data['final_cost'];
    // '0' = výslovně „bez technika" (odebrat přiřazení); prázdné = beze změny.
    // POZOR: '0' je v PHP falsy, proto strict porovnání (dřív se '0' tiše ignorovala).
    $target_tech_id = ($technician_id !== null && $technician_id !== '') ? (int)$technician_id : (int)$current_tech_id;

    // Pobočka zakázky se přiřazením technika NEMĚNÍ — zakázka patří pobočce, kde
    // je zařízení. (Dřívější „zakázka následuje technika" po uvolnění výběru
    // techniků způsobovala, že by si aktér přiřazením kolegy z jiné pobočky
    // zakázku sám schoval — zmizela by mu ze seznamu i z detailu.)
    $target_branch_id = (int)($order_data['branch_id'] ?? getCurrentStaffBranchId());
    if (!canAssignTechnicianToOrder($target_tech_id, $target_branch_id)) {
        throw new Exception('Vybraný technik neexistuje nebo není aktivní.');
    }

    if (isOrderStatusIn($current_status, 'collected') && !isOrderStatusIn($new_status, 'collected')) {
        throw new Exception($t('status_locked_after_collected'));
    }

    // „Provedená oprava" je povinná pro dokončení/výdej (Připraveno k převzetí / Vydáno).
    // UI ji může poslat rovnou s přechodem (modal doplnění) — pak se zde i uloží.
    // 'Nevyzvednuto' je vynecháno ZÁMĚRNĚ — zařízení může být nevyzvednuté i bez
    // provedené opravy (klient nereagoval na cenový návrh); výdej z něj už hlídaný je.
    $posted_solution = isset($_POST['repair_solution']) ? trim((string)$_POST['repair_solution']) : '';
    $effective_solution = $posted_solution !== '' ? $posted_solution : trim((string)($order_data['repair_solution'] ?? ''));
    if (($effective_solution === '') && (isOrderStatusIn($new_status, 'completed') || isOrderStatusIn($new_status, 'collected'))) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'code' => 'repair_solution_required',
            'message' => $t('repair_solution_required'),
        ]);
        exit;
    }

    $finishing_statuses = getOrderStatusList('done');
    $was_finished = in_array($current_status, $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);
    $is_starting = (isOrderStatusIn($new_status, 'in_progress') && !isOrderStatusIn($current_status, 'in_progress'));
    $technician_changed = ((int)$target_tech_id !== (int)$current_tech_id);
    $is_reassigning_in_progress = (isOrderStatusIn($current_status, 'in_progress') && isOrderStatusIn($new_status, 'in_progress') && $technician_changed);

    if (isOrderStatusIn($new_status, 'in_progress')) {
        if (!$target_tech_id) {
            throw new Exception($t('in_progress_requires_technician'));
        }
        $active_count = getTechnicianInProgressCount($target_tech_id, (int)$order_id);
        if ($active_count >= CRM_TECH_IN_PROGRESS_LIMIT && !$was_finished) {
            throw new Exception($t('technician_in_progress_limit_reached'));
        }
    }

    // Nezaplacený výdej nesmí skončit v plném „Vydáno" (v3.49.0): platbu
    // zaznamenává výhradně Pokladna a ta k doplatku nabízí právě zakázky ve
    // stavu „Vydáno - čeká na platbu". Plné „Vydáno" tu dostane jen výdej,
    // kde je platba už podchycená (faktura / prodejka z kasy / hotovost) nebo
    // není co platit (0 Kč — reklamace, záruka); jinak ho doplní kasa po platbě.
    if ((string)$new_status === 'Vydáno' && !isOrderStatusIn($current_status, 'collected')) {
        $amountDue = ($final_cost !== null && $final_cost !== '')
            ? (float)$final_cost
            : (float)(($current_final !== null && $current_final !== '') ? $current_final : ($current_estimated ?? 0));
        if ($amountDue > 0) {
            $paidAlready = trim((string)($order_data['payment_method'] ?? '')) !== '';
            try {
                if (!$paidAlready) {
                    $iv = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND status <> 'cancelled' LIMIT 1");
                    $iv->execute([(int)$order_id]);
                    $paidAlready = (bool)$iv->fetchColumn();
                }
                if (!$paidAlready && function_exists('crmOrderPosSale')) {
                    $paidAlready = (bool)crmOrderPosSale((int)$order_id);
                }
                if (!$paidAlready) {
                    // POZOR: žádné ensure* (DDL) — jsme uvnitř transakce; chybějící
                    // tabulku spolkne catch a výdej projde postaru (fail-open)
                    $cm = $pdo->prepare("SELECT id FROM pos_cash_movements WHERE ref_type = 'order' AND ref_id = ? LIMIT 1");
                    $cm->execute([(int)$order_id]);
                    $paidAlready = (bool)$cm->fetchColumn();
                }
            } catch (Throwable $e) {
                error_log('update_order_status kontrola platby pred vydejem selhala #' . (int)$order_id . ': ' . $e->getMessage());
            }
            if (!$paidAlready) { $new_status = 'Vydáno - čeká na platbu'; }
        }
    }

    $sql = 'UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$new_status];

    if ($is_starting) {
        $sql .= ', work_started_at = CASE WHEN work_started_at IS NULL OR work_finished_at IS NOT NULL THEN CURRENT_TIMESTAMP ELSE work_started_at END, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL';
        $params[] = $target_tech_id;
    }

    if ($is_reassigning_in_progress) {
        $sql .= ', work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, CURRENT_TIMESTAMP)) ELSE 0 END, work_started_at = CURRENT_TIMESTAMP, work_started_by = ?, work_finished_at = NULL, work_finished_by = NULL';
        $params[] = $target_tech_id;
    }

    if (isOrderStatusIn($current_status, 'in_progress') && $is_finishing) {
        $sql .= ', work_finished_at = IFNULL(work_finished_at, CURRENT_TIMESTAMP), work_finished_by = IFNULL(work_finished_by, ?), work_duration_seconds = COALESCE(work_duration_seconds, 0) + CASE WHEN work_started_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, work_started_at, IFNULL(work_finished_at, CURRENT_TIMESTAMP))) ELSE 0 END';
        $params[] = $target_tech_id;
    }

    if (isOrderStatusIn($new_status, 'collected')) {
        $sql .= ', shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP)';
        // Výdej bez zvoleného způsobu předání → automaticky „Osobní odběr" (Self Pickup).
        // Umožní bleskové „Vydáno" z jakéhokoli stavu bez proklikávání dopravy
        // (rozhodnutí majitele: jiná volba stejně nefunguje).
        $sql .= ", shipping_method = IFNULL(NULLIF(shipping_method, ''), 'Self Pickup')";
        // payment_method se tu od v3.49.0 NEzapisuje — zapisuje ho výhradně kasa.
    }

    if (isOrderStatusIn($new_status, 'collected') && ($final_cost === null || $final_cost === '')) {
        $final_cost = ($current_final !== null && $current_final !== '') ? $current_final : $current_estimated;
    }

    if ($final_cost !== null && $final_cost !== '') {
        $sql .= ', final_cost = ?';
        $params[] = $final_cost;
    }

    $updated_tech_id = ($technician_id !== null && $technician_id !== '') ? (int)$technician_id : (int)$current_tech_id;
    $sql .= ', technician_id = ?, branch_id = ?';
    // 0 = „bez technika" → SQL NULL, jinak padá FK orders_ibfk_2 (technik id 0 neexistuje);
    // týká se hlavně zakázek z RepairPluginu, které vznikají bez přiřazeného technika
    $params[] = $updated_tech_id ?: null;
    $params[] = $target_branch_id;

    if (isset($_REQUEST['extra_expenses']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sql .= ', extra_expenses = ?';
        $params[] = $_REQUEST['extra_expenses'];
    }

    if ($posted_solution !== '') {
        $sql .= ', repair_solution = ?';
        $params[] = $posted_solution;
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Per-technician work segments (mirror the orders.work_* transitions above).
    if ($is_starting || $is_reassigning_in_progress) {
        workSegmentOpen((int)$order_id, (int)$target_tech_id);
    }
    if (isOrderStatusIn($current_status, 'in_progress') && $is_finishing) {
        workSegmentClose((int)$order_id);
    }
    assignmentSegmentSync((int)$order_id, (int)$updated_tech_id ?: null, (string)($new_status ?? $current_status));

    if ($current_status !== $new_status) {
        logOrderStatusChange($order_id, $current_status, $new_status);
    }

    // Návratová hodnota nese upozornění typu „díl X nebyl skladem" — bez převzetí
    // by se zahodila a obsluha by o chybějícím kusu nevěděla (sklad by tiše seděl
    // v minusu). Přibalí se do odpovědi vedle platebního varování.
    $stockWarnings = [];
    if ($was_finished !== $is_finishing) {
        $__inv = processOrderInventoryChange($order_id, $is_finishing, $was_finished);
        if (is_array($__inv)) {
            $stockWarnings = array_values(array_filter(array_map('strval', $__inv)));
        }
    }

    $status_changed = ($current_status !== $new_status);
    $technician_changed = ((int)$current_tech_id !== (int)$updated_tech_id);

    $pdo->commit();

    // Po commitu je změna ULOŽENÁ — audit/notifikace nesmí odpověď převrátit
    // na chybu (uživatel by akci opakoval). Případný pád se jen zaloguje.
    try {
        if ($status_changed) {
            $__oc = trim((string)($order_data['order_code'] ?? '')) !== '' ? (string)$order_data['order_code'] : ('#' . (int)$order_id);
            crmAuditLog('order.status_change', [
                'entity_type' => 'order', 'entity_id' => (int)$order_id, 'entity_label' => $__oc,
                'summary' => 'Zakázka ' . $__oc . ' — stav: ' . $current_status . ' → ' . $new_status,
            ]);
        }

        if ($status_changed || $technician_changed) {
            crmNotifyOrderLifecycleEvent([
                'type' => 'order_status_changed',
                'order_id' => (int)$order_id,
                'old_status' => (string)$current_status,
                'new_status' => (string)$new_status,
                'technician_id' => (int)$updated_tech_id,
                'previous_technician_id' => (int)$current_tech_id,
                'final_cost' => $final_cost,
                'actor_role' => (string)($_SESSION['role'] ?? ''),
                'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
                'actor_name' => (string)($_SESSION['full_name'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        error_log('update_order_status post-commit (audit/notify) selhal, zmena #' . (int)$order_id . ' ulozena: ' . $e->getMessage());
    }

    // ── Platba: od v3.49.0 ji zapisuje VÝHRADNĚ Pokladna (pos_checkout).
    //    Tady zbývá jen pojistka proti tiché ztrátě platby při výdeji. ──
    $payment_note = '';
    $payment_warning = false;
    $entering_collected = isOrderStatusIn($new_status, 'collected') && !isOrderStatusIn($current_status, 'collected');
    if ($entering_collected) {
        // POJISTKA proti tiché ztrátě platby: výdej BEZ zaznamenané platby
        // nezastavujeme (vydat se legitimně smí i bez placení — reklamace, záruka,
        // zakázka za 0 Kč), ale u nenulové částky nesmí projít potichu se zeleným
        // „Aktualizováno". Proto viditelné varování s odkazem na Pokladnu + audit.
        try {
            $amount = (float)($final_cost ?? 0);
            // Falešně nevaruj tam, kde je platba už podchycená jinde:
            // vystavená faktura (pohledávka) nebo hotovost přijatá dřív na kase.
            $already_paid = false;
            if ($amount > 0) {
                // Zaplaceno může být čtyřmi způsoby a každý se zapisuje jinam:
                // faktura (invoices), hotovost při výdeji (pos_cash_movements),
                // prodej přes kasu (pos_sales — jakýkoli způsob, ne jen hotovost)
                // a prostý záznam způsobu platby na zakázce.
                $iv = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND status <> 'cancelled' LIMIT 1");
                $iv->execute([(int)$order_id]);
                $already_paid = (bool)$iv->fetchColumn();
                if (!$already_paid) {
                    ensurePosCashMovementsTable();
                    $cm = $pdo->prepare("SELECT id FROM pos_cash_movements WHERE ref_type = 'order' AND ref_id = ? LIMIT 1");
                    $cm->execute([(int)$order_id]);
                    $already_paid = (bool)$cm->fetchColumn();
                }
                if (!$already_paid && function_exists('crmOrderPosSale')) {
                    $already_paid = (bool)crmOrderPosSale((int)$order_id);
                }
                if (!$already_paid) {
                    $already_paid = trim((string)($order_data['payment_method'] ?? '')) !== '';
                }
            }
            if ($amount > 0 && !$already_paid) {
                $__oc = trim((string)($order_data['order_code'] ?? '')) !== '' ? (string)$order_data['order_code'] : ('#' . (int)$order_id);
                $payment_note = 'Vydáno jako „čeká na platbu" (' . formatMoney($amount) . ' nezaplaceno). Platbu vyřiď v Pokladně — zakázku ' . $__oc . ' tam najdeš vyhledáváním; po zaplacení se sama přepne na Vydáno.';
                $payment_warning = true;
                // záměrně stejný typ jako u zaznamenané platby ('order.payment_set') —
                // jen ten má v přehledu historie český popisek; rozdíl nese souhrn
                crmAuditLog('order.payment_set', [
                    'entity_type' => 'order', 'entity_id' => (int)$order_id, 'entity_label' => $__oc,
                    'summary' => 'Zakázka ' . $__oc . ' — výdej BEZ záznamu platby (' . formatMoney($amount) . ')',
                ]);
            }
        } catch (Throwable $e) {
            error_log('update_order_status kontrola chybejici platby selhala #' . (int)$order_id . ': ' . $e->getMessage());
        }
    }

    // Skladová upozornění se připojí k platební poznámce — frontend pro ni už má
    // červené zobrazení i prodlevu před reloadem, takže obsluha si je stihne přečíst.
    if ($stockWarnings) {
        $payment_note = trim($payment_note . ($payment_note !== '' ? ' ' : '') . implode(' ', $stockWarnings));
        $payment_warning = true;
    }
    echo json_encode(['success' => true, 'message' => $t('status_updated'),
        'payment_note' => $payment_note, 'payment_warning' => $payment_warning,
        'stock_warnings' => $stockWarnings], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
