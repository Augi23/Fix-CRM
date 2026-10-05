<?php
/**
 * ROZPIS SLUŽEB NA PRODEJNĚ — týdenní přehled, do kterého se zaměstnanci
 * zapisují sami. Pravidla a oprávnění drží rozpis/lib.php.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'rozpis/lib.php';
require_once 'upozorneni/lib.php';
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

/** Pro dialog zápisu: otvírací doba a kdo je kdy zapsaný (časová osa, návrh díry). */
$rzDays = [];
foreach ($days as $i => $d) {
    $cov = afxShiftCoverage($branchId, $d, $entries[$d] ?? []);
    $rzDays[$d] = [
        'open'    => $cov['open'],
        'hours'   => $cov['hours'],
        'label'   => $dayNames[$i] . ' ' . date('j. n.', strtotime($d)),
        'canSelf' => afxShiftDayOpen($d)[0],
        'entries' => array_map(static fn($e) => [
            'id' => (int)$e['id'], 'tech' => (int)$e['tech_id'], 'name' => (string)($e['tech_name'] ?? '—'),
            'from' => substr((string)$e['time_from'], 0, 5), 'to' => substr((string)$e['time_to'], 0, 5),
            'note' => (string)$e['note'], 'color' => afxShiftColor((int)$e['tech_id']),
        ], $entries[$d] ?? []),
    ];
}
$rzStaff = array_map(static fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']], $staff);

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

            <a class="btn btn-sm rz-bell" href="upozorneni.php" title="Připomínky směn a další upozornění">
                <i class="fas fa-bell me-1"></i>Upozornění
            </a>

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
            <span><i class="fas fa-bell me-1"></i>Před směnou ti přijde připomínka — předstih si nastavíš v <a href="upozorneni.php" class="link-info">Upozorněních</a>.</span>
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
            // chytré pokrytí: nikdo / díra v otvírací době / zavřeno
            $cov = afxShiftCoverage($branchId, $d, $list);
            $covState = !$cov['open'] ? 'closed' : (!$list ? 'empty' : ($cov['gaps'] ? 'gap' : 'ok'));
        ?>
        <section class="rz-day<?php echo $isToday ? ' is-today' : ''; ?><?php echo $isPast ? ' is-past' : ''; ?><?php echo (!$isPast && $covState === 'empty') ? ' is-uncovered' : ''; ?>" data-label="<?php echo e($dayNames[$i] . ' ' . date('j. n.', strtotime($d))); ?>">
            <header class="rz-day-head">
                <div class="rz-dow"><?php echo $dayShort[$i]; ?><span class="d-none d-xl-inline"><?php echo mb_substr($dayNames[$i], 2); ?></span><?php if ($isToday): ?> <span class="rz-today-pill">dnes</span><?php endif; ?></div>
                <div class="rz-date"><?php echo date('j. n.', strtotime($d)); ?></div>
            </header>

            <?php if (!empty($hours[$i])): ?>
                <div class="rz-hours" title="Otvírací doba"><i class="fas fa-store"></i><?php echo e($hours[$i]); ?></div>
            <?php endif; ?>

            <?php if (!$isPast && $covState === 'empty'): ?>
                <div class="rz-cov is-empty" title="Na tento den se zatím nikdo nezapsal"><i class="fas fa-user-slash"></i>Nikdo zapsaný</div>
            <?php elseif (!$isPast && $covState === 'gap'): ?>
                <div class="rz-cov is-gap" title="Část otvírací doby bez obsluhy"><i class="fas fa-hourglass-half"></i>Chybí <?php echo e(afxNotifyGapsText($cov['gaps'])); ?></div>
            <?php elseif (!$isPast && $covState === 'ok' && $list): ?>
                <div class="rz-cov is-ok" title="Celá otvírací doba je pokrytá"><i class="fas fa-circle-check"></i>Pokryto</div>
            <?php endif; ?>

            <?php if ($cov['open'] && $cov['hours']):
                // pruh otvírací doby: kolik lidí je kdy na prodejně (0 / 1 / 2+)
                [$bo, $bc] = $cov['hours'];
                $pts = [$bo, $bc];
                foreach ($list as $e) {
                    foreach ([afxNotifyTimeToMin((string)$e['time_from']), afxNotifyTimeToMin((string)$e['time_to'])] as $m) {
                        if ($m !== null && $m > $bo && $m < $bc) { $pts[] = $m; }
                    }
                }
                $pts = array_values(array_unique($pts)); sort($pts);
            ?>
                <div class="rz-bar" role="img" aria-label="Obsazení otvírací doby">
                    <?php for ($k = 0; $k < count($pts) - 1; $k++):
                        $a = $pts[$k]; $z = $pts[$k + 1]; $n = 0;
                        foreach ($list as $e) {
                            $ef = afxNotifyTimeToMin((string)$e['time_from']); $et = afxNotifyTimeToMin((string)$e['time_to']);
                            if ($ef !== null && $et !== null && $ef <= $a && $et >= $z) { $n++; }
                        }
                    ?><span class="rz-seg n<?php echo min($n, 2); ?>" style="flex:<?php echo $z - $a; ?>"
                          title="<?php echo afxNotifyMinToTime($a) . '–' . afxNotifyMinToTime($z) . ': ' . ($n === 0 ? 'nikdo' : $n . ' ' . ($n === 1 ? 'člověk' : ($n < 5 ? 'lidé' : 'lidí'))); ?>"></span><?php endfor; ?>
                </div>
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

            <?php if ($canOther): ?>
                <?php /* vedení: přidat můžou kohokoli, i když už někdo zapsaný je (víc lidí naráz, časy se můžou krýt) */ ?>
                <button type="button" class="rz-add<?php echo $covState === 'ok' ? ' is-set' : ''; ?>" data-date="<?php echo e($d); ?>" data-mode="add">
                    <i class="fas fa-user-plus"></i><?php echo $covState === 'gap' ? 'Doplnit směnu' : 'Přidat člověka'; ?>
                </button>
            <?php elseif ($canAdd && $mine): ?>
                <button type="button" class="rz-add is-set" data-date="<?php echo e($d); ?>" data-mode="edit" data-id="<?php echo (int)$mine['id']; ?>">
                    <i class="fas fa-pen"></i>Upravit svůj čas
                </button>
            <?php elseif ($canAdd): ?>
                <button type="button" class="rz-add" data-date="<?php echo e($d); ?>" data-mode="add">
                    <i class="fas fa-plus"></i><?php echo $covState === 'gap' ? 'Zapsat se na chybějící čas' : 'Zapsat se'; ?>
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

                <!-- časová osa dne: otvírací doba, kdo už je zapsaný, navrhovaný čas -->
                <div class="rz-tl mb-3" id="rzTimeline" aria-live="polite"></div>
                <div class="rz-gapchips mb-3" id="rzGaps"></div>

                <?php if ($canOther && $staff): ?>
                <div class="mb-3">
                    <label class="form-label small" for="rzTech">Zaměstnanec</label>
                    <select class="form-select" name="tech_id" id="rzTech">
                        <?php foreach ($staff as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo e((string)$s['name']); ?><?php echo (int)$s['id'] === $meTech ? ' (ty)' : ''; ?></option>
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
                <div class="rz-sum-live mt-2" id="rzHint"></div>

                <div class="mt-3">
                    <label class="form-label small" for="rzNote">Poznámka <span class="text-white-50">(nepovinné)</span></label>
                    <input type="text" class="form-control" name="note" id="rzNote" maxlength="120"
                           placeholder="např. dopoledne na výdeji">
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-danger me-auto" id="rzDelete" style="display:none"><i class="fas fa-trash me-1"></i>Smazat zápis</button>
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
/* pruh obsazení otvírací doby v kartě dne */
.rz-bar { display: flex; height: 6px; border-radius: 999px; overflow: hidden; margin: -2px 2px 12px; gap: 1px;
    background: rgba(255,255,255,.06); }
.rz-seg { min-width: 2px; }
.rz-seg.n0 { background: repeating-linear-gradient(135deg, rgba(255,69,58,.75) 0 4px, rgba(255,69,58,.35) 4px 8px); }
.rz-seg.n1 { background: rgba(48,209,88,.75); }
.rz-seg.n2 { background: linear-gradient(90deg, #30d158, #0dcaf0); }
.rz-day.is-past .rz-bar { opacity: .5; }

/* dialog: časová osa dne */
.rz .rz-tl *, #rzModal .rz-tl * { font-size: 12px !important; }
#rzModal .rz-gapchips button, #rzModal .rz-sum-live, #rzModal .rz-sum-live * { font-size: 13.5px !important; }
.rz-tl { position: relative; padding: 10px 12px 6px; border-radius: 14px; background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08); }
.rz-tl-scale { position: relative; height: 16px; color: rgba(255,255,255,.45); font-variant-numeric: tabular-nums; }
.rz-tl-scale span { position: absolute; transform: translateX(-50%); white-space: nowrap; }
.rz-tl-scale span:first-child { transform: none; } .rz-tl-scale span:last-child { transform: translateX(-100%); }
.rz-tl-track { position: relative; height: 22px; margin: 3px 0; border-radius: 7px; }
.rz-tl-open { position: absolute; top: 0; bottom: 0; border-radius: 7px; background: rgba(255,255,255,.06);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.08); }
.rz-tl-gap { position: absolute; top: 0; bottom: 0; border-radius: 6px;
    background: repeating-linear-gradient(135deg, rgba(255,69,58,.4) 0 5px, rgba(255,69,58,.15) 5px 10px); }
.rz-tl-bar { position: absolute; top: 2px; bottom: 2px; border-radius: 6px; display: flex; align-items: center; padding: 0 7px;
    background: color-mix(in srgb, var(--c) 55%, rgba(20,20,22,.6)); border: 1px solid color-mix(in srgb, var(--c) 80%, transparent);
    color: #fff; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.rz-tl-bar.is-new { --c: #0dcaf0; background: rgba(13,202,240,.18); border: 1.5px dashed #0dcaf0; color: #8be9ff;
    transition: left .2s ease, width .2s ease; }
.rz-tl-bar.is-bad { --c: #ff453a; border-color: #ff453a; background: rgba(255,69,58,.18); color: #ff8a80; }
.rz-tl-label { color: rgba(255,255,255,.45); margin: 4px 0 2px; }
.rz-gapchips { display: flex; flex-wrap: wrap; gap: 6px; }
.rz-gapchips:empty { display: none; }
.rz-gapchips button { border: 1px solid rgba(255,214,10,.45); background: rgba(255,214,10,.10); color: #ffd60a;
    border-radius: 999px; padding: 5px 12px; font-weight: 600; cursor: pointer; }
.rz-gapchips button:hover, .rz-gapchips button.is-on { background: #ffd60a; color: #1c1c1e; }
.rz-sum-live { padding: 8px 12px; border-radius: 11px; line-height: 1.4; }
.rz-sum-live:empty { display: none; }
.rz-sum-live.ok { background: rgba(48,209,88,.10); color: #6ee7a0; border: 1px solid rgba(48,209,88,.25); }
.rz-sum-live.gap { background: rgba(255,214,10,.08); color: #ffd60a; border: 1px solid rgba(255,214,10,.25); }
.rz-sum-live.bad { background: rgba(255,69,58,.10); color: #ff8a80; border: 1px solid rgba(255,69,58,.3); }

/* chytré pokrytí dne (upozorneni/lib.php → afxShiftCoverage) */
.rz .rz-cov { font-size: 13px !important; }
.rz-cov { display: flex; align-items: center; justify-content: center; gap: 6px; margin: -4px 0 10px; padding: 4px 8px;
    border-radius: 999px; font-weight: 650; letter-spacing: -.005em; }
.rz-cov.is-empty { color: #ff8a80; background: rgba(255,69,58,.14); border: 1px solid rgba(255,69,58,.35); }
.rz-cov.is-gap { color: #ffd60a; background: rgba(255,214,10,.10); border: 1px solid rgba(255,214,10,.28); }
.rz-cov.is-ok { color: rgba(48,209,88,.9); background: rgba(48,209,88,.08); border: 1px solid rgba(48,209,88,.2); }
.rz-day.is-uncovered:not(.is-past) { border-color: rgba(255,69,58,.45); box-shadow: 0 0 0 1px rgba(255,69,58,.15) inset, 0 0 24px rgba(255,69,58,.08); }
.rz-day.is-uncovered .rz-add { border-color: rgba(255,69,58,.5); color: #ff8a80; }
.rz-day.is-uncovered .rz-add:hover { background: rgba(255,69,58,.12); color: #fff; }
.rz-bell { border: 1px solid rgba(255,214,10,.35); color: #ffd60a; background: rgba(255,214,10,.06); }
.rz-bell:hover { border-color: #ffd60a; color: #1c1c1e; background: #ffd60a; }
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

    var DAYS = <?php echo json_encode($rzDays, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var STAFF = <?php echo json_encode($rzStaff, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var ME = <?php echo (int)$meTech; ?>;
    var CAN_OTHER = <?php echo $canOther ? 'true' : 'false'; ?>;
    var techSel = document.getElementById('rzTech');
    var fromEl = document.getElementById('rzFrom'), toEl = document.getElementById('rzTo');
    var cur = null;   // { date, id } právě otevřený dialog

    var toMin = function (t) { var m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
    var toHm = function (m) { m = Math.max(0, Math.min(1440, m)); return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2); };
    var merge = function (iv) {
        iv = iv.filter(function (x) { return x[1] > x[0]; }).sort(function (a, b) { return a[0] - b[0]; });
        var out = [];
        iv.forEach(function (x) { if (out.length && x[0] <= out[out.length - 1][1]) { out[out.length - 1][1] = Math.max(out[out.length - 1][1], x[1]); } else { out.push([x[0], x[1]]); } });
        return out;
    };
    /** Nepokryté úseky otvírací doby [o, c] intervaly iv. */
    var gapsOf = function (o, c, iv) {
        var gaps = [], at = o;
        merge(iv).forEach(function (x) {
            if (x[1] <= at || x[0] >= c) { return; }
            if (x[0] > at) { gaps.push([at, Math.min(x[0], c)]); }
            at = Math.max(at, x[1]);
        });
        if (at < c) { gaps.push([at, c]); }
        return gaps.filter(function (g) { return g[1] - g[0] >= 15; });
    };
    var gapsText = function (g) { return g.map(function (x) { return toHm(x[0]) + '–' + toHm(x[1]); }).join(', '); };

    /** Zápisy dne kromě toho, který se právě upravuje. */
    var others = function () {
        return (DAYS[cur.date].entries || []).filter(function (e) { return e.id !== cur.id; });
    };

    /** Časová osa: otvírací doba, kolegové, navrhovaný čas, díry. */
    var render = function () {
        var day = DAYS[cur.date], list = others();
        var f = toMin(fromEl.value), t = toMin(toEl.value);
        var hours = day.hours;
        var lo = hours ? hours[0] : 8 * 60, hi = hours ? hours[1] : 20 * 60;
        list.forEach(function (e) { lo = Math.min(lo, toMin(e.from)); hi = Math.max(hi, toMin(e.to)); });
        if (f !== null) { lo = Math.min(lo, f); } if (t !== null) { hi = Math.max(hi, t); }
        lo = Math.floor(lo / 60) * 60; hi = Math.ceil(hi / 60) * 60; if (hi <= lo) { hi = lo + 60; }
        var pct = function (m) { return ((m - lo) / (hi - lo) * 100).toFixed(2) + '%'; };
        var w = function (a, b) { return ((b - a) / (hi - lo) * 100).toFixed(2) + '%'; };

        var h = '<div class="rz-tl-scale">';
        var step = (hi - lo) > 12 * 60 ? 180 : ((hi - lo) > 6 * 60 ? 120 : 60);
        var ticks = [];
        for (var m = lo; m <= hi; m += step) { ticks.push(m); }
        if (ticks[ticks.length - 1] !== hi) {
            // poslední pravidelná značka moc blízko konci by se s ním překryla
            if (hi - ticks[ticks.length - 1] < step * 0.6) { ticks.pop(); }
            ticks.push(hi);
        }
        ticks.forEach(function (m) { h += '<span style="left:' + pct(m) + '">' + toHm(m) + '</span>'; });
        h += '</div>';
        var iv = list.map(function (e) { return [toMin(e.from), toMin(e.to)]; });
        var valid = f !== null && t !== null && t > f;
        // obsazení: kolegové
        if (list.length) {
            h += '<div class="rz-tl-label">Už zapsaní</div>';
            list.forEach(function (e) {
                h += '<div class="rz-tl-track">' + (hours ? '<div class="rz-tl-open" style="left:' + pct(hours[0]) + ';width:' + w(hours[0], hours[1]) + '"></div>' : '')
                   + '<div class="rz-tl-bar" style="--c:' + esc(e.color) + ';left:' + pct(toMin(e.from)) + ';width:' + w(toMin(e.from), toMin(e.to)) + '" title="' + esc(e.name + ' ' + e.from + '–' + e.to) + '">'
                   + esc(e.name.split(' ')[0]) + ' ' + esc(e.from) + '–' + esc(e.to) + '</div></div>';
            });
        }
        // navrhovaný zápis + díry, které po něm zbydou
        var after = valid ? iv.concat([[f, t]]) : iv;
        var gaps = hours ? gapsOf(hours[0], hours[1], after) : [];
        h += '<div class="rz-tl-label">' + (cur.id ? 'Tenhle zápis' : 'Nový zápis') + ' + co zůstane bez obsluhy</div>';
        h += '<div class="rz-tl-track">' + (hours ? '<div class="rz-tl-open" style="left:' + pct(hours[0]) + ';width:' + w(hours[0], hours[1]) + '"></div>' : '');
        gaps.forEach(function (g) { h += '<div class="rz-tl-gap" style="left:' + pct(g[0]) + ';width:' + w(g[0], g[1]) + '" title="Bez obsluhy ' + toHm(g[0]) + '–' + toHm(g[1]) + '"></div>'; });
        if (valid) {
            h += '<div class="rz-tl-bar is-new" style="left:' + pct(f) + ';width:' + w(f, t) + '">' + toHm(f) + '–' + toHm(t) + '</div>';
        }
        h += '</div>';
        document.getElementById('rzTimeline').innerHTML = h;

        // živé shrnutí
        var hint = document.getElementById('rzHint');
        hint.className = 'rz-sum-live';
        if (!valid) {
            hint.classList.add('bad'); hint.innerHTML = '<i class="fas fa-circle-exclamation me-1"></i>Konec musí být po začátku.';
        } else if (!day.open) {
            hint.classList.add('gap'); hint.innerHTML = '<i class="fas fa-store-slash me-1"></i>V tenhle den má pobočka podle otvírací doby zavřeno.';
        } else if (!hours) {
            hint.innerHTML = '';
        } else {
            var outside = f < hours[0] || t > hours[1];
            var together = iv.filter(function (x) { return x[0] < t && x[1] > f; }).length;
            var extra = (together ? ' Ve stejnou dobu tam ' + (together === 1 ? 'bude ještě 1 kolega' : (together < 5 ? 'budou ještě ' + together + ' kolegové' : 'bude ještě ' + together + ' kolegů')) + '.' : '')
                      + (outside ? ' Část času je mimo otvírací dobu (' + toHm(hours[0]) + '–' + toHm(hours[1]) + ').' : '');
            if (gaps.length) {
                hint.classList.add('gap');
                hint.innerHTML = '<i class="fas fa-hourglass-half me-1"></i>Po uložení bude pořád bez obsluhy <b>' + gapsText(gaps) + '</b>.' + esc(extra);
            } else {
                hint.classList.add('ok');
                hint.innerHTML = '<i class="fas fa-circle-check me-1"></i>Po uložení bude pokrytá celá otvírací doba ' + toHm(hours[0]) + '–' + toHm(hours[1]) + '.' + esc(extra);
            }
        }

        // tlačítka „doplnit díru" — díry BEZ tohohle zápisu (kam se hodí)
        var before = hours ? gapsOf(hours[0], hours[1], iv) : [];
        var chips = document.getElementById('rzGaps');
        chips.innerHTML = before.length ? before.map(function (g) {
            var on = valid && f === g[0] && t === g[1];
            return '<button type="button" class="' + (on ? 'is-on' : '') + '" data-f="' + g[0] + '" data-t="' + g[1] + '"><i class="fas fa-wand-magic-sparkles me-1"></i>Doplnit ' + toHm(g[0]) + '–' + toHm(g[1]) + '</button>';
        }).join('') + (hours && iv.length ? '<button type="button" data-f="' + hours[0] + '" data-t="' + hours[1] + '">Celý den ' + toHm(hours[0]) + '–' + toHm(hours[1]) + '</button>' : '') : '';
    };
    document.getElementById('rzGaps').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-f]');
        if (!b) { return; }
        fromEl.value = toHm(+b.dataset.f); toEl.value = toHm(+b.dataset.t);
        render();
    });
    fromEl.addEventListener('input', render);
    toEl.addEventListener('input', render);

    /** Výběr člověka: kdo už je na den zapsaný, nejde přidat znovu (jen upravit jeho zápis). */
    var syncTechOptions = function (selected) {
        if (!techSel) { return; }
        var booked = {};
        others().forEach(function (e) { booked[e.tech] = e; });
        var firstFree = null;
        Array.prototype.forEach.call(techSel.options, function (o) {
            var id = +o.value, b = booked[id], s = STAFF.filter(function (x) { return x.id === id; })[0];
            o.disabled = !!b;
            o.textContent = (s ? s.name : o.textContent) + (id === ME ? ' (ty)' : '') + (b ? ' — už zapsán/a ' + b.from + '–' + b.to : '');
            if (!b && firstFree === null) { firstFree = id; }
        });
        var want = selected && !booked[selected] ? selected : (ME && !booked[ME] && STAFF.some(function (x) { return x.id === ME; }) ? ME : firstFree);
        if (want !== null) { techSel.value = String(want); }
        return firstFree !== null || !!selected;
    };

    var open = function (o) {
        var day = DAYS[o.date];
        if (!day) { return; }
        form.reset();
        cur = { date: o.date, id: +(o.id || 0) };
        var entry = cur.id ? day.entries.filter(function (e) { return e.id === cur.id; })[0] : null;
        document.getElementById('rzDate').value = o.date;
        document.getElementById('rzEntryId').value = cur.id;
        document.getElementById('rzDay').textContent = (entry ? 'Upravit — ' : (CAN_OTHER ? 'Přidat na ' : 'Zapsat se — ')) + day.label;
        document.getElementById('rzNote').value = entry ? entry.note : '';
        var anyFree = syncTechOptions(entry ? entry.tech : 0);
        var submit = form.querySelector('button[type=submit]');
        submit.disabled = (anyFree === false);
        if (anyFree === false) {
            document.getElementById('rzHint').className = 'rz-sum-live gap';
        }
        if (entry) {
            fromEl.value = entry.from; toEl.value = entry.to;
        } else {
            // návrh: první díra v otvírací době, jinak celá otvírací doba
            var iv = others().map(function (e) { return [toMin(e.from), toMin(e.to)]; });
            var g = day.hours ? gapsOf(day.hours[0], day.hours[1], iv) : [];
            var pick = g.length ? g[0] : (day.hours || [600, 1080]);
            fromEl.value = toHm(pick[0]); toEl.value = toHm(pick[1]);
        }
        var del = document.getElementById('rzDelete');
        // inline styl: globální CSS tlačítek přebíjí bootstrapové d-none
        del.style.setProperty('display', cur.id ? '' : 'none', 'important');
        del.dataset.id = cur.id;
        render();
        if (anyFree === false) {
            document.getElementById('rzHint').innerHTML = '<i class="fas fa-users me-1"></i>Na tenhle den jsou zapsaní už všichni z pobočky. Čas změníš tužkou u jména.';
        }
        modal().show();
    };

    document.querySelectorAll('.rz-add').forEach(function (b) {
        b.addEventListener('click', function () { open({ date: b.dataset.date, id: b.dataset.mode === 'edit' ? b.dataset.id : 0 }); });
    });
    document.querySelectorAll('.rz-edit').forEach(function (b) {
        b.addEventListener('click', function () { open({ date: b.dataset.date, id: b.dataset.id }); });
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
    if (techSel) { techSel.addEventListener('change', render); }

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
        post({
            action: 'save', branch_id: BRANCH,
            entry_id: cur ? cur.id : 0,
            tech_id: techSel ? techSel.value : 0,
            work_date: document.getElementById('rzDate').value,
            time_from: fromEl.value,
            time_to: toEl.value,
            note: document.getElementById('rzNote').value
        }, function (r) {
            if (r.success) { location.reload(); } else { showAlert(esc(r.message)); }
        }, form.querySelector('button[type=submit]'));
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
