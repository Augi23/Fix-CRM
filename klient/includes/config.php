<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

/* ── Jazyk klientského portálu ────────────────────────────────────────────────
   Přihlášený klient vidí portál ve svém jazyce (viz $_SESSION['client_lang']
   naseedovaný při přihlášení z customers.preferred_language, cs/en; uk→en).
   Jazyk vynucujeme jen pro TENTO request přes $_GET['lang'] — ten má v
   crm_get_language() nejvyšší prioritu, takže všechna volání __() bez druhého
   parametru se přeloží do jazyka klienta (i tiskové dokumenty otevřené z portálu).
   ZÁMĚRNĚ neměníme $_SESSION['lang'] ani cookie 'crm_lang' — ty jsou globální a
   řídí zaměstnanecké UI, takže by portál klienta jinak „přebarvil" i servis.
   Tento soubor načítají POUZE stránky v klient/ (login.php a servis ho neincludují),
   takže je to bezpečný klientský chokepoint. Explicitní ?lang= v URL respektujeme. */
if (!empty($_SESSION['client_lang']) && empty($_GET['lang'])) {
    $_GET['lang'] = $_SESSION['client_lang'];
}
