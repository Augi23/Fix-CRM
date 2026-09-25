<?php
/**
 * NASTAVENÍ POKLADNÍ (ÚČTENKOVÉ) TISKÁRNY POBOČKY — v3.81.0
 *
 * Proč to vzniklo: účtenkovou tiskárnu měl dlouho jen Karlín a cíl tisku byl jediný
 * globální (receipt_printer_target). Pobočka Na Příkopě má stejný Xprinter XP58-IIN,
 * ale není se serverem v Karlíně nijak propojená — tiskne tedy POČÍTAČ U JEJICH KASY:
 *   · prohlížeč pošle hotové bajty na místní můstek 127.0.0.1:9101, a když na něj
 *     nedosáhne (appka z TestFlightu, Safari), úloha se uloží do fronty na serveru
 *     a poller na tom Macu si ji do ~2 s stáhne (api/print_poll.php).
 * Poller se prokazuje TAJNÝM TOKENEM POBOČKY — a ten se doteď nedal nikde získat.
 * Tohle je ta chybějící část: token vyrobit, ukázat, otočit a složit instalační
 * příkaz pro Mac na pobočce.
 *
 *   GET  ?action=status&branch_id=N   → stav pobočky (token, poslední ozvání, cíl)
 *   POST action=token, branch_id      → vyrobí / otočí token pobočky
 *   POST action=save_target, branch_id, target → serverový cíl tisku pobočky ('' = tiskne kasa)
 *
 * Smí jen ten, kdo smí pobočce párovat tiskárny (admin kteroukoli, ostatní tu svou).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno'], JSON_UNESCAPED_UNICODE); exit;
}

$action = (string)($_REQUEST['action'] ?? 'status');
$bid = (int)($_REQUEST['branch_id'] ?? 0);
if ($bid <= 0) { $bid = (int)getCurrentStaffBranchId(); }
if (!crmCanPairBranchPrinter($bid)) {
    echo json_encode(['ok' => false, 'error' => 'Tiskárny smíš nastavovat jen na své pobočce.'], JSON_UNESCAPED_UNICODE); exit;
}
ensureBranchPrinterColumn();

/** Základ adresy CRM pro instalační příkaz (na pobočce ho kopírují do Terminálu). */
function afxStationBaseUrl(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:]{3,120}$/', $host)) { return 'https://admin.applefix.cloud'; }
    return 'https://' . $host;
}

/** Lidsky „před 4 s" / „před 3 min" — ať je na první pohled vidět, že agent žije. */
function afxStationAgo(string $ts): string {
    $t = strtotime($ts);
    if (!$t) { return ''; }
    $d = max(0, time() - $t);
    if ($d < 60) { return 'před ' . $d . ' s'; }
    if ($d < 3600) { return 'před ' . (int)floor($d / 60) . ' min'; }
    if ($d < 86400) { return 'před ' . (int)floor($d / 3600) . ' h'; }
    return 'před ' . (int)floor($d / 86400) . ' dny';
}

/** Ověření serverového cíle tisku. Prázdno = pobočka nemá serverový cíl a tiskne kasa. */
function afxStationValidTarget(string $t, ?string &$err = null): bool {
    $err = null;
    if ($t === '') { return true; }
    if (strlen($t) > 120) { $err = 'Cíl tisku je příliš dlouhý.'; return false; }
    $priv = static function (string $host): bool {
        // jen místní síť: veřejná adresa by ze serveru dělala skener cizích portů
        if (!filter_var($host, FILTER_VALIDATE_IP)) { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) { return false; }
        return !(str_starts_with($host, '127.') || str_starts_with($host, '169.254.') || $host === '0.0.0.0');
    };
    if (str_starts_with($t, 'usb:')) {
        if (preg_match('#^usb:/dev/[A-Za-z0-9/_.\-]{1,60}$#', $t) === 1) { return true; }
        $err = 'Cíl usb: má být např. usb:/dev/usb/lp0.';
        return false;
    }
    if (str_starts_with($t, 'tcp:')) {
        [$host, $port] = array_pad(explode(':', substr($t, 4), 2), 2, '9100');
        if (!$priv($host) || !ctype_digit((string)$port) || (int)$port < 1 || (int)$port > 65535) {
            $err = 'Cíl tcp: má být tcp:192.168.x.x[:port] z místní sítě.'; return false;
        }
        return true;
    }
    if (str_starts_with($t, 'cups:')) {
        $c = explode(':', substr($t, 5));
        if (count($c) === 2) { [$host, $q] = $c; $port = '631'; }
        elseif (count($c) === 3) { [$host, $port, $q] = $c; }
        else { $err = 'Cíl cups: má být cups:host[:port]:fronta.'; return false; }
        if (!$priv($host) || !ctype_digit((string)$port) || preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $q) !== 1) {
            $err = 'Cíl cups: má být cups:192.168.x.x[:631]:fronta.'; return false;
        }
        return true;
    }
    if (str_starts_with($t, 'lp:')) {
        if (preg_match('/^lp:[A-Za-z0-9_.\-]{1,60}$/', $t) === 1) { return true; }
        $err = 'Cíl lp: má být lp:nazev_fronty.';
        return false;
    }
    $err = 'Neznámý cíl tisku. Použij usb:, tcp:, cups:, lp: — nebo nech prázdné (tiskne počítač u kasy).';
    return false;
}

