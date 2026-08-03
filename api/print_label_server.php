<?php
/* SERVEROVÝ tisk štítků (Brother QL-810W) — IP tiskárny PER POBOČKU:
   prohlížeč → HTTPS → server → tiskárna té pobočky (tcp 9100). Bez můstku na počítačích,
   funguje ze Safari, iPadu i telefonu. Štítek zakázky vyjede na tiskárně pobočky, které
   zakázka patří (branchPrinterIp). Pobočka v jiné síti než server potřebuje agenta/VPN.
     POST action=print, id=<order_id>          → tisk štítku zakázky (tiskárna pobočky zakázky)
     POST action=print_complaint, id=<cmpl_id> → štítek reklamace (RK kód, tiskárna pobočky zaměstnance)
     POST action=test [branch_id]              → testovací štítek (tiskárna pobočky / zvolené admin)
     POST action=print_product, id=<prod_id>   → cenový štítek produktu
     GET  action=status [branch_id]            → dosažitelnost tiskárny pobočky + stav prostředí
     POST action=save_ip, ip=<IP> [branch_id]  → uložení IP tiskárny pobočky (admin) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}

$action = (string)($_REQUEST['action'] ?? 'print');
// IP tiskárny se řeší PER POBOČKU (branchPrinterIp) — až po zjištění pobočky dané akce.
ensureBranchPrinterColumn();

$VENV_DIR = realpath(__DIR__ . '/../print-bridge') . '/.venv';
$PY = $VENV_DIR . '/bin/python3';
$CLI = realpath(__DIR__ . '/../print-bridge') . '/stitek_print_cli.py';

/** Smí se štítek téhle pobočky vytisknout přes MŮSTEK NA POČÍTAČI obsluhy?
 *  Jen když jde o VLASTNÍ pobočku přihlášeného. Bez toho by vedení (vidí zakázky
 *  obou poboček) tisklo štítky Na Příkopě na karlínské tiskárně u sebe na stole —
 *  tedy přesně ta chyba, kterou pobočkové tiskárny odstraňují. */
function afxLabelBridgeAllowed(int $branchId): bool {
    // pobočka Z DB (crmStaffBranchIdStrict), ne odhad z getCurrentStaffBranchId —
    // ten u člověka bez přiřazené pobočky propadne na Karlín
    $own = function_exists('crmStaffBranchIdStrict') ? crmStaffBranchIdStrict() : 0;
    return $branchId > 0 && $own > 0 && $branchId === $own;
}

