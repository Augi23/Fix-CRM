<?php
/* Dokumenty — archiv VŠECH vyplněných listů na jednom místě, v podzáložkách:
     • Výkupní listy / Zástavní formuláře — dokumenty z enginu (crm_documents),
       klik = úprava, oko = tiskový náhled (print_document.php).
     • Zakázkové listy — tiskový list každé zakázky (print_order.php), proklik
       do detailu zakázky; řídí se pobočkovým rozsahem přihlášeného.
     • Reklamační protokoly — print_complaint.php + proklik do reklamace.
   Řádek seznamu: číslo, datum, jméno, telefon, e-mail, předmět, cena. */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/documents.php';
require_once 'includes/header.php';

ensureCrmDocumentsTable();

$types = crmDocTypes();
$virtualTabs = ['zakazky', 'reklamace'];
$tab = (string)($_GET['t'] ?? 'vykup');
if (!isset($types[$tab]) && !in_array($tab, $virtualTabs, true)) { $tab = 'vykup'; }
$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . $q . '%';

$tabLabels = [
    'vykup' => 'Výkupní listy',
    'zastava' => 'Zástavní formuláře',
    'zakazky' => 'Zakázkové listy',
    'reklamace' => 'Reklamační protokoly',
];
$newLabels = ['vykup' => 'Nový výkupní list', 'zastava' => 'Nový zástavní formulář'];
$emptyText = [
    'vykup' => 'Zatím žádné dokumenty — založ první tlačítkem „Nový výkupní list".',
    'zastava' => 'Zatím žádné dokumenty — založ první tlačítkem „Nový zástavní formulář".',
    'zakazky' => 'Zatím žádné zakázky.',
    'reklamace' => 'Zatím žádné reklamace.',
];

/* Každý řádek se normalizuje na společný tvar:
   num, badge_html, date, name, online_at, phone, email, subject, price,
   preview_url (tiskový náhled), open_url + open_icon + open_title (detail/úprava) */
$rows = [];

