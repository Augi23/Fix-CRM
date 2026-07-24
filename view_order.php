<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Čárový kód / QR → otevři zakázku: ?scan=<číslo zakázky nebo id> se přeloží na konkrétní zakázku.
// (Musí proběhnout PŘED includes/header.php, který už posílá výstup.)
if (!isset($_GET['id']) && !isset($_GET['order_id']) && isset($_GET['scan'])) {
    $scanVal = trim((string)$_GET['scan']);
    if ($scanVal !== '') {
        $resolvedId = function_exists('resolveScannedOrderId') ? resolveScannedOrderId($pdo, $scanVal) : null;
        if ($resolvedId) {
            header('Location: view_order.php?id=' . (int)$resolvedId);
        } else {
            header('Location: orders.php?search=' . urlencode($scanVal));
        }
        exit;
    }
}

require_once 'includes/header.php';

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) die(__('order_id_missing'));

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.email, t.name as tech_name 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       LEFT JOIN technicians t ON o.technician_id = t.id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die(__('order_not_found'));

// zapamatuj naposledy otevřenou zakázku (pro předvyplnění při objednávání dílu)
$_SESSION['last_order_id'] = (int)$order['id'];

// Branch access control: staff see only orders from their branch; managers/admins see all.
if (!canAccessOrderBranch($order)) {
    die(__('no_edit_permission'));
}

// Self-heal: zakázka bez kódu (nesmí se uživateli ukazovat interní #ID) → doplnit další v řadě.
if (trim((string)($order['order_code'] ?? '')) === '') {
    try {
        $healCode = generateNextOrderCode($pdo);
        if ($healCode !== null) {
            $pdo->prepare("UPDATE orders SET order_code = ? WHERE id = ?")->execute([$healCode, (int)$order['id']]);
            $order['order_code'] = $healCode;
        }
    } catch (Throwable $e) { /* nevadí, zobrazí se #id a doplní se příště */ }
}

// Od 1.6.1: technika u zakázky smí změnit KAŽDÝ zaměstnanec (dřív jen vedoucí).
// Definice musí být PŘED $can_handoff níže — dřív se používala nedefinovaná
// (PHP warning) a vedoucím se nezobrazovala tlačítka předání.
$can_change_technician = true;

// Předání mezi techniky: tlačítka vidí přiřazený technik (nebo vedoucí) u rozpracované zakázky
$__isOwnTech = !empty($_SESSION['tech_id']) && (int)$_SESSION['tech_id'] === (int)($order['technician_id'] ?? 0);
$can_handoff = !empty($order['technician_id'])
    && isOrderStatusIn((string)$order['status'], 'in_progress')
    && ($__isOwnTech || $can_change_technician);
$techTimes = crmGetOrderTechTimes((int)$order['id']);

// Klienta zakázky smí změnit každý s právy k zakázce; záměna skutečného klienta
// se v Historii výrazně označí „RUČNĚ ZMĚNĚN" (audit v update_order_full.php).
$__canEditCustomer = true;

// Podpisy klienta (příjem/výdej) — blok v pravém sloupci + tisk na zakázkovém listu
$orderSignatures = crmGetOrderSignatures((int)$order['id']);

// Vazba na rezervaci z webu (RepairPlugin) — číslo objednávky z webu se ukazuje pod kódem zakázky.
$webBookingRef = null;
try {
    $wbq = $pdo->prepare("SELECT wp_booking_id, appointment_at FROM web_bookings WHERE order_id = ? LIMIT 1");
    $wbq->execute([(int)$order['id']]);
    $webBookingRef = $wbq->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $webBookingRef = null; }

// Fetch parts linked to this order
$stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$order_items = $stmt->fetchAll();

// Parts are loaded dynamically in the modal so technicians can search the full catalog.
$inventory = [];

// Klient se v editaci vybírá přes AJAX vyhledávání (api/search_customers.php).
// Do <select> předvyplníme JEN aktuálního zákazníka zakázky jako vybranou položku.
// DŘÍV se načítalo prvních 500 zákazníků (ORDER BY last_name LIMIT 500) — jenže
// když klient zakázky v tom seznamu nebyl (víc než 500 zákazníků / příjmení dál
// v abecedě), <select> neměl žádnou selected option a prohlížeč tiše vybral
// PRVNÍHO zákazníka v seznamu; uložení editace ho pak přepsalo. Odtud „přepsaní
// zákazníci jedním klientem". Načtením jen aktuálního klienta to nemůže nastat.
$stmt = $pdo->prepare("SELECT id, first_name, last_name, phone FROM customers WHERE id = ? LIMIT 1");
$stmt->execute([(int)$order['customer_id']]);
$customers_list = $stmt->fetchAll();

// Fetch active technicians for edit modal
$techs = getActiveTechnicians(true);   // vsichni aktivni technici (1.6.1)

$status = $order['status'] ?? getDefaultOrderStatus();
$next_status = null;
if (isOrderStatusIn($status, 'new') || isOrderStatusIn($status, 'pending_approval') || isOrderStatusIn($status, 'waiting_parts')) {
    $next_status = 'V opravě';
} elseif (isOrderStatusIn($status, 'in_progress')) {
    $next_status = 'Připraveno k převzetí';
} elseif (isOrderStatusIn($status, 'completed') || isOrderStatusIn($status, 'uncollected')) {
    $next_status = 'Vydáno';
} elseif ($status === 'Vydáno - čeká na platbu') {
    $next_status = 'Vydáno';
}
$next_label_map = [
    'V opravě' => __('move_to_in_progress'),
    'Připraveno k převzetí' => __('move_to_completed'),
    'Vydáno' => __('move_to_collected')
];
$next_label = $next_status ? ($next_label_map[$next_status] ?? __('next_step')) : '';
$show_shipping = isOrderStatusIn($status, 'done');
$show_invoice = crmCanManageInvoices()
    && isOrderStatusIn($status, 'done')
    && (($order['final_cost'] ?? 0) > 0 || ($order['estimated_cost'] ?? 0) > 0);
// $can_change_technician je definováno výše (od 1.6.1 = true pro všechny zaměstnance).
// Self-assign zvláštní režim už není potřeba — select technika je plně povolený všem.
$can_self_assign = false;
$ui_lang = crm_get_language();

// Fetch status log
$status_log = [];
try {
    ensureOrderStatusLogTable();
    $stmt = $pdo->prepare(
        "SELECT l.*, u.username, t.name AS tech_name, st.name AS status_tech_name
         FROM order_status_log l
         LEFT JOIN users u ON (l.changed_role = 'admin' AND u.id = l.changed_by)
         LEFT JOIN technicians t ON (l.changed_role <> 'admin' AND t.id = l.changed_by)
         LEFT JOIN technicians st ON st.id = l.technician_id
         WHERE l.order_id = ?
         ORDER BY l.changed_at DESC"
    );
    $stmt->execute([$id]);
    $status_log = $stmt->fetchAll();
} catch (Exception $e) {
    $status_log = [];
}

function localizedOrderStatusLabel(string $status): string {
    return getOrderStatusLabel($status);
}

?>

