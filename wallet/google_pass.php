<?php
/**
 * GOOGLE WALLET — „Add to Google Wallet" save-link (JWT / RS256).
 * URL: wallet/google_pass.php?t=<card_token>  → přesměruje na pay.google.com/gp/v/save/<JWT>
 *
 * Vyžaduje (Nastavení → Integrace → Google Wallet, uloženo v /secure/wallet):
 *   - google_service_account.json … service-account klíč (client_email + private_key)
 *   - nastavení: wallet_google_issuer_id (Issuer ID z Google Pay & Wallet Console)
 *
 * Třída i objekt karty jdou inline v JWT (nemusíme volat REST API).
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';

function afx_gpass_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!crmWalletGoogleReady()) {
    afx_gpass_fail('Google Wallet zatím není nakonfigurováno. Nahraj service-account JSON v Nastavení → Integrace.', 503);
}

$token = trim((string)($_GET['t'] ?? ''));
$custId = crmCustomerIdByCardToken($token);
if ($custId <= 0) { afx_gpass_fail('Karta nenalezena.', 404); }
$card = crmClientCardData($custId);
if (!$card) { afx_gpass_fail('Karta nenalezena.', 404); }

$dir = crmWalletCertDir();
$sa = json_decode((string)file_get_contents($dir . '/google_service_account.json'), true);
if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
    afx_gpass_fail('Neplatný service-account JSON.', 500);
}
$issuerId = get_setting('wallet_google_issuer_id', '');
$company  = get_setting('company_name', 'AppleFix');
$classId  = $issuerId . '.applefix_loyalty';
$objectId = $issuerId . '.' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $card['token']);

// Třída karty (šablona) je spravovaná v Google Pay & Wallet Console
// (applefix_loyalty, stav APPROVED, vytvořeno 17.7.2026) — do JWT se proto
// posílají jen objekty; inline třída by kolidovala se schválenou verzí.
$loyaltyObject = [
    'id' => $objectId,
    'classId' => $classId,
    'state' => 'ACTIVE',
    'accountName' => $card['name'],
    'accountId' => $card['token'],
    'loyaltyPoints' => [
        'label' => 'Body',
        'balance' => ['int' => (int)$card['points']],
    ],
    'textModulesData' => [
        ['header' => 'Zařízení u nás', 'body' => (string)(int)$card['devices_total'], 'id' => 'devices'],
        ['header' => 'V servisu', 'body' => (string)(int)$card['devices_active'], 'id' => 'active'],
        ['header' => 'Člen od', 'body' => (string)$card['since'], 'id' => 'since'],
    ],
    'barcode' => [
        'type' => 'QR_CODE',
        'value' => crmCardScanUrl($card['token']),
        'alternateText' => $card['token'],
    ],
];

$claims = [
    'iss' => $sa['client_email'],
    'aud' => 'google',
    'typ' => 'savetowallet',
    'iat' => (int)($_SERVER['REQUEST_TIME'] ?? time()),
    'origins' => ['https://admin.applefix.cloud'],
    'payload' => [
        'loyaltyObjects' => [$loyaltyObject],
    ],
];

$header = ['alg' => 'RS256', 'typ' => 'JWT'];
$segments = [
    afx_b64url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    afx_b64url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
];
$signingInput = implode('.', $segments);
$signature = '';
if (!openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
    afx_gpass_fail('Podpis JWT selhal.', 500);
}
$jwt = $signingInput . '.' . afx_b64url($signature);

header('Location: https://pay.google.com/gp/v/save/' . $jwt);
exit;

function afx_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
