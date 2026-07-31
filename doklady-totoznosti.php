<?php
/**
 * DOKLADY TOTOŽNOSTI — sekce jen pro vedení.
 *
 * Skeny občanek/pasů z výkupu leží mimo webový kořen a nikde jinde v CRM se
 * nezobrazují (ani na dokladu, ani v e-mailu). Tohle je jediné místo, kde je jde
 * projít — proto sem smí jen admin a Boss a každé zobrazení skenu se zapisuje
 * do historie změn.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/documents.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanManageInvoices()) {
    header('Location: index.php');
    exit;
}

require_once 'includes/header.php';
ensureDocumentMediaTable();

$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
try {
    $sql = "SELECT d.id, d.doc_number, d.doc_date, d.payload, d.doc_type,
                   d.customer_name, d.subject,
                   MAX(CASE WHEN m.kind = 'id_front' THEN m.id END) AS front_id,
                   MAX(CASE WHEN m.kind = 'id_back'  THEN m.id END) AS back_id,
                   MAX(m.created_at) AS last_upload
            FROM document_media m
            JOIN crm_documents d ON d.id = m.document_id
            WHERE m.kind IN ('id_front','id_back')
            GROUP BY d.id
            ORDER BY last_upload DESC
            LIMIT 300";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

// jméno a předmět jsou ve sloupcích, číslo dokladu totožnosti v uloženém obsahu
foreach ($rows as &$r) {
    $f = json_decode((string)($r['payload'] ?? '{}'), true) ?: [];
    $f = is_array($f['fields'] ?? null) ? $f['fields'] : $f;
    $r['seller'] = trim((string)($r['customer_name'] ?? ($f['customer_name'] ?? '')));
    $r['id_doc'] = trim((string)($f['customer_id_doc'] ?? ''));
    $r['item'] = trim((string)($r['subject'] ?? ($f['item_model'] ?? '')));
}
unset($r);
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower(
        $r['seller'] . ' ' . $r['id_doc'] . ' ' . $r['doc_number'] . ' ' . $r['item']), $needle)));
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-0"><i class="fas fa-id-card me-2 text-warning"></i>Doklady totožnosti</h2>
        <div class="small text-white-50">Skeny z výkupu — jen pro vedení. Každé zobrazení se zapisuje do historie změn.</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input type="search" name="q" value="<?php echo e($q); ?>" class="form-control form-control-sm"
               placeholder="Hledat jméno, číslo dokladu…" style="min-width:240px;">
        <button class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
    </form>
</div>

<div class="glass-panel p-3 border-secondary mb-3" style="background:rgba(255,159,10,.08);">
    <i class="fas fa-shield-halved me-2 text-warning"></i>
    <b>Citlivé osobní údaje.</b> Skeny se ukládají mimo veřejnou část systému a nejsou nikde jinde dostupné —
    ani na výkupním listu, ani v e-mailu klientovi. Uchovávej je jen po dobu, kterou ukládá zákon,
    a nesdílej je mimo firmu. Sken smaž tlačítkem u řádku, jakmile důvod pomine.
</div>

<div class="table-responsive glass-panel border-secondary">
    <table class="table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Výkupní list</th>
                <th>Prodávající</th>
                <th>Doklad</th>
                <th>Zařízení</th>
                <th>Nahráno</th>
                <th class="text-end">Skeny</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-white-50 py-4">
                <?php echo $q !== '' ? 'Nic neodpovídá hledání.' : 'Zatím žádné skeny dokladů totožnosti.'; ?>
            </td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><a href="dokument.php?type=<?php echo e((string)$r['doc_type']); ?>&id=<?php echo (int)$r['id']; ?>"
                       class="text-info fw-semibold"><?php echo e((string)($r['doc_number'] ?: '#' . $r['id'])); ?></a></td>
                <td><?php echo e($r['seller'] ?: '—'); ?></td>
                <td class="small text-white-50"><?php echo e($r['id_doc'] ?: '—'); ?></td>
                <td class="small"><?php echo e(mb_substr($r['item'], 0, 40) ?: '—'); ?></td>
                <td class="small text-white-50"><?php echo $r['last_upload'] ? date('d.m.Y H:i', strtotime((string)$r['last_upload'])) : '—'; ?></td>
                <td class="text-end" style="white-space:nowrap;">
                    <?php foreach ([['front_id', 'Přední'], ['back_id', 'Zadní']] as [$key, $label]): ?>
                        <?php if (!empty($r[$key])): ?>
                            <a class="btn btn-sm btn-white border text-warning" target="_blank" rel="noopener"
                               href="api/document_id_scan.php?id=<?php echo (int)$r[$key]; ?>"><?php echo $label; ?></a>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo $label; ?> chybí</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once 'includes/footer.php'; ?>
