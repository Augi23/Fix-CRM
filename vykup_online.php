<?php
/* VEŘEJNÉ online vyplnění výkupního listu klientem (bez přihlášení).
   Přístup výhradně přes tajný token z e-mailu: vykup_online.php?t=<48 hex>.
   Klient doplní svoje údaje a zařízení, odesláním se dokument uloží do CRM
   a vykoupený produkt se založí/doplní automaticky (crmSyncVykupProduct) —
   stejně, jako by list vyplnila obsluha na prodejně.

   Bezpečnost: token = 24 náhodných bajtů (nehádatelné), pomalé odpovědi na
   špatný token, zamčená pole (cena, ověření totožnosti, datum) se berou VŽDY
   z databáze, klientův POST je nikdy nepřepíše. Meta o vyplnění se ukládá do
   payload (online_filled_at/ip) a do auditu. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/documents.php';

function vo_die(string $msg, int $code = 404): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AppleFix</title></head>'
        . '<body style="font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#eceff3;display:grid;place-items:center;min-height:100vh;margin:0;">'
        . '<div style="background:#fff;border-radius:16px;padding:34px 38px;max-width:420px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08);">'
        . '<div style="font-size:40px;">📄</div><h1 style="font-size:19px;margin:12px 0 8px;">' . e($msg) . '</h1>'
        . '<p style="color:#6b7280;font-size:14px;margin:0;">Pokud odkaz nefunguje, ozvěte se nám prosím — AppleFix s.r.o., www.applefix.cz</p>'
        . '</div></body></html>';
    exit;
}

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{40,64}$/', $token)) { usleep(400000); vo_die('Odkaz není platný.'); }

ensureDocPublicTokenColumn();
$st = $pdo->prepare("SELECT id FROM crm_documents WHERE public_token = ? AND doc_type = 'vykup' LIMIT 1");
$st->execute([$token]);
$docId = (int)$st->fetchColumn();
if ($docId <= 0) { usleep(400000); vo_die('Odkaz není platný nebo už vypršel.'); }

$doc = crmGetDocument($docId);
if (!$doc) { vo_die('Dokument nenalezen.'); }

$cfg = crmDocTypes()['vykup'];
$lang = crmDocLangOrDefault($doc['lang'] ?? 'cs');
$locked = crmDocOnlineLockedFields();

// povolená pole = všechna pole výkupního listu kromě zamčených
$allowed = [];
foreach ($cfg['sections'] as $sec) {
    foreach ($sec['fields'] as $f) {
        if (!in_array($f['n'], $locked, true)) { $allowed[] = $f['n']; }
    }
}

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    usleep(300000);   // zpomalení proti hrubé síle / robotům
    if (trim((string)($_POST['website'] ?? '')) !== '') { vo_die('Odesláno.', 200); }   // honeypot

    $fields = $doc['fields'];
    foreach ($allowed as $n) {
        if (array_key_exists($n, $_POST)) {
            $fields[$n] = mb_substr(trim(is_scalar($_POST[$n]) ? (string)$_POST[$n] : ''), 0, 4000);
        }
    }
    // zamčená pole natvrdo z DB (obrana proti podvrženému POSTu)
    foreach ($locked as $n) { $fields[$n] = (string)($doc['fields'][$n] ?? ''); }
    $fields['online_filled_at'] = date('Y-m-d H:i:s');
    $fields['online_filled_ip'] = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    // sloupce pro seznam — stejná pravidla jako api/save_document.php; cena z DB
    $name  = mb_substr((string)($fields['customer_name'] ?? ''), 0, 190);
    $phone = mb_substr((string)($fields['customer_phone'] ?? ''), 0, 60);
    $email = mb_substr((string)($fields['customer_email'] ?? ''), 0, 190);
    $subject = '';
    foreach ($cfg['subject_fields'] as $sf) {
        $sv = trim((string)($fields[$sf] ?? ''));
        if ($sv !== '') { $subject = trim($subject . ($subject !== '' ? ' — ' : '') . $sv); }
    }
    $subject = mb_substr($subject, 0, 255);

    try {
        $pdo->prepare("UPDATE crm_documents SET customer_name=?, customer_phone=?, customer_email=?, subject=?, payload=? WHERE id=?")
            ->execute([$name, $phone, $email, $subject, json_encode($fields, JSON_UNESCAPED_UNICODE), $docId]);
        try { crmSyncVykupProduct($docId); }
        catch (Throwable $e) { error_log('vykup_online product: ' . $e->getMessage()); }
        crmAuditLog('document.online_fill', [
            'entity_type' => 'document', 'entity_id' => $docId, 'entity_label' => (string)$doc['doc_number'],
            'summary' => 'Výkupní list ' . $doc['doc_number'] . ' vyplnil klient online' . ($name !== '' ? ' (' . $name . ')' : ''),
            'branch_id' => (int)($doc['branch_id'] ?? 0) ?: null,
        ]);
        $saved = true;
        $doc = crmGetDocument($docId);   // pro zobrazení uložených hodnot
    } catch (Throwable $e) {
        error_log('vykup_online save: ' . $e->getMessage());
        vo_die('Uložení se nepodařilo — zkuste to prosím znovu.', 500);
    }
}

$date = !empty($doc['doc_date']) ? date('d.m.Y', strtotime((string)$doc['doc_date'])) : date('d.m.Y');
$sheet = crmRenderDocumentSheet('vykup', $doc['fields'] ?? [], $lang, 'form', (string)$doc['doc_number'], $date, $docId);
// zamčená pole jen ke čtení (vizuálně ztlumená) — hodnoty stejně vždy vládne DB
foreach ($locked as $n) {
    $sheet = str_replace('name="' . $n . '"', 'name="' . $n . '" readonly style="background:#f3f4f6;color:#6b7280;pointer-events:none;"', $sheet);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Výkupní list <?php echo e((string)$doc['doc_number']); ?> — online vyplnění</title>
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <style>
        body { margin: 0; background: #eceff3; padding: 18px 10px 60px;
               font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; }
        <?php echo crmDocumentSheetCss(); ?>
        .vo-bar { max-width: 794px; margin: 0 auto 14px; background: #0b57d0; color: #fff;
                  border-radius: 14px; padding: 14px 18px; font-size: 14.5px; line-height: 1.5; }
        .vo-bar strong { font-weight: 700; }
        .vo-ok { background: #157f3d; }
        .vo-submit { max-width: 794px; margin: 16px auto 0; }
        .vo-submit button { width: 100%; padding: 15px; border: 0; border-radius: 14px;
                            background: #0b57d0; color: #fff; font-size: 16.5px; font-weight: 700; cursor: pointer; }
        .vo-submit button:active { filter: brightness(.92); }
        .vo-note { max-width: 794px; margin: 10px auto 0; color: #6b7280; font-size: 12px; text-align: center; }
        /* služební prvky (sken dokladu, mazání fotek) na veřejné stránce nefungují → skrýt */
        .idscan-add, .photo-del { display: none !important; }
        /* na telefonu se A4 arch zmenší, ať jde pohodlně vyplňovat */
        @media (max-width: 830px) { .doc-sheet { transform: none; width: auto; min-width: 0; } }
    </style>
</head>
<body>
<?php if ($saved): ?>
    <div class="vo-bar vo-ok">✅ <strong>Děkujeme, údaje jsme uložili.</strong> Výkupní list je připravený —
        při předání zařízení už jen zkontrolujeme doklad totožnosti a podepíšeme.</div>
<?php else: ?>
    <div class="vo-bar">✍️ <strong>Vyplňte prosím výkupní list online.</strong> Doplňte svoje údaje a informace
        o zařízení a dole klikněte na <strong>Odeslat do AppleFix</strong>. Výkupní cenu a služební pole vyplňuje
        prodejna — jsou jen pro čtení.</div>
<?php endif; ?>

<form method="post" action="vykup_online.php" autocomplete="on">
    <input type="hidden" name="t" value="<?php echo e($token); ?>">
    <input type="text" name="website" value="" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true">
    <?php echo $sheet; ?>
    <div class="vo-submit"><button type="submit">📨 Odeslat do AppleFix</button></div>
    <div class="vo-note">Údaje slouží výhradně k sepsání kupní smlouvy (výkupního listu) dle zákona č. 253/2008 Sb.
        Zpracovává AppleFix s.r.o. — www.applefix.cz</div>
</form>
</body>
</html>
