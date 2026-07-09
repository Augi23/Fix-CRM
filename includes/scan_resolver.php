<?php
/* Sken štítku čtečkou do vyhledávacího pole (?search=...).
   HW čtečka „píše" jako klávesnice: s ČESKÝM rozložením se číslice promění v +ěščřžýáíé
   a Y↔Z se prohodí (QWERTZ) — z APFAZ2600485 je „APFAYěžééčář". Tady patlanici přeložíme
   zpět a při PŘESNÉ shodě kódu zakázky rovnou přesměrujeme do detailu.
   Musí se includovat PŘED includes/header.php (ten už posílá HTML).
   Používá index.php (dashboard hledání) i orders.php. */
if (isset($pdo) && ($_scan = trim($_GET['search'] ?? '')) !== '' && function_exists('resolveScannedOrderId')) {
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
