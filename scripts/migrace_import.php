<?php
/**
 * MIGRACE zakazkovylist.cz → Fix-CRM — OSTRÝ IMPORT (den D).
 *
 * Bez přepínače --execute jede v REŽIMU SIMULACE: provede kompletní běh
 * v transakci a na konci udělá ROLLBACK — v databázi nezůstane nic.
 * S --execute na konci COMMITne. Vše ostatní je totožné.
 *
 * Použití (na serveru, PO deployi CRM ≥ 2.14.1 a PO ruční záloze!):
 *   php scripts/migrace_import.php /cesta/normalized_orders.json [/cesta/complaints.json] [--execute]
 *
 * Co dělá (schválený plán, viz changelog v2.12.0–2.14.1):
 *   A) dřívější import (marker „Importovaný kód:") označí source='legacy'
 *   B) kolizní CRM-native zakázky přečísluje ZA maximum listu (legacy_code = původní číslo)
 *   C) klienty z listu doplní s dedupem (telefon → e-mail; jen prázdná pole, nic nepřepisuje)
 *   D) zakázky: nové vloží s PŮVODNÍM created_at, existující jen dorovná stav (+pobočka ČR)
 *   E) reklamace: nové vloží, existující dorovná stav; řada RK-xxx nedotčena
 *   Stavy VÝHRADNĚ z aktuálního stavového modelu CRM (rozhodnutí majitele 20.7.2026).
 *   Zakázky bez klienta → společný klient „Import bez klienta".
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$crmRoot = is_file(__DIR__ . '/../includes/config.php') ? dirname(__DIR__) : '/home/augi/repair-crm';
ob_start();
require_once $crmRoot . '/includes/config.php';
require_once $crmRoot . '/includes/functions.php';
ob_end_clean();

if (!isset($pdo)) { fwrite(STDERR, "DB nedostupná\n"); exit(1); }

/* ── Argumenty ───────────────────────────────────────────────────────────── */
$files = []; $execute = false; $force = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--execute') { $execute = true; continue; }
    if ($a === '--force')   { $force = true; continue; }
    $files[] = $a;
}
$ordersFile = $files[0] ?? null;
$cmplFile   = $files[1] ?? null;
if (!$ordersFile || !is_readable($ordersFile)) { fwrite(STDERR, "Chybí normalized_orders.json (arg 1)\n"); exit(1); }

$data = json_decode(file_get_contents($ordersFile), true);
if (!$data || empty($data['orders'])) { fwrite(STDERR, "Vadný JSON zakázek\n"); exit(1); }
$L  = $data['orders'];
$LC = $data['customers'] ?? [];
$CM = [];
if ($cmplFile) {
    if (!is_readable($cmplFile)) { fwrite(STDERR, "Nečitelný soubor reklamací: $cmplFile\n"); exit(1); }
    $cm = json_decode(file_get_contents($cmplFile), true);
    $CM = $cm['records'] ?? [];
}

function say(string $s = ''): void { echo $s . "\n"; }
function normPhone(?string $p): string {
    $p = preg_replace('/[^0-9+]/', '', (string)$p);
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if (preg_match('/^[67]\d{8}$/', $p)) $p = '+420' . $p;
    return $p;
}
function normName(?string $s): string {
    $s = mb_strtolower(trim((string)$s));
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    return preg_replace('/\s+/', ' ', ($t !== false && $t !== '') ? $t : $s);
}
function splitName(string $full): array {
    $parts = preg_split('/\s+/', trim($full)) ?: [];
    if (!$parts) return ['', ''];
    $first = array_shift($parts);
    return [$first, implode(' ', $parts)];
}
function deviceType(string $dev): string {
    $d = mb_strtolower($dev);
    if (preg_match('/iphone|galaxy|redmi|pixel|xperia|telefon|phone|oneplus|honor|huawei|moto/u', $d)) return 'Phone';
    if (preg_match('/macbook|notebook|laptop|thinkpad|thinkbook|ideapad|vivobook|zenbook|aspire|latitude|pavilion|elitebook|probook/u', $d)) return 'Notebook';
    if (preg_match('/ipad|tablet|tab /u', $d)) return 'Tablet';
    if (preg_match('/imac|mac mini|mac pro|mac studio|\bpc\b|desktop/u', $d)) return 'PC';
    return 'Other';
}

