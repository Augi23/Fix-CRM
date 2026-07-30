<?php
/**
 * BANKA — vytvoření SOFTWARE STATEMENTU u KB (krok 2 napojení).
 *
 * Software statement je podepsané potvrzení „tato aplikace patří téhle firmě",
 * platí 12 měsíců a KB ho vydá jen proti KVALIFIKOVANÉMU CERTIFIKÁTU (I.CA nebo
 * PostSignum) — spojení se navazuje certifikátem (mTLS). V sandboxu je nepovinný,
 * pro produkci povinný.
 *
 * Certifikát ani jeho heslo se nikam neukládají — použijí se jen pro toto jedno
 * volání. Výsledný JWT se uloží do nastavení CRM (kb_software_statement).
 *
 * Použití (na serveru, z kořene CRM):
 *   php scripts/kb_software_statement.php --p12=/cesta/cert.p12 --pass=HESLO
 *   php scripts/kb_software_statement.php --cert=/cesta/cert.crt --key=/cesta/cert.key [--pass=HESLO]
 *   php scripts/kb_software_statement.php --dry-run          (jen vypíše, co se pošle)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/kb_api.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? '1'; }
}
$dry = !empty($args['dry-run']);

$apiKey = (string)get_setting('kb_api_key_client_reg', '') ?: (string)get_setting('kb_api_key_oauth', '');
if ($apiKey === '') {
    exit("Chybí API klíč pro Client Registration API (Nastavení → Banka).\n");
}

// Tělo požadavku — redirectUris a registrationBackUri MUSÍ odpovídat adresám CRM,
// jinak se banka po autorizaci nevrátí zpět a napojení nedokončíš.
$body = [
    'softwareName' => 'AppleFix CRM',
    'softwareNameEn' => 'AppleFix CRM',
    'softwareId' => (string)(get_setting('kb_software_id', '') ?: bin2hex(random_bytes(16))),
    'softwareVersion' => trim((string)@file_get_contents($root . '/VERSION')) ?: '1.0',
    'softwareUri' => kbBaseUrl(),
    'redirectUris' => [kbRedirectUri()],
    'tokenEndpointAuthMethod' => 'client_secret_post',
    'grantTypes' => ['authorization_code', 'refresh_token'],
    'responseTypes' => ['code'],
    'registrationBackUri' => kbRegistrationBackUri(),
    'contacts' => ['email: ' . (get_setting('acc_email', '') ?: '8augis8@gmail.com')],
    'logoUri' => kbBaseUrl() . '/assets/img/logo-black.png',
    'tosUri' => kbBaseUrl() . '/vop.php',
    'policyUri' => kbBaseUrl() . '/gdpr.php',
];
set_setting('kb_software_id', $body['softwareId']);   // musí zůstat stejné i při obnově

echo "Prostředí:  " . kbApiEnv() . "\n";
echo "Endpoint:   " . kbSoftwareStatementUrl() . "\n";
echo "Odesílané údaje:\n" . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

if ($dry) { exit("(dry-run) Nic se neodeslalo.\n"); }

$ch = curl_init(kbSoftwareStatementUrl());
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'apiKey: ' . $apiKey,
        'x-correlation-id: ' . bin2hex(random_bytes(16)),
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
];
// klientský certifikát (mTLS) — P12 nebo dvojice crt+key
if (!empty($args['p12'])) {
    if (!is_file($args['p12'])) { exit("Certifikát nenalezen: {$args['p12']}\n"); }
    $opts[CURLOPT_SSLCERT] = $args['p12'];
    $opts[CURLOPT_SSLCERTTYPE] = 'P12';
    if (!empty($args['pass'])) { $opts[CURLOPT_SSLCERTPASSWD] = $args['pass']; }
} elseif (!empty($args['cert']) && !empty($args['key'])) {
    if (!is_file($args['cert']) || !is_file($args['key'])) { exit("Certifikát nebo klíč nenalezen.\n"); }
    $opts[CURLOPT_SSLCERT] = $args['cert'];
    $opts[CURLOPT_SSLKEY] = $args['key'];
    if (!empty($args['pass'])) { $opts[CURLOPT_SSLKEYPASSWD] = $args['pass']; }
} elseif (kbApiEnv() === 'prod') {
    exit("Pro produkci je certifikát povinný: --p12=… --pass=… (nebo --cert=… --key=…).\n");
} else {
    echo "POZNÁMKA: bez certifikátu — v sandboxu to KB může přijmout, v produkci ne.\n\n";
}
curl_setopt_array($ch, $opts);

$raw = (string)curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === '' && $err !== '') { exit("Spojení selhalo: $err\n"); }
echo "HTTP $code\n";
$data = json_decode($raw, true);
$jwt = '';
if (is_array($data)) {
    foreach (['softwareStatement', 'software_statement', 'statement', 'jwt'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) { $jwt = $data[$k]; break; }
    }
}
if ($jwt === '') {
    echo "Odpověď banky:\n" . mb_substr($raw, 0, 1200) . "\n";
    exit("Software statement se nepodařilo získat — zkontroluj certifikát a API klíč.\n");
}

set_setting('kb_software_statement', $jwt);
set_setting('kb_software_statement_at', date('Y-m-d H:i:s'));
echo "\nSoftware statement uložen do nastavení CRM (platí 12 měsíců, obnov ho do "
    . date('d.m.Y', strtotime('+12 months')) . ").\n";
echo "Další krok: Nastavení → Banka → „1. Registrovat aplikaci u KB\".\n";
