<?php
/**
 * BANKA — návrat z autorizace účtu (redirect_uri).
 *
 * Obě adresy (tato i api/kb_oauth_callback.php) vedou do stejného zpracování —
 * KB se totiž podle situace vrací na jednu nebo druhou a rozhoduje se podle OBSAHU,
 * ne podle adresy. Viz includes/kb_callback.php.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';
require_once '../includes/kb_callback.php';

/* Přihlášení se tu NEVYŽADUJE: banka se vrací z jiné domény a při POSTu prohlížeč
   kvůli pravidlu SameSite nepošle přihlašovací cookie CRM. Stačí, že napojení právě
   probíhá; pravost dat potvrdí až úspěšné rozšifrování naším vlastním klíčem. */
$prihlaseny = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageSettings();
$probiha = trim((string)get_setting('kb_reg_state', '')) !== '' || trim((string)get_setting('kb_auth_state', '')) !== '';
if (!$prihlaseny && !$probiha) {
    // i tohle si zapíšeme — jinak by „nic se nestalo" nešlo dohledat
    crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Návrat z banky ODMÍTNUT (neprobíhá žádné napojení a nikdo není přihlášený): '
            . kbDescribeParams(array_merge($_GET, $_POST))]);
    header('Location: ../login.php');
    exit;
}

kbHandleBankReturn('../settings.php?tab=banka');
