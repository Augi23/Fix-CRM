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

/* POZOR na přihlášení: banka se vrací z JINÉ domény, a když použije POST, prohlížeč
   kvůli pravidlu SameSite=Lax NEPOŠLE přihlašovací cookie CRM. Kdybychom tady trvali na
   přihlášeném uživateli, výsledek registrace by se zahodil a člověk by skončil na
   přihlašovací stránce (přesně to se stalo při prvních pokusech).
   Proto stačí, že registrace právě probíhá (uložený state), nebo že je uživatel přihlášený.
   Pravost dat pak potvrdí to, že je dokážeme dešifrovat NAŠÍM klíčem — cizí data se
   dešifrovat nedají, takže se nic podstrčit nedá. */
$__prihlaseny = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageSettings();
$__probiha = trim((string)get_setting('kb_reg_state', '')) !== '';
if (!$__prihlaseny && !$__probiha) {
    header('Location: ../login.php');
    exit;
}

$back = '../settings.php?tab=banka';

// Banka se vrací na SAML endpoint, takže parametry můžou přijít GETem i POSTem a
// s odlišnou velikostí písmen — bereme to odkudkoli. (Přesně na tom napojení poprvé
// ztroskotalo: kód čekal jen parametry v URL a POST od banky neviděl.)
$in = array_merge($_GET, $_POST);
$pick = static function (array $in, array $names): string {
    foreach ($in as $k => $v) {
        if (is_string($v) && in_array(strtolower((string)$k), $names, true)) { return $v; }
    }
    return '';
};
$state = $pick($in, ['state', 'relaystate']);
$salt  = $pick($in, ['salt', 'iv', 'initializationvector']);
$enc   = $pick($in, ['encrypteddata', 'encrypted_data', 'data', 'response']);

// co přišlo, si vždy zapíšeme do historie — aby se případná další chyba dala dohledat
// bez hádání (hodnoty NE, jen názvy a délky: jsou v nich tajné údaje)
$popis = [];
foreach ($in as $k => $v) { $popis[] = $k . '(' . (is_string($v) ? strlen($v) : '?') . ')'; }
crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
    'summary' => 'Návrat z registrace aplikace (' . $_SERVER['REQUEST_METHOD'] . '): '
        . ($popis ? implode(', ', $popis) : 'BEZ PARAMETRŮ')]);

$fail = function (string $msg) use ($back): void {
    // do session I do nastavení: když se banka vrátila POSTem, prohlížeč nemusel poslat
    // přihlašovací cookie a session hláška by se ztratila (uživatel by nevěděl proč)
    $_SESSION['kb_connect_error'] = $msg;
    set_setting('kb_connect_msg', 'CHYBA: ' . $msg);
    set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
    header('Location: ' . $back . '&kb=error');
    exit;
};

$expected = (string)get_setting('kb_reg_state', '');
if ($state !== '' && $expected !== '' && !hash_equals($expected, $state)) {
    $fail('Návrat z banky nesouhlasí s požadavkem, který CRM odeslalo (neplatný state). Spusť registraci aplikace znovu.');
}
$statelessReturn = $state === '';   // banka state nevrátila — pravost pozná až dešifrování

if ($salt === '' || $enc === '') {
    $fail('Banka se vrátila, ale bez zašifrovaných údajů registrace. Přišlo: '
        . ($popis ? implode(', ', $popis) : 'nic') . '. Ověř, že v software statementu je '
        . 'registrationBackUri přesně ' . kbRegistrationBackUri());
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

set_setting('kb_reg_state', '');   // jednorázové použití — až teď, po ověření obsahu
set_setting('kb_client_id', (string)$reg['client_id']);
set_setting('kb_client_secret', (string)$reg['client_secret']);
// registrací se mění aplikace → starý refresh token už neplatí
set_setting('kb_refresh_token', '');
set_setting('kb_access_token', '');
set_setting('kb_access_token_expires', '0');

crmAuditLog('banka.napojeni', [
    'entity_type' => 'bank', 'entity_label' => 'KB',
    'summary' => 'Aplikace zaregistrována u Komerční banky (' . kbApiEnv() . ') — CRM získalo client_id '
        . (string)$reg['client_id'] . '. Dalším krokem je autorizace přístupu k účtu.'
        . ($statelessReturn ? ' (banka nevrátila state — pravost potvrzena úspěšným dešifrováním)' : ''),
]);

set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
$__ok = 'Aplikace je u banky zaregistrovaná (client_id ' . (string)$reg['client_id']
    . '). Teď zbývá autorizovat přístup k účtu — pokračuj tlačítkem „2. Autorizovat přístup k účtu".';
$_SESSION['kb_connect_ok'] = $__ok;
set_setting('kb_connect_msg', $__ok);
header('Location: ' . $back . '&kb=registered');
