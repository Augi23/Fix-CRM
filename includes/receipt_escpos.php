<?php
/**
 * Server-side tisk účtenky na Xprinter XP58-IIN (ESC/POS).
 *
 * Princip: účtenka se NEskládá z textových příkazů tiskárny (kódové stránky =
 * peklo s češtinou), ale vykreslí se přes GD do 1bitového rastru 384 bodů
 * širokého (přesně tisková hlava, 203 dpi) a pošle se příkazem GS v 0.
 * Vzhled tak odpovídá HTML šabloně z includes/receipt58.php a divakritika
 * funguje vždy.
 *
 * Cíl tisku (get_setting 'receipt_printer_target'):
 *   usb:/dev/usb/lp0     — tiskárna v USB serveru (výchozí; XP58-IIN je USB-only)
 *   tcp:192.168.1.x:9100 — kdyby někdy byla síťová varianta
 *   lp:nazev_fronty      — přes CUPS (lp -d fronta -o raw)
 *
 * Vstupem kreslení je STEJNÉ pole $d jako u crmRenderReceipt58() — jeden zdroj
 * pravdy o obsahu dokladu (crmBuildPosReceipt58).
 */

const CRM_RCPT_DOTS = 384;   // bodů na řádek (48 mm při 203 dpi)

/** Font pro GD: preferuje SF Pro z assets, jinak DejaVu (na serveru vždy). */
function crmRcptFont(bool $bold): string {
    $kandidati = $bold
        ? [__DIR__ . '/../assets/fonts/SF-Pro-Display-Semibold.otf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
        : [__DIR__ . '/../assets/fonts/SF-Pro-Display-Regular.otf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];
    foreach ($kandidati as $p) {
        if (is_file($p) && @imagettfbbox(12, 0, $p, 'Ag') !== false) { return $p; }
    }
    return '';
}

/** Vykreslí účtenku do GD obrazu širokého 384 px (bílá plocha, černý text). */
function crmReceiptRaster(array $d): \GdImage {
    $W = CRM_RCPT_DOTS; $M = 6; $CW = $W - 2 * $M;   // okraj 6 bodů (~0,75 mm)
    $fontR = crmRcptFont(false); $fontB = crmRcptFont(true);

    // kreslí se na "nekonečné" plátno, na konci se ořízne podle skutečné výšky
    $img = imagecreatetruecolor($W, 4000);
    $bila = imagecolorallocate($img, 255, 255, 255);
    $cerna = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $W, 4000, $bila);

    $sirka = function (float $size, string $t, bool $bold) use ($fontR, $fontB): int {
        $b = imagettfbbox($size, 0, $bold ? $fontB : $fontR, $t);
        return (int)abs($b[2] - $b[0]);
    };
    $y = 10;
    // text: $align 'l'|'c'|'r'; vrací novou Y pozici pod řádkem
    $text = function (float $size, string $t, bool $bold = false, string $align = 'l', float $lh = 1.35) use (&$img, &$y, $cerna, $fontR, $fontB, $sirka, $W, $M, $CW) {
        if ($t === '') { return; }
        $x = $M;
        if ($align === 'c') { $x = (int)(($W - $sirka($size, $t, $bold)) / 2); }
        if ($align === 'r') { $x = $W - $M - $sirka($size, $t, $bold); }
        imagettftext($img, $size, 0, max(0, $x), (int)($y + $size * 1.05), $cerna, $bold ? $fontB : $fontR, $t);
        $y += (int)ceil($size * $lh) + 4;
    };
    // dvojice popisek vlevo / hodnota vpravo na jednom řádku
    $radek = function (float $size, string $lbl, string $val, bool $boldVal = true, ?float $valSize = null) use (&$img, &$y, $cerna, $fontR, $fontB, $sirka, $W, $M) {
        $vs = $valSize ?? $size;
        $bl = (int)($y + max($size, $vs) * 1.05);
        imagettftext($img, $size, 0, $M, $bl, $cerna, $fontR, $lbl);
        imagettftext($img, $vs, 0, $W - $M - $sirka($vs, $val, $boldVal), $bl, $cerna, $boldVal ? $fontB : $fontR, $val);
        $y += (int)ceil(max($size, $vs) * 1.35) + 4;
    };
    $cara = function (int $tloustka = 2, bool $dashed = false) use (&$img, &$y, $cerna, $W, $M) {
        $y += 6;
        if ($dashed) {
            for ($x = $M; $x < $W - $M; $x += 12) {
                imagefilledrectangle($img, $x, $y, min($x + 6, $W - $M), $y + $tloustka - 1, $cerna);
            }
        } else {
            imagefilledrectangle($img, $M, $y, $W - $M, $y + $tloustka - 1, $cerna);
        }
        $y += $tloustka + 6;
    };
    // zalamování slov na obsahovou šířku
    $zalom = function (float $size, string $t, bool $bold) use ($sirka, $CW): array {
        $out = []; $line = '';
        foreach (preg_split('/\s+/u', trim($t)) as $w) {
            $zk = $line === '' ? $w : $line . ' ' . $w;
            if ($sirka($size, $zk, $bold) <= $CW) { $line = $zk; continue; }
            if ($line !== '') { $out[] = $line; }
            $line = $w;
        }
        if ($line !== '') { $out[] = $line; }
        return $out ?: [''];
    };

    $co = $d['company'] ?? []; $doc = $d['doc'] ?? []; $tot = $d['totals'] ?? [];
    $vat = $d['vat'] ?? []; $money = $d['money'];

    // ---- logo (černé PNG přeprahované na čistou čerň) ----
    $logoFs = __DIR__ . '/../assets/img/logo-black.png';
    if (is_file($logoFs) && ($src = @imagecreatefrompng($logoFs))) {
        $lw = 232; $lhpx = (int)round(imagesy($src) * $lw / imagesx($src));
        $tmp = imagecreatetruecolor($lw, $lhpx);
        imagefilledrectangle($tmp, 0, 0, $lw, $lhpx, imagecolorallocate($tmp, 255, 255, 255));
        imagealphablending($tmp, true);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $lw, $lhpx, imagesx($src), imagesy($src));
        $x0 = (int)(($W - $lw) / 2);
        for ($yy = 0; $yy < $lhpx; $yy++) {
            for ($xx = 0; $xx < $lw; $xx++) {
                $rgb = imagecolorat($tmp, $xx, $yy);
                $g = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                if ($g < 140) { imagesetpixel($img, $x0 + $xx, $y + $yy, $cerna); }
            }
        }
        $y += $lhpx + 8;
    }

    // ---- hlavička firmy ----
    $text(17, (string)($co['name'] ?? ''), true, 'c');
    foreach (preg_split('/\n/', (string)($co['address'] ?? '')) as $a) { $text(13, trim($a), false, 'c', 1.25); }
    $ids = [];
    if (!empty($co['ico'])) { $ids[] = 'IČO ' . $co['ico']; }
    if (!empty($co['dic'])) { $ids[] = 'DIČ ' . $co['dic']; }
    if ($ids) { $text(13, implode(' · ', $ids), false, 'c', 1.25); }
    $kontakt = trim((string)($co['phone'] ?? '') . (!empty($co['phone']) && !empty($co['web']) ? ' · ' : '') . (string)($co['web'] ?? ''));
    if ($kontakt !== '') { $text(12, $kontakt, false, 'c', 1.25); }

    // ---- typ + číslo dokladu ----
    $cara(4);
    foreach ($zalom(18, mb_strtoupper((string)($doc['title'] ?? 'Účtenka')), true) as $tln) {
        $text(18, $tln, true, 'c', 1.2);
    }
    $text(26, (string)($doc['number'] ?? ''), true, 'c');
    $cara(2, true);

    if (!empty($doc['cancelled_at'])) {
        $stamp = 'STORNO ' . $doc['cancelled_at'];
        $y += 4;
        $sw = $sirka(16, $stamp, true) + 24;
        $x0 = (int)(($W - $sw) / 2);
        imagerectangle($img, $x0, $y, $x0 + $sw, $y + 34, $cerna);
        imagerectangle($img, $x0 + 1, $y + 1, $x0 + $sw - 1, $y + 33, $cerna);
        imagettftext($img, 16, 0, $x0 + 12, $y + 25, $cerna, crmRcptFont(true), $stamp);
        $y += 44;
    }

    // ---- meta ----
    if (!empty($doc['datetime'])) { $radek(13, 'Datum', (string)$doc['datetime'], true, 14); }
    if (!empty($doc['seller']))   { $radek(13, 'Obsluha', (string)$doc['seller'], true, 14); }
    if (!empty($doc['customer'])) { $radek(13, 'Odběratel', (string)$doc['customer'], true, 14); }
    if (!empty($doc['customer_ico'])) { $radek(13, 'IČO odběratele', (string)$doc['customer_ico'], true, 14); }
    if (!empty($doc['invoice']))  { $radek(13, 'Faktura', (string)$doc['invoice'], true, 14); }

    // ---- položky ----
    $cara(2);
    foreach (($d['items'] ?? []) as $l) {
        $y += 4;
        foreach ($zalom(16, (string)$l['name'], true) as $ln) { $text(16, $ln, true, 'l', 1.2); }
        $pozn = [];
        if (!empty($l['code']))  { $pozn[] = (string)$l['code']; }
        if (!empty($l['used']))  { $pozn[] = 'POUŽITÉ ZBOŽÍ'; }
        if (!empty($l['par90'])) { $pozn[] = '§ 90'; }
        if ($pozn) { $text(12, implode(' · ', $pozn), false, 'l', 1.2); }
        $qty = rtrim(rtrim(number_format((float)$l['qty'], 2, ',', ' '), '0'), ',');
        $radek(14, $qty . ' × ' . $money((float)$l['unit_price']), $money((float)$l['total']), true, 16);
    }

    // ---- součet ----
    $cara(4);
    $y += 2;
    $radek(17, 'CELKEM', $money((float)($tot['total'] ?? 0)), true, 26);
    if (!empty($doc['payment'])) { $radek(14, 'Úhrada', (string)$doc['payment'], true, 15); }
    if (isset($tot['paid']) && $tot['paid'] !== null) {
        $radek(13, 'Placeno', $money((float)$tot['paid']), true, 14);
        $radek(13, 'Vráceno', $money((float)($tot['change'] ?? 0)), true, 14);
    }

    // ---- rekapitulace DPH ----
    if (!empty($vat['is_payer'])) {
        $cara(2, true);
        $rate = rtrim(rtrim(number_format((float)($vat['rate'] ?? 0), 1, ',', ' '), '0'), ',');
        if ((float)($vat['base'] ?? 0) > 0 || (float)($vat['tax'] ?? 0) > 0) {
            $radek(13, 'Základ ' . $rate . ' %', $money((float)$vat['base']), true, 13);
            $radek(13, 'DPH ' . $rate . ' %', $money((float)$vat['tax']), true, 13);
        }
        if ((float)($vat['used_total'] ?? 0) > 0) {
            $radek(13, 'Zvl. režim § 90', $money((float)$vat['used_total']), true, 13);
        }
    }

    // ---- právní věty ----
    $cara(2, true);
    foreach (($d['legal'] ?? []) as $veta) {
        foreach ($zalom(12, (string)$veta, false) as $ln) { $text(12, $ln, false, 'l', 1.25); }
        $y += 3;
    }

    $y += 6;
    $text(16, (string)($d['thanks'] ?? 'Děkujeme za nákup'), true, 'c');
    if (!empty($co['web'])) { $text(12, (string)$co['web'], false, 'c'); }
    $y += 8;   // zbytek feedu řeší ESC d při tisku

    // ořez na skutečnou výšku
    $fin = imagecreatetruecolor($W, $y);
    imagecopy($fin, $img, 0, 0, 0, 0, $W, $y);
    return $fin;
}

