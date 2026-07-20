<?php
/**
 * MIGRACE zakazkovylist.cz → Fix-CRM — ZKUŠEBNÍ POROVNÁNÍ (dry-run).
 * POUZE ČTE — do databáze NIC nezapisuje. Vypíše report, co by ostrý běh udělal:
 * kolik zakázek/klientů/reklamací by se vložilo, dorovnalo či přeskočilo,
 * přečíslování kolizních CRM zakázek, nespárovaní technici, sporné případy.
 *
 * Použití (na serveru):
 *   php scripts/migrace_dry_run.php /cesta/k/normalized_orders.json [/cesta/k/complaints_mini.json]
 *
 * Vstupní JSONy vytváří parse_zakazkovylist.py (mimo repozitář — osobní údaje).
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

// Kořen CRM: vedle skriptu (v repu), případně standardní cesta na serveru —
// skript tak jde spustit i zkopírovaný mimo repozitář (bez špinění git stromu).
$crmRoot = is_file(__DIR__ . '/../includes/config.php') ? dirname(__DIR__) : '/home/augi/repair-crm';
ob_start();
require_once $crmRoot . '/includes/config.php';
require_once $crmRoot . '/includes/functions.php';
ob_end_clean();

if (!isset($pdo)) { fwrite(STDERR, "DB nedostupná\n"); exit(1); }

$ordersFile = $argv[1] ?? null;
$cmplFile   = $argv[2] ?? null;
if (!$ordersFile || !is_readable($ordersFile)) {
    fwrite(STDERR, "Chybí/nečitelný soubor normalized_orders.json (arg 1)\n");
    exit(1);
}
$data = json_decode(file_get_contents($ordersFile), true);
if (!$data || empty($data['orders'])) { fwrite(STDERR, "Vadný JSON zakázek\n"); exit(1); }
$L = $data['orders'];          // zakázky z listu (normalizované)
$LC = $data['customers'] ?? [];

function say(string $s = ''): void { echo $s . "\n"; }
function normPhone(?string $p): string {
    $p = preg_replace('/[^0-9+]/', '', (string)$p);
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if (preg_match('/^[67]\d{8}$/', $p)) $p = '+420' . $p;
    return $p;
}
function normName(?string $s): string {
    $s = mb_strtolower(trim((string)$s));
    $t = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    return preg_replace('/\s+/', ' ', $t ?: $s);
}

say(str_repeat('═', 70));
say('  DRY-RUN MIGRACE zakazkovylist.cz → Fix-CRM   (' . date('d.m.Y H:i') . ')');
say('  Zdroj: ' . basename($ordersFile) . ' (' . count($L) . ' zakázek, ' . count($LC) . ' klientů)');
say(str_repeat('═', 70));

/* ── 0) Struktura DB (kontrola cílových sloupců) ─────────────────────────── */
$cols = static function (string $t) use ($pdo): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
};
$oCols = $cols('orders'); $cCols = $cols('customers'); $kCols = $cols('complaints');
$need = [
    'orders'    => ['order_code','legacy_code','source','status','branch_id','customer_id','technician_id','device_brand','device_model','problem_description','technician_notes','estimated_cost','pin_code','shipping_method','serial_number','created_at'],
    'customers' => ['first_name','last_name','phone','email','company','preferred_language'],
    'complaints'=> ['complaint_code','complaint_status','order_id','order_code','device','complaint_reason','phone','customer_id','source'],
];
say("\n── Kontrola cílových sloupců ──");
foreach ([['orders',$oCols],['customers',$cCols],['complaints',$kCols]] as [$t,$have]) {
    $missing = array_diff($need[$t], $have);
    say(sprintf('  %-10s %s', $t . ':', $missing ? ('CHYBÍ: ' . implode(', ', $missing)) : 'OK (vše k dispozici)'));
}