if (isset($types[$tab])) {
    // ── dokumenty z enginu (výkup / zástava) ──
    ensureDocumentSignatureSupport();
    $params = [$tab];
    $where = 'doc_type = ?';
    if ($q !== '') {
        $where .= ' AND (doc_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ? OR subject LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    try {
        $st = $pdo->prepare("SELECT d.*, (SELECT MAX(s.signed_at) FROM document_signatures s WHERE s.document_id = d.id) AS signed_at
                             FROM crm_documents d WHERE $where ORDER BY d.id DESC LIMIT 300");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $badge = '';
            if (!empty($d['signed_at'])) {
                $badge = '<span class="badge ms-1" style="background:rgba(52,199,89,.18);color:#4ade80;border:1px solid rgba(52,199,89,.4);" title="Podepsáno '
                    . e(date('d.m.Y H:i', strtotime((string)$d['signed_at']))) . '"><i class="fas fa-signature me-1"></i>podepsáno</span>';
            }
            $onlineAt = '';
            try { $onlineAt = (string)((json_decode((string)($d['payload'] ?? ''), true) ?: [])['online_filled_at'] ?? ''); } catch (Throwable $e) {}
            $rows[] = [
                'num' => (string)$d['doc_number'],
                'badge_html' => $badge,
                'date' => !empty($d['doc_date']) ? date('d.m.Y', strtotime((string)$d['doc_date'])) : '',
                'name' => (string)($d['customer_name'] ?? ''),
                'online_at' => $onlineAt,
                'phone' => (string)($d['customer_phone'] ?? ''),
                'email' => (string)($d['customer_email'] ?? ''),
                'subject' => (string)($d['subject'] ?? ''),
                'price' => (string)($d['price'] ?? ''),
                'preview_url' => 'print_document.php?id=' . (int)$d['id'],
                'open_url' => 'dokument.php?type=' . e($tab) . '&id=' . (int)$d['id'],
                'open_icon' => 'fa-pen',
                'open_title' => 'Otevřít / upravit',
            ];
        }
    } catch (Throwable $e) {}
} elseif ($tab === 'zakazky') {
    // ── zakázkové listy: každá zakázka má svůj tiskový list (print_order.php) ──
    try {
        $scope = orderBranchScopeSql('o.branch_id', 'o.technician_id');
        $sql = "SELECT o.id, o.order_code, o.created_at, o.status, o.device_brand, o.device_model,
                       o.final_cost, o.estimated_cost, c.first_name, c.last_name, c.phone, c.email
                FROM orders o JOIN customers c ON c.id = o.customer_id WHERE 1=1$scope";
        $p2 = [];
        if ($q !== '') {
            $sql .= " AND (o.order_code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ?)";
            $p2 = [$like, $like, $like, $like, $like, $like];
        }
        $sql .= " ORDER BY o.id DESC LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($p2);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $cost = ($o['final_cost'] !== null && $o['final_cost'] !== '') ? $o['final_cost'] : $o['estimated_cost'];
            $rows[] = [
                'num' => (string)($o['order_code'] ?: ('#' . (int)$o['id'])),
                'badge_html' => '',
                'date' => !empty($o['created_at']) ? date('d.m.Y', strtotime((string)$o['created_at'])) : '',
                'name' => trim((string)$o['first_name'] . ' ' . (string)$o['last_name']),
                'online_at' => '',
                'phone' => (string)($o['phone'] ?? ''),
                'email' => (string)($o['email'] ?? ''),
                'subject' => trim((string)$o['device_brand'] . ' ' . (string)$o['device_model']),
                'price' => ($cost !== null && $cost !== '') ? number_format((float)$cost, 0, ',', ' ') . ' Kč' : '',
                'preview_url' => 'print_order.php?id=' . (int)$o['id'],
                'open_url' => 'view_order.php?id=' . (int)$o['id'],
                'open_icon' => 'fa-up-right-from-square',
                'open_title' => 'Otevřít zakázku',
            ];
        }
    } catch (Throwable $e) {}
} else {
    // ── reklamační protokoly (print_complaint.php) ──
    try {
        $sql = "SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cu_phone, cu.email AS cu_email,
                       o.device_brand AS o_brand, o.device_model AS o_model
                FROM complaints c
                LEFT JOIN customers cu ON cu.id = c.customer_id
                LEFT JOIN orders o ON o.id = c.order_id WHERE 1=1";
        $p2 = [];
        if ($q !== '') {
            $sql .= " AND (c.complaint_code LIKE ? OR cu.first_name LIKE ? OR cu.last_name LIKE ? OR cu.phone LIKE ? OR o.device_model LIKE ?)";
            $p2 = [$like, $like, $like, $like, $like];
        }
        $sql .= " ORDER BY c.id DESC LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($p2);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            // zařízení: vlastní pole reklamace, a když jsou prázdná, tak ze zakázky
            $__brand = trim((string)($c['device_brand'] ?? ''));
            $__model = trim((string)($c['device_model'] ?? ''));
            if ($__brand === '' && $__model === '') {
                $__brand = trim((string)($c['o_brand'] ?? ''));
                $__model = trim((string)($c['o_model'] ?? ''));
            }
            $subject = trim($__brand . ' ' . $__model);
            $rows[] = [
                'num' => (string)($c['complaint_code'] ?: ('#' . (int)$c['id'])),
                'badge_html' => '',
                'date' => !empty($c['created_at']) ? date('d.m.Y', strtotime((string)$c['created_at'])) : '',
                'name' => trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? '')),
                'online_at' => '',
                'phone' => (string)($c['cu_phone'] ?? ''),
                'email' => (string)($c['cu_email'] ?? ''),
                'subject' => $subject,
                'price' => '',
                'preview_url' => 'print_complaint.php?id=' . (int)$c['id'],
                'open_url' => 'view_complaint.php?id=' . (int)$c['id'],
                'open_icon' => 'fa-up-right-from-square',
                'open_title' => 'Otevřít reklamaci',
            ];
        }
    } catch (Throwable $e) {}
}
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0 text-white"><i class="fas fa-file-signature me-3 text-primary"></i>Dokumenty</h2>
    <?php /* Obě tlačítka vždy viditelná a graficky STEJNÁ — Apple liquid glass
             buňka (tokeny .afx-cell.act-blue z docku, + vlastní backdrop blur,
             protože tady nestojí na skle docku). */ ?>
    <style>
        .afx-glassbtn {
            display: inline-flex; align-items: center; gap: 9px;
            padding: 11px 18px; border-radius: 15px; text-decoration: none;
            font-weight: 600; font-size: 14px; white-space: nowrap;
            color: #5fd2ff; text-shadow: 0 1px 2px rgba(0,0,0,.55), 0 0 12px rgba(95,210,255,.5);
            background: rgba(0,163,255,.16);
            box-shadow: inset 0 0 0 1px rgba(0,163,255,.24);
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
            transition: background .16s, color .16s, transform .16s, box-shadow .16s;
        }
        .afx-glassbtn:hover {
            background: rgba(0,163,255,.26); color: #aee6ff; transform: translateY(-1px);
            box-shadow: inset 0 0 0 1px rgba(0,163,255,.45), 0 0 16px rgba(0,163,255,.28);
        }
        .afx-glassbtn i { font-size: 14px; opacity: .9; }
    </style>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (function_exists('crmCanManageInvoices') && crmCanManageInvoices()): ?>
        <a class="afx-glassbtn" href="doklady-totoznosti.php" title="Skeny dokladů totožnosti z výkupu — jen pro vedení">
            <i class="fas fa-id-card me-2"></i>Doklady totožnosti
        </a>
        <?php endif; ?>
        <a class="afx-glassbtn" href="dokument.php?type=vykup">
            <i class="fas fa-plus"></i>Nový výkupní list
        </a>
        <a class="afx-glassbtn" href="dokument.php?type=zastava">
            <i class="fas fa-plus"></i>Nový zástavní formulář
        </a>
    </div>
