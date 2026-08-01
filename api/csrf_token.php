<?php
/**
 * Čerstvý bezpečnostní token pro PŘIHLAŠOVACÍ stránku.
 *
 * PROČ TO EXISTUJE: stránka otevřená v prohlížeči v sobě nese token z relace,
 * ve které se vykreslila. Relace se po 8 hodinách nečinnosti smaže
 * (session.gc_maxlifetime), ale karta na pultu nebo iPadu zůstane otevřená
 * klidně přes noc — a odeslání formuláře pak skončilo hláškou „Neplatný
 * bezpečnostní token". Přihlašovací stránka si proto token vyžádá čerstvý
 * těsně před odesláním; tímhle dotazem zároveň vznikne nová platná relace.
 *
 * BEZPEČNOST:
 *  - odpověď NEMÁ hlavičky CORS, takže cizí web si ji v prohlížeči nepřečte,
 *  - dotaz z cizí stránky se odmítne podle Sec-Fetch-Site (a to JEŠTĚ PŘED
 *    startem relace, ať odmítnutý požadavek nezakládá soubory a nesahá do DB),
 *  - PŘIHLÁŠENÉ relaci se token nevydává — jediný konzument je odhlášená
 *    přihlašovací stránka, takže není důvod nechat komukoli přečíst token
 *    právě přihlášeného admina.
 */

// ── vše, co jde rozhodnout BEZ relace a databáze ─────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"token":""}';
    exit;
}
$__sfs = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if ($__sfs !== '' && $__sfs !== 'same-origin' && $__sfs !== 'none') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"token":""}';
    exit;
}

ob_start();
require_once __DIR__ . '/../includes/config.php';
ob_clean();   // kdyby cokoliv v includech vypsalo byť mezeru, hlavičky níž by selhaly

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// přihlášenému se token nevydává (jeho stránky ho mají v meta tagu)
if (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']) || !empty($_SESSION['client_authenticated'])) {
    echo '{"token":""}';
    exit;
}

echo json_encode(['token' => (string)($_SESSION['csrf_token'] ?? '')], JSON_UNESCAPED_UNICODE);