say(str_repeat('═', 70));
say('  IMPORT zakazkovylist.cz → Fix-CRM   (' . date('d.m.Y H:i:s') . ')');
say('  Režim: ' . ($execute ? '⚠️  OSTRÝ BĚH (--execute, zapíše se!)' : 'SIMULACE (rollback na konci, nic se neuloží)'));
say('  Zdroj: ' . basename($ordersFile) . ' (' . count($L) . ' zakázek, ' . count($LC) . ' klientů, ' . count($CM) . ' reklamací)');
say(str_repeat('═', 70));

/* ── PREFLIGHT (vč. DDL — MUSÍ proběhnout PŘED transakcí, DDL commituje) ── */
if (!function_exists('ensureOrdersSourceColumn')) {
    fwrite(STDERR, "!! Na serveru běží stará verze CRM bez ensureOrdersSourceColumn — nasaď ≥ v2.12.0 a spusť znovu.\n");
    exit(1);
}
ensureOrdersSourceColumn();
if (function_exists('ensureComplaintsWorkflowColumns')) { try { ensureComplaintsWorkflowColumns($pdo); } catch (Throwable $e) {} }

$oCols = array_column($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['source', 'legacy_code'] as $c) {
    if (!in_array($c, $oCols, true)) { fwrite(STDERR, "!! Sloupec orders.$c neexistuje ani po ensure — zkontroluj deploy.\n"); exit(1); }
}

/* Stavy: cíle musí být v AKTUÁLNÍM modelu (ne legacy) */
$defs = getOrderStatusDefinitions();
$badTargets = [];
foreach ($L as $o) {
    $t = $o['status_crm'] ?? null;
    if ($t === null || !isset($defs[$t]) || !empty($defs[$t]['legacy'])) $badTargets[$t ?? ('RAW:' . $o['status_raw'])] = true;
}
if ($badTargets) { fwrite(STDERR, "!! Cílové stavy mimo aktuální model: " . implode(', ', array_keys($badTargets)) . "\n"); exit(1); }

/* Reklamace: cíle VÝHRADNĚ reklamační model CRM (['Přijato','V řešení','Čeká na zákazníka',
   'Vyřízeno','Zamítnuto'] — reklamace.php / api/update_complaint_status.php). List používá
   pro reklamace slovník STAVŮ ZAKÁZEK („Vydáno", „Nevyzvednuto"…), takže je nutné je přemapovat
   na reklamační model (rozhodnutí majitele „stavy použij naše nové"). DEFAULTY níže — hotové
   reklamace → „Vyřízeno"; majitel může jednotlivé přemapovat. */
$CMPL_MODEL = ['Přijato', 'V řešení', 'Čeká na zákazníka', 'Vyřízeno', 'Zamítnuto'];
$cmplStatusMap = [
    'Přijato'                => 'Přijato',
    'V řešení'               => 'V řešení',
    'Čeká na zákazníka'      => 'Čeká na zákazníka',
    'Vyřízeno'               => 'Vyřízeno',
    'Zamítnuto'              => 'Zamítnuto',
    'Vydáno'                 => 'Vyřízeno',           // reklamace vyřízena a zařízení vydáno
    'Připraveno k převzetí'  => 'Vyřízeno',           // vyřízeno, čeká na vyzvednutí
    'Nevyzvednuto'           => 'Vyřízeno',           // vyřízeno, klient si nevyzvedl
    'Stornováno'             => 'Zamítnuto',
];
$cmplMap = static function (string $raw) use ($cmplStatusMap): ?string {
    $raw = trim($raw);
    return $cmplStatusMap[$raw] ?? null;   // null = neznámý stav → preflight to zachytí
};
if ($CM) {
    $badCmpl = [];
    foreach ($CM as $r) {
        $st = trim((string)($r['s'] ?? ''));
        if ($st === '' && empty($r['o']) && ($r['r'] ?? '') === '') continue;   // prázdný koncept (přeskočí se)
        $m = $cmplMap($st);
        if ($m === null || !in_array($m, $CMPL_MODEL, true)) $badCmpl[$st === '' ? '(prázdný)' : $st] = true;
    }
    if ($badCmpl) { fwrite(STDERR, "!! Stavy reklamací mimo mapu/model: " . implode(', ', array_keys($badCmpl)) . "\n"); exit(1); }
}

