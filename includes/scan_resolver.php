<?php
/* Sken štítku čtečkou do vyhledávacího pole (?search=...).
   HW čtečka „píše" jako klávesnice: s ČESKÝM rozložením se číslice promění v +ěščřžýáíé
   a Y↔Z se prohodí (QWERTZ) — z APFAZ2600485 je „APFAYěžééčář". Tady patlanici přeložíme
   zpět a při PŘESNÉ shodě kódu zakázky rovnou přesměrujeme do detailu.
   Musí se includovat PŘED includes/header.php (ten už posílá HTML).
   Používá index.php (dashboard hledání) i orders.php. */
if (isset($pdo) && ($_scan = trim($_GET['search'] ?? '')) !== '') {
    // překládat jen OPRAVDU přepsaný vstup (obsahuje háčky) — běžné hledání nesahat
    if (preg_match('/[+ěščřžýáíé]/u', $_scan)) {
        $_scan_fixed = strtr($_scan, ['+' => '1', 'ě' => '2', 'š' => '3', 'č' => '4', 'ř' => '5',
                                      'ž' => '6', 'ý' => '7', 'á' => '8', 'í' => '9', 'é' => '0',
                                      'y' => 'z', 'z' => 'y', 'Y' => 'Z', 'Z' => 'Y']);
    } else {
        $_scan_fixed = $_scan;
    }
    if (preg_match('/^[A-Za-z]{2,10}\d{4,}$/', $_scan_fixed)) {
        try {
            $_st = $pdo->prepare("SELECT id FROM orders WHERE order_code = ? LIMIT 1");
            $_st->execute([strtoupper($_scan_fixed)]);
            if ($_oid = $_st->fetchColumn()) {
                header("Location: view_order.php?id=" . (int)$_oid);
                exit;
            }
            $_GET['search'] = $_scan_fixed;   // kód nenalezen -> aspoň opravený výraz do výpisu
        } catch (Throwable $e) { /* výpis níže */ }
    }
}
