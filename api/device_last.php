<?php
/** Co je zrovna připojené k Macu u pultu (pro naskladnění produktu).
 *  GET → { ok, station, age, info:{…}, device:{…} }  */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/device_bridge.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => __('unauthorized')], JSON_UNESCAPED_UNICODE); exit;
}
// Čte i výkupní list (dokument.php), který vypisuje běžná obsluha — proto
// nestačí právo na naskladnění. Účetní výkupy nedělá, ta sem nepatří.
if (function_exists('crmIsAccountant') && crmIsAccountant()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Čtení zařízení není pro roli účetní.'], JSON_UNESCAPED_UNICODE); exit;
}

$latest = afxDeviceBridgeLatest();
if (!$latest) {
    echo json_encode(['ok' => false, 'error' => 'Žádné připojené zařízení. Připoj iPhone/iPad kabelem k Macu, odemkni ho a potvrď „Důvěřovat tomuto počítači".'], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode([
    'ok' => true,
    'station' => $latest['station'],
    'age' => $latest['age'],
    'info' => afxDeviceBridgeToForm($latest['device']),
    // pole pro výkupní list / zástavní formulář (dokument.php)
    'doc' => afxDeviceBridgeToDocFields($latest['device']),
], JSON_UNESCAPED_UNICODE);