/* Duplicitní kódy klientů = rozbitý scrape (parser je má dedupovat) — tvrdě zastavit */
$__ccodes = array_filter(array_column($LC, 'code'));
if (count($__ccodes) !== count(array_unique($__ccodes))) {
    fwrite(STDERR, "!! normalized_orders.json: duplicitní kódy klientů — přegeneruj parserem (parse_customers dedup).\n");
    exit(1);
}

/* Pojistka proti opakovanému ostrému běhu (jednorázová migrace) */
$__migDone = get_setting('migration_zakazkovylist_done', '');
if ($execute && $__migDone && !$force) {
    fwrite(STDERR, "!! Migrace už proběhla ($__migDone). Pro vědomé opakování přidej --force.\n");
    exit(1);
}

/* Pobočky */
$branches = $pdo->query('SELECT id, name FROM branches')->fetchAll(PDO::FETCH_KEY_PAIR);
$karlinId = $prikopeId = null;
foreach ($branches as $bid => $bn) {
    $n = normName($bn);
    if (str_contains($n, 'prikop') || str_contains($n, 'cerna ruze')) $prikopeId = (int)$bid;
    if (str_contains($n, 'karlin') || str_contains($n, 'krizikova')) $karlinId = (int)$bid;
}
if (!$karlinId || !$prikopeId) { fwrite(STDERR, "!! Nenašel jsem pobočky Karlín/Na Příkopě: " . json_encode($branches, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
say("Pobočky: Karlín=#$karlinId · Na Příkopě=#$prikopeId");

/* Technici (párování: přesná shoda → podřetězec; složené „A, B" → první) */
$techRows = $pdo->query('SELECT id, name FROM technicians')->fetchAll(PDO::FETCH_ASSOC);
$techByName = [];
foreach ($techRows as $t) $techByName[normName($t['name'])] = (int)$t['id'];
$techMatch = static function (string $raw) use ($techByName): ?int {
    $first = normName(trim(explode(',', $raw)[0]));
    if ($first === '') return null;
    if (isset($techByName[$first])) return $techByName[$first];
    foreach ($techByName as $tn => $tid) {
        if ($tn !== '' && (str_contains($first, $tn) || str_contains($tn, $first))) return $tid;
    }
    return null;
};

$listSet = [];
$maxListNum = 0;
foreach ($L as $o) {
    $listSet[$o['code']] = true;
    $n = (int)preg_replace('/\D/', '', $o['code']);
    if ($n > $maxListNum) $maxListNum = $n;
}

/* ═════════════════════════ TRANSAKCE ═════════════════════════ */
$pdo->beginTransaction();
try {

/* ── A) Označit dřívější import ──────────────────────────────────────────── */
$aff = $pdo->exec("UPDATE orders SET source = 'legacy'
                   WHERE technician_notes LIKE '%Importovaný kód:%' AND source <> 'legacy'");
say("\nA) Dřívější import označen source='legacy': $aff zakázek");

/* ── B) Přečíslování kolizních CRM-native zakázek ────────────────────────── */
$crmAll = $pdo->query("SELECT id, order_code, status, customer_id, branch_id, source,
                              (technician_notes LIKE '%Importovaný kód:%') AS was_imported
                       FROM orders WHERE order_code IS NOT NULL AND order_code <> ''")->fetchAll(PDO::FETCH_ASSOC);
$colliding = [];
$maxCrmNum = 0;
foreach ($crmAll as $r) {
    $n = (int)preg_replace('/\D/', '', $r['order_code']);
    if ($n > $maxCrmNum && str_starts_with($r['order_code'], 'APFAZ')) $maxCrmNum = $n;
    if (isset($listSet[$r['order_code']]) && !$r['was_imported'] && $r['source'] !== 'legacy') $colliding[] = $r;
}
usort($colliding, static fn($a, $b) => (int)preg_replace('/\D/','',$a['order_code']) <=> (int)preg_replace('/\D/','',$b['order_code']));
$base = max($maxListNum, $maxCrmNum);
$renumbered = [];
$updRenum = $pdo->prepare('UPDATE orders SET legacy_code = ?, order_code = ? WHERE id = ?');
foreach ($colliding as $r) {
    $base++;
    $newCode = 'APFAZ' . $base;
    $updRenum->execute([$r['order_code'], $newCode, (int)$r['id']]);
    $renumbered[] = $r['order_code'] . ' → ' . $newCode;
}
say('B) Přečíslováno kolizních CRM zakázek: ' . count($renumbered)
    . ($renumbered ? ' (' . $renumbered[0] . ' … ' . end($renumbered) . ')' : ''));
say('   Čítač nových zakázek naváže od: APFAZ' . ($base + 1));

/* ── C) Klienti ──────────────────────────────────────────────────────────── */
$crmCust = $pdo->query('SELECT id, first_name, last_name, phone, email, company FROM customers')->fetchAll(PDO::FETCH_ASSOC);
$byP = $byE = $byN = [];
$indexCust = static function (array $c) use (&$byP, &$byE, &$byN): void {
    $p = normPhone($c['phone'] ?? ''); $e = mb_strtolower(trim((string)($c['email'] ?? '')));
    $n = normName(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    if ($p && !isset($byP[$p])) $byP[$p] = (int)$c['id'];
    if ($e && !isset($byE[$e])) $byE[$e] = (int)$c['id'];
    if ($n && !isset($byN[$n])) $byN[$n] = (int)$c['id'];
};
foreach ($crmCust as $c) $indexCust($c);

/* interní dedup seznamu z listu (4× Charlie Sheppard apod.) */
$dedup = [];
foreach ($LC as $c) {
    $key = normPhone($c['phone']) ?: mb_strtolower(trim($c['email'])) ?: normName($c['name']) ?: $c['code'];
    if (isset($dedup[$key])) {
        foreach (['phone', 'email', 'company'] as $f) {
            if (empty($dedup[$key][$f]) && !empty($c[$f])) $dedup[$key][$f] = $c[$f];
        }
    } else { $dedup[$key] = $c; }
}

$insCust = $pdo->prepare("INSERT INTO customers (customer_type, first_name, last_name, phone, email, company, preferred_language)
                          VALUES (?, ?, ?, ?, ?, ?, 'cs')");
$custCodeMap = [];             // APFC kód → CRM id
$cNew = $cFill = $cSkip = 0;
foreach ($dedup as $c) {
    $p = normPhone($c['phone']); $e = mb_strtolower(trim($c['email']));
    $hitId = ($p && isset($byP[$p])) ? $byP[$p] : (($e && isset($byE[$e])) ? $byE[$e] : null);
    if ($hitId) {
        /* doplnit JEN prázdná pole (CRM je autorita) */
        $row = $pdo->prepare('SELECT phone, email, company FROM customers WHERE id = ?');
        $row->execute([$hitId]); $row = $row->fetch(PDO::FETCH_ASSOC);
        $set = []; $vals = [];
        if (!trim((string)$row['phone']) && $p) { $set[] = 'phone = ?'; $vals[] = $c['phone']; }
        if (!trim((string)$row['email']) && $e) { $set[] = 'email = ?'; $vals[] = $c['email']; }
        if (!trim((string)$row['company']) && trim((string)$c['company'])) { $set[] = 'company = ?'; $vals[] = $c['company']; }
        if ($set) { $vals[] = $hitId; $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals); $cFill++; }
        else $cSkip++;
    } else {
        /* shoda jen jménem → dle rozhodnutí vkládáme jako NOVÉHO klienta */
        [$fn, $ln] = splitName($c['name']);
        $insCust->execute([trim((string)$c['company']) !== '' ? 'company' : 'private', $fn, $ln, $c['phone'], $c['email'], $c['company']]);
        $hitId = (int)$pdo->lastInsertId();
        $indexCust(['id' => $hitId, 'first_name' => $fn, 'last_name' => $ln, 'phone' => $c['phone'], 'email' => $c['email']]);
        $cNew++;
    }
    if (!empty($c['code'])) $custCodeMap[$c['code']] = $hitId;
}
say("C) Klienti: nových $cNew · doplněno $cFill · beze změny $cSkip");

/* společný klient pro zakázky bez klienta (založí se, jen když bude potřeba) */
$placeholderId = null;
$getPlaceholder = static function () use ($pdo, &$placeholderId): int {
    if ($placeholderId) return $placeholderId;
    $q = $pdo->prepare("SELECT id FROM customers WHERE first_name = 'Import' AND last_name = 'bez klienta' LIMIT 1");
    $q->execute();
    $placeholderId = (int)$q->fetchColumn();
    if (!$placeholderId) {
        $pdo->prepare("INSERT INTO customers (customer_type, first_name, last_name, phone, email, company, preferred_language)
                       VALUES ('private', 'Import', 'bez klienta', '', '', '', 'cs')")->execute();
        $placeholderId = (int)$pdo->lastInsertId();
    }
    return $placeholderId;
};

/* ── D) Zakázky ──────────────────────────────────────────────────────────── */
$crmByCode = [];
foreach ($pdo->query('SELECT id, order_code, status, branch_id FROM orders WHERE order_code IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $crmByCode[$r['order_code']] = $r;
}

$insOrder = $pdo->prepare(
    "INSERT INTO orders (customer_id, technician_id, branch_id, device_type, order_type,
                         device_brand, device_model, problem_description, technician_notes,
                         pin_code, priority, estimated_cost, shipping_method, status,
                         order_code, source, created_at)
     VALUES (?, ?, ?, ?, 'Non-Warranty', ?, ?, ?, ?, ?, 'Normal', ?, ?, ?, ?, 'legacy', ?)");

$resolveCustomer = static function (array $o) use (&$byP, &$byE, &$byN, $custCodeMap, $pdo, $insCust, $indexCust, $getPlaceholder): int {
    if (!empty($o['customer_code']) && isset($custCodeMap[$o['customer_code']])) return $custCodeMap[$o['customer_code']];
    $p = normPhone($o['customer_phone']); $e = mb_strtolower(trim((string)$o['customer_email']));
    if ($p && isset($byP[$p])) return $byP[$p];
    if ($e && isset($byE[$e])) return $byE[$e];
    $n = normName($o['customer_name']);
    if ($n && isset($byN[$n])) return $byN[$n];
    if ($o['customer_name'] || $p || $e) {
        [$fn, $ln] = splitName((string)$o['customer_name'] ?: 'Klient z importu');
        $insCust->execute(['private', $fn, $ln, $o['customer_phone'], $o['customer_email'], '']);
        $id = (int)$pdo->lastInsertId();
        $indexCust(['id' => $id, 'first_name' => $fn, 'last_name' => $ln, 'phone' => $o['customer_phone'], 'email' => $o['customer_email']]);
        return $id;
    }
    return $getPlaceholder();
};

$oIns = $oUpd = $oSame = 0;
$updStatus = $pdo->prepare("UPDATE orders SET status = ?, source = 'legacy', branch_id = ? WHERE id = ?");
$updSourceOnly = $pdo->prepare("UPDATE orders SET source = 'legacy', branch_id = ? WHERE id = ?");
foreach ($L as $o) {
    $status = $o['status_crm'];
    $branchId = ($o['branch'] === 'prikope') ? $prikopeId : $karlinId;
    $ex = $crmByCode[$o['code']] ?? null;
    if ($ex) {
        /* existující (dřívější import) → jen dorovnat stav + pobočku + source */
        $targetBranch = ($o['branch'] === 'prikope') ? $prikopeId : (int)$ex['branch_id'];   // karlínské neměnit
        if ($ex['status'] !== $status) { $updStatus->execute([$status, $targetBranch, (int)$ex['id']]); $oUpd++; }
        else { $updSourceOnly->execute([$targetBranch, (int)$ex['id']]); $oSame++; }
        continue;
    }
    /* nová zakázka */
    $customerId = $resolveCustomer($o);
    $techRaw = $o['tech_opravoval'] ?: ($o['tech_vydal'] ?: $o['tech_prijal']);
    $techId = $techRaw ? $techMatch($techRaw) : null;

    $noteParts = [];
    if ($o['state_desc'])   $noteParts[] = 'Stav zařízení: ' . $o['state_desc'];
    if ($o['diagnostics'])  $noteParts[] = 'Diagnostika: ' . $o['diagnostics'];
    if ($o['note'])         $noteParts[] = 'Poznámka: ' . $o['note'];
    if ($o['device_pin'])   $noteParts[] = 'Heslo zařízení: ' . $o['device_pin'];
    if ($o['handover_note'])$noteParts[] = 'Poznámka k předání: ' . $o['handover_note'];
    $t = array_filter(['Přijal ' . ($o['tech_prijal'] ?: '—'), 'Opravoval ' . ($o['tech_opravoval'] ?: '—'), 'Vydal ' . ($o['tech_vydal'] ?: '—')]);
    $noteParts[] = 'Technici (zakázkový list): ' . implode(' · ', $t);
    if ($o['status_raw'] === 'Zakládá se') $noteParts[] = 'Import: v listu nedokončené založení („Zakládá se").';
    if ($o['max_cost'])     $noteParts[] = 'Maximální cena (list): ' . $o['max_cost'] . ' Kč';
    $noteParts[] = 'Import ze zakazkovylist.cz (' . $o['status_raw'] . ')';

    $insOrder->execute([
        $customerId, $techId, $branchId, deviceType($o['device_raw']),
        $o['device_brand'] ?: 'Ostatní', $o['device_model'] ?: $o['device_raw'],
        $o['problem'] ?: '—', implode("\n", $noteParts),
        $o['order_pin'] ?: '', $o['estimated_cost'],   // null = cena nezadaná (sloupec DEFAULT NULL) — NEplést s 0 Kč
        $o['handover_method'] ?: null, $status, $o['code'],
        $o['created_at'],
    ]);
    $oIns++;
}
say("D) Zakázky: vloženo $oIns · dorovnán stav $oUpd · beze změny $oSame");

/* ── E) Reklamace ────────────────────────────────────────────────────────── */
$kIns = $kUpd = $kSame = $kSkip = 0;
if ($CM) {
    $crmCmpl = [];
    foreach ($pdo->query('SELECT id, complaint_code, complaint_status FROM complaints')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $crmCmpl[$r['complaint_code']] = $r;
    }
    $ordByCode = [];
    foreach ($pdo->query('SELECT id, order_code, customer_id FROM orders WHERE order_code IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ordByCode[$r['order_code']] = $r;
    }
    $insCmpl = $pdo->prepare(
        "INSERT INTO complaints (complaint_code, customer_id, order_id, order_code, phone, device, complaint_reason, complaint_status, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'staff')");
    $updCmpl = $pdo->prepare('UPDATE complaints SET complaint_status = ? WHERE id = ?');
    /* jméno klienta bez jediného písmene („...", „—") = fakticky prázdné */
    $hasLetter = static fn(string $s): bool => (bool)preg_match('/\p{L}/u', $s);
    foreach ($CM as $r) {
        $code = $r['c']; $stRaw = (string)$r['s'];
        if ($stRaw === '' && empty($r['o']) && ($r['r'] ?? '') === '') { $kSkip++; continue; }   // prázdný koncept
        $st = $cmplMap($stRaw) ?? 'Přijato';   // mapa na reklamační model CRM (preflight už ověřil platnost)
        if (isset($crmCmpl[$code])) {
            if ($crmCmpl[$code]['complaint_status'] !== $st) { $updCmpl->execute([$st, (int)$crmCmpl[$code]['id']]); $kUpd++; }
            else $kSame++;
            continue;
        }
        $ord = $r['o'] ? ($ordByCode[$r['o']] ?? null) : null;
        $customerId = $ord ? (int)$ord['customer_id'] : null;
        $phone = '';
        if (!$ord) {
            /* e-shopová reklamace (APFARE) bez zakázky → klient dle kontaktu */
            $phone = (string)($r['ph'] ?? '');
            $p = normPhone($phone); $e = mb_strtolower(trim((string)($r['em'] ?? '')));
            $customerId = ($p && isset($byP[$p])) ? $byP[$p] : (($e && isset($byE[$e])) ? $byE[$e] : null);
            $cuName = trim((string)($r['cu'] ?? ''));
            if (!$hasLetter($cuName)) $cuName = '';   // „..." → nevytvářet paskvil-klienta
            if (!$customerId && ($cuName !== '' || $p || $e)) {
                [$fn, $ln] = splitName($cuName ?: 'Klient z importu');
                $insCust->execute(['private', $fn, $ln, $phone, (string)($r['em'] ?? ''), '']);
                $customerId = (int)$pdo->lastInsertId();
            }
            if (!$customerId) $customerId = $getPlaceholder();
        }
        $insCmpl->execute([$code, $customerId, $ord ? (int)$ord['id'] : null, (string)$r['o'], $phone, (string)$r['d'], (string)$r['r'], $st]);
        $kIns++;
    }
    say("E) Reklamace: vloženo $kIns · dorovnáno $kUpd · beze změny $kSame · přeskočeno $kSkip");
} else {
    say('E) Reklamace: soubor nepředán — přeskočeno');
}

/* ── Kontrolní počty (ještě uvnitř transakce) ────────────────────────────── */
say("\n── KONTROLA ──");
$tot   = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$leg   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE source = 'legacy'")->fetchColumn();
$nat   = $tot - $leg;
$legPr = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE source = 'legacy' AND branch_id = " . (int)$prikopeId)->fetchColumn();
$countsOk = ($leg === count($L));
say("  Zakázek celkem: $tot  ·  import (šedé): $leg  ·  naše CRM (barevné): $nat");
say('  Očekáváno: import = ' . count($L) . ' (list) → ' . ($countsOk ? 'SOUHLASÍ ✓' : '!! NESOUHLASÍ'));
say("  Import na pobočce Na Příkopě: $legPr");
$custTot = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$cmplTot = (int)$pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
say("  Klientů celkem: $custTot  ·  reklamací celkem: $cmplTot");
$next = generateNextOrderCode($pdo);
say('  Další číslo nové zakázky: ' . ($next ?? '???'));

/* ═════════════════════════ ZÁVĚR ═════════════════════════ */
if ($execute && !$countsOk) {
    $pdo->rollBack();
    fwrite(STDERR, "\n!! KONTROLA SELHALA: import ($leg) ≠ počet zakázek v listu (" . count($L) . ") — VŠE VRÁCENO (rollback). Nic se nezapsalo.\n");
    exit(1);
}
if ($execute) {
    set_setting('migration_zakazkovylist_done', date('Y-m-d H:i:s'));   // pojistka proti dvojímu běhu
    $pdo->commit();
    say("\n✅ COMMIT — migrace zapsána. Rollback = obnova zálohy (Systém → Databáze).");
} else {
    $pdo->rollBack();
    say("\n↩️  ROLLBACK — simulace, v databázi nezůstalo nic. Ostrý běh: přidej --execute.");
}
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\n!! CHYBA — vše vráceno (rollback): " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
