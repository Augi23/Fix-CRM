<?php
/* Zakázkový list (A4) — obsah dle firemního vzoru: typy polí + KOMPLETNÍ text
   podmínek drobným písmem (sepsáno speciálně, NEMĚNIT). Lze vložit i pro e-mail:
   includer nastaví ORDER_DOC_EMBED (má $order, $items, $target_lang) a $__EMAIL_MODE. */
if (!defined('ORDER_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
    if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("Order ID is not specified");

    $id = $_GET['id'] ?? $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email
                           FROM orders o
                           JOIN customers c ON o.customer_id = c.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) die(__("print_not_found"));

    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $target_lang = $_GET['lang'] ?? 'cs';
}
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}
$__EMAIL_MODE = $__EMAIL_MODE ?? false;

/* ---- data pro doklad ---- */
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';

$co_name  = get_setting('company_name', 'AppleFix s.r.o.');
$co_addr  = trim((string) get_setting('company_address', ''));
$co_ico   = trim((string) get_setting('company_ico', ''));
$co_dic   = trim((string) get_setting('company_dic', ''));
$co_phone = trim((string) get_setting('company_phone', ''));
$co_email = trim((string) get_setting('company_email', '')) ?: 'info@applefix.cz';
$co_web   = trim((string) get_setting('company_web', '')) ?: 'www.applefix.cz';

