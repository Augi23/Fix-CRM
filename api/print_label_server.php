<?php
/* SERVEROVÝ tisk štítků (Brother QL-810W na pobočce Karlín):
   prohlížeč → HTTPS → server → tiskárna (tcp 9100). Bez můstku na počítačích,
   funguje ze Safari, iPadu i telefonu.
     POST action=print, id=<order_id>   → tisk štítku zakázky
     POST action=test                   → testovací štítek
     GET  action=status                 → dosažitelnost tiskárny + stav prostředí
     POST action=save_ip, ip=<IP>       → uložení IP tiskárny (admin) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}

$action = (string)($_REQUEST['action'] ?? 'print');
$printerIp = trim((string)get_setting('label_printer_ip', '192.168.1.220'));

$VENV_DIR = realpath(__DIR__ . '/../print-bridge') . '/.venv';
$PY = $VENV_DIR . '/bin/python3';
$CLI = realpath(__DIR__ . '/../print-bridge') . '/stitek_print_cli.py';

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
    if (!hasPermission('admin_access') || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit;
    }
    $ip = trim((string)($_POST['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['ok' => false, 'error' => 'Neplatná IP adresa']); exit;
    }
    set_setting('label_printer_ip', $ip);
    echo json_encode(['ok' => true, 'ip' => $ip]); exit;
}

if ($action === 'status') {
    echo json_encode([
        'ok' => true,
        'printer_ip' => $printerIp,
        'printer_reachable' => afxPrinterReachable($printerIp),
        'env_ready' => is_file($PY),
    ]); exit;
}

// ── tisk (print / test) ──
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}
if (!afxPrinterReachable($printerIp)) {
    echo json_encode(['ok' => false, 'error' => 'Tiskárna ' . $printerIp . ' neodpovídá (port 9100). Je zapnutá a na síti pobočky?']); exit;
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
        'baterie' => $bat, 'ram' => $ram, 'cpu' => $cpu, 'gpu' => $gpu,
        'sn' => $sn, 'cena' => $cena,
        'mac' => str_contains(mb_strtolower($nazev !== '' ? $nazev : $title), 'macbook'),
    ];
    $args = ['--product-json' => base64_encode((string)json_encode($data, JSON_UNESCAPED_UNICODE))];
} elseif ($action === 'test') {
    $args = ['--code' => 'TEST' . date('His'), '--defect' => 'Testovací štítek ze serveru', '--date' => date('d.m.Y'), '--client' => 'Fix-CRM'];
} else {
    $orderId = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT o.id, o.order_code, o.problem_description, o.created_at, o.branch_id, o.technician_id,
        TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name, c.company
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
    if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }

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
$outLines = [];
$rc = 0;
set_time_limit(60);
exec($cmd . ' 2>&1', $outLines, $rc);
$last = trim((string)end($outLines));
$res = json_decode($last, true);

if (is_array($res) && !empty($res['ok'])) {
    echo json_encode(['ok' => true, 'code' => $args['--code'] ?? 'produkt']);
} else {
    $err = is_array($res) ? (string)($res['error'] ?? '') : implode(' | ', array_slice($outLines, -3));
    echo json_encode(['ok' => false, 'error' => 'Tisk selhal: ' . ($err !== '' ? $err : 'neznámá chyba')]);
}
