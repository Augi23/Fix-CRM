<?php
/**
 * BANKA — start napojení na KB. Jen přesměruje jednatele na správnou stránku banky.
 *   ?step=register  → registrace aplikace (KB vrátí client_id + client_secret)
 *   ?step=authorize → autorizace přístupu k účtu (KB vrátí authorization code)
 *
 * Pokaždé se vygeneruje jednorázový „state", který se uloží do nastavení a při
 * návratu se ověří — bez toho by šlo do CRM podstrčit cizí registraci nebo kód.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanManageSettings()) {
    header('Location: ../login.php');
    exit;
}

$back = '../settings.php?tab=banka';
$step = (string)($_GET['step'] ?? '');
$state = bin2hex(random_bytes(16));

if ($step === 'register') {
    if (get_setting('kb_api_key_oauth', '') === '' || get_setting('kb_api_key_adaa', '') === '') {
        $_SESSION['kb_connect_error'] = 'Nejdřív ulož API klíče z portálu developers.kb.cz (ADAA a OAuth2).';
        header('Location: ' . $back . '&kb=error'); exit;
    }
    if (kbApiEnv() === 'prod' && get_setting('kb_software_statement', '') === '') {
        $_SESSION['kb_connect_error'] = 'Pro produkci je nutný software statement (vzniká s kvalifikovaným '
            . 'certifikátem I.CA/PostSignum — scripts/kb_software_statement.php). V sandboxu je nepovinný.';
        header('Location: ' . $back . '&kb=error'); exit;
    }
    set_setting('kb_reg_state', $state);
    crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Spuštěna registrace aplikace u KB (' . kbApiEnv() . ')']);
    header('Location: ' . kbRegistrationUiUrl(kbBuildRegistrationRequest(), $state));
    exit;
}

if ($step === 'sandbox') {
    /* SANDBOX ZKRATKA. Testovací prostředí KB registraci aplikace vůbec nepotřebuje:
       client_id si volíme sami, client_secret je podle manuálu KB natvrdo „password"
       a autorizační kód vydá testovací stránka, kde se jen napíše jméno testovacího
       klienta (žádné přihlášení do bankovnictví). Registrace aplikace v sandboxu vede
       na SKUTEČNÉ přihlášení do KB, které tam nemá co dělat — proto tahle cesta. */
    if (kbApiEnv() !== 'sandbox') {
        $_SESSION['kb_connect_error'] = 'Zkratka je jen pro testovací prostředí (sandbox).';
        header('Location: ' . $back . '&kb=error'); exit;
    }
    $clientId = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($_GET['client_id'] ?? '')) ?: 'AppleFixCRM';
    set_setting('kb_client_id', $clientId);
    set_setting('kb_client_secret', 'password');
    set_setting('kb_refresh_token', '');
    set_setting('kb_access_token', '');
    set_setting('kb_access_token_expires', '0');
    set_setting('kb_auth_state', $state);
    crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Sandbox: nastaven testovací přístup (client_id ' . $clientId
            . ', client_secret dle manuálu KB) a spuštěna autorizace']);
    header('Location: ' . kbAuthorizeUrl($state, $clientId));
    exit;
}

if ($step === 'authorize') {
    if (get_setting('kb_client_id', '') === '' || get_setting('kb_client_secret', '') === '') {
        $_SESSION['kb_connect_error'] = 'Chybí client_id / client_secret — nejdřív projdi krok 1 (registrace aplikace).';
        header('Location: ' . $back . '&kb=error'); exit;
    }
    set_setting('kb_auth_state', $state);
    crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Spuštěna autorizace přístupu k účtu u KB (' . kbApiEnv() . ')']);
    header('Location: ' . kbAuthorizeUrl($state));
    exit;
}

header('Location: ' . $back);
