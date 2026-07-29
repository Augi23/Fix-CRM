<?php
/* Dokumenty — archiv vyplněných dokumentů (výkupní listy, zástavní formuláře).
   Podzáložky dle typu; řádek seznamu: číslo, jméno, telefon, e-mail, předmět,
   cena (jako seznam zakázek). Klik otevře dokument k úpravě / tisku / e-mailu. */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/documents.php';
require_once 'includes/header.php';

ensureCrmDocumentsTable();

$types = crmDocTypes();
$tab = (string)($_GET['t'] ?? 'vykup');
if (!isset($types[$tab])) { $tab = 'vykup'; }
$q = trim((string)($_GET['q'] ?? ''));

$params = [$tab];
$where = 'doc_type = ?';
if ($q !== '') {
    $where .= ' AND (doc_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ? OR subject LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$docs = [];
try {
    $st = $pdo->prepare("SELECT * FROM crm_documents WHERE $where ORDER BY id DESC LIMIT 300");
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $docs = []; }

$tabLabels = ['vykup' => 'Výkupní listy', 'zastava' => 'Zástavní formuláře'];
$newLabels = ['vykup' => 'Nový výkupní list', 'zastava' => 'Nový zástavní formulář'];
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0 text-white"><i class="fas fa-file-signature me-3 text-primary"></i>Dokumenty</h2>
    <a class="btn btn-primary" href="dokument.php?type=<?php echo e($tab); ?>">
        <i class="fas fa-plus me-2"></i><?php echo e($newLabels[$tab] ?? 'Nový dokument'); ?>
    </a>
</div>

<ul class="nav nav-pills mb-3">
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
    <input type="text" name="q" class="form-control" placeholder="Hledat číslo, jméno, telefon, e-mail, předmět…" value="<?php echo e($q); ?>">
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
                <?php if (!$docs): ?>
                    <tr><td colspan="8" class="text-center text-white-75 py-4">
                        <?php echo $q !== '' ? 'Nic nenalezeno.' : 'Zatím žádné dokumenty — založ první tlačítkem „' . e($newLabels[$tab] ?? '') . '".'; ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($docs as $d):
                    $href = 'dokument.php?type=' . e($tab) . '&id=' . (int)$d['id'];
                ?>
                    <tr style="cursor:pointer" onclick="window.location='<?php echo $href; ?>'">
                        <td><strong class="text-info"><?php echo e((string)$d['doc_number']); ?></strong></td>
                        <td class="text-white-75"><?php echo !empty($d['doc_date']) ? e(date('d.m.Y', strtotime((string)$d['doc_date']))) : '—'; ?></td>
                        <td><strong><?php echo e((string)($d['customer_name'] ?: '—')); ?></strong></td>
                        <td>
                            <?php if (!empty($d['customer_phone'])): ?>
                                <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', (string)$d['customer_phone'])); ?>" class="text-reset text-decoration-none" onclick="event.stopPropagation();">
                                    <i class="fas fa-phone me-1 text-success"></i><?php echo e((string)$d['customer_phone']); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($d['customer_email'])): ?>
                                <a href="mailto:<?php echo e((string)$d['customer_email']); ?>" class="text-white-75 text-decoration-none" onclick="event.stopPropagation();">
                                    <i class="fas fa-envelope me-1 text-info"></i><?php echo e((string)$d['customer_email']); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-white-75"><div class="text-truncate" style="max-width: 260px;" title="<?php echo e((string)$d['subject']); ?>"><?php echo e((string)($d['subject'] ?: '—')); ?></div></td>
                        <td class="text-end"><strong><?php echo e((string)($d['price'] ?: '—')); ?></strong></td>
                        <td class="text-end pe-3">
                            <a class="btn btn-sm btn-outline-light" href="<?php echo $href; ?>" onclick="event.stopPropagation();" title="Otevřít">
                                <i class="fas fa-pen"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="text-white-50 small mt-2"><?php echo count($docs); ?> dokumentů<?php echo $q !== '' ? ' (filtr: „' . e($q) . '")' : ''; ?></div>

<?php require_once 'includes/footer.php'; ?>
