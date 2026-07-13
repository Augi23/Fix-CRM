<?php
/* Zakázkový list (A4) — obsah dle firemního vzoru: typy polí + KOMPLETNÍ text
   podmínek drobným písmem (sepsáno speciálně, NEMĚNIT — jen vzhled/rozvržení).
   Lze vložit i pro e-mail: includer nastaví ORDER_DOC_EMBED ($order,$items,$target_lang) + $__EMAIL_MODE. */
if (!defined('ORDER_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
    if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("Order ID is not specified");

    $id = $_GET['id'] ?? $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email, c.preferred_language
                           FROM orders o
                           JOIN customers c ON o.customer_id = c.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) die(__("print_not_found"));

    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    // bez ?lang → dokument v jazyce KLIENTA (volba při zakládání zakázky)
    $target_lang = $_GET['lang'] ?? crmCustomerDocLang($order['preferred_language'] ?? 'cs');
}
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}
$__EMAIL_MODE = $__EMAIL_MODE ?? false;

/* ---- data pro doklad ---- */
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';

$co_name  = get_setting('company_name', 'AppleFix s.r.o.');
$co_ico   = trim((string) get_setting('company_ico', ''));
$co_dic   = trim((string) get_setting('company_dic', ''));
$co_web   = trim((string) get_setting('company_web', '')) ?: 'www.applefix.cz';
// adresa/telefon/e-mail dle POBOČKY zakázky (Karlín / Černá růže), fallback firma
$__bc     = crmOrderBranchContact((int)($order['branch_id'] ?? 0));
$co_addr  = $__bc['address'];
$co_phone = $__bc['phone'];
$co_email = $__bc['email'];

// firemní identita na JEDEN řádek do záhlaví
$__co_parts = [];
$__addr1 = trim(preg_replace('/\s*[\r\n]+\s*/u', ', ', $co_addr));
if ($__addr1 !== '') $__co_parts[] = $__addr1;
if ($co_ico !== '') $__co_parts[] = 'IČO: ' . $co_ico . ($co_dic !== '' ? ' · DIČ: ' . $co_dic : '');
if ($co_phone !== '') $__co_parts[] = 'Tel.: ' . $co_phone;
$__co_parts[] = $co_web;
$__co_parts[] = $co_email;
$co_line = implode('  ·  ', $__co_parts);

$orderCode = orderDisplayCode($order);
$custName  = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
$deviceStr = trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));
$pin       = trim((string)($order['pin_code'] ?? ''));
$estimated = ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') ? (float)$order['estimated_cost'] : null;
$receivedAt = !empty($order['created_at']) ? date('d.m.Y', strtotime((string)$order['created_at'])) : date('d.m.Y');
$pickupMethod = trim((string)($order['shipping_method'] ?? '')) ?: 'Osobní předání na pobočce';