/** Stav pobočky pro nastavení + instalační příkaz. */
function afxStationStatus(int $bid): array {
    global $pdo;
    $name = '';
    try {
        $st = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $st->execute([$bid]);
        $name = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    $token = afxPrintPollToken($bid);               // BEZ create — token se vyrábí jen na kliknutí
    $last = trim((string)get_setting('print_poll_last_' . $bid, ''));
    return [
        'ok' => true,
        'branch_id' => $bid,
        'branch_name' => $name,
        'target' => crmBranchReceiptTarget($bid),
        'global_target' => trim((string)get_setting('receipt_printer_target', '')),
        'has_token' => $token !== '',
        'token' => $token,
        'last_poll' => $last,
        'last_poll_ago' => $last !== '' ? afxStationAgo($last) : '',
        'agent_alive' => $last !== '' && strtotime($last) > time() - 300,
        'install_cmd' => $token !== ''
            ? 'curl -fsSL ' . afxStationBaseUrl() . '/scripts/mac_xprinter_setup.sh | zsh -s -- ' . $token
            : '',
    ];
}

if ($action === 'status') {
    echo json_encode(afxStationStatus($bid), JSON_UNESCAPED_UNICODE); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE); exit;
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'token') {
    $had = afxPrintPollToken($bid) !== '';
    if ($had) {
        // otočení tokenu: starý agent hned přestane dostávat úlohy, proto se to
        // dělá jen na výslovné kliknutí („Vygenerovat nový")
        set_setting('print_poll_token_' . $bid, bin2hex(random_bytes(24)));
    } else {
        afxPrintPollToken($bid, true);
    }
    try {
        $bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $bn->execute([$bid]);
        crmAuditLog('settings.printer', [
            'entity_type' => 'branch', 'entity_id' => $bid, 'entity_label' => (string)$bn->fetchColumn(),
            'branch_id' => $bid,
            'summary' => $had
                ? 'Vygenerován NOVÝ token pokladní tiskárny — starý agent přestal tisknout, je potřeba ho přeinstalovat'
                : 'Vytvořen token pokladní tiskárny pobočky',
        ]);
    } catch (Throwable $e) {}
    echo json_encode(afxStationStatus($bid), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'save_target') {
    $target = trim((string)($_POST['target'] ?? ''));
    $err = null;
    if (!afxStationValidTarget($target, $err)) {
        echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE); exit;
    }
    try {
        $st = $pdo->prepare("UPDATE branches SET receipt_printer_target = ? WHERE id = ?");
        $st->execute([$target !== '' ? $target : null, $bid]);
        $bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $bn->execute([$bid]);
        crmAuditLog('settings.printer', [
            'entity_type' => 'branch', 'entity_id' => $bid, 'entity_label' => (string)$bn->fetchColumn(),
            'branch_id' => $bid,
            'summary' => $target !== ''
                ? 'Účtenky pobočky tiskne server na ' . $target
                : 'Účtenky pobočky tiskne počítač u kasy (můstek / fronta)',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Uložení selhalo: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
    }
    echo json_encode(afxStationStatus($bid), JSON_UNESCAPED_UNICODE); exit;
}

echo json_encode(['ok' => false, 'error' => 'Neznámá akce.'], JSON_UNESCAPED_UNICODE);