/** GD obraz → ESC/POS bajty (GS v 0 po pásech, feed, pokus o ořez). */
function crmEscposFromImage(\GdImage $im): string {
    $W = imagesx($im); $H = imagesy($im);
    $rowBytes = (int)($W / 8);
    $out = "\x1B\x40";                        // ESC @ init
    $band = 240;                              // pásy kvůli 32kB bufferu tiskárny
    for ($y0 = 0; $y0 < $H; $y0 += $band) {
        $h = min($band, $H - $y0);
        $out .= "\x1D\x76\x30\x00" . chr($rowBytes & 0xFF) . chr(($rowBytes >> 8) & 0xFF)
              . chr($h & 0xFF) . chr(($h >> 8) & 0xFF);
        for ($yy = $y0; $yy < $y0 + $h; $yy++) {
            $rowStr = '';
            for ($bx = 0; $bx < $rowBytes; $bx++) {
                $b = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $rgb = imagecolorat($im, $bx * 8 + $bit, $yy);
                    $g = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                    if ($g < 160) { $b |= (0x80 >> $bit); }
                }
                $rowStr .= chr($b);
            }
            $out .= $rowStr;
        }
    }
    $out .= "\x1B\x64\x04";                   // feed 4 řádky (odtržení o hranu)
    $out .= "\x1D\x56\x42\x10";               // partial cut — bez řezačky se ignoruje
    return $out;
}

