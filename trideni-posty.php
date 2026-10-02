<?php
/**
 * TŘÍDĚNÍ POŠTY — automatické roztřídění firemních schránek (Forpsi IMAP)
 * na Zákazníky / Nabídky / Roboty. Logika: posta/lib.php,
 * akce: posta/api.php. Přístup: jen administrátor (hesla ke schránkám).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'posta/lib.php';

if (empty($_SESSION['user_id']) || !crmCanManageSettings()) {
    header('Location: index.php');
    exit;
}

require_once 'includes/header.php';
crmMailEnsureSchema();
crmMailCleanupLeftovers();

$meta = crmMailCategoryMeta();
$accounts = crmMailAccounts();
$accById = [];
foreach ($accounts as $a) { $accById[(int)$a['id']] = $a; }
$aiKeySet = trim((string)get_setting('ai_api_key', '')) !== '' || trim((string)getenv('AI_API_KEY')) !== '';

// statistiky: dnes + 7 dní po kategoriích
$stats = [];
foreach (array_keys($meta) as $c) { $stats[$c] = ['today' => 0, 'week' => 0]; }
try {
    $q = $pdo->query("SELECT category,
            SUM(created_at >= CURDATE()) AS today,
            COUNT(*) AS week
        FROM mail_sort_log WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY category");
    foreach ($q as $r) {
        if (isset($stats[$r['category']])) { $stats[$r['category']] = ['today' => (int)$r['today'], 'week' => (int)$r['week']]; }
    }
} catch (Throwable $e) {}
$weekTotal = array_sum(array_column($stats, 'week'));

// přehled roztříděné pošty
$fCat = (string)($_GET['cat'] ?? '');
$fAcc = (int)($_GET['acc'] ?? 0);
$fQ = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$where = ['1=1'];
$par = [];
if (isset($meta[$fCat])) { $where[] = 'category = ?'; $par[] = $fCat; }
if ($fAcc > 0) { $where[] = 'account_id = ?'; $par[] = $fAcc; }
if ($fQ !== '') { $where[] = '(from_email LIKE ? OR from_name LIKE ? OR subject LIKE ?)'; $like = '%' . $fQ . '%'; array_push($par, $like, $like, $like); }
$total = 0; $rows = [];
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM mail_sort_log WHERE ' . implode(' AND ', $where));
    $s->execute($par);
    $total = (int)$s->fetchColumn();
    $s = $pdo->prepare('SELECT * FROM mail_sort_log WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(received_at, created_at) DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $s->execute($par);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$pages = max(1, (int)ceil($total / $per));
$rules = $pdo->query('SELECT * FROM mail_sort_rules ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$qs = static function (array $over) use ($fCat, $fAcc, $fQ): string {
    $p = array_filter(array_merge(['cat' => $fCat, 'acc' => $fAcc ?: '', 'q' => $fQ], $over), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'trideni-posty.php' . ($p ? '?' . http_build_query($p) : '');
};
$methodLabel = ['rule' => 'pravidlo', 'crm' => 'klient CRM', 'heuristic' => 'automaticky', 'ai' => 'AI', 'manual' => 'ručně'];
?>
<style>
.ms-hero { position: relative; overflow: hidden; }
.ms-flow { display: grid; grid-template-columns: minmax(150px, 1fr) 1.2fr minmax(0, 3fr); gap: 0; align-items: stretch; }
.ms-inbox { display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 18px 10px; }
.ms-inbox-icon { width: 74px; height: 74px; border-radius: 22px; display: grid; place-items: center; font-size: 30px;
    background: linear-gradient(145deg, rgba(255,255,255,.14), rgba(255,255,255,.03)); border: 1px solid rgba(255,255,255,.16);
    box-shadow: 0 18px 40px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.18); position: relative; }
.ms-inbox-icon::after { content: ''; position: absolute; inset: -6px; border-radius: 26px; border: 2px solid rgba(100,210,255,.35);
    animation: msPulse 2.6s ease-out infinite; }
@keyframes msPulse { 0% { opacity: .9; transform: scale(.92); } 100% { opacity: 0; transform: scale(1.18); } }
.ms-pipes { position: relative; min-height: 210px; }
.ms-pipes svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.ms-pipes path { fill: none; stroke-width: 2; opacity: .35; }
.ms-pipes .ms-dot { r: 4; }
.ms-lanes { display: grid; grid-template-rows: repeat(3, 1fr); gap: 10px; padding: 6px 0; }
.ms-lane { --c: #fff; display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 16px; text-decoration: none; color: inherit;
    background: linear-gradient(90deg, color-mix(in srgb, var(--c) 16%, transparent), color-mix(in srgb, var(--c) 4%, transparent));
    border: 1px solid color-mix(in srgb, var(--c) 35%, transparent); transition: transform .18s ease, box-shadow .18s ease; }
.ms-lane:hover { transform: translateX(4px); box-shadow: 0 10px 28px color-mix(in srgb, var(--c) 22%, transparent); color: inherit; }
.ms-lane.is-active { box-shadow: inset 0 0 0 2px var(--c); }
.ms-lane-ico { width: 42px; height: 42px; border-radius: 13px; display: grid; place-items: center; flex: none; color: #0b0c0f; background: var(--c); font-size: 17px;
    box-shadow: 0 6px 18px color-mix(in srgb, var(--c) 45%, transparent); }
.ms-lane-num { font-size: 26px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.ms-lane-sub { font-size: 12px; opacity: .65; }
.ms-bar { height: 6px; border-radius: 4px; background: rgba(255,255,255,.08); overflow: hidden; margin-top: 6px; }
.ms-bar > span { display: block; height: 100%; background: var(--c); border-radius: 4px; transition: width 1s cubic-bezier(.2,.8,.2,1); }
.ms-chip { --c: #fff; display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap;
    color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); border: 1px solid color-mix(in srgb, var(--c) 35%, transparent); }
.ms-acc { border-radius: 18px; padding: 16px 18px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.03); }
.ms-dotstat { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; }
.ms-move { display: inline-flex; gap: 4px; }
.ms-move button { --c: #fff; width: 30px; height: 30px; border-radius: 9px; border: 1px solid color-mix(in srgb, var(--c) 30%, transparent); background: transparent; color: var(--c); opacity: .55; }
.ms-move button:hover { opacity: 1; background: color-mix(in srgb, var(--c) 16%, transparent); }
.ms-move button.is-cur { opacity: 1; background: var(--c); color: #0b0c0f; cursor: default; }
.ms-subject { max-width: 420px; }
.ms-snippet { font-size: 12px; opacity: .55; max-width: 520px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-method { display: inline-block; padding: 1px 7px; border-radius: 6px; font-size: 11px; font-weight: 600; border: 1px solid rgba(255,255,255,.18); opacity: .8; }
.ms-step { display: flex; gap: 12px; align-items: flex-start; }
.ms-step b.n { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; flex: none; font-size: 13px; background: rgba(255,255,255,.1); }
[data-lg-theme="light"] .ms-lane-ico, [data-lg-theme="light"] .ms-move button.is-cur { color: #fff; }
[data-lg-theme="light"] .ms-bar { background: rgba(0,0,0,.08); }
[data-lg-theme="light"] .ms-method { border-color: rgba(0,0,0,.2); }
[data-lg-theme="light"] .ms-acc { border-color: rgba(0,0,0,.1); background: rgba(0,0,0,.02); }
@media (max-width: 900px) {
    .ms-flow { grid-template-columns: 1fr; }
    .ms-pipes { display: none; }
    .ms-inbox { flex-direction: row; gap: 14px; justify-content: flex-start; text-align: left; padding: 6px 0 14px; }
    .ms-inbox-icon { width: 54px; height: 54px; font-size: 22px; border-radius: 16px; }
}
@media (prefers-reduced-motion: reduce) { .ms-inbox-icon::after, .ms-pipes animateMotion { animation: none; display: none; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-0"><i class="fas fa-envelope-open-text me-2"></i>Třídění pošty</h2>
        <div class="small text-white-50">Nová pošta se sama rozdělí na zákazníky, nabídky a automatické zprávy.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="settings.php?tab=system&amp;sub=integrace" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Nastavení</a>
        <button class="btn btn-primary" id="msAddBtn"><i class="fas fa-plus me-2"></i>Přidat schránku</button>
    </div>
</div>

<!-- ── Tok pošty: Doručená → 3 kategorie ── -->
<div class="glass-panel border-secondary p-3 p-md-4 mb-4 ms-hero">
    <div class="ms-flow">
        <div class="ms-inbox">
            <div class="ms-inbox-icon"><i class="fas fa-inbox"></i></div>
            <div class="mt-md-3">
                <div class="fw-bold">Doručená pošta</div>
                <div class="small text-white-50"><?php echo number_format($weekTotal, 0, ',', ' '); ?> roztříděno za 7 dní</div>
            </div>
        </div>
        <div class="ms-pipes" aria-hidden="true">
            <svg viewBox="0 0 200 210" preserveAspectRatio="none">
                <?php $ys = [35, 105, 175]; $i = 0; foreach ($meta as $cat => $m): $y = $ys[$i]; ?>
                <path id="msPipe<?php echo $i; ?>" d="M0,105 C90,105 110,<?php echo $y; ?> 200,<?php echo $y; ?>" stroke="<?php echo $m['color']; ?>"/>
                <?php for ($k = 0; $k < 2; $k++): ?>
                <circle class="ms-dot" r="4" fill="<?php echo $m['color']; ?>">
                    <animateMotion dur="<?php echo 2.4 + $i * 0.35; ?>s" begin="<?php echo $k * 1.2 + $i * 0.4; ?>s" repeatCount="indefinite"><mpath href="#msPipe<?php echo $i; ?>"/></animateMotion>
                </circle>
                <?php endfor; $i++; endforeach; ?>
            </svg>
        </div>
        <div class="ms-lanes">
            <?php foreach ($meta as $cat => $m):
                $pct = $weekTotal > 0 ? round($stats[$cat]['week'] / $weekTotal * 100) : 0;
                $folders = array_unique(array_map(static fn($a) => crmMailFolderFor($a, $cat), $accounts));
            ?>
            <a class="ms-lane <?php echo $fCat === $cat ? 'is-active' : ''; ?>" style="--c: <?php echo $m['color']; ?>" href="<?php echo e($qs(['cat' => $fCat === $cat ? '' : $cat, 'p' => ''])); ?>">
                <span class="ms-lane-ico"><i class="fas <?php echo $m['icon']; ?>"></i></span>
                <span class="flex-grow-1">
                    <span class="d-flex justify-content-between align-items-baseline gap-2">
                        <span>
                            <span class="fw-bold"><?php echo $m['label']; ?></span>
                            <span class="ms-lane-sub d-block d-sm-inline ms-sm-2">→ <?php echo e($folders ? implode(', ', array_map(static fn($f) => $f === 'INBOX' ? 'zůstává v Doručené' : 'složka ' . $f, $folders)) : ($cat === 'customer' ? 'zůstává v Doručené' : 'složka ' . ($cat === 'offer' ? 'Nabídky' : 'Roboti'))); ?></span>
                        </span>
                        <span class="text-end">
                            <span class="ms-lane-num" data-count="<?php echo (int)$stats[$cat]['week']; ?>">0</span>
                            <span class="ms-lane-sub d-block">dnes <?php echo (int)$stats[$cat]['today']; ?></span>
                        </span>
                    </span>
                    <span class="ms-bar d-block"><span style="width:0" data-w="<?php echo $pct; ?>%"></span></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Schránky ── -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0"><i class="fas fa-at me-2 text-info"></i>Schránky</h5>
</div>
<?php if (!$accounts): ?>
<div class="glass-panel border-secondary p-4 mb-4">
    <div class="row g-4 align-items-center">
        <div class="col-lg-7">
            <h5 class="mb-3">Připoj firemní schránku z Forpsi</h5>
            <div class="d-grid gap-3">
                <div class="ms-step"><b class="n">1</b><div>Klikni na <b>Přidat schránku</b> a vyplň e-mail a heslo (stejné jako do webmailu Forpsi). Server <code>imap.forpsi.com</code> je předvyplněný.</div></div>
                <div class="ms-step"><b class="n">2</b><div><b>Náhled</b> ukáže, jak by se roztřídilo posledních 30 e-mailů. Nic se zatím nepřesouvá.</div></div>
                <div class="ms-step"><b class="n">3</b><div>Zapni <b>automatické třídění</b>. Zákazníci zůstanou v Doručené poště, nabídky a roboti se přesunou do vlastních složek, které uvidíš v telefonu, Outlooku i webmailu.</div></div>
            </div>
        </div>
        <div class="col-lg-5 text-center">
            <button class="btn btn-lg btn-primary px-4" id="msAddBtn2"><i class="fas fa-plus me-2"></i>Přidat schránku</button>
            <div class="small text-white-50 mt-2">Přidat jde i víc adres (servis@, info@…).</div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($accounts as $a):
        $okState = $a['last_ok'] === null ? null : (bool)$a['last_ok'];
        $dot = !$a['enabled'] ? '#8e8e93' : ($okState === false ? '#ff453a' : '#30d158');
    ?>
    <div class="col-xl-6">
        <div class="ms-acc h-100">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div>
                    <div class="fw-bold fs-6"><span class="ms-dotstat" style="background:<?php echo $dot; ?>"></span><?php echo e($a['email']); ?></div>
                    <div class="small text-white-50"><?php echo e($a['imap_host'] . ':' . $a['imap_port']); ?><?php echo (int)$a['use_ai'] ? ' · <i class="fas fa-wand-magic-sparkles"></i> AI u nejasných' : ''; ?></div>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input ms-toggle" type="checkbox" role="switch" id="msT<?php echo (int)$a['id']; ?>" data-id="<?php echo (int)$a['id']; ?>" <?php echo $a['enabled'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="msT<?php echo (int)$a['id']; ?>"><?php echo $a['enabled'] ? 'Třídí se' : 'Vypnuto'; ?></label>
                </div>
            </div>
            <div class="small mt-2 <?php echo $okState === false ? 'text-danger' : 'text-white-50'; ?>">
                <?php if ($a['last_run_at']): ?>
                    <i class="fas fa-clock me-1"></i><?php echo date('d.m. H:i', strtotime((string)$a['last_run_at'])); ?> — <?php echo e($a['last_status']); ?>
                <?php else: ?>
                    <i class="fas fa-circle-info me-1"></i>Zatím neběželo. Zkus <b>Náhled</b>, pak třídění zapni.
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 mt-3 flex-wrap">
                <button class="btn btn-sm btn-outline-info ms-preview" data-id="<?php echo (int)$a['id']; ?>"><i class="fas fa-eye me-1"></i>Náhled</button>
                <button class="btn btn-sm btn-outline-success ms-run" data-id="<?php echo (int)$a['id']; ?>" <?php echo $a['enabled'] ? '' : 'disabled'; ?>><i class="fas fa-rotate me-1"></i>Roztřídit teď</button>
                <button class="btn btn-sm btn-outline-secondary ms-edit" data-acc='<?php echo e(json_encode([
                    'id' => (int)$a['id'], 'email' => $a['email'], 'imap_host' => $a['imap_host'], 'imap_port' => (int)$a['imap_port'],
                    'imap_secure' => $a['imap_secure'], 'username' => $a['username'], 'folder_customer' => $a['folder_customer'],
                    'folder_offer' => $a['folder_offer'], 'folder_robot' => $a['folder_robot'], 'use_ai' => (int)$a['use_ai'],
                ], JSON_UNESCAPED_UNICODE)); ?>'><i class="fas fa-pen me-1"></i>Upravit</button>
                <button class="btn btn-sm btn-outline-danger ms-del ms-auto" data-id="<?php echo (int)$a['id']; ?>" data-email="<?php echo e($a['email']); ?>"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Přehled roztříděné pošty ── -->
<div class="glass-panel border-secondary p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="mb-0"><i class="fas fa-list-check me-2 text-info"></i>Roztříděná pošta <span class="small text-white-50 fw-normal">(<?php echo number_format($total, 0, ',', ' '); ?>)</span></h5>
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <?php if ($fCat !== ''): ?><input type="hidden" name="cat" value="<?php echo e($fCat); ?>"><?php endif; ?>
            <?php if (count($accounts) > 1): ?>
            <select name="acc" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="">Všechny schránky</option>
                <?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php echo $fAcc === (int)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['email']); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?php echo e($fQ); ?>" class="form-control form-control-sm" style="width:220px" placeholder="Hledat odesílatele, předmět…">
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <div class="d-flex gap-2 flex-wrap mb-3">
        <a href="<?php echo e($qs(['cat' => '', 'p' => ''])); ?>" class="btn btn-sm <?php echo $fCat === '' ? 'btn-light' : 'btn-outline-secondary'; ?>">Vše</a>
        <?php foreach ($meta as $cat => $m): ?>
        <a href="<?php echo e($qs(['cat' => $cat, 'p' => ''])); ?>" class="ms-chip text-decoration-none <?php echo $fCat === $cat ? '' : 'opacity-75'; ?>" style="--c: <?php echo $m['color']; ?>; <?php echo $fCat === $cat ? 'box-shadow: inset 0 0 0 1px ' . $m['color'] : ''; ?>"><i class="fas <?php echo $m['icon']; ?>"></i><?php echo $m['label']; ?></a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Kategorie</th>
                    <th>Od</th>
                    <th>Předmět</th>
                    <th class="d-none d-lg-table-cell">Proč</th>
                    <th style="white-space:nowrap">Přišlo</th>
                    <th class="text-end">Přeřadit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-white-50 py-5">
                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                    <?php echo $accounts ? 'Zatím tu nic není — po zapnutí třídění se sem zapisuje každá roztříděná zpráva.' : 'Nejdřív přidej schránku.'; ?>
                </td></tr>
            <?php else: foreach ($rows as $r): $m = $meta[$r['category']] ?? $meta['customer']; ?>
                <tr>
                    <td><span class="ms-chip" style="--c: <?php echo $m['color']; ?>"><i class="fas <?php echo $m['icon']; ?>"></i><?php echo $m['one']; ?></span></td>
                    <td style="max-width:240px">
                        <div class="fw-semibold small text-truncate"><?php echo e($r['from_name'] !== '' ? $r['from_name'] : $r['from_email']); ?></div>
                        <div class="small text-white-50 text-truncate"><?php echo e($r['from_email']); ?><?php echo $r['reply_to'] !== '' && $r['reply_to'] !== $r['from_email'] ? ' → ' . e($r['reply_to']) : ''; ?></div>
                    </td>
                    <td class="ms-subject">
                        <div class="small fw-semibold text-truncate" style="max-width:420px"><?php echo e($r['subject'] !== '' ? $r['subject'] : '(bez předmětu)'); ?></div>
                        <div class="ms-snippet"><?php echo e($r['snippet']); ?></div>
                    </td>
                    <td class="small text-white-50 d-none d-lg-table-cell" style="max-width:260px">
                        <span class="ms-method me-1"><?php echo e($methodLabel[$r['method']] ?? $r['method']); ?></span><?php echo e($r['reason']); ?>
                    </td>
                    <td class="small text-white-50" style="white-space:nowrap"><?php echo $r['received_at'] ? date('d.m. H:i', strtotime((string)$r['received_at'])) : date('d.m. H:i', strtotime((string)$r['created_at'])); ?></td>
                    <td class="text-end">
                        <span class="ms-move">
                            <?php foreach ($meta as $cat => $mm): ?>
                            <button type="button" title="<?php echo $r['category'] === $cat ? 'Aktuálně: ' . $mm['one'] : 'Přeřadit: ' . $mm['one']; ?>" class="<?php echo $r['category'] === $cat ? 'is-cur' : 'ms-re'; ?>" style="--c: <?php echo $mm['color']; ?>"
                                data-log="<?php echo (int)$r['id']; ?>" data-cat="<?php echo $cat; ?>" data-from="<?php echo e($r['from_email']); ?>"><i class="fas <?php echo $mm['icon']; ?>"></i></button>
                            <?php endforeach; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php for ($p = max(1, $page - 4); $p <= min($pages, $page + 4); $p++): ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($qs(['p' => $p > 1 ? $p : ''])); ?>"><?php echo $p; ?></a></li>
        <?php endfor; ?>
    </ul></nav>
    <?php endif; ?>
</div>

<!-- ── Pravidla + jak to funguje ── -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="glass-panel border-secondary p-3 h-100">
            <h5 class="mb-1"><i class="fas fa-filter me-2 text-info"></i>Pravidla</h5>
            <div class="small text-white-50 mb-3">Mají přednost před vším ostatním. Vznikají samy, když v přehledu zprávu přeřadíš.</div>
            <form id="msRuleForm" class="d-flex gap-2 mb-3 flex-wrap">
                <input type="text" name="pattern" class="form-control form-control-sm" style="flex:1 1 200px" placeholder="jan@firma.cz nebo @firma.cz" required>
                <select name="category" class="form-select form-select-sm" style="width:auto">
                    <?php foreach ($meta as $cat => $m): ?><option value="<?php echo $cat; ?>"><?php echo $m['one']; ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-info"><i class="fas fa-plus me-1"></i>Přidat</button>
            </form>
            <?php if (!$rules): ?>
                <div class="small text-white-50">Zatím žádná pravidla.</div>
            <?php else: ?>
            <div class="d-grid gap-1" style="max-height:320px;overflow:auto">
                <?php foreach ($rules as $ru): $m = $meta[$ru['category']] ?? $meta['customer']; ?>
                <div class="d-flex align-items-center gap-2 small py-1 border-bottom border-secondary border-opacity-25">
                    <code class="flex-grow-1 text-truncate"><?php echo e($ru['pattern']); ?></code>
                    <span class="ms-chip" style="--c: <?php echo $m['color']; ?>"><i class="fas <?php echo $m['icon']; ?>"></i><?php echo $m['one']; ?></span>
                    <button class="btn btn-sm btn-link text-danger p-0 ms-rule-del" data-id="<?php echo (int)$ru['id']; ?>" title="Smazat"><i class="fas fa-xmark"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="glass-panel border-secondary p-3 h-100 small">
            <h5 class="mb-3"><i class="fas fa-circle-question me-2 text-info"></i>Jak třídič rozhoduje</h5>
            <ol class="ps-3 mb-3 d-grid gap-2">
                <li><b>Pravidla</b> pro odesílatele nebo doménu (vlevo).</li>
                <li><b>Klient v CRM</b> — adresa je u zákazníka nebo v e-shopu → vždy <b>Zákazník</b>.</li>
                <li><b>Znaky automatu a reklamy</b> — hlavičky hromadné pošty (List-Unsubscribe, Precedence), adresy <code>noreply@</code>, rozesílací systémy (Mailchimp, Ecomail…), slova jako sleva/akce/souhrn/faktura. Kontaktní formuláře z webu se poznají a jdou k zákazníkům.</li>
                <li><b>AI</b> (volitelně u schránky) rozhodne jen nejasné zprávy. Používá klíč z Nastavení → Integrace → AI<?php echo $aiKeySet ? '' : ' — <span class="text-warning">zatím není vyplněný</span>'; ?>.</li>
            </ol>
            <div class="p-2 rounded" style="background:rgba(48,209,88,.08);border:1px solid rgba(48,209,88,.25)">
                <i class="fas fa-shield-heart me-1" style="color:#30d158"></i>
                Když si třídič není jistý, nechá zprávu v Doručené poště. Přečtené/nepřečtené se tříděním nemění.
            </div>
            <div class="text-white-50 mt-3">
                Třídí se samo každé ~3 minuty, když je někdo v CRM přihlášený. Aby se třídilo i v noci, přidej na serveru cron:<br>
                <code>*/5 * * * * php <?php echo e(__DIR__); ?>/posta/cron.php</code>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: schránka ── -->
