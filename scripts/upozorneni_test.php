<?php
/**
 * CHYTRÁ UPOZORNĚNÍ — test pravidel (v3.88.0).
 *
 * Projde každé pravidlo se simulovaným časem na testovacích pobočkách
 * a zaměstnancích v roce 2031 (mimo skutečný rozpis). Běží v transakci
 * s ROLLBACKem a s VYPNUTÝMI kanály (Telegram, push, e-mail, SMS) — nikomu
 * nic neodejde a v databázi po něm nic nezůstane.
 *
 * Spuštění z kořene CRM:  php scripts/upozorneni_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/upozorneni/lib.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }
function at(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }

/** Spustí pravidlo nanečisto a vrátí zprávy jen pro testovací lidi. */
function dry(callable $fn, DateTimeImmutable $now): array {
    afxNotifyDryRun(true);
    $fn($now);
    $items = afxNotifyCollected();
    afxNotifyDryRun(false);
    return array_values(array_filter($items, static fn($i) => str_starts_with((string)$i['to'], 'ZZ ')));
}
function to(array $items, string $name): array {
    return array_values(array_filter($items, static fn($i) => $i['to'] === $name));
}
function names(array $items): string {
    return implode(', ', array_map(static fn($i) => $i['to'] . ' [' . $i['rule'] . ']', $items)) ?: '(nic)';
}

// ── Čisté funkce (bez DB) ────────────────────────────────────────────────
head('Pomocné funkce');
ok('„10:00 – 20:00" → [600,1200]', afxNotifyParseHoursText('10:00 – 20:00') === [600, 1200]);
ok('„9–18" → [540,1080]', afxNotifyParseHoursText('9–18') === [540, 1080]);
ok('„zavřeno" → false', afxNotifyParseHoursText('zavřeno') === false);
ok('nečitelné → null', afxNotifyParseHoursText('dle domluvy') === null);
ok('sloučení intervalů', afxNotifyMergeIntervals([[600, 720], [700, 800], [900, 960]]) === [[600, 800], [900, 960]]);
ok('díry v pokrytí', afxNotifyGaps(600, 1200, [[660, 800], [900, 1000]]) === [[600, 660], [800, 900], [1000, 1200]]);
ok('bez děr při plném pokrytí', afxNotifyGaps(600, 1200, [[540, 1260]]) === []);
ok('časy „20:00, 14:00, x" → seřazené', afxNotifyParseTimes('20:00, 14:00, x') === ['14:00', '20:00']);
ok('splatnost v okně', afxNotifyDue(at('2031-03-04 14:10'), '14:00', 60) && !afxNotifyDue(at('2031-03-04 15:01'), '14:00', 60)
    && !afxNotifyDue(at('2031-03-04 13:59'), '14:00', 60));
ok('popisek dne', afxNotifyDayLabel('2031-03-06') === 'čt 6. 3.');

