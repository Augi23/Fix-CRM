<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

function complaintStatusUi(?string $status): array
{
    $s = mb_strtolower(trim((string)$status));

    if ($s === '') return ['row' => '', 'badge' => 'bg-secondary', 'label' => '—'];
    if (str_contains($s, 'přij') || str_contains($s, 'nova') || str_contains($s, 'nová')) return ['row' => 'table-primary', 'badge' => 'bg-primary', 'label' => $status];
    if (str_contains($s, 'řeš') || str_contains($s, 'oprav') || str_contains($s, 'proces')) return ['row' => 'table-warning', 'badge' => 'bg-warning text-dark', 'label' => $status];
    if (str_contains($s, 'ček') || str_contains($s, 'zákazn')) return ['row' => 'table-info', 'badge' => 'bg-info text-dark', 'label' => $status];
    if (str_contains($s, 'vyří') || str_contains($s, 'dokon') || str_contains($s, 'hotov')) return ['row' => 'table-success', 'badge' => 'bg-success', 'label' => $status];
    if (str_contains($s, 'zamít') || str_contains($s, 'storn') || str_contains($s, 'zruš')) return ['row' => 'table-danger', 'badge' => 'bg-danger', 'label' => $status];

    return ['row' => '', 'badge' => 'bg-secondary', 'label' => $status];
}

$rows = [];
if (isset($pdo)) {
    try {
        $limit = 100;
        $page = isset($_GET['p']) && is_numeric($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');

        if ($search !== '') {
            $term = "%$search%";
            $count = $pdo->prepare("SELECT COUNT(*) FROM complaints c LEFT JOIN customers cu ON cu.id=c.customer_id WHERE c.complaint_code LIKE ? OR c.device LIKE ? OR c.complaint_reason LIKE ? OR cu.first_name LIKE ? OR cu.last_name LIKE ? OR cu.phone LIKE ?");
            $count->execute([$term,$term,$term,$term,$term,$term]);
            $total = (int)$count->fetchColumn();

            $stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name FROM complaints c LEFT JOIN customers cu ON cu.id=c.customer_id WHERE c.complaint_code LIKE ? OR c.device LIKE ? OR c.complaint_reason LIKE ? OR cu.first_name LIKE ? OR cu.last_name LIKE ? OR cu.phone LIKE ? ORDER BY CAST(SUBSTRING_INDEX(c.complaint_code, '-', -1) AS UNSIGNED) DESC, c.id DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([$term,$term,$term,$term,$term,$term]);
        } else {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
            $stmt = $pdo->query("SELECT c.*, cu.first_name, cu.last_name FROM complaints c LEFT JOIN customers cu ON cu.id=c.customer_id ORDER BY CAST(SUBSTRING_INDEX(c.complaint_code, '-', -1) AS UNSIGNED) DESC, c.id DESC LIMIT $limit OFFSET $offset");
        }

        $rows = $stmt->fetchAll();
        $pages = max(1, (int)ceil($total / $limit));
    } catch (Throwable $e) {
        $pages = 1;
    }
}

// počty fotek k reklamacím (tabulka na starších instalacích nemusí existovat)
$complaint_photos = [];
if (!empty($rows) && isset($pdo)) {
    try {
        $ids = array_map('intval', array_column($rows, 'id'));
        $q = $pdo->query("SELECT complaint_id, COUNT(*) AS n, MIN(file_path) AS first_path
                          FROM complaint_attachments WHERE complaint_id IN (" . implode(',', $ids) . ")
                          GROUP BY complaint_id");
        foreach ($q as $p) { $complaint_photos[(int)$p['complaint_id']] = $p; }
    } catch (Throwable $e) { /* bez fotek */ }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Reklamace</h2>
    <button class="btn fw-semibold" style="background:#f97316;color:#fff" data-bs-toggle="modal" data-bs-target="#newComplaintModal">
        <i class="fas fa-rotate-left me-1"></i> Nová reklamace
    </button>
</div>

<?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Reklamace <strong><?php echo e($_GET['created']); ?></strong> byla založena.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-2"></i><?php echo e($_GET['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="GET" class="mb-3 d-flex gap-2">
            <input type="text" class="form-control" name="search" placeholder="Hledat reklamace" value="<?php echo e($_GET['search'] ?? ''); ?>">
            <button class="btn btn-primary" type="submit">Hledat</button>
            <?php if (!empty($_GET['search'])): ?><a class="btn btn-outline-secondary" href="reklamace.php">Reset</a><?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Kód</th>
                        <th>Zákazník</th>
                        <th>Telefon</th>
                        <th>Zařízení</th>
                        <th>IMEI/SN</th>
                        <th>Důvod reklamace</th>
                        <th>Stav</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $ui = complaintStatusUi($r['complaint_status'] ?? ''); ?>
                    <tr class="<?php echo e($ui['row']); ?>">
                        <td>
                            <?php echo e($r['complaint_code']); ?>
                            <?php if (!empty($complaint_photos[(int)$r['id']])): $cp = $complaint_photos[(int)$r['id']]; ?>
                                <a href="<?php echo e($cp['first_path']); ?>" target="_blank" rel="noopener"
                                   class="badge bg-secondary text-decoration-none ms-1" title="Fotodokumentace">
                                    <i class="fas fa-camera"></i> <?php echo (int)$cp['n']; ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></td>
                        <td><?php echo e($r['phone'] ?? ''); ?></td>
                        <td><?php echo e($r['device'] ?? ''); ?></td>
                        <td><?php echo e($r['serial_number'] ?? ''); ?></td>
                        <td style="min-width:280px;"><?php echo nl2br(e($r['complaint_reason'] ?? '')); ?></td>
                        <td><span class="badge <?php echo e($ui['badge']); ?>"><?php echo e($ui['label']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (($pages ?? 1) > 1): ?>
            <nav><ul class="pagination mt-3">
                <?php for ($i=1; $i<=$pages; $i++): ?>
                    <li class="page-item <?php echo $i===($page ?? 1) ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i; ?><?php echo !empty($_GET['search']) ? '&search='.urlencode($_GET['search']) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul></nav>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
