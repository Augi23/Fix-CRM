<?php
/**
 * BANKA — návrat z REGISTRACE APLIKACE u KB (registrationBackUri).
 *
 * Jednatel potvrdil v internetovém bankovnictví spojení aplikace a KB nás sem
 * poslala zpět se ZAŠIFROVANÝMI údaji (parametry salt + encryptedData). Dešifrujeme
 * je naším AES klíčem a uložíme client_id a client_secret — dál už jde jen autorizace
 * účtu (api/kb_oauth_callback.php).
 *
 * Bezpečnost: parametr state musí odpovídat tomu, který jsme si uložili při odchodu
 * do banky (jinak by šlo podstrčit cizí registraci). Tajemství se nikde nevypisují.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanManageSettings()) {
    header('Location: ../login.php');
    exit;
}

$back = '../settings.php?tab=banka';
$state = (string)($_GET['state'] ?? '');
$expected = (string)get_setting('kb_reg_state', '');

$fail = function (string $msg) use ($back): void {
    $_SESSION['kb_connect_error'] = $msg;
    header('Location: ' . $back . '&kb=error');
    exit;
};

if ($expected === '' || !hash_equals($expected, $state)) {
    $fail('Návrat z banky nesouhlasí s požadavkem, který CRM odeslalo (neplatný state). Spusť registraci aplikace znovu.');
}
set_setting('kb_reg_state', '');   // jednorázové použití

$salt = (string)($_GET['salt'] ?? '');
$enc  = (string)($_GET['encryptedData'] ?? '');
if ($salt === '' || $enc === '') {
    $fail('Banka nevrátila zašifrovaná data registrace (chybí salt nebo encryptedData). '
        . 'Zkontroluj, že registrationBackUri v software statementu je ' . kbRegistrationBackUri());
}

$reg = kbDecryptRegistration($salt, $enc);
if (!$reg) {
    $fail('Odpověď banky se nepodařilo dešifrovat. Šifrovací klíč se musel mezi odesláním '
        . 'a návratem změnit — spusť registraci aplikace znovu (klíč se vytvoří nový).');
}
if (empty($reg['client_id']) || empty($reg['client_secret'])) {
    $fail('V odpovědi banky chybí client_id nebo client_secret. Odpověď obsahovala: '
        . implode(', ', array_slice(array_keys($reg), 0, 12)));
}

set_setting('kb_client_id', (string)$reg['client_id']);
set_setting('kb_client_secret', (string)$reg['client_secret']);
// registrací se mění aplikace → starý refresh token už neplatí
set_setting('kb_refresh_token', '');
set_setting('kb_access_token', '');
set_setting('kb_access_token_expires', '0');

crmAuditLog('banka.napojeni', [
    'entity_type' => 'bank', 'entity_label' => 'KB',
    'summary' => 'Aplikace zaregistrována u Komerční banky (' . kbApiEnv() . ') — CRM získalo client_id '
        . (string)$reg['client_id'] . '. Dalším krokem je autorizace přístupu k účtu.',
]);

$_SESSION['kb_connect_ok'] = 'Aplikace je u banky zaregistrovaná (client_id ' . (string)$reg['client_id']
    . '). Teď zbývá autorizovat přístup k účtu — pokračuj tlačítkem „2. Autorizovat přístup k účtu".';
header('Location: ' . $back . '&kb=registered');