$orderCode = orderDisplayCode($order);
$custName  = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
$deviceStr = trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));
$pin       = trim((string)($order['pin_code'] ?? ''));
$estimated = ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') ? (float)$order['estimated_cost'] : null;
$receivedAt = !empty($order['created_at']) ? date('d.m.Y', strtotime((string)$order['created_at'])) : date('d.m.Y');
$pickupMethod = trim((string)($order['shipping_method'] ?? '')) ?: 'Osobní předání na pobočce';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Zakázkový list <?php echo e($orderCode); ?></title>
    <style>
        :root { --ink:#1d1d1f; --muted:#6b7280; --line:#d9dde3; --accent:#0A84FF; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; font-size: 12.5px; line-height: 1.5;
               color: var(--ink); margin: 0; padding: 22px; background: #f4f5f7; }
        .sheet { max-width: 820px; margin: auto; background: #fff; border-radius: 10px; overflow: hidden;
                 box-shadow: 0 8px 34px rgba(0,0,0,0.08); padding: 28px 30px 30px; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .top .brand img { height: 34px; width: auto; }
        .top .brand .co { font-size: 15px; font-weight: 800; margin-top: 6px; }
        .top .brand .meta { font-size: 11px; color: var(--muted); line-height: 1.5; margin-top: 2px; }
        .top .doc { text-align: right; }
        .top .doc h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.01em; }
        .top .doc .track { font-size: 11px; color: var(--muted); margin-top: 4px; }
        .idbar { display: flex; flex-wrap: wrap; gap: 0; border: 1px solid var(--line); border-radius: 10px;
                 margin: 18px 0 4px; overflow: hidden; }
        .idbar .cell { flex: 1 1 20%; min-width: 120px; padding: 9px 12px; border-right: 1px solid var(--line); }
        .idbar .cell:last-child { border-right: none; }
        .idbar .k { font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
        .idbar .v { font-size: 13px; font-weight: 700; margin-top: 2px; word-break: break-word; }
        .idbar .v.mono { font-family: ui-monospace, Menlo, monospace; }
        .grid2 { display: flex; gap: 16px; margin-top: 16px; }
        .field { flex: 1; }
        .field .k { font-size: 9.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 3px; }
        .field .v { font-size: 13px; }
        .note { font-size: 11.5px; color: var(--ink); background: #fbfbfd; border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; }
        .row3 { display: flex; gap: 16px; margin-top: 14px; }
        .legal { margin-top: 20px; padding-top: 12px; border-top: 1px solid var(--line);
                 font-size: 8.4px; line-height: 1.5; color: #3b3f45; text-align: justify; }
        .legal p { margin: 0 0 7px; }
        .sigwrap { display: flex; justify-content: flex-end; margin-top: 4px; }
        .sig { width: 260px; text-align: center; }
        .sig .line { border-top: 1px solid var(--ink); margin-top: 34px; padding-top: 5px; font-size: 10px; color: var(--muted); }
        .pickup { margin-top: 22px; padding-top: 12px; border-top: 1px solid var(--line); }
        .pickup h3 { margin: 0 0 2px; font-size: 13px; font-weight: 800; }
        .pickup .sub { font-size: 11px; color: var(--muted); }
        .toolbar { text-align: center; margin-bottom: 16px; }
        .toolbar button { padding: 10px 24px; cursor: pointer; background: var(--accent); color: #fff; border: none;
                          border-radius: 9px; font-size: 14px; font-weight: 600; }
        @media print {
            body { background: #fff; padding: 0; font-size: 12px; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 12mm 12mm 10mm; }
            .no-print { display: none !important; }
            .legal { font-size: 8px; }
        }
    </style>
</head>
<body>

<?php if (!$__EMAIL_MODE): ?>
<div class="no-print toolbar">
    <button onclick="window.print()"><?php echo _l('print'); ?></button>
</div>
<?php endif; ?>

<div class="sheet">
    <div class="top">
        <div class="brand">
            <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo e($co_name); ?>"><?php endif; ?>
            <div class="co"><?php echo e($co_name); ?></div>
            <div class="meta">
                <?php echo nl2br(e($co_addr)); ?><?php if ($co_addr !== ''): ?><br><?php endif; ?>
                <?php if ($co_ico !== ''): ?>IČO: <?php echo e($co_ico); ?><?php if ($co_dic !== ''): ?> · DIČ: <?php echo e($co_dic); ?><?php endif; ?><br><?php endif; ?>
                <?php if ($co_phone !== ''): ?>Tel.: <?php echo e($co_phone); ?><br><?php endif; ?>
                <?php echo e($co_web); ?><br>
                <?php echo e($co_email); ?>
            </div>
        </div>
        <div class="doc">
            <h1>Zakázkový list</h1>
            <div class="track">Vytvořeno: <?php echo e($receivedAt); ?></div>
        </div>
    </div>

    <div class="idbar">
        <div class="cell"><div class="k">Číslo zakázky</div><div class="v mono"><?php echo e($orderCode); ?></div></div>
        <div class="cell"><div class="k">PIN / heslo zařízení</div><div class="v mono"><?php echo e($pin !== '' ? $pin : '—'); ?></div></div>
        <div class="cell"><div class="k">Zařízení</div><div class="v"><?php echo e($deviceStr ?: '—'); ?></div></div>
        <div class="cell"><div class="k">Přijetí zařízení do opravy</div><div class="v"><?php echo e($receivedAt); ?></div></div>
    </div>

    <div class="grid2">
        <div class="field">
            <div class="k">Heslo zařízení / Kód obrazovky</div>
            <?php if ($pin !== ''): ?>
                <div class="v"><span style="font-family:ui-monospace,Menlo,monospace;font-weight:700"><?php echo e($pin); ?></span></div>
            <?php else: ?>
                <div class="note">Zákazník heslo nesdělil a je si vědom toho, že zařízení není možné po opravě plně otestovat.</div>
            <?php endif; ?>
        </div>
        <div class="field">
            <div class="k">Předpokládaná cena opravy</div>
            <div class="v"><?php echo $estimated !== null ? e(formatMoney($estimated)) : 'dle diagnostiky'; ?></div>
        </div>
    </div>

    <div class="grid2">
        <div class="field">
            <div class="k">Požadovaná oprava</div>
            <div class="v"><?php echo nl2br(e((string)($order['problem_description'] ?? '')) ?: '—'); ?></div>
        </div>
    </div>

    <div class="grid2">
        <div class="field">
            <div class="k">Kontakt na zákazníka</div>
            <div class="v"><?php echo e($custName ?: '—'); ?><?php if (!empty($order['phone'])): ?>, Tel.: <?php echo e($order['phone']); ?><?php endif; ?></div>
        </div>
        <div class="field">
            <div class="k">Převzetí zařízení zákazníkem</div>
            <div class="v"><?php echo e($pickupMethod); ?></div>
        </div>
    </div>

    <div class="legal">
        <p>Podpisem zakázkového listu se zhotovitel zavazuje provést pro zákazníka diagnostiku vad a následnou opravu výše identifikovaného elektronického zařízení (dále jen „oprava“) a zákazník se zavazuje za opravu zaplatit ujednanou cenu. Pokud bude cena opravy vyšší než výše uvedená předpokládaná cena opravy, zhotovitel zákazníka kontaktuje s žádostí o schválení navýšené ceny opravy. Zákazník výslovně bere na vědomí, že zhotovitel neodpovídá za případnou ztrátu či poškození dat z elektronického zařízení po provedení nebo během provádění opravy. Zhotovitel zákazníkovi doporučuje, aby před předáním elektronického zařízení zhotoviteli provedl zákazník zálohu veškerých dat umístěných na elektronickém zařízení. Nedomluví-li se zhotovitel a zákazník jinak, zhotovitel vyměněné vadné díly elektronického zařízení nevrací a ekologicky je zlikviduje. Zhotovitel dále výslovně upozorňuje zákazníka a zákazník bere na vědomí, že při provádění opravy může v některých případech dojít k projevu skrytých vad elektronického zařízení, které mohou vést ke „zhroucení“ elektronického zařízení, případně může mít elektronické zařízení jiné vady nežli zákazníkem uvedené. Za takové vady a případně zhroucení zhotovitel nenese odpovědnost. Zhotovitel neodpovídá za vady funkčnosti elektronického zařízení, které nesouvisejí s opravami provedenými zhotovitelem. Zhotovitel rovněž neodpovídá za skryté vady, které se vyskytly po provedení opravy, avšak nesouvisejí s funkčností dílů instalovaných zhotovitelem. Zhotovitel rovněž výslovně upozorňuje zákazníka a zákazník bere na vědomí, že zhotovitel nezaručuje funkčnost elektronického zařízení jako celku, když vada ve funkčnosti může být způsobena jinou vadou, nežli vadou označenou zákazníkem. Zákazník je povinen opravené, případně neopravitelné, elektronické zařízení vyzvednout osobně na pobočce zhotovitele nejpozději do 30 dnů od zaslání informace o provedení opravy. Pokud zákazník elektronické zařízení nepřevezme ve lhůtě uvedené v předchozím odstavci je zhotovitel oprávněn požadovat na zákazníkovi uhrazení poplatku za uskladnění ve výši 20,– Kč denně za každý den prodlení zákazníka s převzetím elektronického zařízení. V případě, že si zákazník nevyzvedne zařízení ve shora uvedené lhůtě, stanoví mu zhotovitel k vyzvednutí náhradní lhůtu, která nesmí být kratší než 5 měsíců od uplynutí původní lhůty k vyzvednutí opraveného elektronického zařízení a na tuto lhůtu zákazníka upozorní formou SMS zprávy, případně zasláním emailu, společně s upozorněním, že pokud si zákazník elektronické zařízení nevyzvedne, je zhotovitel oprávněn jej prodat. Pokud si zákazník elektronické zařízení nevyzvedne ani v dodatečné lhůtě stanovené za tímto účelem zhotovitelem, je zhotovitel oprávněn elektronické zařízení prodat a z jeho prodeje pokrýt náklady na uskladnění elektronického zařízení. V případě, že zákazník odmítne uhradit zhotoviteli cenu za provedenou opravu, má zhotovitel právo využít zadržovací právo dle § 1395 zákona č. 89/2012 Sb., občanského zákoníku, a elektronické zařízení zákazníkovi nevydat až do úplného zaplacení ceny a jejího příslušenství.</p>
        <p>Zákazník svým podpisem potvrzuje, že se s výše uvedenými právy a povinnosti seznámil, porozuměl jim a souhlasí s nimi. Pokud zhotovitel zveřejnil obchodní podmínky, práva a povinnosti mezi zákazníkem a zhotovitelem se rovněž řídí obchodními podmínkami zhotovitele dostupnými na jeho webových stránkách. Svým podpisem zákazník potvrzuje, že se s obchodními podmínkami seznámil.</p>
    </div>

    <div class="sigwrap">
        <div class="sig"><div class="line">Podpis zákazníka</div></div>
    </div>

    <div class="pickup">
        <h3>Převzetí hotové zakázky</h3>
        <div class="sub">Svým podpisem stvrzuji převzetí výše uvedeného zařízení z opravy.</div>
        <div class="sigwrap">
            <div class="sig"><div class="line">Podpis zákazníka</div></div>
        </div>
    </div>
</div>

</body>
</html>