<div class="row">
    <div class="col-md-8">
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center">
                    <?php 
                        $back_url = $_GET['return'] ?? "javascript:history.back()";
                    ?>
                    <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary btn-sm me-2" title="<?php echo __('back'); ?>">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editOrderFullModal">
                        <i class="fas fa-edit me-1"></i> <?php echo __('edit'); ?>
                    </button>
                    <?php if(crmCanDeleteOrders()): ?>
                    <button class="btn btn-sm btn-outline-danger me-3" onclick="deleteOrder(<?php echo $order['id']; ?>)">
                        <i class="fas fa-trash me-1"></i> <?php echo __('delete'); ?>
                    </button>
                    <?php endif; ?>
                    <div>
                        <h5 class="mb-0">
                            <?php echo __('order'); ?> <?php echo e(orderDisplayCode($order)); ?> - <?php echo htmlspecialchars($order['device_model']); ?>
                            <span class="text-white-75 fw-normal ms-2" style="font-size: 0.9rem;">
                                (<?php echo __('created'); ?>: <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?>)
                            </span>
                        </h5>
                        <?php if (($__legacyCode = trim((string)($order['legacy_code'] ?? ''))) !== ''): ?>
                            <div class="mt-1" style="font-size:.9rem; color:rgba(255,255,255,.72);"><i class="fas fa-clock-rotate-left me-1" style="font-size:.8rem;"></i>(<?php echo __('ord_prev_code'); ?> <?php echo e($__legacyCode); ?>)</div>
                        <?php endif; ?>
                        <?php if (!empty($webBookingRef['wp_booking_id'])): ?>
                        <div class="small mt-1" style="color:#5fd2ff;">
                            <i class="fas fa-globe me-1"></i><?php echo __('web_booking_no'); ?>
                            <span class="fw-bold font-monospace"><?php echo e((string)$webBookingRef['wp_booking_id']); ?></span>
                            <?php if (!empty($webBookingRef['appointment_at'])): ?>
                                <span class="text-white-75 ms-2"><i class="far fa-clock me-1"></i><?php echo date('j.n.Y H:i', strtotime((string)$webBookingRef['appointment_at'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php echo getStatusBadge($order['status']); ?>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4 order-summary-hl">
                    <div class="col-md-6">
                      <div class="order-summary-panel">
                        <h6><?php echo __('client'); ?></h6>
                        <p class="osh-title mb-2"><strong><?php echo htmlspecialchars($order['first_name'].' '.$order['last_name']); ?></strong>
                            <?php if (crmIsInternalCustomer($order['customer_id'] ?? 0)): ?>
                                <span class="afx-internal-chip ms-2" title="Interní zakázka — není pro veřejného klienta"><i class="fas fa-screwdriver-wrench"></i>Interní</span>
                            <?php endif; ?>
                        </p>
                        <?php
                            $order_phone_href = normalizePhoneForTel($order['phone'] ?? '');
                            $order_email_href = normalizeEmailForMailto($order['email'] ?? '');
                        ?>
                        <p class="osh-contact text-white-75 mb-2">
                            <i class="fas fa-phone me-2 text-success"></i>
                            <?php if ($order_phone_href !== ''): ?>
                                <a href="tel:<?php echo e($order_phone_href); ?>" class="text-reset text-decoration-none"><?php echo htmlspecialchars($order['phone']); ?></a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($order['phone']); ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($order['email'])): ?>
                        <p class="osh-contact text-white-75 mb-0">
                            <i class="fas fa-envelope me-2 text-info"></i>
                            <?php if ($order_email_href !== ''): ?>
                                <a href="mailto:<?php echo e($order_email_href); ?>" class="text-reset text-decoration-none"><?php echo htmlspecialchars($order['email']); ?></a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($order['email']); ?>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                      <div class="order-summary-panel">
                        <h6><?php echo __('device_model'); ?></h6>
                        <p class="osh-title mb-2"><strong><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></strong></p>
                        <p class="osh-meta mb-2">
                            <?php echo htmlspecialchars(__($order['device_type'])); ?> ·
                            <strong><?php echo $order['order_type'] == 'Warranty' ? __('Warranty') : __('Non-Warranty'); ?></strong>
                        </p>
                        <h6 class="mt-3 mb-1"><?php echo __('serial_numbers'); ?></h6>
                        <p class="osh-serial mb-0">
                            <i class="fas fa-barcode me-2"></i><?php echo __('sn1'); ?>: <span><?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?></span>
                        </p>
                        <?php if(!empty($order['serial_number_2'])): ?>
                        <p class="osh-serial mb-0">
                            <i class="fas fa-barcode me-2"></i><?php echo __('sn2'); ?>: <span><?php echo htmlspecialchars($order['serial_number_2']); ?></span>
                        </p>
                        <?php endif; ?>
                      </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <h6><?php echo __('pin'); ?></h6>
                        <div class="alert alert-warning bg-transparent border border-warning py-2 mb-0">
                            <code class="text-warning"><?php echo htmlspecialchars($order['pin_code'] ?: '---'); ?></code>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6><?php echo __('technician'); ?></h6>
                        <div class="alert alert-info bg-transparent border border-info py-2 mb-0 text-info">
                            <i class="fas fa-user-cog me-2"></i><strong><?php echo htmlspecialchars($order['tech_name'] ?: '---'); ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <h6><?php echo __('priority'); ?></h6>
                        <?php echo getOrderPriorityBadge($order['priority'] ?? 'Normal'); ?>
                    </div>
                </div>

                <?php if (!empty($order['work_duration_seconds']) || !empty($order['work_started_at'])): ?>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="alert alert-success bg-transparent border border-success py-2 mb-0">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small">
                                <div><i class="far fa-clock me-2"></i><strong><?php echo __('work_time_on_order'); ?></strong> <?php echo formatWorkDuration($order['work_duration_seconds'] ?? 0); ?></div>
                                <?php if (!empty($order['work_started_at'])): ?>
                                    <div class="text-white-75"><?php echo __('work_start'); ?> <?php echo date('d.m.Y H:i', strtotime($order['work_started_at'])); ?><?php if (!empty($order['work_finished_at'])): ?> | <?php echo __('work_end'); ?> <?php echo date('d.m.Y H:i', strtotime($order['work_finished_at'])); ?><?php endif; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6><?php echo __('appearance'); ?></h6>
                        <div class="alert alert-secondary bg-transparent border border-secondary text-white-75 py-2 mb-0 small">
                            <?php echo htmlspecialchars($order['appearance'] ?: '---'); ?>
                        </div>
                    </div>
                </div>

                <h6><?php echo __('problem'); ?></h6>
                <div class="alert alert-light bg-transparent border border-secondary text-white mb-4">
                    <?php echo nl2br(htmlspecialchars($order['problem_description'])); ?>
                </div>

                <?php if(!empty($order['technician_notes'])): ?>
                <h6><?php echo __('notes'); ?></h6>
                <div class="alert alert-info border border-info bg-transparent text-info mb-4 small">
                    <?php echo nl2br(htmlspecialchars($order['technician_notes'])); ?>
                </div>
                <?php endif; ?>

                <h6><?php echo __('status_history'); ?></h6>
                <?php if (!empty($status_log)): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="bg-transparent border-bottom">
                            <tr>
                                <th class="text-white-75"><?php echo __('created'); ?></th>
                                <th class="text-white-75"><?php echo __('status'); ?></th>
                                <th class="text-white-75"><?php echo __('user'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($status_log as $log):
                                $who = $log['username'] ?? $log['tech_name'] ?? '---';
                            ?>
                            <tr>
                                <td class="small text-white-75"><?php echo date('d.m.Y H:i', strtotime($log['changed_at'])); ?></td>
                                <td>
                                    <?php if (trim((string)$log['old_status']) !== ''): ?>
                                    <span class="badge bg-transparent border border-secondary text-white-75"><?php echo htmlspecialchars(localizedOrderStatusLabel((string)$log['old_status'])); ?></span>
                                    <span class="mx-1 text-white-75" aria-hidden="true">&rarr;</span>
                                    <?php else: ?>
                                    <span class="text-white-75 me-1"><i class="fas fa-plus-circle me-1 text-success"></i><?php echo __('history_created'); ?></span>
                                    <?php endif; ?>
                                    <span class="badge bg-primary text-white"><?php echo htmlspecialchars(orderStatusHistoryLabel((string)$log['new_status'], $log['status_tech_name'] ?? null)); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($who); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-white-75 small mb-4"><?php echo __('not_found'); ?></div>
                <?php endif; ?>

                <!-- Media Section -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><?php echo __('media_files'); ?></h6>
                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
                        <i class="fas fa-upload me-1"></i> <?php echo __('upload'); ?>
                    </button>
                </div>
                <div class="row g-2 mb-4">
                    <?php 
                    $stmt_files = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ? ORDER BY created_at DESC");
                    $stmt_files->execute([$id]);
                    $attachments = $stmt_files->fetchAll();
                    
                    if(empty($attachments)): ?>
                        <div class="col-12 text-white-75 small"><?php echo __('no_media_files'); ?></div>
                    <?php else:
                        foreach($attachments as $file): 
                            $is_video = strpos($file['file_type'], 'video') !== false;
                    ?>
                        <div class="col-6 col-md-3" id="media-item-<?php echo $file['id']; ?>">
                            <div class="card h-100 shadow-sm border position-relative">
                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 z-3 shadow-sm" 
                                        onclick="deleteMedia(<?php echo $file['id']; ?>)" style="padding: 2px 6px; font-size: 10px;">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>

                                <?php if($is_video): ?>
                                    <a href="<?php echo $file['file_path']; ?>" data-fancybox="gallery" data-type="video" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1 bg-dark d-flex align-items-center justify-content-center">
                                            <i class="fas fa-video fa-2x text-white"></i>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo $file['file_path']; ?>" data-fancybox="gallery" data-type="image" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1 overflow-hidden">
                                            <img src="<?php echo $file['file_path']; ?>" class="w-100 h-100 object-fit-cover d-block" alt="Attachment">
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <div class="card-footer p-1 text-center small">
                                    <div class="text-truncate text-white-75" title="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <?php echo htmlspecialchars($file['file_name']); ?>
                                    </div>
                                    <div class="text-white-75 d-flex justify-content-center align-items-center" style="font-size: 0.75rem;">
                                        <i class="far fa-clock me-1"></i>
                                        <span><?php echo date('d.m.Y H:i', strtotime($file['created_at'])); ?></span>
                                        <a href="javascript:void(0)" class="ms-1 text-primary edit-attachment-date" 
                                           data-id="<?php echo $file['id']; ?>" 
                                           data-date="<?php echo date('Y-m-d\TH:i', strtotime($file['created_at'])); ?>"
                                           title="<?php echo __('edit'); ?>">
                                            <i class="fas fa-calendar-alt" style="font-size: 0.7rem;"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><?php echo __('parts_used'); ?></h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm btn-outline-success" href="procurement.php?order_id=<?php echo $order['id']; ?>">
                            <i class="fas fa-truck-loading me-1"></i> <?php echo __('add_to_procurement'); ?>
                        </a>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPartModal">
                            <i class="fas fa-plus me-1"></i> <?php echo __('add_part'); ?>
                        </button>
                        <?php /* QR výdej: připraví tuto zakázku, technik pak jen naskenuje QR dílu
                                 na regálu. U vydaných/stornovaných zakázek nemá smysl. */ ?>
                        <?php if (!isOrderStatusIn($status, 'collected') && !isOrderStatusIn($status, 'cancelled')): ?>
                        <button class="btn btn-sm btn-outline-warning" id="qrTakePartBtn" data-order-id="<?php echo (int)$order['id']; ?>"
                                title="Připraví tuto zakázku — pak stačí mobilem naskenovat QR kód dílu na regálu a díl se sem přidá i s cenou">
                            <i class="fas fa-qrcode me-1"></i> Vzít díl skenem QR
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="table-responsive">
                <table class="table table-sm border align-middle">
                    <thead class="bg-transparent border-bottom">
                        <tr>
                            <th><?php echo __('part_name'); ?></th>
                            <th class="text-center"><?php echo __('quantity'); ?></th>
                            <th class="text-end"><?php echo __('price'); ?></th>
                            <th class="text-end"><?php echo __('sum'); ?></th>
                            <th class="text-end" style="width: 80px;"><?php echo __('action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $parts_total = 0;
                        foreach ($order_items as $item): 
                            $sum = $item['price'] * $item['quantity'];
                            $parts_total += $sum;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                            <td class="text-end"><?php echo formatMoney($item['price']); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($sum); ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="openEditPartModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" title="<?php echo __('edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="deletePart(<?php echo $item['id']; ?>)" title="<?php echo __('delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($order_items)): ?>
                        <tr><td colspan="5" class="text-center text-white-75 py-3"><?php echo __('no_parts'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Čárový kód zakázky (Code128): X-9100 i mobil přes náš skener → otevře tuto zakázku. Nahoře v pravém sloupci. -->
        <div class="card glass-card border-0 mb-4 order-barcode-card">
            <div class="card-body text-center py-3">
                <div class="text-white-75 small mb-2"><i class="fas fa-barcode me-1"></i> <?php echo __('scan_to_open_order'); ?></div>
                <div id="orderBarcode" style="display:inline-block; background:#fff; padding:8px 10px; border-radius:8px; line-height:0;"></div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
        <script>
        (function() {
            var box = document.getElementById('orderBarcode');
            if (!box || typeof window.JsBarcode !== 'function') { if (box) box.style.display = 'none'; return; }
            try {
                var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                box.appendChild(svg);
                JsBarcode(svg, '<?php echo e(orderDisplayCode($order)); ?>', { format: 'CODE128', displayValue: true, fontSize: 14, margin: 0, height: 56, width: 2 });
            } catch (e) { box.style.display = 'none'; }
        })();
        </script>

        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('actions'); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($show_shipping): ?>
                <!-- Doprava / Výdej — přímo v Akcích, NAD tlačítkem „Označit jako vydané".
                     Pozn.: #shippingForm nesmí být vnořený do #statusForm (nevalidní HTML),
                     proto stojí samostatně před ním; všechna JS ID zůstávají. -->
                <div class="order-shipping-inline mb-1">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="fas fa-truck me-2 text-info"></i><?php echo __('shipping'); ?></h6>
                        <?php if(isOrderStatusIn($order['status'], 'collected')): ?>
                            <span class="badge bg-success small"><?php echo __('collected'); ?></span>
                        <?php endif; ?>
                    </div>
                    <form id="shippingForm">
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <div class="mb-2">
                            <label class="form-label mb-1"><?php echo __('shipping_method'); ?></label>
                            <select name="shipping_method" class="form-select">
                                <option value="" <?php echo empty($order['shipping_method']) ? 'selected' : ''; ?>>-- <?php echo __('not_found'); ?> --</option>
                                <option value="Self Pickup" <?php echo $order['shipping_method'] == 'Self Pickup' ? 'selected' : ''; ?>><?php echo __('self_pickup'); ?></option>
                                <option value="Zasilkovna" <?php echo $order['shipping_method'] == 'Zasilkovna' ? 'selected' : ''; ?>>Zásilkovna</option>
                                <option value="Ceska Posta" <?php echo $order['shipping_method'] == 'Ceska Posta' ? 'selected' : ''; ?>><?php echo __('czech_post'); ?></option>
                                <option value="PPL" <?php echo $order['shipping_method'] == 'PPL' ? 'selected' : ''; ?>>PPL</option>
                                <option value="DPD" <?php echo $order['shipping_method'] == 'DPD' ? 'selected' : ''; ?>>DPD</option>
                                <option value="GLS" <?php echo $order['shipping_method'] == 'GLS' ? 'selected' : ''; ?>>GLS</option>
                                <option value="Courier" <?php echo $order['shipping_method'] == 'Courier' ? 'selected' : ''; ?>><?php echo __('courier'); ?></option>
                            </select>
                        </div>
                        <div id="shippingDetails" class="<?php echo in_array($order['shipping_method'], ['Self Pickup', 'Courier', '']) ? 'd-none' : ''; ?>">
                            <div class="mb-2">
                                <label class="form-label mb-1"><?php echo __('shipping_tracking'); ?></label>
                                <input type="text" name="shipping_tracking" class="form-control" value="<?php echo htmlspecialchars($order['shipping_tracking'] ?? ''); ?>" placeholder="<?php echo __('tracking_placeholder'); ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label mb-1"><?php echo __('shipping_date'); ?></label>
                            <input type="datetime-local" name="shipping_date" class="form-control" value="<?php echo $order['shipping_date'] ? date('Y-m-d\TH:i', strtotime($order['shipping_date'])) : ''; ?>">
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><?php echo __('save'); ?></button>
                    </form>
                    <hr class="border-secondary my-3">
                </div>
                <?php else: ?>
                    <div class="text-white-75 small mb-2"><?php echo __('shipping_available_after_completed'); ?></div>
                <?php endif; ?>

                <?php /* Naskladnit zařízení ze zakázky jako díl na sklad dílů — jen admin a Boss.
                         Zařízení, které se nevrací klientovi (neopravitelné, výkup) → 1 ks na sklad. */ ?>
                <?php if (hasPermission('admin_access')): ?>
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-warning btn-sm w-100" id="stockAsPartBtn">
                        <i class="fas fa-boxes-stacked me-2"></i><?php echo __('stock_as_part'); ?>
                    </button>
                    <div class="text-white-50 mt-1" style="font-size:.72rem;"><?php echo __('stock_as_part_hint'); ?></div>
                </div>
                <?php endif; ?>

                <form id="statusForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="ui_lang" value="<?php echo e($ui_lang); ?>">
                    <?php if ($can_handoff): ?>
                    <?php /* Jen „uvolnit dalšímu technikovi" — dokončení řeší hlavní zelené
                             tlačítko níže (dřív tu bylo druhé tlačítko na tutéž akci = matoucí). */ ?>
                    <div class="border border-info border-opacity-50 rounded-3 p-2 mb-2">
                        <div class="small text-white-75 mb-2"><i class="fas fa-people-arrows me-1 text-info"></i><?php echo __('handoff_release_hint'); ?></div>
                        <div class="d-grid">
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="afxReleaseOrder()"><i class="fas fa-share me-1"></i><?php echo __('handoff_release'); ?></button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($order['status'] ?? '') === 'Přijato z RepairPluginu'): ?>
                    <?php /* Onboard: klient z webové rezervace (RepairPlugin) fyzicky dorazil →
                             stejná automatika jako po ručním založení: tisk štítku + okno
                             zakázkového listu s podpisem (openOrderDocChoice). */ ?>
                    <div class="d-grid mb-2">
                        <button type="button" class="btn btn-info fw-semibold" id="onboardClientBtn"
                            onclick="var b=this;b.disabled=true;try{printOrderLabel(<?php echo (int)$order['id']; ?>);}catch(e){}setTimeout(function(){openOrderDocChoice(<?php echo (int)$order['id']; ?>, '<?php echo e(orderDisplayCode($order)); ?>');b.disabled=false;},450);">
                            <i class="fas fa-user-check me-2"></i>Onboard zákazníka
                        </button>
                        <div class="small text-white-50 mt-1 text-center">vytiskne štítek a otevře podpis / zakázkový list</div>
                    </div>
                    <?php endif; ?>
                    <div class="d-grid gap-2 mb-2">
                        <?php if ($next_status): ?>
                            <?php
                            // barva + ikona pulzujícího tlačítka podle CÍLOVÉHO stavu
                            $__pulseCls = ['V opravě' => 'afx-status-pulse--progress', 'Připraveno k převzetí' => 'afx-status-pulse--completed', 'Vydáno' => 'afx-status-pulse--collected'][$next_status] ?? '';
                            $__pulseIco = ['V opravě' => 'fa-play', 'Připraveno k převzetí' => 'fa-check', 'Vydáno' => 'fa-box-open'][$next_status] ?? 'fa-arrow-right';
                            ?>
                            <button type="button" class="btn btn-success afx-status-pulse <?php echo $__pulseCls; ?>" id="nextStatusBtn" data-next-status="<?php echo $next_status; ?>">
                                <i class="fas <?php echo $__pulseIco; ?> me-2"></i><?php echo $next_label ?: __('next_step'); ?>
                            </button>
                        <?php else: ?>
                            <div class="text-white-75 small"><?php echo __('no_next_step'); ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$show_invoice && crmCanManageInvoices()): ?>
                        <div class="text-white-75 small mb-2"><?php echo __('invoice_available_after_completed'); ?></div>
                    <?php endif; ?>

                    <div id="actionsAdvanced">
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('technician'); ?></label>
                            <?php /* Od 1.6.0: technika smí u zakázky nastavit KAŽDÝ zaměstnanec —
                                     select je vždy povolený, nabízí všechny aktivní techniky
                                     a volbu „bez technika" (value=0 → endpoint uloží NULL). */ ?>
                            <select name="technician_id" class="form-select mb-2">
                                <option value="0" <?php echo empty($order['technician_id']) ? 'selected' : ''; ?>>— bez technika —</option>
                                <?php $techs = getActiveTechnicians(true); foreach($techs as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $order['technician_id'] == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span><?php echo __('status'); ?></span>
                                <span class="text-white-75 small">
                                    <span id="display_updated_at"><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?></span>
                                    <a href="javascript:void(0)" class="ms-1 text-primary" data-bs-toggle="modal" data-bs-target="#editOrderDatesModal" title="<?php echo __('edit'); ?>">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                </span>
                            </label>
                            <select name="status" class="form-select mb-2">
                                <?php foreach (getOrderStatusOptions(false, (string)($order['status'] ?? '')) as $statusValue => $statusLabel): ?>
                                    <option value="<?php echo e($statusValue); ?>" <?php if($order['status'] === $statusValue) echo 'selected'; ?>><?php echo e($statusLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('work_cost'); ?></label>
                            <div class="input-group">
                                <input type="number" step="1" name="final_cost" class="form-control" value="<?php echo (int)round((float)($order['final_cost'] ?? $order['estimated_cost'] ?? 0)); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('extra_expenses'); ?></label>
                            <div class="input-group">
                                <input type="number" name="extra_expenses" class="form-control" step="1" value="<?php echo (int)round((float)($order['extra_expenses'] ?? 0)); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success w-100 mb-2"><?php echo __('update_status'); ?></button>
                        <button type="button" class="btn btn-outline-primary w-100 mb-2" onclick="openUniversalPreview('print_order.php?id=<?php echo $order['id']; ?>', '<?php echo __('order_header'); ?> <?php echo e(orderDisplayCode($order)); ?>')">
                            <i class="fas fa-file-lines me-2"></i> <?php echo __('view_order_sheet'); ?>
                        </button>
                        <div class="border border-secondary border-opacity-50 rounded-3 p-2">
                            <div class="small text-white-75 mb-2"><i class="fas fa-print me-2"></i><?php echo __('print'); ?></div>
                            <?php /* „A4 faktura" odstraněna — dělala totéž co velké tlačítko
                                     „Zobrazit zakázkový list" výše (stejný náhled print_order.php). */ ?>
                            <div class="d-grid gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start" onclick="openOrderDocChoice(<?php echo (int)$order['id']; ?>, '<?php echo e(orderDisplayCode($order)); ?>')"><i class="fas fa-paper-plane me-2 text-primary"></i><?php echo __('order_sheet_print_email'); ?></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start" onclick="openUniversalPreview('print_workshop.php?id=<?php echo $order['id']; ?>', '<?php echo __('work_order'); ?> <?php echo e(orderDisplayCode($order)); ?>')"><i class="fas fa-tools me-2 text-warning"></i><?php echo __('work_order'); ?></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start" onclick="printOrderLabel(<?php echo (int)$order['id']; ?>)"><i class="fas fa-barcode me-2 text-info"></i><?php echo __('print_label'); ?></button>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-top border-secondary">
                            <div class="small text-white-75 mb-2"><i class="fas fa-signature me-2 text-info"></i><?php echo __('client_signature'); ?></div>
                            <?php foreach (['prijem' => __('sign_reception'), 'vydej' => __('sign_pickup')] as $sigT => $sigLabel): $sig = $orderSignatures[$sigT] ?? null; ?>
                            <div class="d-flex align-items-center justify-content-between mb-2" id="sigRow-<?php echo $sigT; ?>">
                                <span class="small"><?php echo e($sigLabel); ?></span>
                                <?php if ($sig): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success"><i class="fas fa-check me-1"></i><?php echo date('j.n. H:i', strtotime((string)$sig['signed_at'])); ?></span>
                                <?php else: ?>
                                    <span class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-info" onclick="afxSignOrder('<?php echo $sigT; ?>')"><i class="fas fa-pen-nib me-1"></i><?php echo __('sign_btn'); ?></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="<?php echo __('sign_station_send'); ?>" onclick="afxSignRemote('<?php echo $sigT; ?>', this)"><i class="fas fa-tablet-screen-button"></i></button>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($techTimes)): ?>
                        <div class="mt-3 pt-3 border-top border-secondary">
                            <div class="small text-white-75 mb-2"><i class="fas fa-stopwatch me-2 text-info"></i><?php echo __('tech_time_title'); ?></div>
                            <?php foreach ($techTimes as $tt): ?>
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <span class="small"><?php echo e($tt['tech_name']); ?><?php if ((int)$tt['running'] === 1): ?> <i class="fas fa-circle text-success ms-1" style="font-size:7px;" title="běží"></i><?php endif; ?></span>
                                <span class="small fw-bold font-monospace"><?php echo e(formatWorkDuration((int)$tt['minutes'])); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <script>
                        function afxReleaseOrder() {
                            showConfirm('<?php echo __('handoff_confirm'); ?>', function () {
                                var fd = new FormData();
                                fd.append('order_id', '<?php echo (int)$order['id']; ?>');
                                fd.append('csrf_token', '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>');
                                fetch('api/release_order.php', { method: 'POST', body: fd })
                                    .then(function (r) { return r.json(); })
                                    .then(function (j) { if (j.ok) { location.reload(); } else { alert(j.error || 'Chyba'); } })
                                    .catch(function () { alert('Chyba spojení'); });
                            });
                        }
                        window.AFX_SIGN_L10N = { clear: '<?php echo __('sign_clear'); ?>', cancel: '<?php echo __('cancel'); ?>', save: '<?php echo __('sign_save'); ?>' };
                        function afxSignRemote(type, btn) {
                            var $row = $(btn).closest('.d-flex');
                            var fd = new FormData();
                            fd.append('action', 'create');
                            fd.append('order_id', '<?php echo (int)$order['id']; ?>');
                            fd.append('sig_type', type);
                            fd.append('csrf_token', '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>');
                            fetch('api/request_signature.php', { method: 'POST', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (j) {
                                    if (!j.ok) { alert(j.error || 'Chyba'); return; }
                                    var reqId = j.request_id;
                                    var $wait = $('<span class="small text-info"><i class="fas fa-tablet-screen-button me-1"></i><?php echo __('sign_station_waiting'); ?> <a href="javascript:void(0)" class="text-white-75">✕</a></span>');
                                    $row.find('span.d-inline-flex, button').last().parent().find('.d-inline-flex').replaceWith($wait);
                                    var iv = setInterval(function () {
                                        fetch('api/request_signature.php?check=' + reqId, { cache: 'no-store' })
                                            .then(function (r) { return r.json(); })
                                            .then(function (c) {
                                                if (c.status === 'done') { clearInterval(iv); location.reload(); }
                                                if (c.status === 'cancelled' || c.status === 'missing') { clearInterval(iv); location.reload(); }
                                            }).catch(function () {});
                                    }, 3000);
                                    $wait.find('a').on('click', function () {
                                        clearInterval(iv);
                                        var cf = new FormData();
                                        cf.append('action', 'cancel');
                                        cf.append('request_id', reqId);
                                        cf.append('csrf_token', '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>');
                                        fetch('api/request_signature.php', { method: 'POST', body: cf }).finally(function () { location.reload(); });
                                    });
                                });
                        }
                        function afxSignOrder(type) {
                            afxSignaturePad({
                                title: type === 'prijem' ? '<?php echo __('sign_pad_title_reception'); ?>' : '<?php echo __('sign_pad_title_pickup'); ?>',
                                subtitle: '<?php echo e(orderDisplayCode($order)); ?> · <?php echo e(trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''))); ?> · <?php echo e(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''))); ?>',
                                terms: type === 'prijem' ? '<?php echo __('sign_terms_reception'); ?>' : '<?php echo __('sign_terms_pickup'); ?>',
                                onSave: function (dataUrl) {
                                    var fd = new FormData();
                                    fd.append('order_id', '<?php echo (int)$order['id']; ?>');
                                    fd.append('sig_type', type);
                                    fd.append('image', dataUrl);
                                    fd.append('csrf_token', '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>');
                                    fetch('api/save_signature.php', { method: 'POST', body: fd })
                                        .then(function (r) { return r.json(); })
                                        .then(function (j) {
                                            if (j.ok) { location.reload(); }
                                            else { alert(j.error || 'Chyba'); }
                                        })
                                        .catch(function () { alert('Chyba spojení'); });
                                }
                            });
                        }
                        </script>
                    </div>
                </form>
            </div>
        </div>

        <!-- Express Invoice Block -->
        <?php if ($show_invoice): 
            // Fetch existing invoice for this order (first one)
            $stmt_inv = $pdo->prepare("SELECT * FROM invoices WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt_inv->execute([$id]);
            $existing_invoice = $stmt_inv->fetch();
            
            // Fetch invoice item if exists
            $invoice_item_name = __('repair_service') . ' #' . $order['id'];
            if ($existing_invoice) {
                $stmt_item = $pdo->prepare("SELECT item_name FROM invoice_items WHERE invoice_id = ? LIMIT 1");
                $stmt_item->execute([$existing_invoice['id']]);
                $inv_item = $stmt_item->fetch();
                if ($inv_item && !empty($inv_item['item_name'])) {
                    $invoice_item_name = $inv_item['item_name'];
                }
            }
        ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2 text-success"></i><?php echo __('invoice'); ?></h5>
                <?php if($existing_invoice): ?>
                    <span class="badge <?php echo $existing_invoice['status'] == 'paid' ? 'bg-success' : 'bg-warning text-white'; ?>">
                        <?php echo __($existing_invoice['status']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="expressInvoiceForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="invoice_id" value="<?php echo $existing_invoice['id'] ?? ''; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('invoice_number'); ?></label>
                        <input type="text" name="invoice_number" class="form-control" value="<?php echo $existing_invoice['invoice_number'] ?? $order['id']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('item_description'); ?></label>
                        <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($invoice_item_name); ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" class="form-control" value="<?php echo $existing_invoice ? $existing_invoice['date_issue'] : date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" class="form-control" value="<?php echo $existing_invoice ? $existing_invoice['date_due'] : date('Y-m-d', strtotime('+14 days')); ?>" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?php echo __('status'); ?></label>
                            <select name="status" class="form-select">
                                <option value="draft" <?php echo ($existing_invoice['status'] ?? '') == 'draft' ? 'selected' : ''; ?>><?php echo __('status_draft'); ?></option>
                                <option value="issued" <?php echo ($existing_invoice['status'] ?? 'issued') == 'issued' ? 'selected' : ''; ?>><?php echo __('status_issued'); ?></option>
                                <option value="paid" <?php echo ($existing_invoice['status'] ?? '') == 'paid' ? 'selected' : ''; ?>><?php echo __('status_paid'); ?></option>
                                <option value="overdue" <?php echo ($existing_invoice['status'] ?? '') == 'overdue' ? 'selected' : ''; ?>><?php echo __('status_overdue'); ?></option>
                                <option value="cancelled" <?php echo ($existing_invoice['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>><?php echo __('status_cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php echo __('payment_method'); ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="bank_transfer" <?php echo ($existing_invoice['payment_method'] ?? 'bank_transfer') == 'bank_transfer' ? 'selected' : ''; ?>><?php echo __('bank_transfer'); ?></option>
                                <option value="cash" <?php echo ($existing_invoice['payment_method'] ?? '') == 'cash' ? 'selected' : ''; ?>><?php echo __('cash'); ?></option>
                                <option value="card" <?php echo ($existing_invoice['payment_method'] ?? '') == 'card' ? 'selected' : ''; ?>><?php echo __('card'); ?></option>
                                <option value="cod" <?php echo ($existing_invoice['payment_method'] ?? '') == 'cod' ? 'selected' : ''; ?>><?php echo __('cod'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('total_to_pay'); ?></label>
                        <div class="input-group">
                            <input type="number" name="total_amount" class="form-control" step="0.01" value="<?php echo $existing_invoice ? $existing_invoice['total_amount'] : (int)round((float)($order['final_cost'] ?: $order['estimated_cost'])); ?>" required>
                            <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn <?php echo $existing_invoice ? 'btn-primary' : 'btn-success'; ?> flex-grow-1">
                            <i class="fas fa-<?php echo $existing_invoice ? 'save' : 'plus'; ?> me-2"></i>
                            <?php echo $existing_invoice ? __('save') : __('create_invoice'); ?>
                        </button>
                        <?php if($existing_invoice): ?>
                        <a href="javascript:void(0)" onclick="openUniversalPreview('print_invoice.php?id=<?php echo $existing_invoice['id']; ?>', '<?php echo __('invoice'); ?> #<?php echo $existing_invoice['invoice_number']; ?>')" class="btn btn-outline-secondary" title="<?php echo __('print'); ?>">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Add Part -->
<div class="modal fade" id="addPartModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="addPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('add_part_to_order'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('select_part_from_warehouse'); ?></label>
                        <select name="inventory_id" id="addPartInventory" class="form-select" required></select>
                        <div class="form-text text-white-75 small"><?php echo in_array(getCurrentStaffRole(), ['engineer', 'brigadnik'], true) ? __('search_part_hint_engineer') : 'Search by name, SKU, or price.'; ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1">
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('add'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Upload Media -->
<div class="modal fade" id="uploadMediaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="uploadMediaForm" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <input type="hidden" name="ui_lang" value="<?php echo e($ui_lang); ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('upload_media'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('select_files'); ?></label>
                        <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*" required>
                    </div>
                    <div id="uploadProgress" class="progress d-none mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"><?php echo __('uploading'); ?>...</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('upload'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Order Dates -->
<div class="modal fade" id="editOrderDatesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderDatesForm">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_order_dates'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('created_at'); ?></label>
                        <input type="datetime-local" name="created_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($order['created_at'])); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('updated_at'); ?></label>
                        <input type="datetime-local" name="updated_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($order['updated_at'])); ?>">
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

<!-- Modal Edit Attachment Date -->
<div class="modal fade" id="editAttachmentDateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editAttachmentDateForm">
                <input type="hidden" name="attachment_id" id="edit_attachment_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_upload_date'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('date_time'); ?></label>
                        <input type="datetime-local" name="created_at" id="edit_attachment_date" class="form-control">
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

<script>
$(document).ready(function() {
    // Express Invoice Form
    $('#expressInvoiceForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __("saving"); ?>...');
        
        $.post('api/create_express_invoice.php', $(this).serialize(), function(res) {
            if(res.success) {
                // Just reload to show updated invoice status and amounts
                location.reload();
            } else {
                btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
                showAlert('<?php echo __("error"); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __("error"); ?>';
            showAlert(msg);
        });
    });

    // „Vzít díl skenem QR" — ozbrojí tuto zakázku (30 min), pak stačí naskenovat QR na regálu
    $('#qrTakePartBtn').on('click', function () {
        var btn = this;
        $.post('api/qr_arm.php', {order_id: btn.dataset.orderId, csrf_token: $('meta[name="csrf-token"]').attr('content')}, function (d) {
            if (d && d.success) {
                showAlert('<i class="fas fa-qrcode me-1"></i> ' + d.message + ' Výdej se přidá k této zakázce (platí 30 minut).');
            } else {
                showAlert((d && d.message) || 'Chyba');
            }
        }, 'json').fail(function () {
            showAlert('Nepodařilo se připravit zakázku — obnov stránku a zkus to znovu.');
        });
    });

    $('#statusForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const status = form.find('select[name="status"]').val();
        const shippingMethod = $('select[name="shipping_method"]').val();
        const collectedStatuses = <?php echo json_encode(getOrderStatusList('collected'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        
        if (collectedStatuses.includes(status)) {
            // Výdej NEblokujeme na dopravě: když není zvolena, doplní se automaticky
            // „Osobní odběr" (Self Pickup) — umožní vydat z jakéhokoli stavu jedním krokem.
            const $shipForm = $('#shippingForm');
            if ($shipForm.length) {
                const $sel = $shipForm.find('select[name="shipping_method"]');
                if ($sel.length && (!$sel.val() || $sel.val() === '')) { $sel.val('Self Pickup'); }
                $.post('api/update_shipping.php', $shipForm.serialize(), function(res) {
                    // I kdyby uložení dopravy selhalo, výdej nezastavíme — stav se změní
                    // a API u „Vydáno" dopravu stejně doplní na Osobní odběr.
                    showStatusConfirmModal(form);
                }).fail(function() {
                    showStatusConfirmModal(form);
                });
            } else {
                // Formulář dopravy není (zakázka nebyla ve stavu „hotovo") → API doplní
                // způsob předání (Osobní odběr) samo při přechodu na „Vydáno".
                showStatusConfirmModal(form);
            }
        } else {
            showStatusConfirmModal(form);
        }
    });

    $('#nextStatusBtn').on('click', function() {
        const nextStatus = $(this).data('next-status');
        if (!nextStatus) return;
        const form = $('#statusForm');
        form.find('select[name="status"]').val(nextStatus);
        form.submit();
    });

    // Naskladnit zařízení ze zakázky jako díl na sklad dílů (admin + Boss) — okno s rozpisem + poznámkami
    $('#stockAsPartBtn').on('click', function() { $('#stockAsPartModal').modal('show'); });
    $('#stockAsPartConfirm').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post('api/stock_order_as_part.php', {
            order_id: <?php echo (int)$order['id']; ?>,
            csrf_token: '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>',
            part_name: $('#stockPartName').val(),
            quantity: $('#stockPartQty').val(),
            cost_price: $('#stockPartCost').val(),
            notes: $('#stockPartNotes').val()
        }, function(res) {
            if (res && res.success) {
                showAlert('<?php echo __('stock_as_part_done'); ?>: ' + (res.part_name || ''));
                setTimeout(function() { window.location.reload(); }, 900);
            } else {
                $btn.prop('disabled', false);
                showAlert('<?php echo __('error'); ?>: ' + (res && res.message ? res.message : '<?php echo __('stock_as_part_error'); ?>'));
            }
        }, 'json').fail(function() {
            $btn.prop('disabled', false);
            showAlert('<?php echo __('stock_as_part_error'); ?>');
        });
    });

    // ... existing scripts ...
    $('#editOrderDatesForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_dates.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('.edit-attachment-date').on('click', function() {
        $('#edit_attachment_id').val($(this).data('id'));
        $('#edit_attachment_date').val($(this).data('date'));
        $('#editAttachmentDateModal').modal('show');
    });

    $('#editAttachmentDateForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_attachment_date.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    // Initialize Fancybox 5
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind("[data-fancybox]", {
            dragToClose: false,
            Image: {
                zoom: true,
            },
        });
    }

    // Initialize Select2
    $('.select2-customer').select2({
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        allowClear: true,
        width: '100%'
    });

    const addPartStockOnly = <?php echo in_array(getCurrentStaffRole(), ['engineer', 'brigadnik'], true) ? '1' : '0'; ?>;

    $('#addPartInventory').select2({
        dropdownParent: $('#addPartModal'),
        placeholder: "<?php echo __('search_part_placeholder'); ?>",
        width: '100%',
        ajax: {
            url: 'api/search_catalog_items.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term || '', limit: 20, stock_only: addPartStockOnly };
            },
            processResults: function(data) {
                return {
                    results: (data.results || []).map(function(item) {
                        return {
                            id: item.id,
                            text: item.text,
                            part_name: item.part_name,
                            sku: item.sku || '',
                            quantity: item.quantity || 0,
                            sale_price: item.sale_price || 0,
                            supplier_key: item.supplier_key || ''
                        };
                    })
                };
            }
        }
    });

    $('#shippingForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_shipping.php', $(this).serialize(), function(res) {
            if(res.success) {
                showAlert('<?php echo __('shipping_updated'); ?>');
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('select[name="shipping_method"]').on('change', function() {
        const method = $(this).val();
        if (['Zasilkovna', 'Ceska Posta', 'PPL', 'DPD', 'GLS'].includes(method)) {
            $('#shippingDetails').removeClass('d-none');
        } else {
            $('#shippingDetails').addClass('d-none');
        }
    });

    $('#addPartForm').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        $.post('api/add_order_item.php', $form.serialize(), function(res) {
            if(res.success) {
                if (res.message) {
                    showAlert(res.message);
                }
                setTimeout(function() {
                    location.reload();
                }, res.auto_procurement_queued ? 900 : 200);
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('#editPartForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_item.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('#uploadMediaForm').on('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        $('#uploadProgress').removeClass('d-none');
        
        $.ajax({
            url: 'api/upload_media.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                $('#uploadProgress').addClass('d-none');
                if (res.success) {
                    showAlert('<?php echo __('files_uploaded'); ?>' + res.count);
                    location.reload();
                } else {
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                }
            },
            error: function() {
                $('#uploadProgress').addClass('d-none');
                showAlert('<?php echo __('upload_error'); ?>');
            }
        });
    });

    // Full Edit Form AJAX
    $('#editOrderFullForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const oldHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');
        
        $.post('api/update_order_full.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                btn.prop('disabled', false).html(oldHtml);
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html(oldHtml);
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __('network_error'); ?>';
            showAlert(msg);
        });
    });

    // Initialize Select2 in modal
    $('.select2-modal').select2({
        dropdownParent: $('#editOrderFullModal'),
        width: '100%'
    });

    $('.select2-tags-modal').select2({
        dropdownParent: $('#editOrderFullModal'),
        tags: true,
        width: '100%'
    });

    // Klient v editaci: AJAX vyhledávání přes VŠECHNY zákazníky (ne jen 500),
    // aktuální klient je předvybraný. Zabraňuje tichému přepisu na prvního v seznamu.
    var $custEdit = $('.select2-customer-edit');
    if ($custEdit.length) {
        $custEdit.select2({
            dropdownParent: $('#editOrderFullModal'),
            width: '100%',
            placeholder: $custEdit.data('placeholder') || 'Hledat klienta…',
            allowClear: false,
            minimumInputLength: 0,
            ajax: {
                url: 'api/search_customers.php',
                dataType: 'json',
                delay: 250,
                data: function(params) { return { q: params.term, page: params.page || 1 }; },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return { results: data.results, pagination: { more: data.pagination.more } };
                }
            }
        });
    }
});

function deletePart(id) {
    showConfirm('<?php echo __('confirm_delete_part'); ?>', function() {
        $.post('api/delete_order_item.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

function openEditPartModal(item) {
    $('#edit_item_id').val(item.id);
    $('#edit_item_name').val(item.part_name);
    $('#edit_item_quantity').val(item.quantity);
    $('#edit_item_price').val(item.price);
    
    var editModal = new bootstrap.Modal(document.getElementById('editPartModal'));
    editModal.show();
}

function testTechTG(id) {
    if (!id) return;
    $.post('api/test_tech_tg.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
        if (res.success) {
            showAlert('<?php echo __('test_msg_sent'); ?>');
        } else {
            showAlert('<?php echo __('error'); ?>: ' + res.message);
        }
    });
}

function deleteMedia(id) {
    if (typeof showConfirm !== 'function') {
        if (confirm('<?php echo __('confirm_delete_file'); ?>')) {
            $.post('api/delete_media.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
                if (res.success) $('#media-item-' + id).fadeOut();
                else alert('<?php echo __('error'); ?>: ' + res.message);
            });
        }
        return;
    }
    showConfirm('<?php echo __('confirm_delete_file'); ?>', function() {
        $.post('api/delete_media.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                $('#media-item-' + id).fadeOut();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

// Show animated modal when shipping method is required for Collected status
function showShippingRequiredModal() {
    const modal = $('#shippingRequiredModal');
    modal.modal('show');
    
    // Add shake animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-shake');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-shake');
        }, 600);
    }, 100);
}

// Show status confirmation modal with animation
function showStatusConfirmModal(form) {
    const modal = $('#statusConfirmModal');
    const status = form.find('select[name="status"]').val();
    const statusLabels = <?php echo json_encode(getOrderStatusOptions(true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    $('#confirmStatusText').text(statusLabels[status] || status);
    modal.modal('show');
    
    // Add pulse animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-pulse');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-pulse');
        }, 500);
    }, 100);
    
    // Handle confirm button
    $('#confirmStatusBtn').off('click').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ...');

        $.post('api/update_order_status.php', form.serialize(), function(raw) {
            let res = null;
            try {
                res = (typeof raw === 'string') ? JSON.parse(raw) : raw;
            } catch (e) {
                res = null;
            }

            if (res && res.success) {
                modal.modal('hide');
                location.reload();
                return;
            }

            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            if (res && res.message) {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            } else if (typeof raw === 'string' && raw.trim() !== '') {
                showAlert('<?php echo __('error'); ?>: ' + raw.trim());
            } else {
                showAlert('<?php echo __('error'); ?>');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            const text = (xhr && xhr.responseText) ? xhr.responseText : '';
            showAlert('<?php echo __('error'); ?>' + (text ? ': ' + text : ''));
        });
    });
}

// Go to shipping section
function goToShipping() {
    $('#shippingRequiredModal').modal('hide');
    // Scroll to shipping form and highlight it
    $('html, body').animate({
        scrollTop: $('#shippingForm').offset().top - 100
    }, 500);
    
    // Highlight shipping method dropdown
    $('select[name="shipping_method"]').addClass('border-danger border-2');
    setTimeout(function() {
        $('select[name="shipping_method"]').focus();
    }, 600);
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                window.location.href = 'orders.php';
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>

<!-- Shipping Required Modal (Animated) -->
<div class="modal fade" id="shippingRequiredModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-warning border-3 text-white">
            <div class="modal-header bg-warning bg-opacity-25 border-bottom-0">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-warning"></i><?php echo __('shipping_required_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-4">
                    <i class="fas fa-shipping-fast fa-4x text-warning mb-3 animate-bounce"></i>
                </div>
                <h5><?php echo __('before_status_collected'); ?></h5>
                <p class="text-white-75 mb-0"><?php echo __('shipping_required_msg'); ?></p>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-warning px-4" onclick="goToShipping()">
                    <i class="fas fa-truck me-2"></i><?php echo __('specify_shipping'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Status Confirm Modal (Animated) -->
<div class="modal fade" id="statusConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-card border-success border-2 text-white">
            <div class="modal-header bg-success bg-opacity-10 border-bottom-0">
                <h5 class="modal-title"><i class="fas fa-check-circle me-2 text-success"></i><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i class="fas fa-clipboard-check fa-3x text-success"></i>
                </div>
                <p class="mb-0"><?php echo __('change_status_prompt'); ?></p>
                <h4 class="text-success mt-2" id="confirmStatusText"></h4>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-success px-4" id="confirmStatusBtn">
                    <i class="fas fa-check me-2"></i><?php echo __('confirm'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if (hasPermission('admin_access')): ?>
<!-- Naskladnit jako díl — okno s rozpisem dílů zakázky + poznámkami (admin + Boss) -->
<div class="modal fade" id="stockAsPartModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-warning border-2 text-white">
            <div class="modal-header bg-warning bg-opacity-10 border-bottom-0">
                <h5 class="modal-title"><i class="fas fa-boxes-stacked me-2 text-warning"></i><?php echo __('stock_as_part'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-white-75 mb-3"><?php echo __('stock_as_part_hint'); ?></div>
                <?php if (!empty($order_items)): ?>
                <div class="mb-3 p-2 rounded" style="background:rgba(255,255,255,.05);">
                    <div class="fw-medium small mb-1"><i class="fas fa-list-ul me-1 text-info"></i><?php echo __('stock_as_part_order_parts'); ?></div>
                    <ul class="small text-white-75 mb-0 ps-3">
                        <?php foreach ($order_items as $it): ?>
                            <li><?php echo htmlspecialchars($it['part_name']); ?> — <?php echo (int)$it['quantity']; ?> ks</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label mb-1"><?php echo __('part_name'); ?></label>
                    <input type="text" id="stockPartName" class="form-control" value="<?php echo htmlspecialchars(preg_replace('/\s+/', ' ', trim(((string)($order['device_brand'] ?? '')) . ' ' . ((string)($order['device_model'] ?? ''))))); ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label mb-1"><?php echo __('quantity'); ?></label>
                        <input type="number" id="stockPartQty" class="form-control" value="1" min="1" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1"><?php echo __('cost_price'); ?></label>
                        <div class="input-group">
                            <input type="number" id="stockPartCost" class="form-control" value="<?php echo (int)round((float)($order['final_cost'] ?: $order['estimated_cost'] ?: 0)); ?>" step="1">
                            <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label mb-1"><?php echo __('stock_as_part_notes'); ?></label>
                    <textarea id="stockPartNotes" class="form-control" rows="3" placeholder="<?php echo e(__('stock_as_part_notes_ph')); ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-warning" id="stockAsPartConfirm"><i class="fas fa-boxes-stacked me-2"></i><?php echo __('stock_as_part'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Full Edit Order Modal -->
<div class="modal fade" id="editOrderFullModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderFullForm" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <input type="hidden" name="ui_lang" value="<?php echo e($ui_lang); ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_order_title'); ?> <?php echo e(orderDisplayCode($order)); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('client'); ?></label>
                            <select name="customer_id" class="form-select select2-customer-edit" data-placeholder="<?php echo __('search_client_placeholder'); ?>" <?php echo $__canEditCustomer ? '' : 'disabled'; ?>>
                                <?php foreach($customers_list as $cl):
                                    // předvyplněná (vybraná) položka = aktuální klient zakázky; ostatní se dohledají AJAXem
                                    $clFirst = in_array(trim((string)$cl['first_name']), ['-','–','—'], true) ? '' : trim((string)$cl['first_name']);
                                    $clLast  = in_array(trim((string)$cl['last_name']),  ['-','–','—'], true) ? '' : trim((string)$cl['last_name']);
                                    $clLabel = trim($clFirst . ' ' . $clLast);
                                    if (trim((string)$cl['phone']) !== '') { $clLabel .= ' (' . $cl['phone'] . ')'; }
                                ?>
                                    <option value="<?php echo (int)$cl['id']; ?>" selected>
                                        <?php echo htmlspecialchars($clLabel !== '' ? $clLabel : ('#' . (int)$cl['id'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text"><i class="fas fa-history me-1"></i>Změna klienta se zaznamenává do historie jako „ručně změněno".</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('brand'); ?></label>
                            <select name="device_brand" class="form-select select2-tags-modal">
                                <?php
                                // PAST „prvního vybraného": značka zakázky (volný text z wizardu)
                                // nemusí být v číselníku → bez selected by spadla na první ('Acer')
                                // a uložení by ji tiše přepsalo. Aktuální značku vždy doplníme.
                                $__brands = getDeviceBrands();
                                $__curBrand = trim((string)($order['device_brand'] ?? ''));
                                if ($__curBrand !== '' && !in_array($__curBrand, $__brands, true)) { array_unshift($__brands, $__curBrand); }
                                foreach($__brands as $brand): ?>
                                    <option value="<?php echo htmlspecialchars($brand); ?>" <?php echo ($brand === $__curBrand) ? 'selected' : ''; ?>><?php echo htmlspecialchars($brand); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('device_model'); ?></label>
                            <input type="text" name="device_model" class="form-control" value="<?php echo htmlspecialchars($order['device_model']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('device_type'); ?></label>
                            <select name="device_type" class="form-select">
                                <option value="Phone" <?php echo ($order['device_type'] == 'Phone') ? 'selected' : ''; ?>><?php echo __('phone_type'); ?></option>
                                <option value="Notebook" <?php echo ($order['device_type'] == 'Notebook') ? 'selected' : ''; ?>><?php echo __('notebook_type'); ?></option>
                                <option value="Tablet" <?php echo ($order['device_type'] == 'Tablet') ? 'selected' : ''; ?>><?php echo __('tablet_type'); ?></option>
                                <option value="Other" <?php echo ($order['device_type'] == 'Other') ? 'selected' : ''; ?>><?php echo __('other_type'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('technician'); ?></label>
                            <?php /* Od 1.6.1: povoleno všem, všichni aktivní technici
                                     (prázdná volba = beze změny — update_order_full nechá aktuálního) */ ?>
                            <select name="technician_id" class="form-select">
                                <option value=""><?php echo __('choose_option'); ?></option>
                                <?php foreach($techs as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" <?php if($order['technician_id']==$t['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($t['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('priority'); ?></label>
                            <select name="priority" class="form-select">
                                <?php $curPriority = normalizeOrderPriority($order['priority'] ?? 'Normal'); ?>
                                <?php foreach (getOrderPriorityOptions() as $prioValue => $prioLabel): ?>
                                    <option value="<?php echo e($prioValue); ?>" <?php echo ($curPriority === $prioValue) ? 'selected' : ''; ?>><?php echo $prioValue === 'High' ? '🔥 ' : ''; ?><?php echo e($prioLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('warranty_type'); ?></label>
                            <select name="order_type" class="form-select">
                                <option value="Non-Warranty" <?php echo ($order['order_type'] == 'Non-Warranty') ? 'selected' : ''; ?>><?php echo __('paid_repair'); ?></option>
                                <option value="Warranty" <?php echo ($order['order_type'] == 'Warranty') ? 'selected' : ''; ?>><?php echo __('warranty_repair'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('serial'); ?></label>
                            <input type="text" name="serial_number" class="form-control" value="<?php echo htmlspecialchars($order['serial_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('serial_2'); ?></label>
                            <input type="text" name="serial_number_2" class="form-control" value="<?php echo htmlspecialchars($order['serial_number_2'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('pin'); ?></label>
                            <input type="text" name="pin_code" class="form-control" value="<?php echo htmlspecialchars($order['pin_code'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('appearance'); ?></label>
                            <input type="text" name="appearance" class="form-control" value="<?php echo htmlspecialchars($order['appearance'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('problem'); ?></label>
                            <textarea name="problem_description" class="form-control" rows="3"><?php echo htmlspecialchars($order['problem_description']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('notes'); ?></label>
                            <textarea name="technician_notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['technician_notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('price_estimated'); ?></label>
                            <div class="input-group">
                                <input type="number" name="estimated_cost" class="form-control" step="1" value="<?php $v=$order['estimated_cost']; echo ($v===null||$v==='')?'':(int)round((float)$v); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('price_final'); ?></label>
                            <div class="input-group">
                                <input type="number" name="final_cost" class="form-control" step="1" value="<?php $v=$order['final_cost']; echo ($v===null||$v==='')?'':(int)round((float)$v); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('extra_expenses'); ?></label>
                            <div class="input-group">
                                <input type="number" name="extra_expenses" class="form-control" step="1" value="<?php $v=$order['extra_expenses']; echo ($v===null||$v==='')?'':(int)round((float)$v); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save_changes'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Part -->
<div class="modal fade" id="editPartModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_item_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_part_title'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('part_name'); ?></label>
                        <input type="text" id="edit_item_name" class="form-control" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" id="edit_item_quantity" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('price_per_unit'); ?></label>
                        <div class="input-group">
                            <input type="number" name="price" id="edit_item_price" class="form-control" step="0.01" required>
                            <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php require_once 'includes/footer.php'; ?>

