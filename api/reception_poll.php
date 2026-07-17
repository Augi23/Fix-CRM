<?php
/**
 * REŽIM RECEPCE — polling pro počítač na recepci.
 * Mac s zapnutým režimem recepce se každé ~3 s ptá, jestli někdo z personálu
 * (typicky firemní iPhone) nenaskenoval klientskou kartu. Když ano, vrátí
 * událost a Mac sám otevře profil klienta (klient-karta.php).
 * Událost zapisuje klient-karta.php (crmReceptionPushScan) — sdílí se přes
 * settings v DB, stejný vzor jako „ozbrojená zakázka" u QR skladu.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false]);
    exit;
}

$branch = getCurrentStaffBranchId() ?: getDefaultBranchId();
session_write_close();   // poll jen čte — nedržet zámek session (běží každé ~3 s)
$raw = (string)get_setting('reception_scan_b' . (int)$branch, '');
$ev = $raw !== '' ? json_decode($raw, true) : null;
$seen = (string)($_GET['seen'] ?? '');

$fresh = is_array($ev)
    && (time() - (int)($ev['ts'] ?? 0)) <= 60          // jen čerstvé skeny (do minuty)
    && (string)($ev['n'] ?? '') !== ''
    && (string)$ev['n'] !== $seen;                     // a jen ty, které Mac ještě neviděl

echo json_encode([
    'ok' => true,
    'event' => $fresh ? [
        'n'    => (string)$ev['n'],
        't'    => (string)($ev['t'] ?? ''),
        'name' => (string)($ev['name'] ?? ''),
        'age'  => max(0, time() - (int)($ev['ts'] ?? 0)),   // stáří skenu v sekundách
        'url'  => 'klient-karta.php?t=' . rawurlencode((string)($ev['t'] ?? '')) . '&nopush=1',
    ] : null,
], JSON_UNESCAPED_UNICODE);
