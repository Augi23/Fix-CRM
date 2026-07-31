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

/** Šířka tiskové plochy v mm (384 bodů / 8 bodů na mm při 203 dpi). */
function crmReceiptWidthMm(): float {
    $w = (float)get_setting('receipt_width_mm', '48');
    return ($w >= 30 && $w <= 80) ? $w : 48.0;
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
    .cut { margin-top: 4mm; }
    /* Náhled na obrazovce: papír na podkladu, ať je vidět skutečná šířka role. */
    @media screen {
        html { background: #e2e5ea; }
        body { box-shadow: 0 2mm 8mm rgba(0,0,0,.18); padding-bottom: 8mm; }
    }
    @page { size: {$wStr}mm auto; margin: 0; }
    @media print {
        html { background: #fff; }
        body { box-shadow: none; width: auto; }
        .no-print { display: none !important; }
    }
CSS;
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
    $h .= '<div class="c sm">' . e((string)($co['address'] ?? '')) . '</div>';
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
    $h .= '<div class="total"><span class="lbl">Celkem</span><span>' . e($money((float)($tot['total'] ?? 0))) . '</span></div>';
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