// datum ukončení opravy (den) = kdy zakázka přešla do dokončeného stavu; prázdné při příjmu
$completedAt = '';
if (isset($pdo) && function_exists('getOrderStatusList')) {
    try {
        $doneList = getOrderStatusList('done');
        if (!empty($doneList)) {
            $ph = implode(',', array_fill(0, count($doneList), '?'));
            $qc = $pdo->prepare("SELECT MAX(changed_at) FROM order_status_log WHERE order_id = ? AND new_status IN ($ph)");
            $qc->execute(array_merge([(int)$order['id']], $doneList));
            $ts = $qc->fetchColumn();
            if ($ts) $completedAt = date('d.m.Y', strtotime((string)$ts));
        }
    } catch (Throwable $e) { $completedAt = ''; }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Zakázkový list <?php echo e($orderCode); ?></title>
    <?php if (!$__EMAIL_MODE): ?><link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>"><?php endif; ?>
    <style>
        :root {
            --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0;
            --accent:#0a84ff; --accent-ink:#0a5bd6; --soft:#f6f8fb;
        }
        * { box-sizing: border-box; }
        body { font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Arial, sans-serif;
               font-size: 12.5px; line-height: 1.5; color: var(--ink); margin: 0; padding: 26px 18px;
               background: #eceff3; -webkit-font-smoothing: antialiased; }
        .sheet { max-width: 840px; margin: auto; background: #fff; border-radius: 18px; overflow: hidden;
                 box-shadow: 0 24px 64px rgba(17,20,24,0.12); }
        .accent-bar { height: 5px; background: linear-gradient(90deg, #0a84ff, #5ac8fa 55%, #64d2ff); }
        .pad { padding: 28px 34px 34px; }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .head img { height: 34px; width: auto; }
        .doc { text-align: right; }
        .doc .kick { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: var(--accent-ink); font-weight: 800; }
        .doc h1 { margin: 3px 0 0; font-size: 24px; font-weight: 800; letter-spacing: -0.03em; font-family: ui-monospace, Menlo, monospace; }
        .doc .date { font-size: 11px; color: var(--muted); margin-top: 5px; font-weight: 300; }

        /* firemní identita — designová PATIČKA dole (dřív řádek nahoře) */
        .head-sep { margin-top: 16px; border-bottom: 1px solid var(--line); }
        .foot { margin-top: 24px; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; }
        .foot .foot-name { font-size: 12px; font-weight: 800; letter-spacing: 0.02em; color: var(--ink); }
        .foot .foot-line { font-size: 10px; color: var(--muted); font-weight: 300; margin-top: 4px; letter-spacing: 0.02em; }
        .foot .foot-line b { color: var(--sub); font-weight: 600; }

        /* dva sloupce: KLIENT | ZAŘÍZENÍ A OPRAVA */
        .cols { display: flex; gap: 16px; margin-top: 18px; }
        .panel { flex: 1; border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
        .panel.client { flex: 0 0 40%; background: var(--soft); }
        .panel .title { font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink);
                        font-weight: 800; margin-bottom: 10px; }
        .client .name { font-size: 21px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; }
        .client .contact { margin-top: 8px; font-size: 12.5px; color: var(--sub); line-height: 1.7; }
        .client .contact .ic { display: inline-block; width: 15px; color: var(--muted); }

        .kv { display: flex; justify-content: space-between; gap: 14px; padding: 6px 0; border-bottom: 1px dashed var(--line); }
        .kv:last-child { border-bottom: none; }
        .kv .k { font-size: 11px; color: var(--muted); flex: 0 0 auto; padding-top: 1px; font-weight: 300; }
        .kv .v { font-size: 13px; font-weight: 700; text-align: right; word-break: break-word; }
        .kv .v.mono { font-family: ui-monospace, Menlo, monospace; }
        .kv .v.price { font-size: 15px; font-weight: 800; }
        .kv .v.repair { color: var(--ink); }
        .kv .v.done { color: var(--accent-ink); }
        .note { margin-top: 8px; font-size: 11px; color: #8a5a1a; background: #fff7ec; border: 1px solid #f6dcb4;
                border-radius: 9px; padding: 8px 10px; line-height: 1.5; }

        .legal { margin-top: 20px; padding-top: 14px; border-top: 2px solid var(--line);
                 font-size: 8.4px; line-height: 1.6; color: #495059; text-align: justify; font-weight: 300; }
        .legal p { margin: 0 0 8px; }

        .sigs { display: flex; gap: 44px; margin-top: 14px; }
        .sig { flex: 1; }
        .sig .line { border-top: 1.4px solid var(--ink); margin-top: 40px; padding-top: 7px;
                     font-size: 10.5px; color: var(--muted); text-align: center; }
        .pickup { margin-top: 24px; padding: 15px 18px 16px; background: var(--soft);
                  border: 1px solid var(--line); border-radius: 14px; }
        .pickup h3 { margin: 0; font-size: 13.5px; font-weight: 800; letter-spacing: -0.01em; }
        .pickup .sub { font-size: 11px; color: var(--sub); margin-top: 3px; }

        .toolbar { text-align: center; margin-bottom: 18px; }
        .toolbar button { padding: 11px 26px; cursor: pointer; background: var(--accent); color: #fff;
                          border: none; border-radius: 11px; font-size: 14px; font-weight: 600;
                          box-shadow: 0 8px 22px rgba(10,132,255,0.28); }

        /* Vynutit A4 na výšku (bez tohoto se orientace řídila výchozím nastavením tiskárny → tisklo na šířku) */
        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; padding: 0; font-size: 12px; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 210mm; }
            .pad { padding: 11mm 13mm 10mm; }
            .accent-bar, .panel.client, .pickup, .note { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
    <div class="accent-bar"></div>
    <div class="pad">
        <div class="head">
            <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo e($co_name); ?>"><?php endif; ?>
            <div class="doc">
                <div class="kick">Zakázkový list</div>
                <h1><?php echo e($orderCode); ?></h1>
                <div class="date">Vytvořeno: <?php echo e($receivedAt); ?></div>
            </div>
        </div>

        <div class="head-sep"></div>

        <div class="cols">
            <div class="panel client">
                <div class="title">Klient</div>
                <div class="name"><?php echo e($custName ?: '—'); ?></div>
                <div class="contact">
                    <?php if (!empty($order['phone'])): ?><div><span class="ic">☎</span> <?php echo e($order['phone']); ?></div><?php endif; ?>
                    <?php if (!empty($order['email'])): ?><div><span class="ic">✉</span> <?php echo e($order['email']); ?></div><?php endif; ?>
                    <?php if (!empty($order['address'])): ?><div><span class="ic">⌂</span> <?php echo nl2br(e($order['address'])); ?></div><?php endif; ?>
                </div>
                <div class="kv" style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;">
                    <div class="k">Převzetí zařízení zákazníkem</div>
                    <div class="v"><?php echo e($pickupMethod); ?></div>
                </div>
            </div>

            <div class="panel">
                <div class="title">Zařízení a oprava</div>
                <div class="kv"><div class="k">Zařízení</div><div class="v"><?php echo e($deviceStr ?: '—'); ?></div></div>
                <div class="kv"><div class="k">Heslo zařízení / Kód obrazovky</div><div class="v mono"><?php echo e($pin !== '' ? $pin : '—'); ?></div></div>
                <div class="kv"><div class="k">Požadovaná oprava</div><div class="v repair"><?php echo e((string)($order['problem_description'] ?? '') ?: '—'); ?></div></div>
                <div class="kv"><div class="k">Předpokládaná cena opravy</div><div class="v price"><?php echo $estimated !== null ? e(formatMoney($estimated)) : 'dle diagnostiky'; ?></div></div>
                <div class="kv"><div class="k">Přijetí zařízení do opravy</div><div class="v"><?php echo e($receivedAt); ?></div></div>
                <div class="kv"><div class="k">Datum ukončení opravy</div><div class="v done"><?php echo e($completedAt !== '' ? $completedAt : '—'); ?></div></div>
                <?php if ($pin === ''): ?>
                    <div class="note">Zákazník heslo nesdělil a je si vědom toho, že zařízení není možné po opravě plně otestovat.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="legal">
            <p>Podpisem zakázkového listu se zhotovitel zavazuje provést pro zákazníka diagnostiku vad a následnou opravu výše identifikovaného elektronického zařízení (dále jen „oprava“) a zákazník se zavazuje za opravu zaplatit ujednanou cenu. Pokud bude cena opravy vyšší než výše uvedená předpokládaná cena opravy, zhotovitel zákazníka kontaktuje s žádostí o schválení navýšené ceny opravy. Zákazník výslovně bere na vědomí, že zhotovitel neodpovídá za případnou ztrátu či poškození dat z elektronického zařízení po provedení nebo během provádění opravy. Zhotovitel zákazníkovi doporučuje, aby před předáním elektronického zařízení zhotoviteli provedl zákazník zálohu veškerých dat umístěných na elektronickém zařízení. Nedomluví-li se zhotovitel a zákazník jinak, zhotovitel vyměněné vadné díly elektronického zařízení nevrací a ekologicky je zlikviduje. Zhotovitel dále výslovně upozorňuje zákazníka a zákazník bere na vědomí, že při provádění opravy může v některých případech dojít k projevu skrytých vad elektronického zařízení, které mohou vést ke „zhroucení“ elektronického zařízení, případně může mít elektronické zařízení jiné vady nežli zákazníkem uvedené. Za takové vady a případně zhroucení zhotovitel nenese odpovědnost. Zhotovitel neodpovídá za vady funkčnosti elektronického zařízení, které nesouvisejí s opravami provedenými zhotovitelem. Zhotovitel rovněž neodpovídá za skryté vady, které se vyskytly po provedení opravy, avšak nesouvisejí s funkčností dílů instalovaných zhotovitelem. Zhotovitel rovněž výslovně upozorňuje zákazníka a zákazník bere na vědomí, že zhotovitel nezaručuje funkčnost elektronického zařízení jako celku, když vada ve funkčnosti může být způsobena jinou vadou, nežli vadou označenou zákazníkem. Zákazník je povinen opravené, případně neopravitelné, elektronické zařízení vyzvednout osobně na pobočce zhotovitele nejpozději do 30 dnů od zaslání informace o provedení opravy. Pokud zákazník elektronické zařízení nepřevezme ve lhůtě uvedené v předchozím odstavci je zhotovitel oprávněn požadovat na zákazníkovi uhrazení poplatku za uskladnění ve výši 20,– Kč denně za každý den prodlení zákazníka s převzetím elektronického zařízení. V případě, že si zákazník nevyzvedne zařízení ve shora uvedené lhůtě, stanoví mu zhotovitel k vyzvednutí náhradní lhůtu, která nesmí být kratší než 5 měsíců od uplynutí původní lhůty k vyzvednutí opraveného elektronického zařízení a na tuto lhůtu zákazníka upozorní formou SMS zprávy, případně zasláním emailu, společně s upozorněním, že pokud si zákazník elektronické zařízení nevyzvedne, je zhotovitel oprávněn jej prodat. Pokud si zákazník elektronické zařízení nevyzvedne ani v dodatečné lhůtě stanovené za tímto účelem zhotovitelem, je zhotovitel oprávněn elektronické zařízení prodat a z jeho prodeje pokrýt náklady na uskladnění elektronického zařízení. V případě, že zákazník odmítne uhradit zhotoviteli cenu za provedenou opravu, má zhotovitel právo využít zadržovací právo dle § 1395 zákona č. 89/2012 Sb., občanského zákoníku, a elektronické zařízení zákazníkovi nevydat až do úplného zaplacení ceny a jejího příslušenství.</p>
            <p>Zákazník svým podpisem potvrzuje, že se s výše uvedenými právy a povinnosti seznámil, porozuměl jim a souhlasí s nimi. Pokud zhotovitel zveřejnil obchodní podmínky, práva a povinnosti mezi zákazníkem a zhotovitelem se rovněž řídí obchodními podmínkami zhotovitele dostupnými na jeho webových stránkách. Svým podpisem zákazník potvrzuje, že se s obchodními podmínkami seznámil.</p>
        </div>

        <div class="sigs">
            <div class="sig"></div>
            <div class="sig"><div class="line">Podpis zákazníka</div></div>
        </div>

        <div class="pickup">
            <h3>Převzetí hotové zakázky</h3>
            <div class="sub">Svým podpisem stvrzuji převzetí výše uvedeného zařízení z opravy.</div>
            <div class="sigs">
                <div class="sig"></div>
                <div class="sig"><div class="line">Podpis zákazníka</div></div>
            </div>
        </div>

        <div class="foot">
            <div class="foot-name"><?php echo e($co_name); ?></div>
            <div class="foot-line"><?php echo e($co_line); ?></div>
        </div>
    </div>
</div>

</body>
</html>
