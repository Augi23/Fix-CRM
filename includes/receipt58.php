<?php
/**
 * Účtenka pro pokladní termotiskárnu (role 57 mm, tiskárna Xprinter XP58-IIN).
 *
 * Proč vlastní šablona a ne zmenšená A4: termohlava má 203 dpi, tiskne 384 bodů
 * na řádek = 48 mm tiskové plochy, a umí JEN černý bod / nic. Žádná šedá, žádné
 * barvy, žádné jemné linky — všechno, co je světlejší, se buď ztratí, nebo vyjede
 * jako špinavý rastr. Hierarchie se proto dělá výhradně velikostí, tučností,
 * verzálkami a prázdným místem.
 *
 * Rozměry drží mm (ne px), aby výsledek nezávisel na dpi prohlížeče ani ovladače.
 *
 * Vstup je pole (ne přímo řádky z DB), ať se šablona dá vyrenderovat i s ukázkovými
 * daty pro náhled a testy:
 *   company => [name, ico, dic, address, phone, email, web, vat_payer]
 *   doc     => [title, number, datetime, seller, payment, customer, invoice, cancelled_at]
 *   items   => [[name, code, qty, unit_price, total, used]]
 *   vat     => [rate, base, tax, used_total]      // jen u plátce
 *   totals  => [total, paid, change]
 *   legal   => [text, ...]                        // doplňkové věty pod součet
 *   logo    => data: URI (černobílé logo) nebo ''
 */

/** Návrhová šířka v mm. Hlava tiskne 48 mm (384 bodů, 203 dpi), ale papír má
 * v mechanice toleranci ±0,5 mm — na plné šířce se okraje ořezávají. 46 mm je
 * bezpečné maximum ověřené rešerší (manuál XP58-IIN + praxe prodejců). */
function crmReceiptWidthMm(): float {
    $w = (float)get_setting('receipt_width_mm', '46');
    return ($w >= 30 && $w <= 80) ? $w : 46.0;
}