/** Impulz do pokladní zásuvky (RJ11 na tiskárně): ESC p 0 60ms 120ms. */
function crmEscposDrawerPulse(): string {
    return "\x1B\x70\x00\x3C\x78";
}

/** Kam se tiskne. */
function crmReceiptPrintTarget(): string {
    return trim((string)get_setting('receipt_printer_target', 'usb:/dev/usb/lp0'));
}

/** Pošle syrové bajty na tiskárnu. Vrací ['ok'=>bool,'error'=>?string]. */
function crmReceiptSendBytes(string $bytes, ?string $target = null): array {
    $target = $target ?: crmReceiptPrintTarget();
    try {
        if (str_starts_with($target, 'usb:')) {
            $dev = substr($target, 4);
            if (!file_exists($dev)) { return ['ok' => false, 'error' => 'Tiskárna není připojená (' . $dev . ' neexistuje).']; }
            $fp = @fopen($dev, 'wb');
            if (!$fp) { return ['ok' => false, 'error' => 'Nelze otevřít ' . $dev . ' — zkontroluj oprávnění (skupina lp).']; }
            fwrite($fp, $bytes); fflush($fp); fclose($fp);
            return ['ok' => true, 'error' => null];
        }
        if (str_starts_with($target, 'tcp:')) {
            [$host, $port] = array_pad(explode(':', substr($target, 4), 2), 2, '9100');
            $fp = @fsockopen($host, (int)$port, $errno, $errstr, 3);
            if (!$fp) { return ['ok' => false, 'error' => 'Tiskárna ' . $host . ':' . $port . ' neodpovídá.']; }
            fwrite($fp, $bytes); fclose($fp);
            return ['ok' => true, 'error' => null];
        }
        if (str_starts_with($target, 'lp:')) {
            $fronta = substr($target, 3);
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $p = proc_open(['lp', '-d', $fronta, '-o', 'raw', '-s'], $desc, $pipes);
            if (!is_resource($p)) { return ['ok' => false, 'error' => 'Nelze spustit lp.']; }
            fwrite($pipes[0], $bytes); fclose($pipes[0]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $rc = proc_close($p);
            return $rc === 0 ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'lp: ' . trim($err)];
        }
        return ['ok' => false, 'error' => 'Neznámý cíl tisku: ' . $target];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