<div class="modal fade" id="msAccModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content glass-panel border-secondary" id="msAccForm" autocomplete="off">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="msAccTitle"><i class="fas fa-at me-2"></i>Přidat schránku</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="0">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small">E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="servis@applefix.cz" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Heslo ke schránce</label>
                        <input type="password" name="password" class="form-control" placeholder="heslo jako do webmailu" autocomplete="new-password">
                        <div class="form-text small d-none" id="msPassHint">Nech prázdné pro zachování uloženého hesla.</div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label small"><span class="ms-chip" style="--c:#30d158"><i class="fas fa-user"></i>Zákazníci</span></label>
                        <input type="text" name="folder_customer" class="form-control form-control-sm" value="INBOX">
                        <div class="form-text small">INBOX = zůstávají v Doručené poště</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small"><span class="ms-chip" style="--c:#ff9f0a"><i class="fas fa-tags"></i>Nabídky</span></label>
                        <input type="text" name="folder_offer" class="form-control form-control-sm" value="Nabídky">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small"><span class="ms-chip" style="--c:#64d2ff"><i class="fas fa-robot"></i>Roboti</span></label>
                        <input type="text" name="folder_robot" class="form-control form-control-sm" value="Roboti">
                    </div>
                </div>
                <div class="form-text small mb-2">Chybějící složky se na serveru založí samy.</div>

                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" name="use_ai" value="1" id="msUseAi">
                    <label class="form-check-label" for="msUseAi"><i class="fas fa-wand-magic-sparkles me-1"></i>U nejasných e-mailů se zeptat AI</label>
                    <div class="form-text small">Odesílatel, předmět a začátek textu jdou poskytovateli AI z Nastavení → Integrace<?php echo $aiKeySet ? '' : ' (klíč zatím není vyplněný, zapni až po jeho doplnění)'; ?>. Bez AI se nejasné zprávy nechávají v Doručené poště.</div>
                </div>

                <details class="mt-3">
                    <summary class="small text-white-50" style="cursor:pointer">Server (pro Forpsi není třeba měnit)</summary>
                    <div class="row g-3 mt-1">
                        <div class="col-md-5">
                            <label class="form-label small">IMAP server</label>
                            <input type="text" name="imap_host" class="form-control form-control-sm" value="imap.forpsi.com">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Port</label>
                            <input type="number" name="imap_port" class="form-control form-control-sm" value="993">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Šifrování</label>
                            <select name="imap_secure" class="form-select form-select-sm">
                                <option value="ssl">SSL</option>
                                <option value="tls">STARTTLS</option>
                                <option value="none">žádné</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Přihlašovací jméno</label>
                            <input type="text" name="username" class="form-control form-control-sm" placeholder="= e-mail">
                        </div>
                    </div>
                </details>
                <div id="msTestOut" class="small mt-3"></div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-info me-auto" id="msTestBtn"><i class="fas fa-plug me-1"></i>Otestovat spojení</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>Uložit</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal: náhled ── -->