function crmReceipt58Css(float $w): string {
    $wStr = rtrim(rtrim(number_format($w, 1, '.', ''), '0'), '.');
    return <<<CSS
    /* Vše v mm — 203 dpi hlava, 8 bodů na mm. Nic pod 0,25 mm se spolehlivě nevytiskne. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: #fff; }
    body { width: {$wStr}mm; margin: 0 auto; padding: 2mm 1.5mm 6mm;
           font-family: 'SF Pro Text', -apple-system, 'Helvetica Neue', Helvetica, Arial, sans-serif;
           font-size: 2.7mm; line-height: 1.3; color: #000;
           -webkit-font-smoothing: none; font-variant-numeric: tabular-nums; }
    /* jediná povolená „barva" je černá — termopapír nezná odstíny */
    .c { text-align: center; }
    .r { text-align: right; }
    .b { font-weight: 700; }
    .up { text-transform: uppercase; letter-spacing: 0.06em; }
    .sm { font-size: 2.3mm; line-height: 1.35; }
    .xs { font-size: 2.1mm; line-height: 1.35; }
    .logo { display: block; width: 30mm; margin: 0 auto 1.4mm; }
    .co-name { font-size: 3.2mm; font-weight: 700; letter-spacing: 0.02em; }
    .rule { border-top: 0.3mm solid #000; margin: 1.6mm 0; }
    .rule--dash { border-top: 0.3mm dashed #000; }
    .rule--thick { border-top: 0.6mm solid #000; }
    .doctype { font-size: 3.4mm; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; }
    .docno { font-size: 4.6mm; font-weight: 700; letter-spacing: -0.01em; }
    /* dvojice popisek–hodnota; popisek se nezalamuje, hodnota se láme doprava */
    .row { display: flex; justify-content: space-between; gap: 2mm; }
    .row > span:first-child { white-space: nowrap; }
    .row > span:last-child { text-align: right; font-weight: 600; }
    .item { margin-top: 1.6mm; }
    .item-name { font-weight: 700; word-break: break-word; }
    .item-note { font-size: 2.2mm; text-transform: uppercase; letter-spacing: 0.05em; }
    .item-calc { display: flex; justify-content: space-between; gap: 2mm; }
    .item-calc .amt { font-weight: 700; white-space: nowrap; }
    .total { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm;
             font-size: 4.4mm; font-weight: 700; margin: 1mm 0; }
    .total .lbl { font-size: 3.2mm; letter-spacing: 0.08em; text-transform: uppercase; }
    .stamp { border: 0.6mm solid #000; padding: 1.2mm; text-align: center; font-weight: 700;
             text-transform: uppercase; letter-spacing: 0.08em; margin: 1.6mm 0; }
    .legal { margin-top: 1.4mm; }
    .legal p { margin-top: 1mm; }
    .thanks { margin-top: 2.4mm; text-align: center; font-weight: 700; letter-spacing: 0.04em; }
    .qr { display: block; width: 18mm; margin: 2mm auto 0; }
    .cut { height: 10mm; }
    /* Náhled na obrazovce: papír na podkladu, ať je vidět skutečná šířka role. */
    @media screen {
        html { background: #e2e5ea; }
        body { box-shadow: 0 2mm 8mm rgba(0,0,0,.18); padding-bottom: 8mm; }
    }
    @page { size: {$wStr}mm auto; margin: 0; }
    @media print {
        html { background: #fff; }
        body { box-shadow: none; }
        .no-print { display: none !important; }
    }
CSS;
}

/**
 * Sloupce pos_sales.cash_received / cash_change — pojistka pro instalace, kde
 * ještě neproběhla migrace 043 (deploy je pouští hned po pullu, tohle kryje zbytek).
 */
function afxEnsurePosCashColumns(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'cash_received'")->fetch()) {
            $pdo->exec("ALTER TABLE pos_sales ADD COLUMN cash_received DECIMAL(10,2) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE pos_sales ADD COLUMN cash_change DECIMAL(10,2) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { error_log('afxEnsurePosCashColumns: ' . $e->getMessage()); }
}

/**
 * Složí vstupní pole pro crmRenderReceipt58() z řádků kasy (pos_sales + pos_sale_items).
 *
 * Drží VŠECHNA pravidla obsahu dokladu na jednom místě:
 *  - typ dokladu: neplátce „Doklad o prodeji"; plátce do 10 000 Kč vč. DPH
 *    „Zjednodušený daňový doklad" (§ 30 ZDPH), nad to „Daňový doklad";
 *    u platby NA FAKTURU vždy jen „Doklad o prodeji" — daňovým dokladem je faktura,
 *  - DIČ jen u plátce (uvedení daně neplátcem = povinnost ji odvést, § 108 ZDPH),
 *  - rekapitulace DPH ze snapshotu na dokladu (vat_rate/is_vat_payer), § 90 mimo ni,
 *  - záruční věty podle druhů položek (crmReceiptWarrantyLines),
 *  - Placeno/Vráceno jen u hotovosti, je-li evidováno.
 *
 * $co = ['name','address','ico','dic','phone','web'], $labels = přepisy (payment aj.).
 */
function crmBuildPosReceipt58(array $sale, array $items, array $co, string $logo = '', array $labels = []): array {
    $isVat   = (int)($sale['is_vat_payer'] ?? 0) === 1;
    $vatRate = (float)($sale['vat_rate'] ?? 0);

    // Věrohodnost příznaku „použité zboží" — STEJNÉ pravidlo jako na A4 účtence
    // (print_receipt.php): před v3.33.0 kasa označovala použité VŠECHNO, takže
    // u starých řádků (grade NULL, prodej před hranicí) se surové is_used_goods
    // nesmí věřit — dotisk historie by označil nové zboží jako použité.
    $usedSince = trim((string)get_setting('pos_used_goods_grade_since', ''));
    $saleAfterCut = $usedSince !== ''
        && strtotime((string)($sale['created_at'] ?? '')) >= strtotime($usedSince);
    $lineIsUsed = static function (array $l) use ($saleAfterCut): bool {
        if ((int)($l['is_used_goods'] ?? 0) !== 1) { return false; }
        $grade = trim((string)($l['grade'] ?? ''));
        if ($grade !== '' && function_exists('afxGoodsIsUsedByGrade')) {
            return afxGoodsIsUsedByGrade($grade, '58mm účtenka');
        }
        if ($grade !== '') {
            // stejná logika jako afxGoodsIsUsedByGrade: rozhoduje PRVNÍ slovo
            // ('A – jako nové' nesmí projít jako použité jen kvůli pomlčce)
            $first = mb_strtolower((string)preg_split('/[\s–-]+/u', $grade)[0]);
            return !in_array($first, ['nový', 'nove', 'nové', 'novy', 'new'], true);
        }
        if (in_array((string)($l['item_type'] ?? ''), ['part', 'manual', 'order'], true)) { return false; }
        return $saleAfterCut;
    };

    $rows = []; $stdTotal = 0.0; $usedTotal = 0.0; $warrantyItems = [];
    foreach ($items as $l) {
        $line = (float)$l['unit_price'] * (int)$l['quantity'];
        $used = $lineIsUsed($l);
        if ($used) { $usedTotal += $line; } else { $stdTotal += $line; }
        $rows[] = [
            'name' => (string)$l['item_name'],
            'code' => (string)($l['item_code'] ?? ''),
            'qty' => (int)$l['quantity'],
            'unit_price' => (float)$l['unit_price'],
            'total' => $line,
            'used' => $used,
            'par90' => $used && $isVat,
        ];
        $warrantyItems[] = ['used' => $used, 'service' => !empty($l['is_service']) || (string)($l['item_type'] ?? '') === 'order'];
    }
    $total = (float)($sale['total'] ?? 0);

    // rekapitulace v celých Kč tak, aby základ + DPH vždy dalo přesně součet (vzor A4 dokladu)
    $stdKc  = (int)round($stdTotal);
    $baseKc = ($isVat && $vatRate > 0) ? (int)round($stdTotal * 100 / (100 + $vatRate)) : $stdKc;
    $hasUsed = $usedTotal > 0 && $isVat;

    $isInvoicePay = in_array((string)($sale['payment_method'] ?? ''), ['invoice', 'invoice_ico'], true);
    if (!$isVat) {
        $title = 'Doklad o prodeji';
        unset($co['dic']);
    } elseif ($isInvoicePay) {
        // Prodej na fakturu: daňovým dokladem je FAKTURA (vystavená současně s prodejem).
        // Účtenka s titulem „daňový doklad" by byla druhým daňovým dokladem k témuž
        // plnění — zákazník by si mohl DPH uplatnit dvakrát.
        $title = 'Doklad o prodeji';
    } elseif ($total <= 10000) {
        $title = 'Zjednodušený daňový doklad';
    } else {
        $title = 'Daňový doklad';
    }

    $legal = [];
    if ($hasUsed) {
        $legal[] = 'Zvláštní režim – použité zboží podle § 90 zákona č. 235/2004 Sb. U položek označených „§ 90" se daň nevyčísluje.';
    }
    if (!$isVat) { $legal[] = 'Nejsme plátci DPH.'; }
    foreach (crmReceiptWarrantyLines($warrantyItems) as $w) { $legal[] = $w; }
    if ($isInvoicePay) {
        $fak = trim((string)($sale['invoice_number'] ?? ''));
        $legal[] = 'Úhrada převodem na základě faktury' . ($fak !== '' ? ' č. ' . $fak : '')
            . ($isVat ? ' — daňovým dokladem je faktura.' : '.');
    }
    $legal[] = 'Reklamace uplatníte na adrese provozovny výše. Doklad uschovejte.';

    $payment = (string)($sale['payment_method'] ?? '');
    $payLabel = $labels[$payment] ?? ['cash' => 'Hotově', 'card' => 'Kartou', 'invoice' => 'Na fakturu (s.r.o.)', 'invoice_ico' => 'Na fakturu (IČO)'][$payment] ?? $payment;

    $received = isset($sale['cash_received']) && $sale['cash_received'] !== null ? (float)$sale['cash_received'] : null;
    $change   = isset($sale['cash_change'])   && $sale['cash_change']   !== null ? (float)$sale['cash_change']   : null;

    return [
        'lang' => 'cs',
        'logo' => $logo,
        'company' => $co,
        'doc' => [
            'title' => $title,
            'number' => (string)($sale['sale_number'] ?? ''),
            'datetime' => !empty($sale['created_at']) ? date('j. n. Y H:i', strtotime((string)$sale['created_at'])) : '',
            'seller' => (string)($sale['seller_name'] ?? ''),
            'payment' => $payLabel,
            'customer' => (string)($sale['customer_label'] ?? ''),
            'customer_ico' => (string)($sale['customer_ico'] ?? ''),
            'invoice' => (string)($sale['invoice_number'] ?? ''),
            'cancelled_at' => !empty($sale['cancelled_at']) ? date('j. n. Y H:i', strtotime((string)$sale['cancelled_at'])) : '',
        ],
        'items' => $rows,
        'vat' => [
            'is_payer' => $isVat,
            'rate' => $vatRate,
            'base' => $baseKc,
            'tax' => $stdKc - $baseKc,
            'used_total' => $hasUsed ? $usedTotal : 0,
        ],
        'totals' => [
            'total' => $total,
            'paid' => $payment === 'cash' ? $received : null,
            'change' => $payment === 'cash' ? $change : null,
        ],
        'legal' => $legal,
        'money' => static function ($v) {
            // celé Kč bez desetinných; haléře (netypické) se ukážou, ať doklad sedí na korunu
            $v = (float)$v;
            return (abs($v - round($v)) < 0.005)
                ? number_format($v, 0, ',', ' ') . ' Kč'
                : number_format($v, 2, ',', ' ') . ' Kč';
        },
        'thanks' => 'Děkujeme za nákup',
    ];
}

/**
 * Záruční věty do patičky — skládají se podle toho, co na dokladu opravdu je:
 * nové zboží → 24 měsíců, použité → 12 měsíců, servis/oprava → 6 měsíců.
 * Věta se tiskne jen tehdy, když na dokladu příslušný druh položky existuje.
 */
function crmReceiptWarrantyLines(array $items): array {
    $maNove = $maPouzite = $maServis = false;
    foreach ($items as $l) {
        if (!empty($l['service']))   { $maServis = true; }
        elseif (!empty($l['used']))  { $maPouzite = true; }
        else                         { $maNove = true; }
    }
    $out = [];
    if ($maNove)    { $out[] = 'Záruka na nové zboží je 24 měsíců.'; }
    if ($maPouzite) { $out[] = 'Záruka na použité zboží je 12 měsíců.'; }
    if ($maServis)  { $out[] = 'Záruka na opravu je běžně 6 měsíců, pokud není určeno jinak.'; }
    return $out;
}

/** Vyrenderuje kompletní HTML účtenky (samostatný dokument, vhodný i pro náhled). */
function crmRenderReceipt58(array $d): string {
    $w = crmReceiptWidthMm();
    $co    = $d['company'] ?? [];
    $doc   = $d['doc'] ?? [];
    $items = $d['items'] ?? [];
    $vat   = $d['vat'] ?? [];
    $tot   = $d['totals'] ?? [];
    $money = $d['money'] ?? function ($v) { return number_format((float)$v, 0, ',', ' ') . ' Kč'; };

    $h  = '<!DOCTYPE html><html lang="' . e((string)($d['lang'] ?? 'cs')) . '"><head><meta charset="utf-8">';
    $h .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $h .= '<title>' . e((string)($doc['title'] ?? 'Účtenka')) . ' ' . e((string)($doc['number'] ?? '')) . '</title>';
    $h .= '<style>' . crmReceipt58Css($w) . '</style></head><body>';

    // ---- hlavička: logo + identifikace prodávajícího (§ 16 odst. 1 zák. 634/1992 Sb.)
    if (!empty($d['logo'])) {
        $h .= '<img class="logo" src="' . e((string)$d['logo']) . '" alt="' . e((string)($co['name'] ?? '')) . '">';
    }
    $h .= '<div class="c co-name">' . e((string)($co['name'] ?? '')) . '</div>';
    // adresa smí mít víc řádků (ulice / město) — dlouhá adresa se jinak láme nešikovně
    foreach (preg_split('/\n/', (string)($co['address'] ?? '')) as $ar) {
        if (trim($ar) !== '') { $h .= '<div class="c sm">' . e(trim($ar)) . '</div>'; }
    }
    $ids = [];
    if (!empty($co['ico'])) { $ids[] = 'IČO ' . $co['ico']; }
    if (!empty($co['dic'])) { $ids[] = 'DIČ ' . $co['dic']; }
    if ($ids) { $h .= '<div class="c sm">' . e(implode(' · ', $ids)) . '</div>'; }
    if (!empty($co['phone']) || !empty($co['web'])) {
        $h .= '<div class="c xs">' . e(trim((string)($co['phone'] ?? '') . (!empty($co['phone']) && !empty($co['web']) ? ' · ' : '') . (string)($co['web'] ?? ''))) . '</div>';
    }

    // ---- druh a číslo dokladu
    $h .= '<div class="rule rule--thick"></div>';
    $h .= '<div class="c doctype">' . e((string)($doc['title'] ?? 'Účtenka')) . '</div>';
    $h .= '<div class="c docno">' . e((string)($doc['number'] ?? '')) . '</div>';
    $h .= '<div class="rule rule--dash"></div>';

    if (!empty($doc['cancelled_at'])) {
        $h .= '<div class="stamp">Storno ' . e((string)$doc['cancelled_at']) . '</div>';
    }

    // ---- hlavička dokladu: datum a čas, obsluha, odběratel
    $meta = [];
    if (!empty($doc['datetime'])) { $meta[] = ['Datum', (string)$doc['datetime']]; }
    if (!empty($doc['seller']))   { $meta[] = ['Obsluha', (string)$doc['seller']]; }
    if (!empty($doc['customer'])) { $meta[] = ['Odběratel', (string)$doc['customer']]; }
    if (!empty($doc['customer_ico'])) { $meta[] = ['IČO odběratele', (string)$doc['customer_ico']]; }
    if (!empty($doc['invoice']))  { $meta[] = ['Faktura', (string)$doc['invoice']]; }
    foreach ($meta as $m) {
        $h .= '<div class="row sm"><span>' . e($m[0]) . '</span><span>' . e($m[1]) . '</span></div>';
    }

    // ---- položky: název na vlastním řádku (může se zalomit), pod ním výpočet
    $h .= '<div class="rule"></div>';
    foreach ($items as $l) {
        $h .= '<div class="item">';
        $h .= '<div class="item-name">' . e((string)($l['name'] ?? '')) . '</div>';
        $poznamky = [];
        if (!empty($l['code']))  { $poznamky[] = (string)$l['code']; }
        // Označení použitého zboží ukládá § 16 odst. 3 zák. 634/1992 Sb. — tiskne se vždy,
        // i u neplátce DPH; „§ 90" navíc jen tam, kde jede zvláštní režim.
        if (!empty($l['used']))  { $poznamky[] = 'Použité zboží'; }
        if (!empty($l['par90'])) { $poznamky[] = '§ 90'; }
        if ($poznamky) { $h .= '<div class="item-note">' . e(implode(' · ', $poznamky)) . '</div>'; }
        $qty = (float)($l['qty'] ?? 1);
        $qtyStr = rtrim(rtrim(number_format($qty, 2, ',', ' '), '0'), ',');
        $h .= '<div class="item-calc"><span>' . e($qtyStr) . ' × ' . e($money((float)($l['unit_price'] ?? 0))) . '</span>'
            . '<span class="amt">' . e($money((float)($l['total'] ?? 0))) . '</span></div>';
        $h .= '</div>';
    }

    // ---- součet a úhrada
    $h .= '<div class="rule rule--thick" style="margin-top:2mm"></div>';
    $__afxTot = (float)($tot['total'] ?? 0);
    // záporný součet = výplata výkupu (my platíme zákazníkovi) — na dokladu
    // se ukazuje srozumitelně kladná částka s vysvětlujícím popiskem
    $h .= '<div class="total"><span class="lbl">' . ($__afxTot < 0 ? 'Vyplaceno zákazníkovi' : 'Celkem') . '</span><span>' . e($money($__afxTot < 0 ? abs($__afxTot) : $__afxTot)) . '</span></div>';
    if (!empty($doc['payment'])) {
        $h .= '<div class="row"><span>Úhrada</span><span>' . e((string)$doc['payment']) . '</span></div>';
    }
    if (isset($tot['paid']) && $tot['paid'] !== null && (float)$tot['paid'] > 0) {
        $h .= '<div class="row sm"><span>Placeno</span><span>' . e($money((float)$tot['paid'])) . '</span></div>';
        $h .= '<div class="row sm"><span>Vráceno</span><span>' . e($money((float)($tot['change'] ?? 0))) . '</span></div>';
    }

    // ---- rekapitulace DPH (jen plátce; § 90 se do ní nikdy nepočítá)
    if (!empty($vat['is_payer'])) {
        $h .= '<div class="rule rule--dash"></div>';
        $rate = rtrim(rtrim(number_format((float)($vat['rate'] ?? 0), 1, ',', ' '), '0'), ',');
        if ((float)($vat['base'] ?? 0) > 0 || (float)($vat['tax'] ?? 0) > 0) {
            $h .= '<div class="row sm"><span>Základ ' . e($rate) . ' %</span><span>' . e($money((float)$vat['base'])) . '</span></div>';
            $h .= '<div class="row sm"><span>DPH ' . e($rate) . ' %</span><span>' . e($money((float)$vat['tax'])) . '</span></div>';
        }
        if ((float)($vat['used_total'] ?? 0) > 0) {
            $h .= '<div class="row sm"><span>Zvl. režim § 90</span><span>' . e($money((float)$vat['used_total'])) . '</span></div>';
        }
    }

    // ---- povinné a doplňkové věty
    $h .= '<div class="rule rule--dash"></div><div class="legal xs">';
    foreach (($d['legal'] ?? []) as $veta) { $h .= '<p>' . e((string)$veta) . '</p>'; }
    $h .= '</div>';

    if (!empty($d['qr'])) { $h .= '<img class="qr" src="' . e((string)$d['qr']) . '" alt="QR">'; }
    $h .= '<div class="thanks">' . e((string)($d['thanks'] ?? 'Děkujeme za nákup')) . '</div>';
    if (!empty($co['web'])) { $h .= '<div class="c xs">' . e((string)$co['web']) . '</div>'; }
    $h .= '<div class="cut"></div>';

    $h .= '</body></html>';
    return $h;
}