function afxPrinterReachable(string $ip): bool {
    $fp = @fsockopen($ip, 9100, $errno, $errstr, 2);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/** Jednorázově připraví python prostředí pro tisk (venv + brother_ql). */
function afxEnsureLabelEnv(string $venvDir, string $py): array {
    if (is_file($py)) { return [true, '']; }
    set_time_limit(300);
    $out = [];
    $rc = 0;
    exec('python3 -m venv ' . escapeshellarg($venvDir) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { return [false, 'venv: ' . implode(' ', array_slice($out, -3))]; }
    // Ubuntu bez python3-venv balíčku vytvoří venv bez pipu → bootstrap get-pip
    if (!is_file($venvDir . '/bin/pip')) {
        exec('curl -fsSL https://bootstrap.pypa.io/get-pip.py | ' . escapeshellarg($venvDir . '/bin/python3') . ' - --quiet 2>&1', $out, $rc);
        if (!is_file($venvDir . '/bin/pip')) { return [false, 'pip bootstrap selhal']; }
    }
    exec(escapeshellarg($venvDir . '/bin/pip') . ' install --quiet brother_ql python-barcode pillow 2>&1', $out, $rc);
    if ($rc !== 0 || !is_file($py)) { return [false, 'pip: ' . implode(' ', array_slice($out, -3))]; }
    return [true, ''];
}

if ($action === 'save_ip') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
    }
    $ip = trim((string)($_POST['ip'] ?? ''));
    $bid = (int)($_POST['branch_id'] ?? 0);
    // Párovat smí admin kteroukoli pobočku, ostatní zaměstnanci JEN tu svou —
    // druhá pobočka si tak tiskárnu nastaví sama a nikomu ji nepřepíše.
    if (!crmCanPairBranchPrinter($bid > 0 ? $bid : null)) {
        echo json_encode(['ok' => false, 'error' => 'Tiskárnu smíš párovat jen na své pobočce.']); exit;
    }
    if ($ip !== '') {
        // JEN privátní adresa: veřejné/loopback by z odpovědi „reachable" udělaly
        // skener otevřených portů 9100 v okolí serveru (a tisk by stejně nefungoval).
        $isIp = filter_var($ip, FILTER_VALIDATE_IP);
        // NO_PRIV_RANGE|NO_RES_RANGE odmítne veřejné adresy, ale loopback ani
        // link-local NE (127.0.0.1 se tváří stejně jako 192.168.x.x) — proto navíc
        // výslovný seznam: přes uložení + test by šlo šťourat po portu 9100.
        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $isLocalish = in_array($ip, ['127.0.0.1', '0.0.0.0', '::1'], true)
            || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || str_starts_with($ip, 'fe80:');
        if (!$isIp || $isPublic !== false || $isLocalish) {
            echo json_encode(['ok' => false,
                'error' => 'Zadej adresu tiskárny z místní sítě (např. 192.168.x.x).'], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    if ($bid > 0) {
        // prázdná IP = rozpárovat (pobočka pak nemá tiskárnu a tisk to řekne)
        $st = $pdo->prepare("UPDATE branches SET label_printer_ip = ? WHERE id = ?");
        $st->execute([$ip !== '' ? $ip : null, $bid]);
        $__bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $__bn->execute([$bid]);
        crmAuditLog('settings.printer', [
            'entity_type' => 'branch', 'entity_id' => $bid, 'entity_label' => (string)$__bn->fetchColumn(),
            'branch_id' => $bid,
            'summary' => $ip !== '' ? 'Spárována tiskárna štítků ' . $ip : 'Tiskárna štítků odpárována',
        ]);
        // ZÁMĚRNĚ BEZ sondy dosažitelnosti: kdyby uložení rovnou hlásilo „odpovídá",
        // dalo by se opakovaným ukládáním proskenovat port 9100 po celé síti serveru.
        // Stav si klient vyžádá zvlášť přes action=status (a jen pro svou pobočku).
        echo json_encode(['ok' => true, 'ip' => $ip, 'branch_id' => $bid]); exit;
    }
    // Globální tiskárna už neexistuje — od v3.40.0 se tiskne výhradně podle pobočky
    // (branchPrinterIp), takže uložení „bez pobočky" by nemělo na tisk žádný vliv.
    echo json_encode(['ok' => false, 'error' => 'Vyber pobočku, ke které tiskárna patří.'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'status') {
    $bid = (int)($_REQUEST['branch_id'] ?? 0);
    $printerIp = branchPrinterIp($bid ?: null);
    $canPair = crmCanPairBranchPrinter($bid ?: null);
    echo json_encode([
        'ok' => true,
        'branch_id' => $bid,
        'printer_ip' => $canPair ? $printerIp : '',   // adresu vidí jen ten, kdo pobočku spravuje
        'paired' => $printerIp !== '',
        'can_pair' => $canPair,
        'printer_reachable' => $printerIp !== '' && afxPrinterReachable($printerIp),
        'env_ready' => is_file($PY),
    ]); exit;
}

// ── tisk (print / test / print_product) ──
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}

// Pobočku (a tím tiskárnu) urči PŘED kontrolou dosažitelnosti:
//   print         → pobočka ZAKÁZKY (štítek vyjede na tiskárně té pobočky),
//   test/produkt  → pobočka přihlášeného (admin může přes branch_id otestovat konkrétní pobočku).
$order = null;
$cmpl = null;
if ($action === 'print_complaint') {
    // štítek reklamace: RK kód + zařízení + důvod; tiskne se na pobočce zaměstnance
    $cmplId = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT k.id, k.complaint_code, k.device, k.complaint_reason, k.created_at,
        TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name,
        o.branch_id, o.technician_id
        FROM complaints k
        LEFT JOIN customers c ON c.id = k.customer_id
        LEFT JOIN orders o ON o.id = k.order_id
        WHERE k.id = ?');
    $st->execute([$cmplId]);
    $cmpl = $st->fetch();
    if (!$cmpl) { echo json_encode(['ok' => false, 'error' => 'Reklamace nenalezena']); exit; }
    if (!empty($cmpl['branch_id']) && !canAccessOrderBranch($cmpl)) {
        echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit;
    }
    // Pobočka REKLAMOVANÉ ZAKÁZKY, ne pracovníka: jinak by u vedení (vidí obě pobočky)
    // vycházel bridge_ok vždy true a štítek cizí pobočky by vyjel na tomhle počítači.
    $branchId = (int)($cmpl['branch_id'] ?? 0) ?: (int)getCurrentStaffBranchId();
} elseif ($action !== 'test' && $action !== 'print_product') {
    $orderId = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT o.id, o.order_code, o.problem_description, o.created_at, o.branch_id, o.technician_id,
        TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name, c.company
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
    if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }
    $branchId = (int)($order['branch_id'] ?? 0) ?: getCurrentStaffBranchId();
} else {
    $reqBid = (int)($_POST['branch_id'] ?? 0);
    $branchId = ($reqBid > 0 && hasPermission('admin_access')) ? $reqBid : getCurrentStaffBranchId();
}
$printerIp = branchPrinterIp($branchId);

/** Zápis neúspěšného tisku do Historie — ať jde po hlášce „netiskne to" dohledat,
 *  co se dělo. Voláno i u nespárované/nedostupné tiskárny: to jsou nejčastější důvody. */
function afxLabelAuditFail(string $why, int $branchId, string $action, string $label = ''): void {
    try {
        crmAuditLog('label.print_failed', [
            'entity_type' => 'print', 'entity_label' => $label !== '' ? $label : $action,
            'branch_id' => $branchId,
            'summary' => 'Tisk štítku SELHAL (' . $action . '): ' . mb_substr($why, 0, 180),
        ]);
    } catch (Throwable $e) { error_log('label.print_failed audit: ' . $e->getMessage()); }
}

if ($printerIp === '') {
    // Dřív se tady spadlo na tiskárnu v Karlíně — štítek z druhé pobočky vyjel
    // o město dál a nikdo o tom nevěděl. Teď se to rovnou řekne.
    $__bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $__bn->execute([(int)$branchId]);
    $__bname = (string)$__bn->fetchColumn();
    afxLabelAuditFail('pobočka nemá spárovanou tiskárnu', (int)$branchId, $action);
    echo json_encode(['ok' => false, 'not_paired' => true, 'branch_id' => (int)$branchId,
        'bridge_ok' => afxLabelBridgeAllowed((int)$branchId),
        'error' => 'Pobočka ' . ($__bname !== '' ? $__bname : '#' . (int)$branchId)
            . ' nemá spárovanou tiskárnu štítků — spáruj ji v Nastavení → Tisk štítků.'], JSON_UNESCAPED_UNICODE); exit;
}
if (!afxPrinterReachable($printerIp)) {
    afxLabelAuditFail('tiskárna ' . $printerIp . ' neodpovídá (port 9100)', (int)$branchId, $action);
    echo json_encode(['ok' => false, 'unreachable' => true, 'printer_ip' => $printerIp,
        'branch_id' => (int)$branchId, 'bridge_ok' => afxLabelBridgeAllowed((int)$branchId),
        'error' => 'Tiskárna ' . $printerIp . ' neodpovídá (port 9100). Je zapnutá a na síti pobočky?'], JSON_UNESCAPED_UNICODE); exit;
}
[$envOk, $envErr] = afxEnsureLabelEnv($VENV_DIR, $PY);
if (!$envOk) {
    echo json_encode(['ok' => false, 'error' => 'Prostředí tisku se nepodařilo připravit: ' . $envErr]); exit;
}

if ($action === 'print_product') {
    // ── cenový štítek PRODUKTU (Sklad → Produkty) — port label_data() z appky ──
    ensureProductsTable();
    $pid = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) { echo json_encode(['ok' => false, 'error' => 'Produkt nenalezen']); exit; }
    $raw = json_decode((string)($p['raw_csv'] ?? ''), true) ?: [];

    $title = (string)$p['title'];
    $model = trim((string)($p['model'] ?? ''));
    $cap = trim((string)($p['capacity'] ?? ''));
    $color = trim((string)($p['color'] ?? ''));
    $grade = trim((string)($p['grade'] ?? ''));
    $bat = trim((string)($p['battery'] ?? ''));
    $ram = trim((string)($raw['[PARAMETER "RAM"]'] ?? ''));
    $processor = trim((string)($raw['[PARAMETER "Procesor"]'] ?? ''));
    $cpu = trim((string)($raw['CPU_JADRA'] ?? ''));
    $gpu = trim((string)($raw['GPU_JADRA'] ?? ''));
    $sn = (string)$p['product_code'];
    if (str_starts_with(strtoupper($sn), 'AFX') || str_starts_with(strtoupper($sn), 'PREVIEW')) { $sn = ''; }

    $nazev = $model;
    if ($nazev === '') {   // ostatní značky bez PARAMETER Model — odsekat suffixy z názvu
        $nazev = $title;
        foreach ([$grade, $color, $cap] as $suf) {
            if ($suf !== '' && str_ends_with($nazev, ' ' . $suf)) {
                $nazev = rtrim(mb_substr($nazev, 0, mb_strlen($nazev) - mb_strlen(' ' . $suf)));
            }
        }
    }
    if (str_contains(mb_strtolower($title), 'macbook')) {
        // MacBook: starší kusy mají RAM/úložiště jen v názvu — doplnit odsud
        if (preg_match('/(\d+\s*GB)\s*\/\s*(\d+(?:[.,]\d+)?\s*(?:GB|TB))(?:\s*SSD)?/u', $title, $mm, PREG_OFFSET_CAPTURE)) {
            $pname = rtrim(mb_strcut($title, 0, $mm[0][1]), ' ,');
            $pname = trim(preg_replace('/\s+\d+\s*CPU.*$/u', '', $pname));
            if ($pname !== '' && mb_strlen($pname) > mb_strlen($nazev)) { $nazev = $pname; }
            if ($ram === '') { $ram = $mm[1][0]; }
            if ($cap === '') { $cap = $mm[2][0]; }
        }
    }
    // celá cena → „21 290 Kč"; necelá se NEzaokrouhluje (přesně jako appka)
    $priceF = (float)$p['price'];
    if ($priceF <= 0) { $cena = ''; }
    elseif ($priceF == (int)$priceF) { $cena = number_format($priceF, 0, ',', ' ') . ' Kč'; }
    else { $cena = rtrim(rtrim(number_format($priceF, 2, '.', ''), '0'), '.') . ' Kč'; }
    $data = [
        'nazev' => $nazev, 'barva' => $color, 'stav' => $grade, 'uloziste' => $cap,
        'baterie' => $bat, 'ram' => $ram, 'procesor' => $processor, 'cpu' => $cpu, 'gpu' => $gpu,
        'sn' => $sn, 'cena' => $cena,
        'mac' => str_contains(mb_strtolower($nazev !== '' ? $nazev : $title), 'macbook'),
    ];
    $args = ['--product-json' => base64_encode((string)json_encode($data, JSON_UNESCAPED_UNICODE))];
} elseif ($action === 'test') {
    $args = ['--code' => 'TEST' . date('His'), '--defect' => 'Testovací štítek ze serveru', '--date' => date('d.m.Y'), '--client' => 'Fix-CRM'];
} elseif ($action === 'print_complaint') {
    // štítek reklamace — stejný formát jako zakázka: kód (Code128) + zařízení/důvod + klient
    $defect = trim((string)($cmpl['device'] ?? ''));
    $reason = trim(preg_replace('/\s+/', ' ', (string)($cmpl['complaint_reason'] ?? '')));
    if ($reason !== '') { $defect = trim($defect . ($defect !== '' ? ' — ' : '') . $reason); }
    if (mb_strlen($defect) > 80) { $defect = mb_substr($defect, 0, 77) . '…'; }

    $args = [
        '--code' => (string)($cmpl['complaint_code'] ?: ('RK-' . (int)$cmpl['id'])),
        '--defect' => $defect,
        '--date' => !empty($cmpl['created_at']) ? date('d.m.Y', strtotime((string)$cmpl['created_at'])) : date('d.m.Y'),
        '--client' => trim((string)($cmpl['client_name'] ?? '')),
    ];
} else {
    // print — $order už načtený a ověřený (canAccessOrderBranch) výše
    $defect = trim((string)($order['problem_description'] ?? ''));
    if (mb_strlen($defect) > 80) { $defect = mb_substr($defect, 0, 77) . '…'; }
    $client = trim((string)($order['client_name'] ?? '')) ?: trim((string)($order['company'] ?? ''));

    $args = [
        '--code' => orderDisplayCode($order),
        '--defect' => $defect,
        '--date' => !empty($order['created_at']) ? date('d.m.Y', strtotime((string)$order['created_at'])) : date('d.m.Y'),
        '--client' => $client,
    ];
}

$cmd = escapeshellarg($PY) . ' ' . escapeshellarg($CLI) . ' --ip ' . escapeshellarg($printerIp);
foreach ($args as $k => $v) { $cmd .= ' ' . $k . ' ' . escapeshellarg((string)$v); }
// POČET KOPIÍ: u produktu naskladněného po víc kusech chce obsluha štítek na každý
// kus (jinak by 19 z 20 krytů leželo v regále bez ceny — přesně to, kvůli čemu se
// rušilo tlačítko „Přidat" bez tisku). Strop 20, ať překlep nevytiskne roli.
$copies = 1;
if ($action === 'print_product') { $copies = max(1, min(20, (int)($_POST['copies'] ?? 1))); }

$outLines = [];
$rc = 0;
set_time_limit(60 + 10 * $copies);
$res = null;
$printed = 0;
for ($i = 0; $i < $copies; $i++) {
    $outLines = [];
    exec($cmd . ' 2>&1', $outLines, $rc);
    $last = trim((string)end($outLines));
    $res = json_decode($last, true);
    if (!is_array($res) || empty($res['ok'])) { break; }   // při chybě dál netiskneme
    $printed++;
    if ($i + 1 < $copies) { usleep(400000); }              // tiskárna potřebuje oddech mezi štítky
}

if (is_array($res) && !empty($res['ok'])) {
    echo json_encode(['ok' => true, 'code' => $args['--code'] ?? 'produkt', 'copies' => $printed]);
} else {
    $err = is_array($res) ? (string)($res['error'] ?? '') : implode(' | ', array_slice($outLines, -3));
    // NEÚSPĚŠNÝ TISK PATŘÍ DO HISTORIE. Dokud se nikam nezapisoval, nešlo po
    // hlášce „netiskne to" vůbec zjistit, jestli se o tisk někdo pokusil, na které
    // tiskárně a co odpověděla — a hláška v rohu obrazovky zmizí dřív, než ji
    // někdo přečte. Úspěšné tisky se nelogují (bylo by jich denně sto).
    afxLabelAuditFail('tiskárna ' . $printerIp . ': ' . ($err !== '' ? $err : 'neznámá chyba'),
        (int)$branchId, $action, (string)($args['--code'] ?? ('produkt #' . (int)($_POST['id'] ?? 0))));
    // částečná dávka: obsluha musí vědět, kolik štítků UŽ vyjelo, jinak dotiskne duplicity
    $partial = ($copies > 1 && $printed > 0) ? (' Vytisklo se ' . $printed . ' z ' . $copies . ' — dotiskni zbylé.') : '';
    echo json_encode(['ok' => false, 'printed' => $printed, 'copies' => $copies,
        'error' => 'Tisk selhal: ' . ($err !== '' ? $err : 'neznámá chyba') . $partial], JSON_UNESCAPED_UNICODE);
}