<div class="modal fade" id="msPrevModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content glass-panel border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Náhled — posledních 30 e-mailů</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="msPrevBody"></div>
            <div class="modal-footer border-secondary">
                <span class="small text-white-50 me-auto">Náhled nic nepřesouvá. Špatně zařazené odesílatele oprav pravidlem.</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
                <button type="button" class="btn btn-success" id="msPrevEnable"><i class="fas fa-power-off me-1"></i>Vypadá to dobře — zapnout třídění</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: zapnutí ── -->
<div class="modal fade" id="msEnableModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-power-off me-2"></i>Zapnout třídění</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Roztřídit i poštu, která už v Doručené poště je?</p>
                <div class="d-grid gap-2">
                    <?php foreach ([0 => 'Ne, jen nově příchozí', 1 => 'Ano, za poslední den', 7 => 'Ano, za posledních 7 dní', 30 => 'Ano, za posledních 30 dní', 90 => 'Ano, za posledních 90 dní'] as $d => $lbl): ?>
                    <label class="form-check p-2 ps-5 rounded border border-secondary border-opacity-50">
                        <input class="form-check-input" type="radio" name="msBackfill" value="<?php echo $d; ?>" <?php echo $d === 7 ? 'checked' : ''; ?>>
                        <span class="form-check-label"><?php echo $lbl; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="small text-white-50 mt-2">Zákazníci zůstávají v Doručené poště v každém případě.</div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-success" id="msEnableGo"><i class="fas fa-check me-1"></i>Zapnout</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: přeřazení ── -->
