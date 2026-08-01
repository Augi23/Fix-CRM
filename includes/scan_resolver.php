<?php
/* Sken štítku čtečkou do vyhledávacího pole (?search=...).
   HW čtečka „píše" jako klávesnice: s ČESKÝM rozložením se číslice promění v +ěščřžýáíé
   a Y↔Z se prohodí (QWERTZ) — z APFAZ2600485 je „APFAYěžééčář". Tady patlanici přeložíme
   zpět a při PŘESNÉ shodě kódu zakázky rovnou přesměrujeme do detailu.
   Musí se includovat PŘED includes/header.php (ten už posílá HTML).
   Používá index.php (dashboard hledání) i orders.php. */
if (isset($pdo) && ($_scan = trim($_GET['search'] ?? '')) !== '' && function_exists('resolveScannedOrderId')) {
    // Klientská karta (QR z peněženky) → recepční dohledání klienta.
    // Jen pro přihlášený personál — anonym nesmí přes rozdíl v přesměrování
    // zjišťovat, které tokeny karet existují (header.php ho stejně pošle na login).
    if (!empty($_SESSION['user_id']) && function_exists('crmCustomerIdByCardToken')) {
        $_ctok = null;
        if (preg_match('~klient-karta\.php\?t=([A-Za-z0-9\-]+)~i', $_scan, $mm)) { $_ctok = $mm[1]; }
        elseif (preg_match('~^AFXC-[A-Za-z0-9]+$~i', $_scan)) { $_ctok = strtoupper($_scan); }
        elseif (function_exists('scanNormalizeCandidates')) {
            // HW čtečka s českým rozložením „přepíše" číslice na háčky — zkusit demangl
            foreach (scanNormalizeCandidates($_scan) as $_cnd) {
                if (preg_match('~^AFXC-[A-Za-z0-9]+$~i', (string)$_cnd)) { $_ctok = strtoupper((string)$_cnd); break; }
            }
        }
        if ($_ctok && crmCustomerIdByCardToken($_ctok) > 0) {
            header("Location: klient-karta.php?t=" . rawurlencode($_ctok));
            exit;
        }
    }
    // Kód umístění skladu (RegK1 / RegK1-P2 / KrK001, historicky R1 / K001)
    // napsaný či naskenovaný do hledání
    // → rovnou obsah krabičky/police. Přesná shoda v DB, jinak se nic neděje.
    if (!empty($_SESSION['user_id'])) {
        if (function_exists('ensureStockLocationsSchema')) { ensureStockLocationsSchema(); }
        $_lcands = [$_scan];
        if (function_exists('scanNormalizeCandidates')) { $_lcands = array_merge($_lcands, scanNormalizeCandidates($_scan)); }
        foreach ($_lcands as $_lc) {
            $_lc = strtoupper(trim((string)$_lc));
            // kódy umístění: RegK1, RegCR1-P2, KrK001 (a historické R1 / R1-P2 / K001)
            if ($_lc === '' || !preg_match('/^(?:(?:REG|KR)[A-Z]{1,4}\d{1,4}|[KR]\d{1,4})(?:-P\d{1,3})?$/', $_lc)) { continue; }
            try {
                // kódy jsou nově smíšené (RegK1), vstup ze skenu velkými písmeny —
                // porovnat bez ohledu na velikost, ať to nestojí na collation tabulky
                $_lq = $pdo->prepare("SELECT id FROM stock_locations WHERE UPPER(code) = ?");
                $_lq->execute([$_lc]);
                if (($_lid = (int)$_lq->fetchColumn()) > 0) {
                    header("Location: sklad.php?loc=" . $_lid);
                    exit;
                }
            } catch (Throwable $e) { break; }
        }
    }
    // Vypadá to jako naskenovaný kód? (přepis háčky NEBO souvislý alfanumerický token bez mezer)
    $_looksCode = preg_match('/[+ěščřžýáíéĚŠČŘŽÝÁÍÉ]/u', $_scan) || preg_match('/^[A-Za-z0-9\-]{6,}$/', $_scan);
    if ($_looksCode) {
        $_oid = resolveScannedOrderId($pdo, $_scan);
        if ($_oid) {
            header("Location: view_order.php?id=" . (int)$_oid);
            exit;
        }
        // kód nenalezen -> aspoň nejlépe demanglovaný výraz do výpisu (místo patlaniny s háčky)
        $_cands = scanNormalizeCandidates($_scan);
        if (!empty($_cands)) { $_GET['search'] = end($_cands); }
    }
}
