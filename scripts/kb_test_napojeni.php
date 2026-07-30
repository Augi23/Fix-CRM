<?php
/**
 * BANKA — kontrola napojení nanečisto (bez volání banky).
 *
 * Ověřuje tři věci, které se v ostrém provozu nedají zkoušet metodou pokus/omyl,
 * protože authorization code od banky platí jen 2 minuty:
 *   1) dešifrování odpovědi banky funguje — testuje se na OFICIÁLNÍM vzorku
 *      z dokumentace KB (známý klíč, salt a šifrovaná data → musí vyjít client_id),
 *   2) data pro registraci aplikace mají správný tvar (banka je čte z URL),
 *   3) všechny adresy a klíče, které napojení potřebuje, jsou nastavené.
 *
 * Spuštění (na serveru, z kořene CRM):  php scripts/kb_test_napojeni.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/kb_api.php';

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function ok(string $s): void { out("  \033[32mOK  \033[0m" . $s); }
function bad(string $s): void { out("  \033[31mCHYBA \033[0m" . $s); }
function note(string $s): void { out("  \033[33m—   \033[0m" . $s); }

$failures = 0;

// ── 1) dešifrování na oficiálním vzorku z dokumentace KB ─────────────────────
out('Dešifrování odpovědi banky (oficiální vzorek z dokumentace KB):');
$vzorekFile = $root . '/scripts/fixtures/kb_registrace_vzorek.json';
$vzorek = is_file($vzorekFile) ? json_decode((string)file_get_contents($vzorekFile), true) : null;
if (!is_array($vzorek) || empty($vzorek['encryptedData'])) {
    $failures++;
    bad('chybí soubor s oficiálním vzorkem: scripts/fixtures/kb_registrace_vzorek.json');
    $vzorek = ['encryptionKey' => '', 'salt' => '', 'encryptedData' => ''];
}
$vzorekKlic = (string)$vzorek['encryptionKey'];
$vzorekSalt = (string)$vzorek['salt'];
$vzorekData = (string)$vzorek['encryptedData'];

// klíč vzorku se předává parametrem — do nastavení se NESAHÁ, aby se nerozbila
// rozdělaná registrace (a get_setting má stejně cache, která by změnu nezachytila)
$reg = kbDecryptRegistration($vzorekSalt, $vzorekData, $vzorekKlic);

if (!$reg) {
    $failures++;
    bad('vzorek se nepodařilo dešifrovat — párování callbacku od banky by nefungovalo (zkontroluj openssl a aes-256-gcm)');
} else {
    ok('vzorek dešifrován, klíčů v odpovědi: ' . count($reg));
    foreach (['client_id', 'client_secret'] as $k) {
        if (!empty($reg[$k])) { ok("v odpovědi je $k" . ($k === 'client_id' ? ' = ' . $reg[$k] : ' (skryto)')); }
        else { $failures++; bad("v dešifrované odpovědi chybí $k"); }
    }
}
out();

// ── 2) tvar dat pro registraci aplikace ──────────────────────────────────────
out('Data pro registraci aplikace (posílají se bance v URL):');
$req = kbBuildRegistrationRequest();
$json = json_decode((string)base64_decode($req, true), true);
if (!is_array($json)) {
    $failures++; bad('registrationRequest není platný base64 JSON');
} else {
    ok('registrationRequest je platný base64 JSON (' . strlen($req) . ' znaků)');
    foreach (['clientName', 'applicationType', 'redirectUris', 'scope', 'encryptionKey', 'encryptionAlg'] as $k) {
        if (isset($json[$k])) { ok("obsahuje $k"); } else { $failures++; bad("chybí povinné pole $k"); }
    }
    $klic = base64_decode((string)($json['encryptionKey'] ?? ''), true);
    if ($klic !== false && strlen($klic) === 32) { ok('šifrovací klíč je AES-256 (32 bajtů)'); }
    else { $failures++; bad('šifrovací klíč nemá 32 bajtů — banka odpověď zašifruje tak, že ji nerozluštíme'); }
    if (($json['redirectUris'][0] ?? '') === kbRedirectUri()) { ok('redirectUris ukazuje na ' . kbRedirectUri()); }
    else { $failures++; bad('redirectUris neodpovídá callbacku CRM'); }
    if (empty($json['softwareStatement'])) {
        note('bez software statementu (v sandboxu nepovinný, pro produkci POVINNÝ)');
    } else { ok('software statement přiložen'); }
}
out();

// ── 3) co je nastavené a co chybí ────────────────────────────────────────────
out('Stav nastavení:');
out('  prostředí:            ' . kbApiEnv());
out('  adresa CRM:           ' . kbBaseUrl());
out('  redirect_uri:         ' . kbRedirectUri());
out('  registrationBackUri:  ' . kbRegistrationBackUri());
out('  registrace aplikace:  ' . kbRegistrationUiUrl('…', 'test'));
out('  autorizace účtu:      ' . preg_replace('/client_id=[^&]*/', 'client_id=…', kbAuthorizeUrl('test')));
out();
foreach ([
    'kb_api_key_adaa' => 'API klíč ADAA',
    'kb_api_key_oauth' => 'API klíč OAuth2',
    'kb_software_statement' => 'software statement',
    'kb_client_id' => 'client_id',
    'kb_client_secret' => 'client_secret',
    'kb_refresh_token' => 'refresh token',
    'kb_account_id' => 'vybraný účet',
] as $key => $label) {
    $v = (string)get_setting($key, '');
    if ($v !== '') { ok($label . ' — vyplněno' . (in_array($key, ['kb_client_id', 'kb_account_id'], true) ? ' (' . $v . ')' : '')); }
    else { note($label . ' — chybí'); }
}
out();

out($failures === 0
    ? "\033[32mHOTOVO — technická část napojení je připravená.\033[0m Zbývá to, co musí udělat člověk: certifikát (jen produkce) a dvakrát potvrdit přístup v KB."
    : "\033[31mHOTOVO — neprošlo kontrol: $failures\033[0m");
exit($failures === 0 ? 0 : 1);
