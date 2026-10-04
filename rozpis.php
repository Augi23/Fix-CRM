<?php
/**
 * ROZPIS SLUŽEB NA PRODEJNĚ — týdenní přehled, do kterého se zaměstnanci
 * zapisují sami. Pravidla a oprávnění drží rozpis/lib.php.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'rozpis/lib.php';
$afxPageTitle = 'Rozpis služeb';
require_once 'includes/header.php';

afxShiftEnsureSchema();

$branches = afxShiftVisibleBranches();
if (!$branches) {
    echo '<div class="container py-5"><div class="glass-panel border-secondary p-4 text-center">'
       . '<i class="fas fa-store-slash fa-2x text-white-50 mb-3 d-block"></i>'
       . 'Tvůj účet nemá přidělenou pobočku, takže rozpis není co ukázat. Ozvi se vedení.'
       . '</div></div>';
    require_once 'includes/footer.php';
    return;
}

// vybraná pobočka — z URL jen když na ni přihlášený vidí, jinak ta první jeho
$branchId = (int)($_GET['b'] ?? 0);
if (!$branchId || !afxShiftCanSeeBranch($branchId)) { $branchId = (int)$branches[0]['id']; }

$monday   = afxShiftMonday((string)($_GET['t'] ?? date('Y-m-d', strtotime('+1 day'))));
$days     = afxShiftWeekDays($monday);
$entries  = afxShiftEntries($branchId, $days[0], $days[6]);
$staff    = afxShiftStaff($branchId);
$hours    = afxShiftOpeningHours($branchId);
$meTech   = afxShiftCurrentTechId();
$canOther = afxShiftCanEditOthers();
$today    = date('Y-m-d');

$prev = (new DateTimeImmutable($monday))->modify('-7 day')->format('Y-m-d');
$next = (new DateTimeImmutable($monday))->modify('+7 day')->format('Y-m-d');
$link = static fn(array $q): string => 'rozpis.php?' . http_build_query($q);

$dayNames  = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
$dayShort  = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];

/** Odpracované minuty za týden podle člověka — na souhrn dole. */
$weekMinutes = [];
foreach ($entries as $list) {
    foreach ($list as $e) {
        $m = (strtotime((string)$e['time_to']) - strtotime((string)$e['time_from'])) / 60;
        $k = (int)$e['tech_id'];
        $weekMinutes[$k] = ($weekMinutes[$k] ?? 0) + max(0, (int)$m);
    }
}
arsort($weekMinutes);
?>