// ── Testovací data ───────────────────────────────────────────────────────
afxNotifyEnsureSchema();
afxShiftEnsureSchema();
ensureStaffPresenceSchema($pdo);
if (function_exists('afxEnsurePosShiftTable')) { afxEnsurePosShiftTable(); }
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO branches (code, name, address, is_active, opening_hours) VALUES
        ('ZZT1', 'ZZ Pobočka Jedna', '', 1, 'Po – Pá: 10:00 – 19:00\nSo: 10:00 – 14:00\nNe: zavřeno'),
        ('ZZT2', 'ZZ Pobočka Dva', '', 1, '')");
    $b1 = (int)$pdo->query("SELECT id FROM branches WHERE code = 'ZZT1'")->fetchColumn();
    $b2 = (int)$pdo->query("SELECT id FROM branches WHERE code = 'ZZT2'")->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO technicians (name, role, branch_id, telegram_id, is_active) VALUES (?,?,?,?,1)');
    $T = [];
    foreach ([['ZZ Alfa', 'engineer', $b1, '111'], ['ZZ Beta', 'engineer', $b1, null], ['ZZ Gama', 'manager', $b1, null],
              ['ZZ Boss', 'boss', $b1, null], ['ZZ Delta', 'engineer', $b2, null], ['ZZ Účetní', 'accountant', $b1, null]] as [$n, $r, $b, $tg]) {
        $ins->execute([$n, $r, $b, $tg]);
        $T[$n] = (int)$pdo->lastInsertId();
    }

    // kanály vypnout — test nesmí nikomu nic poslat
    $cfg = afxNotifyConfig(true);
    foreach (['ch_telegram', 'ch_push', 'ch_email', 'ch_sms'] as $k) { $cfg['global'][$k] = false; }
    $cfg['global']['enabled'] = true;
    // pravidla na výchozí hodnoty — test nesmí záviset na tom, co si vedení nastavilo
    foreach (afxNotifyRules() as $id => $rule) {
        $cfg['rules'][$id] = ['enabled' => true];
        foreach ($rule['params'] as $p => $def) { $cfg['rules'][$id][$p] = $def['default']; }
    }
    $cfg['global']['quiet_from'] = '22:00'; $cfg['global']['quiet_to'] = '07:00';
    $cfg['rules']['orders_stale']['weekdays'] = false;
    afxNotifyStoreConfig($cfg);
    afxNotifyDirectory(true);

    $shift = $pdo->prepare('INSERT INTO shift_plan (branch_id, tech_id, work_date, time_from, time_to, note, created_at) VALUES (?,?,?,?,?,?,?)');
    $add = static function (string $who, string $date, string $from, string $to, int $branch = 0, string $note = '') use ($shift, $T, $b1): int {
        global $pdo;
        $shift->execute([$branch ?: $b1, $T[$who], $date, $from . ':00', $to . ':00', $note, '2031-01-01 00:00:00']);
        return (int)$pdo->lastInsertId();
    };
    // týden po 3. 3. 2031 – ne 9. 3. 2031
    $alfaTue = $add('ZZ Alfa', '2031-03-04', '10:00', '14:00', 0, 'výdej');
    $add('ZZ Beta', '2031-03-04', '12:00', '19:00');
    $add('ZZ Alfa', '2031-03-06', '10:00', '14:00');
    $add('ZZ Delta', '2031-03-04', '09:00', '17:00', $b2);

    head('Pokrytí dne');
    $tue = afxShiftEntries($b1, '2031-03-04', '2031-03-04')['2031-03-04'] ?? [];
    $c = afxShiftCoverage($b1, '2031-03-04', $tue);
    ok('út: otevřeno 10–19, pokryto beze děr', $c['open'] && $c['hours'] === [600, 1140] && $c['gaps'] === [], json_encode($c));
    $c = afxShiftCoverage($b1, '2031-03-06', afxShiftEntries($b1, '2031-03-06', '2031-03-06')['2031-03-06'] ?? []);
    ok('čt: díra 14:00–19:00', afxNotifyGapsText($c['gaps']) === '14:00–19:00', json_encode($c['gaps']));
    ok('ne: zavřeno', afxShiftCoverage($b1, '2031-03-09', [])['open'] === false);
    ok('pobočka bez otvírací doby: po otevřeno, ne zavřeno',
        afxShiftCoverage($b2, '2031-03-03', [])['open'] === true && afxShiftCoverage($b2, '2031-03-09', [])['open'] === false);

    head('Připomínka před směnou');
    ok('8:30 ještě nic (předstih 60 min)', !to(dry('afxNotifyRuleShiftReminder', at('2031-03-04 08:30')), 'ZZ Alfa'));
    $r = to(dry('afxNotifyRuleShiftReminder', at('2031-03-04 09:05')), 'ZZ Alfa');
    ok('9:05 Alfa dostane připomínku', count($r) === 1, names($r));
    ok('… s kolegou, otvírací dobou a poznámkou', $r && str_contains($r[0]['body'], 'ZZ Beta (12:00–19:00)')
        && str_contains($r[0]['body'], 'Otevřeno 10:00 – 19:00') && str_contains($r[0]['body'], 'Poznámka: výdej'), $r[0]['body'] ?? '');
    ok('… a radou převzít pokladnu (jde první)', $r && str_contains($r[0]['body'], 'převezmi pokladnu'));
    afxNotifySavePrefs('tech:' . $T['ZZ Beta'], ['reminder_minutes' => '120', 'evening_before' => 0, 'ch_telegram' => 1, 'ch_push' => 1]);
    ok('Beta s osobním předstihem 120 min dostane připomínku v 10:05',
        count(to(dry('afxNotifyRuleShiftReminder', at('2031-03-04 10:05')), 'ZZ Beta')) === 1);
    ok('po začátku směny už připomínka nechodí', !to(dry('afxNotifyRuleShiftReminder', at('2031-03-04 10:01')), 'ZZ Alfa'));

    head('Večer předem');
    $r = dry('afxNotifyRuleShiftEvening', at('2031-03-03 19:10'));
    ok('Alfa dostane „zítra jdeš do práce"', count(to($r, 'ZZ Alfa')) === 1, names($r));
    ok('Beta ne — večerní shrnutí si vypnula', !to($r, 'ZZ Beta'));
    ok('v 17:00 ještě nic', !dry('afxNotifyRuleShiftEvening', at('2031-03-03 17:00')));

    head('Zítra nikdo zapsaný');
    $r = dry('afxNotifyRuleShiftUncovered', at('2031-03-04 14:05'));     // na středu 5. 3. nikdo
    ok('manažer pobočky a Boss dostanou upozornění', count(to($r, 'ZZ Gama')) >= 1 && count(to($r, 'ZZ Boss')) >= 1, names($r));
    $g = to($r, 'ZZ Gama');
    ok('… jako varování (1. kontrola)', $g && $g[0]['level'] === 'warn');
    ok('výzva zaměstnancům pobočky (Alfa, Beta), manažer jen jednu zprávu', count(array_filter(to($r, 'ZZ Alfa'), static fn($i) => str_contains($i['title'], 'nikdo na pobočce'))) === 1
        && count(to($r, 'ZZ Beta')) === 1 && count(to($r, 'ZZ Gama')) === 1, names($r));
    ok('účetní výzvu nedostane', !to($r, 'ZZ Účetní'));
    $r = dry('afxNotifyRuleShiftUncovered', at('2031-03-04 20:10'));
    $g = to($r, 'ZZ Gama');
    ok('2. kontrola ve 20:00 je naléhavá a bez opakované výzvy', $g && $g[0]['level'] === 'urgent' && !to($r, 'ZZ Alfa'), names($r));
    $r = dry('afxNotifyRuleShiftUncovered', at('2031-03-08 14:05'));     // na neděli — zavřeno
    ok('na zavřenou neděli se nehlásí', !array_filter($r, static fn($i) => str_contains($i['body'], 'Jedna')), names($r));
    $r = dry('afxNotifyRuleShiftUncovered', at('2031-03-05 07:45'));
    ok('ráno: DNES nikdo na směně → naléhavě vedení', (bool)array_filter(to($r, 'ZZ Boss'), static fn($i) => $i['level'] === 'urgent' && str_contains($i['title'], 'DNES')), names($r));
    // návrh, kdo obvykle chodí: Alfa měl 2 středy v minulých týdnech
    $add('ZZ Alfa', '2031-02-26', '10:00', '19:00'); $add('ZZ Alfa', '2031-02-19', '10:00', '19:00');
    $r = dry('afxNotifyRuleShiftUncovered', at('2031-03-04 14:05'));
    $g = to($r, 'ZZ Gama');
    ok('vedení dostane návrh „obvykle chodí: ZZ Alfa (2×)"', $g && str_contains($g[0]['body'], 'ZZ Alfa (2×)'), $g[0]['body'] ?? '');
    ok('Alfa ve výzvě dostane „obvykle chodíš ty"', (bool)array_filter(to($r, 'ZZ Alfa'), static fn($i) => str_contains($i['body'], 'obvykle chodíš ty')));

    head('Díra v otvírací době');
    $r = dry('afxNotifyRuleShiftGap', at('2031-03-05 17:05'));            // čtvrtek: jen Alfa 10–14
    $g = to($r, 'ZZ Gama');
    ok('manažer dostane „bez obsluhy 14:00–19:00"', $g && str_contains($g[0]['body'], '14:00–19:00'), names($r));
    ok('výzva zaměstnancům je ve výchozím stavu vypnutá', !to($r, 'ZZ Beta'));

    head('Nástup nezaznamenán');
    $r = dry('afxNotifyRuleShiftNoShow', at('2031-03-04 10:20'));
    ok('10:20 jemné připomenutí Alfovi', count(to($r, 'ZZ Alfa')) === 1 && !to($r, 'ZZ Gama'), names($r));
    $r = dry('afxNotifyRuleShiftNoShow', at('2031-03-04 10:45'));
    $g = to($r, 'ZZ Gama');
    ok('10:45 eskalace vedení — „nikdo jiný není" (Beta až od 12)', $g && $g[0]['level'] === 'urgent' && str_contains($g[0]['body'], 'nikdo jiný'), names($r));
    $pdo->prepare('UPDATE technicians SET last_seen = ? WHERE id = ?')->execute(['2031-03-04 09:50:00', $T['ZZ Alfa']]);
    $r = dry('afxNotifyRuleShiftNoShow', at('2031-03-04 10:45'));
    ok('když se Alfa přihlásil v 9:50, o něm se nic nehlásí', !to($r, 'ZZ Alfa') && !array_filter($r, static fn($i) => str_contains($i['title'], 'ZZ Alfa')), names($r));
    ok('Delta (jiná pobočka, nepřihlášený) připomenutí dostane', count(to($r, 'ZZ Delta')) === 1, names($r));

    head('Pokladna');
    $r = dry('afxNotifyRulePos', at('2031-03-04 10:20'));
    ok('nepřevzatá pokladna → Alfa (je na směně)', count(array_filter(to($r, 'ZZ Alfa'), static fn($i) => $i['rule'] === 'pos_open')) === 1, names($r));
    ok('Beta (od 12:00) ne', !to($r, 'ZZ Beta'));
    $pdo->prepare("INSERT INTO pos_shifts (branch_id, status, opened_by, opened_by_tech, opened_at) VALUES (?, 'open', 'ZZ Beta', ?, '2031-03-04 12:01:00')")
        ->execute([$b1, $T['ZZ Beta']]);
    ok('po převzetí už nic', !array_filter(dry('afxNotifyRulePos', at('2031-03-04 12:30')), static fn($i) => $i['rule'] === 'pos_open'));
    $r = dry('afxNotifyRulePos', at('2031-03-04 19:35'));
    $close = array_filter($r, static fn($i) => $i['rule'] === 'pos_close');
    ok('po konci směny otevřená pokladna → Beta (převzala) + vedení',
        count(array_filter($close, static fn($i) => $i['to'] === 'ZZ Beta')) === 1 && count(array_filter($close, static fn($i) => $i['to'] === 'ZZ Boss')) === 1, names($r));
    ok('v 19:20 ještě ne (30 min tolerance)', !array_filter(dry('afxNotifyRulePos', at('2031-03-04 19:20')), static fn($i) => $i['rule'] === 'pos_close'));

    head('Změna rozpisu');
    $row = afxShiftFind($b1, $T['ZZ Alfa'], '2031-03-06');
    $new = $row; $new['time_from'] = '12:00:00'; $new['time_to'] = '19:00:00';
    afxNotifyDryRun(true);
    afxNotifyShiftChangedNow($row, $new, $T['ZZ Gama'], 'ZZ Gama', at('2031-03-01 10:00'));
    $r = array_values(array_filter(afxNotifyCollected(), static fn($i) => str_starts_with($i['to'], 'ZZ ')));
    afxNotifyDryRun(false);
    ok('manažer upravil Alfovi směnu → Alfa ví „10:00–14:00 → 12:00–19:00"',
        ($a = to($r, 'ZZ Alfa')) && str_contains($a[0]['body'], '10:00–14:00 → 12:00–19:00'), names($r));
    ok('změna týden dopředu vedení neobtěžuje', !to($r, 'ZZ Boss'));
    afxNotifyDryRun(true);
    afxNotifyShiftChangedNow($row, $row, $T['ZZ Alfa'], 'ZZ Alfa', at('2031-03-05 15:00'));
    ok('změna jen poznámky nic nehlásí', !array_filter(afxNotifyCollected(), static fn($i) => str_starts_with($i['to'], 'ZZ ')));
    afxNotifyDryRun(false);
    $pdo->prepare('DELETE FROM shift_plan WHERE id = ?')->execute([(int)$row['id']]);
    afxNotifyDryRun(true);
    afxNotifyShiftChangedNow($row, null, $T['ZZ Alfa'], 'ZZ Alfa', at('2031-03-05 15:00'));
    $r = array_values(array_filter(afxNotifyCollected(), static fn($i) => str_starts_with($i['to'], 'ZZ ')));
    afxNotifyDryRun(false);
    $g = to($r, 'ZZ Gama');
    ok('Alfa zrušil zítřejší směnu → vedení naléhavě „zítra nikdo není"', $g && $g[0]['level'] === 'urgent' && str_contains($g[0]['body'], 'nikdo není'), names($r));
    ok('Alfa sám sobě zprávu nedostane', !to($r, 'ZZ Alfa'));
    // vyřešeno: po skutečném hlášení „nikdo" se někdo zapíše
    afxNotifyDeliver(afxNotifyRecipient('tech:' . $T['ZZ Gama']), 'shift_uncovered', $b1 . '|2031-03-06|s0', 'x', 'y', ['now' => at('2031-03-05 14:05')]);
    $add('ZZ Beta', '2031-03-06', '10:00', '19:00');
    afxNotifyDryRun(true);
    afxNotifyShiftChangedNow(null, afxShiftFind($b1, $T['ZZ Beta'], '2031-03-06'), $T['ZZ Beta'], 'ZZ Beta', at('2031-03-05 16:00'));
    $r = array_values(array_filter(afxNotifyCollected(), static fn($i) => str_starts_with($i['to'], 'ZZ ')));
    afxNotifyDryRun(false);
    ok('po hlášení „nikdo" a novém zápisu dostane vedení „Vyřešeno"', (bool)array_filter(to($r, 'ZZ Gama'), static fn($i) => str_starts_with($i['title'], 'Vyřešeno')), names($r));

    head('Výhled na příští týden');
    for ($d = 0; $d < 7; $d++) {
        $day = date('Y-m-d', strtotime('2031-03-03 +' . $d . ' day'));
        if (!afxShiftFind($b1, $T['ZZ Beta'], $day)) { $add('ZZ Beta', $day, '10:00', '19:00'); }
    }
    $add('ZZ Beta', '2031-03-10', '10:00', '19:00');      // 8. den v kuse zasahuje do příštího týdne
    $r = dry('afxNotifyRuleShiftWeek', at('2031-03-06 12:05'));           // čtvrtek
    $boss = to($r, 'ZZ Boss');
    ok('vedení dostane souhrn s nepokrytými dny', $boss && str_contains($boss[0]['body'], 'ZZ Pobočka Jedna') || ($boss && str_contains($boss[0]['body'], 'nikdo')), $boss[0]['body'] ?? names($r));
    ok('… s varováním před přetížením (Beta 8 dní v kuse)', $boss && str_contains($boss[0]['body'], 'ZZ Beta 8 dní'), $boss[0]['body'] ?? '');
    ok('zaměstnanci dostanou výzvu k zápisu', count(to($r, 'ZZ Alfa')) === 1);
    ok('v pondělí se výhled neposílá', !dry('afxNotifyRuleShiftWeek', at('2031-03-03 12:05')));

    head('Doručení a deduplikace');
    $alfa = afxNotifyRecipient('tech:' . $T['ZZ Alfa']);
    ok('Alfa má propojený Telegram v adresáři', $alfa && $alfa['telegram'] === '111');
    ok('první doručení projde', afxNotifyDeliver($alfa, 'test', 'dup1', 'Ahoj', '**tučně**', ['now' => at('2031-03-04 10:00')]));
    ok('stejný klíč podruhé neodejde', !afxNotifyDeliver($alfa, 'test', 'dup1', 'Ahoj', 'x', ['now' => at('2031-03-04 10:01')]));
    [$cnt, $latest] = afxNotifyUnread(['tech:' . $T['ZZ Alfa']]);
    ok('upozornění je v CRM jako nepřečtené (bez markdownu)', $cnt >= 1 && $latest && $latest['body'] === 'tučně', json_encode($latest));
    afxNotifyMarkRead(['tech:' . $T['ZZ Alfa']]);
    ok('označení přečtené', afxNotifyUnread(['tech:' . $T['ZZ Alfa']])[0] === 0);
    $log = $pdo->query("SELECT channels FROM smart_notify_log WHERE dedupe_key LIKE 'test|dup1|%'")->fetchColumn();
    ok('vypnuté kanály: odešlo jen do CRM', $log === 'crm', (string)$log);
    afxNotifySavePrefs('tech:' . $T['ZZ Alfa'], ['muted' => ['shift_evening'], 'evening_before' => 1, 'ch_telegram' => 1]);
    ok('ztlumené pravidlo se nedoručí', !to(dry('afxNotifyRuleShiftEvening', at('2031-03-03 19:10')), 'ZZ Alfa'));
    ok('Telegram v noci potichu', afxNotifyIsQuiet(at('2031-03-04 23:30')) && afxNotifyIsQuiet(at('2031-03-04 06:00')) && !afxNotifyIsQuiet(at('2031-03-04 12:00')));
    ok('formát pro Telegram', afxNotifyFormat('A <b> **tučně**', 'html') === 'A &lt;b&gt; <b>tučně</b>');

    head('Zakázky, reklamace');
    $pdo->exec("INSERT INTO customers (first_name, last_name, phone) VALUES ('ZZ', 'Klient', '777000111')");
    $cust = (int)$pdo->lastInsertId();
    $active = getOrderStatusList('in_progress')[0] ?? 'V opravě';
    $pdo->prepare("INSERT INTO orders (order_code, customer_id, device_type, device_model, device_brand, status, technician_id, branch_id, created_at)
                   VALUES ('ZZ-STALE', ?, 'Phone', '13', 'iPhone', ?, ?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY))")
        ->execute([$cust, $active, $T['ZZ Alfa'], $b1]);
    $now = (new DateTimeImmutable('today'))->setTime(9, 5);
    $r = to(dry('afxNotifyRuleOrdersStale', $now), 'ZZ Alfa');
    ok('technik dostane svou zakázku bez pohybu (10 dní)', $r && str_contains($r[0]['body'], 'ZZ-STALE') && str_contains($r[0]['body'], '10 dní'), names($r));
    ok('manažer pobočky dostane přehled pobočky', (bool)to(dry('afxNotifyRuleOrdersStale', $now), 'ZZ Gama'));
    if (afxNotifyColumnExists('complaints', 'technician_id')) {
        $pdo->prepare("INSERT INTO complaints (complaint_code, device, complaint_status, technician_id, created_at)
                       VALUES ('ZZ-REK', 'iPhone 12', 'Přijato', ?, DATE_SUB(NOW(), INTERVAL 28 DAY))")->execute([$T['ZZ Alfa']]);
        $r = to(dry('afxNotifyRuleComplaints', (new DateTimeImmutable('today'))->setTime(9, 20)), 'ZZ Alfa');
        ok('reklamace 28 dní → „zbývá 2 d"', $r && str_contains($r[0]['body'], 'ZZ-REK') && str_contains($r[0]['body'], 'zbývá 2 d'), names($r));
    } else {
        echo "  (reklamace bez sloupce technician_id — přeskočeno)\n";
    }

    head('Appka: zvuky, položky, plán připomínek');
    ok('zvuk podle typu', afxNotifySound('shift_reminder', 'info') === 'shift' && afxNotifySound('pos_open', 'warn') === 'cash'
        && afxNotifySound('shift_gap', 'warn') === 'warn' && afxNotifySound('orders_stale', 'info') === 'info'
        && afxNotifySound('shift_uncovered', 'urgent') === 'urgent');
    $kA = 'tech:' . $T['ZZ Alfa'];
    $last = afxNotifyLastId([$kA]);
    afxNotifyDeliver($alfa, 'pos_open', 'appka1', 'Pokladna', 'text', ['now' => at('2031-03-04 10:20'), 'level' => 'warn', 'url' => 'pokladna.php']);
    $items = afxNotifyItemsSince([$kA], $last);
    ok('appka dostane nové upozornění se zvukem a ref', count($items) === 1 && $items[0]['sound'] === 'cash'
        && $items[0]['ref'] === 'pos_open|appka1' && $items[0]['url'] === 'pokladna.php', json_encode($items, JSON_UNESCAPED_UNICODE));
    ok('od posledního id už nic nového', afxNotifyItemsSince([$kA], afxNotifyLastId([$kA])) === []);
    afxNotifySavePrefs($kA, ['reminder_minutes' => '', 'evening_before' => 1, 'ch_telegram' => 1, 'ch_push' => 1]);
    $plan = afxNotifyAppSchedule($kA, at('2031-03-03 18:00'));
    $rem = array_values(array_filter($plan, static fn($x) => str_starts_with($x['ref'], 'shift_reminder|')));
    ok('plán: připomínka úterní směny přesně 60 min předem', $rem && $rem[0]['at'] === at('2031-03-04 09:00')->getTimestamp()
        && $rem[0]['ref'] === 'shift_reminder|' . $alfaTue . '|2031-03-04|10:00' && $rem[0]['title'] === 'Směna ti začíná za 60 min',
        json_encode($plan, JSON_UNESCAPED_UNICODE));
    ok('plán: večerní zpráva v 19:00 se stejným ref jako ze serveru', (bool)array_filter($plan, static fn($x) =>
        $x['ref'] === 'shift_evening|' . $T['ZZ Alfa'] . '|2031-03-04' && $x['at'] === at('2031-03-03 19:00')->getTimestamp()));
    // server pak pošle totéž → stejný ref (appka ho podruhé neukáže)
    afxNotifyDryRun(false);
    afxNotifyRuleShiftReminder(at('2031-03-04 09:00'));
    $srv = array_values(array_filter(afxNotifyItemsSince([$kA], 0, 50), static fn($x) => $x['rule'] === 'shift_reminder'));
    ok('server pošle připomínku se stejným ref jako plán appky', $srv && $srv[count($srv) - 1]['ref'] === $rem[0]['ref'] && $srv[count($srv) - 1]['sound'] === 'shift',
        json_encode($srv, JSON_UNESCAPED_UNICODE));
    afxNotifySavePrefs($kA, ['muted' => ['shift_reminder', 'shift_evening'], 'evening_before' => 1, 'ch_push' => 1]);
    ok('ztlumené připomínky appka neplánuje', afxNotifyAppSchedule($kA, at('2031-03-03 18:00')) === []);
    afxNotifySavePrefs($kA, ['evening_before' => 1, 'ch_telegram' => 1, 'ch_push' => 1]);

    head('Rozpis: víc lidí na den, překryvy, bez tichého přepisu');
    $_SESSION['role'] = 'admin'; $_SESSION['user_id'] = 1; unset($_SESSION['tech_id']);   // vedení
    $D = '2031-03-21';                                                                    // pátek (volný týden)
    [$ok1] = afxShiftSave($b1, $T['ZZ Alfa'], $D, '10:00', '14:00', '');
    [$ok2] = afxShiftSave($b1, $T['ZZ Gama'], $D, '12:00', '19:00', '');                 // překryv 12–14
    [$ok3] = afxShiftSave($b1, $T['ZZ Delta'] , $D, '10:00', '12:00', '');               // jiná pobočka
    ok('na jeden den jde zapsat víc lidí a časy se můžou krýt', $ok1 && $ok2);
    ok('člověk z jiné pobočky zapsat nejde', !$ok3);
    $cov = afxShiftCoverage($b1, $D, afxShiftEntries($b1, $D, $D)[$D] ?? []);
    ok('dva lidé pokryjí celou otvírací dobu 10–19', $cov['count'] === 2 && $cov['gaps'] === [], json_encode($cov['gaps']));
    [$okDup, $msgDup] = afxShiftSave($b1, $T['ZZ Alfa'], $D, '15:00', '19:00', '');
    $alfaRow = afxShiftFind($b1, $T['ZZ Alfa'], $D);
    ok('nový zápis už zapsaného člověka NEpřepíše jeho čas (srozumitelná chyba)', !$okDup && str_contains($msgDup, 'už je na tenhle den zapsaný')
        && substr($alfaRow['time_from'], 0, 5) === '10:00', $msgDup);
    [$okEd] = afxShiftSave($b1, $T['ZZ Alfa'], $D, '09:30', '13:00', 'ráno', (int)$alfaRow['id']);
    $alfaRow = afxShiftFind($b1, $T['ZZ Alfa'], $D);
    ok('úprava s id změní čas', $okEd && substr($alfaRow['time_from'], 0, 5) === '09:30' && $alfaRow['note'] === 'ráno');
    [$okMv] = afxShiftSave($b1, $T['ZZ Beta'], $D, '09:30', '13:00', '', (int)$alfaRow['id']);
    ok('úprava s jiným člověkem zápis převede (nevznikne druhý)', $okMv && !afxShiftFind($b1, $T['ZZ Alfa'], $D)
        && afxShiftFind($b1, $T['ZZ Beta'], $D) && count(afxShiftEntries($b1, $D, $D)[$D]) === 2);
    $gamaRow = afxShiftFind($b1, $T['ZZ Gama'], $D);
    [$okCl, $msgCl] = afxShiftSave($b1, $T['ZZ Beta'], $D, '12:00', '19:00', '', (int)$gamaRow['id']);
    ok('převod na člověka, který už zápis má, se odmítne', !$okCl && str_contains($msgCl, 'vlastní zápis'), $msgCl);
    // zaměstnanec: zapisuje jen sebe, ale klidně k ostatním
    $_SESSION['role'] = 'technician'; $_SESSION['tech_id'] = $T['ZZ Alfa']; $_SESSION['internal_role'] = 'engineer';
    $_SESSION['user_id'] = 't' . $T['ZZ Alfa']; unset($_SESSION['_perms'], $_SESSION['_perms_rev']);
    [$okEmp] = afxShiftSave($b1, $T['ZZ Alfa'], '2031-03-22', '10:00', '12:00', '');
    $_SESSION['tech_id'] = $T['ZZ Beta']; $_SESSION['user_id'] = 't' . $T['ZZ Beta'];
    [$okEmp2] = afxShiftSave($b1, $T['ZZ Beta'], '2031-03-22', '11:00', '14:00', '');
    [$okEmp3] = afxShiftSave($b1, $T['ZZ Alfa'], '2031-03-22', '12:00', '14:00', '');
    ok('zaměstnanci se zapíšou ke kolegovi na stejný den (překryv)', $okEmp && $okEmp2);
    ok('zaměstnanec nezapíše kolegu', !$okEmp3);
    $_SESSION = [];

    head('Plánovač');
    $res = afxNotifyRun(at('2031-03-04 09:05'), true);
    ok('průchod nanečisto doběhne a vrátí položky', isset($res['items']) && is_array($res['counts']) && $res['sent'] >= 1, json_encode($res['counts'] ?? $res));
    $up = afxNotifyUpcoming(at('2031-03-03 18:00'), 'tech:' . $T['ZZ Alfa']);
    ok('náhled „co přijde" ukáže připomínku', (bool)array_filter($up, static fn($u) => str_contains($u['title'], 'Připomínka')), json_encode(array_column($up, 'title')));
} catch (Throwable $e) {
    ok('bez výjimky', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo "\n" . ($fail === 0 ? "✅ Vše prošlo ($pass)" : "❌ Selhalo $fail z " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