/* ── Pobočky ─────────────────────────────────────────────────────────────── */
$branches = $pdo->query("SELECT id, name FROM branches")->fetchAll(PDO::FETCH_KEY_PAIR);
say("\n── Pobočky v CRM ──");
foreach ($branches as $bid => $bn) say("  #$bid $bn");
$prikopeId = null; $karlinId = null;
foreach ($branches as $bid => $bn) {
    $n = normName($bn);
    if (str_contains($n, 'prikop') || str_contains($n, 'cerna ruze')) $prikopeId = (int)$bid;
    if (str_contains($n, 'karlin') || str_contains($n, 'krizikova')) $karlinId = (int)$bid;
}
say('  → Karlín = #' . ($karlinId ?? '?') . ' · Na Příkopě = #' . ($prikopeId ?? '?')
    . (($karlinId && $prikopeId) ? '' : '   !! DOPLNIT RUČNĚ'));

/* ── 1) Zakázky: list vs CRM ─────────────────────────────────────────────── */
/* Sloupce source/legacy_code přidává až deploy v2.12.0+ — dry-run musí běžet i bez nich */
$srcExpr = in_array('source', $oCols, true) ? "COALESCE(source,'crm')" : "'crm'";
$crm = $pdo->query("SELECT id, order_code, status, customer_id, created_at,
                           $srcExpr AS source,
                           (technician_notes LIKE '%Importovaný kód:%') AS was_imported
                    FROM orders")->fetchAll(PDO::FETCH_ASSOC);
$crmByCode = [];
foreach ($crm as $r) { if ($r['order_code'] !== null && $r['order_code'] !== '') $crmByCode[$r['order_code']] = $r; }
$listCodes = array_column($L, 'code');
$listSet = array_flip($listCodes);
$maxListNum = 0;
foreach ($listCodes as $c) { $n = (int)preg_replace('/\D/', '', $c); if ($n > $maxListNum) $maxListNum = $n; }

$statusDefs = function_exists('getOrderStatusDefinitions') ? array_keys(getOrderStatusDefinitions()) : [];

$ins = $upd = $same = 0; $insByStatus = []; $updList = []; $unknownTarget = [];
foreach ($L as $o) {
    $target = $o['status_crm'] ?? null;
    if ($target && $statusDefs && !in_array($target, $statusDefs, true)) $unknownTarget[$target] = true;
    $ex = $crmByCode[$o['code']] ?? null;
    if (!$ex) { $ins++; $insByStatus[$target ?? ('?? ' . $o['status_raw'])] = ($insByStatus[$target ?? ('?? ' . $o['status_raw'])] ?? 0) + 1; continue; }
    if ($target !== null && $ex['status'] !== $target) { $upd++; $updList[] = $o['code'] . ': ' . $ex['status'] . ' → ' . $target; }
    else $same++;
}

/* Kolizní CRM-native zakázky (kód existuje v listu, ale u nás NEjde o import) */
$colliding = [];
foreach ($crmByCode as $code => $r) {
    if (isset($listSet[$code]) && !$r['was_imported'] && $r['source'] !== 'legacy') $colliding[] = $r;
}
usort($colliding, static fn($a, $b) => (int)preg_replace('/\D/','',$a['order_code']) <=> (int)preg_replace('/\D/','',$b['order_code']));

/* CRM zakázky mimo list (kód nad maximem listu nebo úplně jiný tvar) */
$outsideList = array_filter($crm, static fn($r) => $r['order_code'] && !isset($listSet[$r['order_code']]));

say("\n── ZAKÁZKY ──");
say('  V listu celkem:            ' . count($L) . '  (nejvyšší číslo: ' . $maxListNum . ')');
say('  V CRM celkem:              ' . count($crm) . '  (z toho dřívější import: ' . count(array_filter($crm, fn($r) => $r['was_imported'])) . ')');
say('  → NOVĚ VLOŽIT:             ' . $ins);
foreach ($insByStatus as $st => $n) say(sprintf('        %-45s %d', $st, $n));
say('  → DOROVNAT STAV:           ' . $upd);
say('  → BEZE ZMĚNY (přeskočit):  ' . $same);
if ($unknownTarget) say('  !! CÍLOVÉ STAVY NEZNÁMÉ V CRM: ' . implode(', ', array_keys($unknownTarget)));

say("\n── PŘEČÍSLOVÁNÍ kolizních CRM zakázek ──");
say('  Kolizních (naše CRM zakázky s číslem, které má v listu jiná zakázka): ' . count($colliding));
$newNum = $maxListNum;
$prefix = 'APFAZ';
foreach (array_slice($colliding, 0, 5) as $r) {
    $newNum++;
    say('    ' . $r['order_code'] . ' → ' . $prefix . str_pad((string)$newNum, strlen((string)$maxListNum), '0', STR_PAD_LEFT) . '   (legacy_code = ' . $r['order_code'] . ')');
}
if (count($colliding) > 5) {
    say('    … a dalších ' . (count($colliding) - 5) . ' (řada pokračuje do '
        . $prefix . ($maxListNum + count($colliding)) . ')');
}
say('  Čítač nových zakázek pak naváže od: ' . $prefix . ($maxListNum + count($colliding) + 1));
say('  CRM zakázky mimo číselnou řadu listu (nedotčené): ' . count($outsideList));

/* ── 2) Dvojmo zapsané (souběh obou systémů) ─────────────────────────────── */
say("\n── PODEZŘENÍ NA DVOJÍ ZÁPIS (klient v obou systémech, ±5 dní) ──");
$srcCond = in_array('source', $oCols, true) ? "COALESCE(o.source,'crm') <> 'legacy' AND" : '';
$crmNativeRecent = $pdo->query(
    "SELECT o.id, o.order_code, o.created_at, o.device_model, c.phone
     FROM orders o JOIN customers c ON c.id = o.customer_id
     WHERE $srcCond
       o.technician_notes NOT LIKE '%Importovaný kód:%'
       AND o.created_at >= '2026-06-01'")->fetchAll(PDO::FETCH_ASSOC);
$byPhone = [];
foreach ($crmNativeRecent as $r) { $p = normPhone($r['phone']); if ($p) $byPhone[$p][] = $r; }
$dupes = 0;
foreach ($L as $o) {
    $p = normPhone($o['customer_phone']);
    if (!$p || empty($byPhone[$p]) || !$o['created_at']) continue;
    foreach ($byPhone[$p] as $r) {
        if (isset($listSet[$r['order_code']])) continue;   // koliduje jen číslem, ne obsahem
        $dt = abs(strtotime($o['created_at']) - strtotime($r['created_at']));
        if ($dt <= 5 * 86400) {
            $dupes++;
            say('  ? list ' . $o['code'] . ' (' . $o['device_raw'] . ', ' . $o['created_at'] . ')'
              . '  ↔  CRM ' . $r['order_code'] . ' (' . $r['device_model'] . ', ' . $r['created_at'] . ')');
        }
    }
}
if (!$dupes) say('  Žádné podezření nenalezeno.');

/* ── 3) Klienti ──────────────────────────────────────────────────────────── */
say("\n── KLIENTI ──");
$crmCust = $pdo->query("SELECT id, first_name, last_name, phone, email FROM customers")->fetchAll(PDO::FETCH_ASSOC);
$byP = $byE = $byN = [];
foreach ($crmCust as $c) {
    $p = normPhone($c['phone']); $e = mb_strtolower(trim((string)$c['email']));
    $n = normName($c['first_name'] . ' ' . $c['last_name']);
    if ($p) $byP[$p] = $c; if ($e) $byE[$e] = $c; if ($n) $byN[$n] = $c;
}
$cNew = $cSkip = $cEnrich = 0; $cNameOnly = [];
foreach ($LC as $c) {
    $p = normPhone($c['phone']); $e = mb_strtolower(trim($c['email'])); $n = normName($c['name']);
    $hit = ($p && isset($byP[$p])) ? $byP[$p] : (($e && isset($byE[$e])) ? $byE[$e] : null);
    if ($hit) {
        $needFill = (!trim((string)$hit['phone']) && $p) || (!trim((string)$hit['email']) && $e);
        $needFill ? $cEnrich++ : $cSkip++;
    } elseif ($n && isset($byN[$n])) {
        $cNameOnly[] = $c['name'] . ' (' . $c['code'] . ')';
    } else {
        $cNew++;
    }
}
say('  Seznam v listu:      ' . count($LC));
say('  → NOVĚ VLOŽIT:       ' . $cNew);
say('  → DOPLNIT ÚDAJE:     ' . $cEnrich . '   (jen prázdná pole, nic se nepřepisuje)');
say('  → BEZE ZMĚNY:        ' . $cSkip);
say('  → JEN SHODA JMÉNA:   ' . count($cNameOnly) . '   (ruční kontrola)');
foreach (array_slice($cNameOnly, 0, 12) as $x) say('       ' . $x);
$noCust = array_filter($L, static fn($o) => !$o['customer_name'] && !$o['customer_phone'] && !$o['customer_email']);
say('  Zakázky bez klienta v listu: ' . count($noCust) . '  → návrh: společný klient „Import – bez klienta"');

/* ── 4) Technici ─────────────────────────────────────────────────────────── */
say("\n── TECHNICI (párování dle jména; priorita Opravoval > Vydal > Přijal) ──");
$techs = $pdo->query("SELECT id, name FROM technicians")->fetchAll(PDO::FETCH_ASSOC);
$techByName = [];
foreach ($techs as $t) $techByName[normName($t['name'])] = $t;
$names = [];
foreach ($L as $o) {
    foreach (['tech_opravoval','tech_vydal','tech_prijal'] as $f) {
        $raw = trim((string)$o[$f]);
        if ($raw === '') continue;
        $first = trim(explode(',', $raw)[0]);   // složené „A, B" → první
        $names[$first] = ($names[$first] ?? 0) + 1;
    }
}
arsort($names);
foreach ($names as $n => $cnt) {
    $hit = $techByName[normName($n)] ?? null;
    if (!$hit) {   // zkusit křestní jméno / podřetězec
        foreach ($techByName as $tn => $t) {
            if (str_contains($tn, normName($n)) || str_contains(normName($n), $tn)) { $hit = $t; break; }
        }
    }
    say(sprintf('  %-32s %5d×   → %s', $n, $cnt, $hit ? ('#' . $hit['id'] . ' ' . $hit['name']) : '!! NESPÁROVÁNO (zakázka zůstane bez technika)'));
}

/* ── 5) Reklamace ────────────────────────────────────────────────────────── */
say("\n── REKLAMACE ──");
if ($cmplFile && is_readable($cmplFile)) {
    $cm = json_decode(file_get_contents($cmplFile), true);
    $recs = $cm['records'] ?? [];
    $crmCmpl = $pdo->query("SELECT complaint_code, complaint_status FROM complaints")->fetchAll(PDO::FETCH_KEY_PAIR);
    $kIns = $kUpd = $kSame = 0; $kNoOrder = 0; $kJunk = [];
    foreach ($recs as $r) {
        $code = $r['c']; $st = $r['s'];
        if ($st === '' && !$r['o'] && !$r['r']) { $kJunk[] = $code; continue; }
        if (!$r['o']) $kNoOrder++;
        if (!isset($crmCmpl[$code])) { $kIns++; continue; }
        ($crmCmpl[$code] === $st) ? $kSame++ : $kUpd++;
    }
    say('  V listu: ' . count($recs) . '  |  v CRM celkem: ' . count($crmCmpl));
    say('  → NOVĚ VLOŽIT: ' . $kIns . '  ·  DOROVNAT STAV: ' . $kUpd . '  ·  BEZE ZMĚNY: ' . $kSame);
    say('  Bez vazby na zakázku (e-shopové APFARE): ' . $kNoOrder . '  → import s klientem dle kontaktu');
    if ($kJunk) say('  → PŘESKOČIT (prázdný koncept): ' . implode(', ', $kJunk));
    say('  CRM-native řada RK-xxx zůstává nedotčená.');
} else {
    say('  (soubor s reklamacemi nebyl předán — přeskočeno)');
}

say("\n" . str_repeat('═', 70));
say('  DRY-RUN HOTOV — nic nebylo zapsáno. Ostrý běh až po schválení majitelem.');
say(str_repeat('═', 70));