</div>

<ul class="nav nav-pills mb-3 flex-wrap">
    <?php foreach ($tabLabels as $tk => $tl): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === $tk ? 'active' : ''; ?>" href="dokumenty.php?t=<?php echo e($tk); ?>">
            <?php echo e($tl); ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<form method="GET" class="mb-3 d-flex gap-2" style="max-width: 460px;">
    <input type="hidden" name="t" value="<?php echo e($tab); ?>">
    <input type="text" name="q" class="form-control" placeholder="Hledat číslo, jméno, telefon, zařízení…" value="<?php echo e($q); ?>">
    <button class="btn btn-outline-light" type="submit"><i class="fas fa-search"></i></button>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Číslo</th>
                        <th>Datum</th>
                        <th>Jméno</th>
                        <th>Telefon</th>
                        <th>E-mail</th>
                        <th>Předmět</th>
                        <th class="text-end">Cena</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-white-75 py-4">
                        <?php echo $q !== '' ? 'Nic nenalezeno.' : e($emptyText[$tab] ?? 'Zatím nic.'); ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr style="cursor:pointer" onclick="window.location='<?php echo e($r['open_url']); ?>'">
                        <td>
                            <strong class="text-info"><?php echo e($r['num']); ?></strong>
                            <?php echo $r['badge_html']; ?>
                        </td>
                        <td class="text-white-75"><?php echo $r['date'] !== '' ? e($r['date']) : '—'; ?></td>
                        <td><strong><?php echo e($r['name'] !== '' ? $r['name'] : '—'); ?></strong>
                            <?php if ($r['online_at'] !== ''): ?>
                                <span class="badge rounded-pill" style="background:rgba(11,87,208,.2);color:#7db2ff;font-size:.66rem;" title="Klient vyplnil online <?php echo e(date('d.m.Y H:i', strtotime($r['online_at']))); ?>"><i class="fas fa-globe me-1"></i>online</span>
                            <?php endif; ?></td>
                        <td>
                            <?php if ($r['phone'] !== ''): ?>
                                <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $r['phone'])); ?>" class="text-reset text-decoration-none" onclick="event.stopPropagation();">
                                    <i class="fas fa-phone me-1 text-success"></i><?php echo e($r['phone']); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['email'] !== ''): ?>
                                <a href="mailto:<?php echo e($r['email']); ?>" class="text-white-75 text-decoration-none" onclick="event.stopPropagation();">
                                    <i class="fas fa-envelope me-1 text-info"></i><?php echo e($r['email']); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-white-75"><div class="text-truncate" style="max-width: 260px;" title="<?php echo e($r['subject']); ?>"><?php echo e($r['subject'] !== '' ? $r['subject'] : '—'); ?></div></td>
                        <td class="text-end"><strong><?php echo e($r['price'] !== '' ? $r['price'] : '—'); ?></strong></td>
                        <td class="text-end pe-3 text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-info me-1" title="Zobrazit / vytisknout"
                                onclick="event.stopPropagation(); if (typeof openUniversalPreview === 'function') { openUniversalPreview('<?php echo e($r['preview_url']); ?>', '<?php echo e($r['num']); ?>'); } else { window.open('<?php echo e($r['preview_url']); ?>', '_blank'); }">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a class="btn btn-sm btn-outline-light" href="<?php echo e($r['open_url']); ?>" onclick="event.stopPropagation();" title="<?php echo e($r['open_title']); ?>">
                                <i class="fas <?php echo e($r['open_icon']); ?>"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="text-white-50 small mt-2"><?php echo count($rows); ?> záznamů<?php echo $q !== '' ? ' (filtr: „' . e($q) . '")' : ''; ?><?php echo count($rows) >= 300 ? ' · zobrazeno posledních 300 — starší dohledáš vyhledáváním' : ''; ?></div>

<?php require_once 'includes/footer.php'; ?>
