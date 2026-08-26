<?php
/**
 * Kontrola SN/IMEI v databázi odcizených mobilů Policie ČR.
 * Vrací ['status' => clean|stolen|unknown|notimei|error, 'imei', 'text'].
 * Chyba/neurčitost NIKDY neblokuje naskladnění — jen se uloží a zobrazí.
 *
 * 25. 8. 2026: PČR aplikaci přepsala. Starý ASP.NET postback
 * (aplikace.policie.gov.cz, __VIEWSTATE → POST → span Label1) zmizel —
 * doména se přesměrovává na nový web a formulářová pole tam nejsou, takže
 * kontrola vracela „unknown" u všeho. Nově stačí GET s parametrem IMEI
 * a výsledek je vypsaný v bloku .gov-message__content.
 * POZOR na parsování: stránka jinde obsahuje slovo „nalezeny-predmet"
 * (položka menu), takže se hledá VÝHRADNĚ uvnitř hlášky, ne v celém HTML.
 */

function afxPcrCheckImei(string $raw, int $timeout = 8): array {
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) < 14) {   // IMEI má 14–15 číslic; SN (např. Apple) kontrolovat nelze
        return ['status' => 'notimei', 'imei' => $digits,
            'text' => 'Zadané SN/IMEI není platné IMEI mobilního telefonu (14–15 číslic) – '
                . 'kontrolu odcizení v databázi PČR nelze provést.'];
    }
    $q = substr($digits, 0, 14);   // 15. číslice je kontrolní, web chce prvních 14
    $url = 'https://policie.gov.cz/patrani-mobily/?IMEI=' . rawurlencode($q);
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)';

    try {
        if (!function_exists('curl_init')) { throw new RuntimeException('cURL není dostupné.'); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout + 4,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: cs,en;q=0.8'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);   // curl_close je od PHP 8.0 no-op
        if ($body === false) { throw new RuntimeException($err ?: 'požadavek selhal'); }
        if ($code >= 400) { throw new RuntimeException('web PČR odpověděl HTTP ' . $code); }
        $body = (string)$body;

        $res = afxPcrClassifyHtml($body);
        return ['status' => $res['status'], 'imei' => $q, 'text' => $res['text']];
    } catch (Throwable $e) {
        return ['status' => 'error', 'imei' => $q,
            'text' => 'Kontrolu v databázi PČR se nepodařilo provést (chyba připojení): ' . $e->getMessage()];
    }
}

/**
 * Vyhodnocení odpovědi webu PČR. Oddělené od stahování, aby šlo otestovat
 * i případ „záznam NALEZEN", který na živém webu bez odcizeného IMEI
 * vyzkoušet nejde (scripts/pcr_test.php).
 * Vrací ['status' => clean|stolen|unknown, 'text' => hláška webu].
 */
function afxPcrClassifyHtml(string $html): array {
    // hláška o výsledku (nový web 2026); fallback na starý span Label1
    $blocks = [];
    if (preg_match_all('/class="[^"]*gov-message__content[^"]*"[^>]*>(.*?)<\/div>/su', $html, $mm)) {
        $blocks = $mm[1];
    } elseif (preg_match('/id="ctl00_Application_Label1"[^>]*>(.*?)<\/span>/su', $html, $m1)) {
        $blocks = [$m1[1]];
    }
    $clean = static function (string $raw): string {
        $t = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $raw) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    };
    $texts = array_values(array_filter(array_map($clean, $blocks), static fn($t) => $t !== ''));
    if (!$texts) {
        return ['status' => 'unknown', 'text' => 'Databáze PČR nevrátila jednoznačnou odpověď.'];
    }
    // rozhoduje blok, který mluví o výsledku hledání — ostatní hlášky (cookies,
    // provozní upozornění) by jinak mohly vyhodnocení zvrátit
    $lower = static fn(string $t): string => function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    // pořadí vodítek od nejjistějšího: výsledková věta vždy zní „Na základě
    // zadaných kritérií … záznam". Slovo „nalez" je až poslední záchrana —
    // samo o sobě je v cizí hlášce („Nalezeny nové funkce") a vyrobilo by
    // FALEŠNÉ ODCIZENO, které by zbytečně zablokovalo naskladnění.
    $pick = $texts[0];
    foreach (['kritéri', 'kriteri', 'záznam', 'zaznam', 'imei', 'nalez'] as $hint) {
        $found = null;
        foreach ($texts as $t) {
            if (str_contains($lower($t), $hint)) { $found = $t; break; }
        }
        if ($found !== null) { $pick = $found; break; }
    }
    $low = $lower($pick);
    // POZOR: „byl nalezen" je podřetězec „nebyl nalezen" → testovat NEJDŘÍV negativ!
    if (str_contains($low, 'nebyl nalezen') || str_contains($low, 'nebyly nalezeny')
        || str_contains($low, 'nebylo nalezeno') || str_contains($low, 'nenalezen')) {
        return ['status' => 'clean', 'text' => $pick];
    }
    if (str_contains($low, 'nalezen') || str_contains($low, 'nalezeno') || str_contains($low, 'nalezeny')
        || str_contains($low, 'odcizen') || str_contains($low, 'evidov') || str_contains($low, 'blokov')) {
        return ['status' => 'stolen', 'text' => $pick];
    }
    return ['status' => 'unknown', 'text' => $pick];
}