<div class="container-fluid px-3 px-md-4 py-4 rz">

    <!-- ── Hlavička: pobočka, týden ──────────────────────────────────────── -->
    <div class="glass-panel border-secondary p-3 p-md-4 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="me-auto">
                <h4 class="mb-1"><i class="fas fa-calendar-days me-2 text-info"></i>Rozpis služeb</h4>
                <div class="small text-white-50">
                    Kdo kdy stojí na prodejně. Zapiš se sám — stačí den a od kolika do kolika.
                </div>
            </div>

            <?php if (count($branches) > 1): ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="Pobočka">
                <?php foreach ($branches as $b): ?>
                    <a class="btn rz-branch<?php echo (int)$b['id'] === $branchId ? ' is-active' : ''; ?>"
                       <?php echo (int)$b['id'] === $branchId ? 'aria-current="page"' : ''; ?>
                       href="<?php echo e($link(['b' => (int)$b['id'], 't' => $monday])); ?>">
                        <?php echo e(crmBranchShortLabel((int)$b['id'])); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-outline-light" href="<?php echo e($link(['b' => $branchId, 't' => $prev])); ?>"
                   aria-label="Předchozí týden"><i class="fas fa-chevron-left"></i></a>
                <a class="btn btn-sm btn-outline-light" href="<?php echo e($link(['b' => $branchId, 't' => date('Y-m-d')])); ?>">Tento týden</a>
                <a class="btn btn-sm btn-outline-light" href="<?php echo e($link(['b' => $branchId, 't' => $next])); ?>"
                   aria-label="Další týden"><i class="fas fa-chevron-right"></i></a>
            </div>
        </div>

        <div class="small text-white-50 mt-3 d-flex flex-wrap gap-3">
            <span><i class="fas fa-calendar me-1"></i>
                <?php echo date('j. n.', strtotime($days[0])) . ' – ' . date('j. n. Y', strtotime($days[6])); ?></span>
            <span><i class="fas fa-lock me-1"></i>Zapsat se jde nejpozději do <b>půlnoci předchozího dne</b>.</span>
            <?php if ($canOther): ?><span class="text-info"><i class="fas fa-user-shield me-1"></i>Jako vedení můžeš zapisovat i za ostatní a po uzávěrce.</span><?php endif; ?>
        </div>
    </div>

    <!-- ── Týden ─────────────────────────────────────────────────────────── -->
    <div class="rz-week mb-4">
        <?php foreach ($days as $i => $d):
            $list = $entries[$d] ?? [];
            [$open, $why] = afxShiftDayOpen($d);
            $canAdd  = $open || $canOther;
            $isToday = ($d === $today);
            $isPast  = ($d < $today);
            $mine    = null;
            foreach ($list as $e) { if ((int)$e['tech_id'] === $meTech) { $mine = $e; break; } }
        ?>
        <section class="rz-day<?php echo $isToday ? ' is-today' : ''; ?><?php echo $isPast ? ' is-past' : ''; ?>" data-label="<?php echo e($dayNames[$i] . ' ' . date('j. n.', strtotime($d))); ?>">
            <header class="rz-day-head">
                <div class="rz-dow"><?php echo $dayShort[$i]; ?><span class="d-none d-xl-inline"><?php echo mb_substr($dayNames[$i], 2); ?></span><?php if ($isToday): ?> <span class="rz-today-pill">dnes</span><?php endif; ?></div>
                <div class="rz-date"><?php echo date('j. n.', strtotime($d)); ?></div>
            </header>

            <?php if (!empty($hours[$i])): ?>
                <div class="rz-hours" title="Otvírací doba"><i class="fas fa-store"></i><?php echo e($hours[$i]); ?></div>
            <?php endif; ?>

            <ul class="rz-list">
                <?php foreach ($list as $e):
                    $isMine = ((int)$e['tech_id'] === $meTech);
                    $canEdit = ($isMine && $open) || $canOther;
                    $role = AFX_SHIFT_ROLE_LABEL[(string)$e['tech_role']] ?? (string)$e['tech_role'];
                ?>
                <li class="rz-chip<?php echo $isMine ? ' is-mine' : ''; ?>" style="--c:<?php echo e(afxShiftColor((int)$e['tech_id'])); ?>">
                    <span class="rz-dot" aria-hidden="true"></span>
                    <div class="rz-chip-body">
                        <div class="rz-name"><?php echo e((string)($e['tech_name'] ?? '—')); ?><?php if ($isMine): ?> <span class="rz-you">ty</span><?php endif; ?></div>
                        <div class="rz-time"><?php echo substr((string)$e['time_from'], 0, 5); ?>–<?php echo substr((string)$e['time_to'], 0, 5); ?>
                            <span class="rz-role"><?php echo e($role); ?></span></div>
                        <?php if (trim((string)$e['note']) !== ''): ?>
                            <div class="rz-note"><?php echo e((string)$e['note']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="rz-acts">
                        <button type="button" class="rz-ico rz-edit" title="Upravit"
                                data-id="<?php echo (int)$e['id']; ?>" data-date="<?php echo e($d); ?>"
                                data-tech="<?php echo (int)$e['tech_id']; ?>"
                                data-from="<?php echo substr((string)$e['time_from'], 0, 5); ?>"
                                data-to="<?php echo substr((string)$e['time_to'], 0, 5); ?>"
                                data-note="<?php echo e((string)$e['note']); ?>"><i class="fas fa-pen"></i></button>
                        <button type="button" class="rz-ico rz-del" title="Smazat"
                                data-id="<?php echo (int)$e['id']; ?>"
                                data-who="<?php echo e((string)($e['tech_name'] ?? '')); ?>"><i class="fas fa-xmark"></i></button>
                    </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!$list): ?>
                <div class="rz-empty"><?php echo $isPast ? 'Nikdo zapsán' : 'Zatím nikdo'; ?></div>
            <?php endif; ?>

            <?php if ($canAdd): ?>
                <button type="button" class="rz-add<?php echo $mine ? ' is-set' : ''; ?>"
                        data-date="<?php echo e($d); ?>" data-day="<?php echo e($dayNames[$i] . ' ' . date('j. n.', strtotime($d))); ?>"
                        data-hours="<?php echo e($hours[$i] ?? ''); ?>"
                        data-id="<?php echo $mine ? (int)$mine['id'] : 0; ?>"
                        data-from="<?php echo $mine ? substr((string)$mine['time_from'], 0, 5) : ''; ?>"
                        data-to="<?php echo $mine ? substr((string)$mine['time_to'], 0, 5) : ''; ?>"
                        data-note="<?php echo $mine ? e((string)$mine['note']) : ''; ?>">
                    <i class="fas <?php echo $mine ? 'fa-pen' : 'fa-plus'; ?>"></i>
                    <?php echo $mine ? 'Upravit svůj čas' : 'Zapsat se'; ?>
                </button>
            <?php else: ?>
                <div class="rz-locked" title="<?php echo e($why); ?>"><i class="fas fa-lock"></i>Uzavřeno</div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>

    <!-- ── Souhrn týdne ──────────────────────────────────────────────────── -->
    <?php if ($weekMinutes): ?>
    <div class="glass-panel border-secondary p-3 p-md-4">
        <div class="small text-white-50 mb-3"><i class="fas fa-chart-simple me-2"></i>Hodiny v tomhle týdnu</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($weekMinutes as $tid => $min):
                $nm = '';
                foreach ($staff as $s) { if ((int)$s['id'] === (int)$tid) { $nm = (string)$s['name']; break; } }
                if ($nm === '') { foreach ($entries as $l) { foreach ($l as $e) { if ((int)$e['tech_id'] === (int)$tid) { $nm = (string)$e['tech_name']; break 2; } } } }
            ?>
                <span class="rz-sum" style="--c:<?php echo e(afxShiftColor((int)$tid)); ?>">
                    <span class="rz-dot" aria-hidden="true"></span><?php echo e($nm ?: '—'); ?>
                    <b><?php echo number_format($min / 60, ($min % 60 ? 1 : 0), ',', ' '); ?> h</b>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Dialog zápisu ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="rzModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content glass-panel border-secondary" id="rzForm" autocomplete="off">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-calendar-check me-2 text-info"></i><span id="rzDay">Zapsat se</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="work_date" id="rzDate">
                <input type="hidden" name="entry_id" id="rzEntryId" value="0">

                <?php if ($canOther && $staff): ?>
                <div class="mb-3">
                    <label class="form-label small" for="rzTech">Zaměstnanec</label>
                    <select class="form-select" name="tech_id" id="rzTech">
                        <?php foreach ($staff as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === $meTech ? 'selected' : ''; ?>>
                                <?php echo e((string)$s['name']); ?><?php echo (int)$s['id'] === $meTech ? ' (ty)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small" for="rzFrom">Od</label>
                        <input type="time" class="form-control" name="time_from" id="rzFrom" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="rzTo">Do</label>
                        <input type="time" class="form-control" name="time_to" id="rzTo" required>
                    </div>
                </div>
                <div class="form-text small mt-2" id="rzHint"></div>

                <div class="mt-3">
                    <label class="form-label small" for="rzNote">Poznámka <span class="text-white-50">(nepovinné)</span></label>
                    <input type="text" class="form-control" name="note" id="rzNote" maxlength="120"
                           placeholder="např. dopoledne na výdeji">
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-danger me-auto d-none" id="rzDelete"><i class="fas fa-trash me-1"></i>Smazat zápis</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-info" data-busy="Ukládám…"><i class="fas fa-check me-1"></i>Uložit</button>
            </div>
        </form>
    </div>
</div>

<style>
/* fix-crm-v2.css nastavuje p, span, label… na 18px !important pro celé CRM.
   V kompaktní kartě rozpisu by to rozbilo řádek (odznak „ty" i role přetekly),
   proto si tyhle drobné texty drží velikost samy. */
.rz .rz-you { font-size: 11px !important; }
.rz .rz-role { font-size: 12.5px !important; }
.rz .rz-dow span { font-size: inherit !important; }
.rz .rz-dow .rz-today-pill { font-size: 12px !important; vertical-align: 2px; margin-left: 4px; }
.rz .rz-sum { font-size: 12.5px !important; }
.rz-branch { border: 1px solid rgba(13,202,240,.35); color: rgba(255,255,255,.7); background: transparent; }
.rz-branch:hover { color: #fff; border-color: #0dcaf0; background: rgba(13,202,240,.1); }
.rz-branch.is-active { background: #0dcaf0; border-color: #0dcaf0; color: #04222a; font-weight: 700; }
.rz-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 12px; margin-top: 16px; }
@media (max-width: 1400px) { .rz-week { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 992px)  { .rz-week { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px)  { .rz-week { grid-template-columns: minmax(0, 1fr); } }

.rz-day { display: flex; flex-direction: column; min-height: 190px; padding: 12px;
    border: 1px solid rgba(255,255,255,.10); border-radius: 16px;
    /* plný tmavý podklad — tečkované pozadí CRM přes něj neprosvítá */
    background: rgba(28,28,30,.94); transition: border-color .2s ease, background .2s ease; }
.rz-day:hover { border-color: rgba(255,255,255,.2); }
.rz-day.is-past > * { opacity: .5; }
.rz-day.is-today { border-color: rgba(13,202,240,.55); background: rgb(22,34,38);
    box-shadow: 0 0 0 1px rgba(13,202,240,.25) inset; }

.rz-day-head { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; margin-bottom: 8px; }
.rz-dow { font-weight: 700; font-size: 17px; letter-spacing: -.01em; }
.rz-dow span { font-weight: 600; opacity: .85; }
.rz-date { font-size: 17px; font-weight: 700; letter-spacing: -.01em; white-space: nowrap; font-variant-numeric: tabular-nums; }
.rz-today-pill { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    padding: 2px 7px; border-radius: 999px; background: #0dcaf0; color: #04222a; }

.rz-hours { display: flex; align-items: center; justify-content: center; gap: 7px;
    font-size: 15px; font-weight: 700; letter-spacing: -.01em; font-variant-numeric: tabular-nums;
    color: rgba(255,255,255,.78); margin: 2px 0 12px; }
.rz-hours i { font-size: 13px; color: rgba(255,255,255,.4); }

.rz-list { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.rz-chip { position: relative; display: flex; align-items: flex-start; gap: 8px; padding: 7px 8px;
    border-radius: 11px; background: color-mix(in srgb, var(--c) 16%, transparent);
    border: 1px solid color-mix(in srgb, var(--c) 34%, transparent); }
.rz-chip.is-mine { border-color: color-mix(in srgb, var(--c) 70%, transparent);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--c) 30%, transparent); }
.rz-dot { width: 10px; height: 10px; border-radius: 999px; background: var(--c); flex: none; margin-top: 4px; }
.rz-chip-body { min-width: 0; flex: 1; }
.rz-name { font-size: 15px; font-weight: 600; line-height: 1.25;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rz-you { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 1px 5px; border-radius: 999px; background: rgba(255,255,255,.16); vertical-align: 1px; }
.rz-time { font-size: 14.5px; font-variant-numeric: tabular-nums; color: rgba(255,255,255,.78); }
.rz-role { font-size: 12.5px; color: rgba(255,255,255,.38); margin-left: 4px; }
.rz-note { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 2px; word-break: break-word; }

.rz-acts { position: absolute; top: 4px; right: 4px; display: flex; gap: 2px; opacity: 0;
    padding: 1px; border-radius: 8px; background: rgba(20,20,22,.85); transition: opacity .15s ease; }
.rz-chip:hover .rz-acts, .rz-chip:focus-within .rz-acts { opacity: 1; }
@media (hover: none) { .rz-acts { opacity: 1; } }
.rz-ico { background: none; border: 0; padding: 4px 6px; border-radius: 7px; font-size: 13px;
    color: rgba(255,255,255,.55); cursor: pointer; }
.rz-ico:hover { background: rgba(255,255,255,.12); color: #fff; }
.rz-del:hover { background: rgba(255,69,58,.22); color: #ff7b72; }

.rz-empty { font-size: 14px; color: rgba(255,255,255,.3); padding: 6px 0 10px; }
.rz-add { margin-top: auto; width: 100%; display: inline-flex; align-items: center; justify-content: center;
    gap: 7px; padding: 9px; font-size: 15px; font-weight: 600; cursor: pointer;
    border-radius: 10px; border: 1px dashed rgba(255,255,255,.22);
    background: transparent; color: rgba(255,255,255,.72); transition: all .18s ease; }
.rz-add:hover { border-color: #0dcaf0; color: #0dcaf0; background: rgba(13,202,240,.09); }
.rz-add.is-set { border-style: solid; border-color: rgba(255,255,255,.16); background: rgba(255,255,255,.05); }
.rz-locked { margin-top: auto; display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 9px; font-size: 14px; color: rgba(255,255,255,.28); }

.rz-sum { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 999px;
    font-size: 12.5px; background: color-mix(in srgb, var(--c) 14%, transparent);
    border: 1px solid color-mix(in srgb, var(--c) 30%, transparent); }
.rz-sum b { font-variant-numeric: tabular-nums; }
@media (prefers-reduced-motion: reduce) { .rz-day, .rz-add, .rz-acts { transition: none; } }
</style>

<script>
(function () {
    var CSRF = '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>';
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var BRANCH = <?php echo (int)$branchId; ?>;
    var form = document.getElementById('rzForm');
    var modalEl = document.getElementById('rzModal');
    var modal = function () { return bootstrap.Modal.getOrCreateInstance(modalEl); };

    var post = function (data, done, btn) {
        data.csrf_token = CSRF;
        var html = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.busy || 'Pracuji…'); }
        $.post('rozpis/api.php', data, function (r) { done(r || {}); })
            .fail(function () { done({ success: false, message: 'Server neodpověděl — zkus to znovu.' }); })
            .always(function () { if (btn) { btn.disabled = false; btn.innerHTML = html; } });
    };

    /** „10:00 – 20:00" z otvírací doby → předvyplněné časy, ať se nemusí psát. */
    var fromHours = function (txt) {
        var m = (txt || '').match(/(\d{1,2}:\d{2})\s*[–-]\s*(\d{1,2}:\d{2})/);
        if (!m) { return null; }
        var pad = function (t) { return t.length === 4 ? '0' + t : t; };
        return [pad(m[1]), pad(m[2])];
    };

    var open = function (o) {
        form.reset();
        document.getElementById('rzDate').value = o.date;
        document.getElementById('rzEntryId').value = o.id || 0;
        document.getElementById('rzDay').textContent = o.day || 'Zapsat se';
        var def = fromHours(o.hours);
        document.getElementById('rzFrom').value = o.from || (def ? def[0] : '10:00');
        document.getElementById('rzTo').value = o.to || (def ? def[1] : '18:00');
        document.getElementById('rzNote').value = o.note || '';
        document.getElementById('rzHint').textContent = o.hours ? ('Otevřeno ' + o.hours) : '';
        var tech = document.getElementById('rzTech');
        if (tech) {
            if (o.tech) { tech.value = String(o.tech); }
            tech.dataset.orig = tech.value;
        }
        var del = document.getElementById('rzDelete');
        del.classList.toggle('d-none', !(Number(o.id) > 0));
        del.dataset.id = o.id || 0;
        modal().show();
    };

    document.querySelectorAll('.rz-add').forEach(function (b) {
        b.addEventListener('click', function () {
            open({ date: b.dataset.date, day: b.dataset.day, hours: b.dataset.hours,
                   id: b.dataset.id, from: b.dataset.from, to: b.dataset.to, note: b.dataset.note });
        });
    });
    document.querySelectorAll('.rz-edit').forEach(function (b) {
        b.addEventListener('click', function () {
            var day = b.closest('.rz-day');
            open({ date: b.dataset.date, id: b.dataset.id, tech: b.dataset.tech,
                   day: day ? day.dataset.label : '',
                   from: b.dataset.from, to: b.dataset.to, note: b.dataset.note,
                   hours: (day && day.querySelector('.rz-hours')) ? day.querySelector('.rz-hours').textContent : '' });
        });
    });
    document.querySelectorAll('.rz-del').forEach(function (b) {
        b.addEventListener('click', function () {
            showConfirm('Smazat zápis — ' + esc(b.dataset.who) + '?', function () {
                post({ action: 'delete', id: b.dataset.id }, function (r) {
                    if (r.success) { location.reload(); } else { showAlert(esc(r.message)); }
                });
            });
        });
    });

    var techSel = document.getElementById('rzTech');
    if (techSel) {
        techSel.addEventListener('change', function () {
            var del = document.getElementById('rzDelete');
            if (techSel.value !== techSel.dataset.orig) { del.classList.add('d-none'); }
            else if (Number(del.dataset.id) > 0) { del.classList.remove('d-none'); }
        });
    }
    document.getElementById('rzDelete').addEventListener('click', function () {
        var b = this;
        showConfirm('Smazat tenhle zápis?', function () {
            post({ action: 'delete', id: b.dataset.id }, function (r) {
                if (r.success) { location.reload(); } else { showAlert(esc(r.message)); }
            });
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var tech = document.getElementById('rzTech');
        post({
            action: 'save', branch_id: BRANCH,
            tech_id: tech ? tech.value : 0,
            work_date: document.getElementById('rzDate').value,
            time_from: document.getElementById('rzFrom').value,
            time_to: document.getElementById('rzTo').value,
            note: document.getElementById('rzNote').value
        }, function (r) {
            if (r.success) { location.reload(); } else { showAlert(esc(r.message)); }
        }, form.querySelector('button[type=submit]'));
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