<div class="modal fade" id="msReModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="msReTitle">Přeřadit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">Zpráva se přesune i na serveru. Co s další poštou od <b id="msReFrom"></b>?</p>
                <div class="d-grid gap-2">
                    <label class="form-check p-2 ps-5 rounded border border-secondary border-opacity-50"><input class="form-check-input" type="radio" name="msLearn" value="sender" checked><span class="form-check-label">Zapamatovat <b>tohoto odesílatele</b></span></label>
                    <label class="form-check p-2 ps-5 rounded border border-secondary border-opacity-50" id="msLearnDomainWrap"><input class="form-check-input" type="radio" name="msLearn" value="domain"><span class="form-check-label">Zapamatovat <b>celou doménu</b> <code id="msReDomain"></code></span></label>
                    <label class="form-check p-2 ps-5 rounded border border-secondary border-opacity-50"><input class="form-check-input" type="radio" name="msLearn" value="none"><span class="form-check-label">Jen tuto zprávu</span></label>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-primary" id="msReGo"><i class="fas fa-arrow-right-arrow-left me-1"></i>Přeřadit</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF = '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>';
    var META = <?php echo json_encode($meta, JSON_UNESCAPED_UNICODE); ?>;
    var FREEMAIL = <?php echo json_encode(crmMailFreemailDomains()); ?>;
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var api = function (data, done, btn) {
        data.csrf_token = CSRF;
        var html = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.busy || 'Pracuji…'); }
        $.post('posta/api.php', data, function (res) { done(res || {}); })
            .fail(function () { done({ success: false, message: 'Server neodpověděl — zkus to znovu.' }); })
            .always(function () { if (btn) { btn.disabled = false; btn.innerHTML = html; } });
    };
    // hláška přežije reload (po zapnutí / roztřídění se mění přehled i statistiky)
    var flash = function (msg) { try { sessionStorage.setItem('msFlash', msg); } catch (e) {} location.reload(); };
    try { var fm = sessionStorage.getItem('msFlash'); if (fm) { sessionStorage.removeItem('msFlash'); setTimeout(function () { showAlert(esc(fm), 'Třídění pošty'); }, 300); } } catch (e) {}
    var chip = function (cat) { var m = META[cat] || META.customer; return '<span class="ms-chip" style="--c:' + m.color + '"><i class="fas ' + m.icon + '"></i>' + esc(m.one) + '</span>'; };

    // počítadla + pruhy (náběh)
    document.querySelectorAll('.ms-lane-num').forEach(function (el) {
        var to = +el.dataset.count || 0, t0 = null;
        if (!to) { el.textContent = '0'; return; }
        var step = function (ts) { t0 = t0 || ts; var p = Math.min(1, (ts - t0) / 900); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))).toLocaleString('cs-CZ'); if (p < 1) requestAnimationFrame(step); };
        requestAnimationFrame(step);
    });
    setTimeout(function () { document.querySelectorAll('.ms-bar > span').forEach(function (b) { b.style.width = b.dataset.w; }); }, 80);

    // ── schránka: přidat / upravit / test / uložit ──
    var accModal = function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('msAccModal')); };
    var form = document.getElementById('msAccForm');
    var openAcc = function (acc) {
        form.reset();
        form.id.value = acc ? acc.id : 0;
        document.getElementById('msAccTitle').innerHTML = '<i class="fas fa-at me-2"></i>' + (acc ? 'Upravit schránku' : 'Přidat schránku');
        document.getElementById('msPassHint').classList.toggle('d-none', !acc);
        document.getElementById('msTestOut').innerHTML = '';
        if (acc) {
            ['email', 'imap_host', 'imap_port', 'imap_secure', 'username', 'folder_customer', 'folder_offer', 'folder_robot'].forEach(function (k) { if (form[k]) form[k].value = acc[k]; });
            form.use_ai.checked = !!acc.use_ai;
        }
        accModal().show();
    };
    ['msAddBtn', 'msAddBtn2'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', function () { openAcc(null); }); });
    document.querySelectorAll('.ms-edit').forEach(function (b) { b.addEventListener('click', function () { openAcc(JSON.parse(b.dataset.acc)); }); });
    var formData = function (action) {
        var d = { action: action };
        new FormData(form).forEach(function (v, k) { d[k] = v; });
        if (!form.use_ai.checked) delete d.use_ai;
        return d;
    };
    document.getElementById('msTestBtn').addEventListener('click', function () {
        var out = document.getElementById('msTestOut');
        this.dataset.busy = 'Připojuji…';
        api(formData('test'), function (r) {
            out.innerHTML = '<div class="p-2 rounded ' + (r.success ? 'text-success' : 'text-danger') + '" style="background:rgba(255,255,255,.04)"><i class="fas ' + (r.success ? 'fa-circle-check' : 'fa-triangle-exclamation') + ' me-1"></i>' + esc(r.message) + '</div>';
        }, this);
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type=submit]');
        btn.dataset.busy = 'Ukládám…';
        api(formData('save'), function (r) {
            if (!r.success) { document.getElementById('msTestOut').innerHTML = '<div class="text-danger"><i class="fas fa-triangle-exclamation me-1"></i>' + esc(r.message) + '</div>'; return; }
            location.reload();
        }, btn);
    });

    // ── smazat schránku ──
    document.querySelectorAll('.ms-del').forEach(function (b) {
        b.addEventListener('click', function () {
            showConfirm('Odebrat <b>' + esc(b.dataset.email) + '</b> z třídění? Pošta i složky na serveru zůstanou, smaže se jen přehled v CRM.', function () {
                api({ action: 'delete', id: b.dataset.id }, function (r) { if (r.success) location.reload(); else showAlert(esc(r.message)); });
            });
        });
    });

    // ── zapnout / vypnout ──
    var enableId = 0;
    var enableModal = function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('msEnableModal')); };
    var askEnable = function (id) { enableId = id; enableModal().show(); };
    document.querySelectorAll('.ms-toggle').forEach(function (t) {
        t.addEventListener('change', function () {
            if (t.checked) { t.checked = false; askEnable(t.dataset.id); return; }
            api({ action: 'enable', id: t.dataset.id, enabled: 0 }, function (r) { if (r.success) location.reload(); else { t.checked = true; showAlert(esc(r.message)); } });
        });
    });
    document.getElementById('msEnableGo').addEventListener('click', function () {
        var days = (document.querySelector('input[name=msBackfill]:checked') || {}).value || 0;
        this.dataset.busy = 'Třídím…';
        api({ action: 'enable', id: enableId, enabled: 1, backfill_days: days }, function (r) {
            enableModal().hide();
            flash(r.message || (r.success ? 'Zapnuto.' : 'Chyba'));
        }, this);
    });

    // ── roztřídit teď ──
    document.querySelectorAll('.ms-run').forEach(function (b) {
        b.addEventListener('click', function () {
            b.dataset.busy = 'Třídím…';
            api({ action: 'run', id: b.dataset.id }, function (r) {
                if (r.success) flash(r.message); else showAlert(esc(r.message));
            }, b);
        });
    });

    // ── náhled ──
    var prevId = 0;
    document.querySelectorAll('.ms-preview').forEach(function (b) {
        b.addEventListener('click', function () {
            prevId = b.dataset.id;
            var body = document.getElementById('msPrevBody');
            body.innerHTML = '<div class="text-center py-5 text-white-50"><span class="spinner-border mb-3"></span><div>Čtu poslední e-maily a třídím je…</div></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('msPrevModal')).show();
            api({ action: 'preview', id: prevId }, function (r) {
                if (!r.success) { body.innerHTML = '<div class="text-danger p-3"><i class="fas fa-triangle-exclamation me-1"></i>' + esc(r.message) + '</div>'; return; }
                var rows = r.rows || [];
                var counts = { customer: 0, offer: 0, robot: 0 };
                rows.forEach(function (x) { counts[x.category]++; });
                var h = '<div class="d-flex gap-2 flex-wrap mb-3">' + Object.keys(META).map(function (c) { return chip(c).replace('</span>', ' · ' + counts[c] + '</span>'); }).join('') + '</div>';
                h += '<div class="table-responsive"><table class="table table-dark table-sm align-middle mb-0"><thead><tr><th>Kategorie</th><th>Od</th><th>Předmět</th><th>Proč</th><th>Přišlo</th></tr></thead><tbody>';
                if (!rows.length) h += '<tr><td colspan="5" class="text-center text-white-50 py-4">Doručená pošta je prázdná.</td></tr>';
                rows.forEach(function (x) {
                    h += '<tr><td>' + chip(x.category) + '</td><td class="small" style="max-width:220px"><div class="text-truncate fw-semibold">' + esc(x.from_name || x.from_email) + '</div><div class="text-truncate text-white-50">' + esc(x.from_email) + '</div></td>'
                        + '<td class="small" style="max-width:320px"><div class="text-truncate">' + esc(x.subject || '(bez předmětu)') + '</div></td>'
                        + '<td class="small text-white-50" style="max-width:280px">' + esc(x.reason) + '</td><td class="small text-white-50" style="white-space:nowrap">' + esc(x.date) + '</td></tr>';
                });
                body.innerHTML = h + '</tbody></table></div>';
            });
        });
    });
    document.getElementById('msPrevEnable').addEventListener('click', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('msPrevModal')).hide();
        askEnable(prevId);
    });

    // ── přeřazení ──
    var re = {};
    document.querySelectorAll('.ms-re').forEach(function (b) {
        b.addEventListener('click', function () {
            re = { log: b.dataset.log, cat: b.dataset.cat, from: b.dataset.from };
            var dom = (re.from.split('@')[1] || '').toLowerCase();
            document.getElementById('msReTitle').innerHTML = 'Přeřadit → ' + chip(re.cat);
            document.getElementById('msReFrom').textContent = re.from || 'neznámého odesílatele';
            document.getElementById('msReDomain').textContent = '@' + dom;
            var free = !dom || FREEMAIL.indexOf(dom) !== -1;
            document.getElementById('msLearnDomainWrap').classList.toggle('d-none', free);
            document.querySelector('input[name=msLearn][value=sender]').checked = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('msReModal')).show();
        });
    });
    document.getElementById('msReGo').addEventListener('click', function () {
        var learn = (document.querySelector('input[name=msLearn]:checked') || {}).value || 'sender';
        this.dataset.busy = 'Přesouvám…';
        api({ action: 'reclassify', log_id: re.log, category: re.cat, learn: learn }, function (r) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('msReModal')).hide();
            if (r.success) { location.reload(); } else { showAlert(esc(r.message)); }
        }, this);
    });

    // ── pravidla ──
    document.getElementById('msRuleForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = this;
        api({ action: 'add_rule', pattern: f.pattern.value, category: f.category.value }, function (r) { if (r.success) location.reload(); else showAlert(esc(r.message)); }, f.querySelector('button'));
    });
    document.querySelectorAll('.ms-rule-del').forEach(function (b) {
        b.addEventListener('click', function () {
            api({ action: 'delete_rule', rule_id: b.dataset.id }, function (r) { if (r.success) location.reload(); else showAlert(esc(r.message)); });
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
