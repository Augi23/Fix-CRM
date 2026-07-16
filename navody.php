<?php
/**
 * NÁVODY — jednoduché klikací postupy pro zaměstnance.
 * Záložky: CRM (funkce systému) a Opravy (servisní postupy — plní se postupně).
 * Návody jsou data-driven (pole níže) — nový návod = přidat položku do pole.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$tab = ($_GET['tab'] ?? 'crm') === 'opravy' ? 'opravy' : 'crm';

/* ── Návody CRM (odpovídají chování systému k v1.8.2) ─────────────────────── */
$guides = [];
$guides['crm'] = [
    [
        'id' => 'nova-zakazka', 'icon' => 'fa-plus-circle', 'color' => '#0A84FF',
        'title' => 'Přidání zakázky',
        'intro' => 'Nová zakázka se zakládá průvodcem o 3 krocích — tlačítko „Nová zakázka" najdeš na Nástěnce i v Zakázkách.',
        'steps' => [
            'Klikni na <b>Nová zakázka</b> (modré tlačítko vpravo nahoře).',
            '<b>Krok 1 — Klient:</b> začni psát jméno nebo telefon a vyber klienta z nabídky. Nový klient → tlačítko <b>Nový klient</b>. Interní zakázka (naše zařízení) → tlačítko <b>Interní zakázka</b>.',
            'Klikni na <b>Další →</b>.',
            '<b>Krok 2 — Zařízení:</b> vyber typ zařízení, typ zakázky, značku a model. Vyplň <b>Heslo/PIN</b> zařízení a <b>popis závady</b>. Volitelně: sériové číslo, fotky stavu při příjmu, oprava z ceníku (předvyplní cenu).',
            'Klikni na <b>Další →</b>.',
            '<b>Krok 3 — Dokončení:</b> zkontroluj souhrn, případně uprav odhad ceny, vyber technika (nebo nech „— Technik —" = bez technika) a klikni na <b>Dokončit</b>.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Povinná pole:</b> klient, typ zařízení a zakázky, značka, model, PIN/heslo a popis závady. Bez nich tě průvodce nepustí dál.'],
            ['typ' => 'info', 'text' => '<b>Technik je nepovinný</b> — zakázku lze založit bez technika, přidělí se později (nebo si ji technik vezme sám). Vybrat jde kdokoliv aktivní z obou poboček.'],
            ['typ' => 'role', 'text' => '<b>Pobočka:</b> vedení (admin, manažer, Boss) volí pobočku v kroku 3; ostatním se doplní jejich pobočka automaticky.'],
        ],
    ],
    [
        'id' => 'interni-zakazka', 'icon' => 'fa-screwdriver-wrench', 'color' => '#BF5AF2',
        'title' => 'Interní zakázka (naše zařízení)',
        'intro' => 'Pro zařízení, která nejsou od veřejného klienta, ale potřebujeme je evidovat (výkup, servisní kusy, vlastní technika).',
        'steps' => [
            'V průvodci novou zakázkou klikni v kroku Klient na <b>🔧 Interní zakázka</b>.',
            'Vybere se profil „Interní zakázka" a předvyplní se PIN <b>0000</b> — nic dalšího u klienta neřešíš.',
            'Pokračuj normálně: zařízení, závada, Dokončit.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Interní zakázky mají v seznamech fialový štítek <span class="badge" style="background:rgba(191,90,242,.2);color:#BF5AF2;border:1px solid rgba(191,90,242,.4);">INTERNÍ</span> a barevný proužek — na první pohled se odliší od klientských.'],
            ['typ' => 'info', 'text' => 'Interní profil nemá telefon ani e-mail → klientovi nikdy neodejde SMS/e-mail a nejde na něj přihlásit do klientského portálu.'],
        ],
    ],
    [
        'id' => 'novy-klient', 'icon' => 'fa-user-plus', 'color' => '#34C759',
        'title' => 'Přidání nového klienta',
        'intro' => 'Nového klienta založíš přímo v průvodci zakázkou — nemusíš opouštět rozdělanou práci.',
        'steps' => [
            'V kroku Klient klikni na <b>Nový klient</b> — rozbalí se panel.',
            'Vyplň <b>jméno, příjmení, telefon a e-mail</b> (u firmy přepni na „Firma" a můžeš načíst údaje z <b>ARES</b> podle IČO).',
            'Klikni na <b>Uložit klienta</b> — klient se rovnou vybere do zakázky.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Povinné:</b> jméno, příjmení, telefon a platný e-mail. Bez nich se klient neuloží (hlídá tlačítko uložení).'],
            ['typ' => 'role', 'text' => 'Nové klienty smí přidávat <b>každý zaměstnanec</b>.'],
        ],
    ],
    [
        'id' => 'uprava-klienta', 'icon' => 'fa-user-pen', 'color' => '#FF9500',
        'title' => 'Úprava údajů klienta u zakázky',
        'intro' => 'Telefon, e-mail či jméno klienta jde upravit v detailu zakázky (tlačítko Upravit zakázku).',
        'steps' => [
            'Otevři zakázku a klikni na <b>Upravit zakázku</b>.',
            'Klienta najdeš vyhledáváním (jméno/telefon) — předvybraný je aktuální klient zakázky.',
            'Uprav údaje a ulož.',
        ],
        'conditions' => [
            ['typ' => 'warn', 'text' => '<b>Pozor:</b> každá změna již vyplněných údajů klienta se výrazně zapisuje do Historie jako <b>„RUČNĚ ZMĚNĚNO"</b> — je dohledatelné kdo, kdy a co přepsal.'],
            ['typ' => 'role', 'text' => 'Údaje klienta smí měnit <b>každý zaměstnanec</b> (od 14. 7. 2026; dřív jen admin).'],
        ],
    ],
    [
        'id' => 'zmena-stavu', 'icon' => 'fa-bolt', 'color' => '#0A84FF',
        'title' => 'Změna stavu zakázky',
        'intro' => 'Stav změníš dvěma způsoby: velkými tlačítky v detailu zakázky, nebo bleskem ⚡ přímo v seznamu.',
        'steps' => [
            '<b>V detailu zakázky:</b> použij výrazná tlačítka <b>Přidat do práce</b> → <b>Připraveno k vyzvednutí</b> → <b>Vydáno</b> (mění se podle aktuálního stavu).',
            '<b>V seznamu zakázek:</b> klikni na <b>⚡ blesk</b> u řádku a vyber cílový stav (funguje i storno).',
            'Při označení <b>Vydáno</b> se automaticky doplní konečná cena z odhadu (pokud nebyla zadaná) a klientovi odejde poděkování s žádostí o recenzi.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Stav smí měnit <b>každý zaměstnanec</b> u kterékoliv zakázky (od v1.6).'],
            ['typ' => 'warn', 'text' => '<b>„V opravě" vyžaduje technika</b> — nepřiřazená zakázka jde do práce až po výběru technika. Jeden technik smí mít max. <b>2 rozdělané</b> zakázky současně.'],
            ['typ' => 'warn', 'text' => 'Po <b>Vydáno</b> se stav zamyká — zpět ho vrátí jen vedení.'],
            ['typ' => 'info', 'text' => 'Při „Připraveno k převzetí" odejde klientovi e-mail (a SMS, je-li zapnuta) s výzvou k vyzvednutí.'],
        ],
    ],
    [
        'id' => 'prirazeni-technika', 'icon' => 'fa-user-cog', 'color' => '#64D2FF',
        'title' => 'Přiřazení / změna / odebrání technika',
        'intro' => 'Technika u zakázky změníš v detailu zakázky v panelu stavu.',
        'steps' => [
            'Otevři zakázku — v pravém panelu je výběr <b>Technik</b>.',
            'Vyber kohokoliv ze seznamu (oba pobočky), nebo zvol <b>— bez technika —</b> pro odebrání.',
            'Ulož změnou stavu nebo tlačítkem uložení — přiřazení se propíše hned.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Technika smí měnit <b>každý zaměstnanec</b>; nabídka obsahuje všechny aktivní techniky.'],
            ['typ' => 'info', 'text' => 'Zakázka <b>zůstává na své pobočce</b>, i když ji dělá technik z druhé pobočky.'],
            ['typ' => 'info', 'text' => 'Nově přiřazenému technikovi vyskočí upozornění na zařízení, kde má otevřené CRM.'],
        ],
    ],
    [
        'id' => 'naskladneni', 'icon' => 'fa-truck-loading', 'color' => '#34C759',
        'title' => 'Naskladnění dílu (příjem)',
        'intro' => 'Přišly nové díly? Naskladnění zabere pár vteřin — mobilem u regálu, nebo z počítače.',
        'steps' => [
            '<b>Mobilem:</b> naskenuj <b>QR kód na regálu</b> (stačí kamera telefonu) — otevře se karta dílu.',
            'Zadej počet přijatých kusů (+/−) a klikni na <b>Přidat do skladu</b>. Stav se ihned navýší.',
            '<b>Z počítače:</b> Sklad → u dílu ikona <b>kamionu</b> → zadej počet.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Naskladňovat smí <b>každý zaměstnanec</b>.'],
            ['typ' => 'info', 'text' => 'Počet na jeden příjem: 1–10 000 ks. Každý příjem se zapisuje do deníku pohybů (kdo, kdy, kolik).'],
            ['typ' => 'info', 'text' => 'Díl přijatý přes <b>Nákupy</b> (stav „přijato") se naskladní automaticky — neskladňuj ho podruhé ručně.'],
        ],
    ],
    [
        'id' => 'vydej-qr', 'icon' => 'fa-qrcode', 'color' => '#FF9500',
        'title' => 'Výdej dílu na zakázku skenem QR',
        'intro' => 'Bereš díl ze skladu pro konkrétní opravu? Naskenuj ho — přidá se k zakázce s cenou a sklad se hned odečte.',
        'steps' => [
            'V detailu zakázky klikni na <b>Vzít díl skenem QR</b> (žluté tlačítko u dílů). Zakázka se „připraví" na 30 minut.',
            'Dojdi ke skladu a <b>naskenuj QR kód dílu</b> na regálu — klidně mobilem, připravená zakázka tě tam čeká předvybraná.',
            'Zadej počet kusů a klikni na <b>Vzít ze skladu</b>. Díl se přidá k zakázce s prodejní cenou a sklad se ihned sníží.',
            'Bereš víc druhů dílů? Prostě skenuj další QR — zakázka zůstává předvybraná.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Bez přípravy ze zakázky jde vydat taky — na kartě dílu vybereš zakázku ze seznamu aktivních (s rychlým hledáním).'],
            ['typ' => 'warn', 'text' => 'Nejde vydávat na <b>vydané</b> ani <b>stornované</b> zakázky a nejde vzít víc, než je skladem.'],
            ['typ' => 'info', 'text' => 'Připravenou zakázku zrušíš křížkem na kartě dílu. Smazáním dílu ze zakázky se kusy automaticky vrátí na sklad.'],
        ],
    ],
    [
        'id' => 'dil-klasicky', 'icon' => 'fa-microchip', 'color' => '#64D2FF',
        'title' => 'Přidání dílu k zakázce z počítače',
        'intro' => 'Klasická cesta bez skeneru — z detailu zakázky.',
        'steps' => [
            'V detailu zakázky klikni na <b>Přidat díl</b>.',
            'Vyhledej díl ze skladu (název, SKU) a zadej počet.',
            'Ulož — díl se přidá s aktuální prodejní cenou.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => '<b>Technik a brigádník</b> smí vybrat jen díly, které jsou <b>skladem</b>. Vedení může přidat i nedostupný díl.'],
            ['typ' => 'info', 'text' => 'Díl s nulovým skladem se automaticky zařadí do <b>Nákupů</b> k objednání.'],
            ['typ' => 'info', 'text' => 'Takto přidané díly se ze skladu odečtou při <b>dokončení</b> zakázky (na rozdíl od QR výdeje, který odečítá hned).'],
        ],
    ],
    [
        'id' => 'qr-stitky', 'icon' => 'fa-tags', 'color' => '#BF5AF2',
        'title' => 'Tisk QR štítků na regály',
        'intro' => 'Každý díl má svůj QR kód — nalepený na regálu umožňuje mobilní naskladnění i výdej.',
        'steps' => [
            'Otevři <b>Sklad</b> a klikni nahoře na <b>QR štítky</b> — otevře se arch se štítky všech naskladněných dílů.',
            'Klikni na <b>🖨 Tisknout</b>, arch nastříhej a štítky nalep na regály k dílům.',
            'Jednotlivý štítek (nový díl): ikona <b>QR</b> u řádku dílu.',
        ],
        'conditions' => [],
    ],
    [
        'id' => 'nakupy', 'icon' => 'fa-cart-shopping', 'color' => '#FF9500',
        'title' => 'Nákupy — objednání a příjem dílů',
        'intro' => 'Fronta dílů k objednání: co chybí skladem, objednává se tady.',
        'steps' => [
            'Otevři <b>Nákupy</b> — vidíš frontu požadavků (čekající / objednané / přijaté).',
            'Nový požadavek: <b>Přidat požadavek</b> (nebo vznikne automaticky, když se k zakázce přidá vyprodaný díl).',
            'Po objednání u dodavatele přepni stav na <b>Objednáno</b>.',
            'Až zboží dorazí, přepni na <b>Přijato</b> — kusy se automaticky naskladní.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Stavy nákupů spravuje <b>vedení</b> (admin, manažer, Boss). Technik může požadavek založit a přiřadit objednaný/přijatý díl ke své zakázce.'],
            ['typ' => 'info', 'text' => 'Vrácení stavu z „přijato" zpět kusy zase odečte — sklad vždy sedí.'],
        ],
    ],
    [
        'id' => 'chat', 'icon' => 'fa-comments', 'color' => '#0A84FF',
        'title' => 'Týmový chat',
        'intro' => 'Jedna společná místnost pro všechny zaměstnance.',
        'steps' => [
            'Otevři <b>Chat</b> v horním menu.',
            'Napiš zprávu a odešli Enterem.',
            'Nová zpráva se ohlásí <b>zvukem kdekoliv v CRM</b> a ikona Chat v menu <b>bíle dýmá</b>, dokud si ji nepřečteš.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Chat vidí a píšou <b>všichni zaměstnanci</b>.'],
        ],
    ],
    [
        'id' => 'historie', 'icon' => 'fa-clock-rotate-left', 'color' => '#64D2FF',
        'title' => 'Historie — kdo co udělal',
        'intro' => 'Auditní stopa systému: přihlášení, založení a úpravy zakázek, změny klientů, skladové pohyby, faktury…',
        'steps' => [
            'Otevři <b>Historie</b> v horním menu.',
            'Filtruj podle akce, člověka nebo data; hledat jde i fulltextem.',
            'Záznamy „RUČNĚ ZMĚNĚNO" u klientů jsou zvýrazněné — rychle najdeš přepsané údaje.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Historii vidí <b>všichni zaměstnanci kromě techniků vedlejších poboček</b> (Roman a Mark ji nevidí).'],
        ],
    ],
    [
        'id' => 'reklamace', 'icon' => 'fa-rotate-left', 'color' => '#f97316',
        'title' => 'Reklamace',
        'intro' => 'Klient reklamuje provedenou opravu? Eviduje se v sekci Reklamace s vazbou na původní zakázku.',
        'steps' => [
            'Otevři <b>Reklamace</b> v horním menu a klikni na <b>Nová reklamace</b>.',
            'Vyber původní zakázku, popiš závadu a ulož.',
            'Průběh měň stavy přímo v seznamu (v řešení → vyřízeno / zamítnuto). Otevřené reklamace svítí jako číslo u ikony Reklamace v menu.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Reklamace vidí a řeší <b>všichni zaměstnanci</b>.'],
            ['typ' => 'warn', 'text' => 'Zakázku s navázanou reklamací nejde smazat — reklamace se musí vyřešit dřív.'],
        ],
    ],
    [
        'id' => 'podpis-klienta', 'icon' => 'fa-signature', 'color' => '#64D2FF',
        'title' => 'Podpis klienta (příjem / výdej)',
        'intro' => 'Klient podepisuje převzetí do servisu a vyzvednutí — prstem na iPadu nebo na tvém zařízení.',
        'steps' => [
            'V detailu zakázky najdi vpravo blok <b>Podpis klienta</b> (řádky Příjem a Výdej).',
            '<b>Podepsat</b> — otevře celoobrazovkový podpisový pad na zařízení, kde stojíš.',
            'Ikona <b>tabletu</b> — pošle žádost na podpisovou stanici (iPad u pultu); klient podepíše tam.',
            'Po podpisu se u řádku objeví zelené potvrzení s časem a podpis se tiskne na zakázkovém listu.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Podepsaný dokument klient vidí i ve svém klientském portálu (applefix.help).'],
        ],
    ],
    [
        'id' => 'tisk-dokumentu', 'icon' => 'fa-print', 'color' => '#0A84FF',
        'title' => 'Tisk dokumentů a štítku zakázky',
        'intro' => 'Zakázkový list, servisní příkaz i štítek na zařízení vytiskneš z detailu zakázky (nebo ze seznamu ikonou tiskárny).',
        'steps' => [
            'V detailu zakázky klikni na tlačítko tisku — nabídka: <b>Zakázkový list A4</b> (pro klienta, s podpisy a rozpisem ceny), <b>Servisní příkaz</b> (pro dílnu).',
            '<b>Tisk štítku</b> — pošle štítek s kódem zakázky rovnou na štítkovačku Brother (bez dialogu, tiskne server).',
            'Ze seznamu zakázek jde totéž přes ikonu tiskárny u řádku.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Štítkovačka je zapojená na Karlíně — štítky se tisknou tam. Čárový kód na štítku umí přečíst skener v horní liště (otevře zakázku).'],
        ],
    ],
    [
        'id' => 'web-objednavky', 'icon' => 'fa-globe', 'color' => '#BF5AF2',
        'title' => 'Objednávky z webu (applefix.cz)',
        'intro' => 'Rezervace z webu se v CRM objevují samy jako zakázky — nic se nezakládá ručně.',
        'steps' => [
            'Nová webová objednávka = zakázka s fialovým stavem <b>Přijato z RepairPluginu</b> a čipem s objednaným termínem (📅). Dnešní termíny visí i ve „Frontě dnes" na nástěnce.',
            'Zakázka přichází <b>bez technika</b> — někdo si ji vezme, nebo se přiřadí.',
            'Když klient objednávku na webu zruší, zakázka se v CRM automaticky stornuje.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'V poznámkách zakázky je vždy číslo webové objednávky, termín a poznámka klienta. Cena z webového ceníku se předvyplní do odhadu.'],
        ],
    ],
    [
        'id' => 'prehledy', 'icon' => 'fa-chart-line', 'color' => '#34C759',
        'title' => 'Přehledy a statistiky',
        'intro' => 'Výkony, tržby a odměny za zvolené období.',
        'steps' => [
            'Otevři <b>Přehledy</b>, nahoře nastav období (od–do) a klikni na <b>Aktualizovat</b>.',
            '<b>Statistiky techniků</b>: opraveno, odpracováno, čas v systému, tržby a odměna po lidech (skupinované po pobočkách).',
            '<b>Celkové statistiky servisu</b> a <b>Detailně po zaměstnancích</b> — další záložky téže stránky.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Plné přehledy vidí <b>vedení</b>; technici vidí své statistiky.'],
            ['typ' => 'info', 'text' => 'Odměny: technici ze zakázek (sazba × odpracovaný čas na opravách), brigádníci a admin z přihlášeného času v systému.'],
        ],
    ],
    [
        'id' => 'imei', 'icon' => 'fa-mobile-screen-button', 'color' => '#64D2FF',
        'title' => 'Kontrola IMEI (kradené telefony)',
        'intro' => 'Rychlé ověření zařízení proti policejní databázi — přímo z nástěnky.',
        'steps' => [
            'Na nástěnce vpravo najdi widget <b>Kontrola IMEI</b>.',
            'Zadej <b>14 nebo 15 číslic</b> (u 14 se kontrolní číslice dopočítá) a klikni na <b>Zkontrolovat</b>.',
        ],
        'conditions' => [
            ['typ' => 'warn', 'text' => 'Ověřuj u výkupů a podezřelých zařízení VŽDY — kontrola běží proti policejní databázi odcizených zařízení.'],
        ],
    ],
    [
        'id' => 'nakupni-seznam', 'icon' => 'fa-basket-shopping', 'color' => '#34C759',
        'title' => 'Nákupní seznam',
        'intro' => 'Rychlý týmový seznam „co dokoupit" — zelené tlačítko v horní liště.',
        'steps' => [
            'Klikni na zelené <b>Nákupní seznam</b> v horní liště.',
            'Přidej položku (název, případně poznámka/priorita) — vidí ji všichni.',
            'Po nákupu položku odškrtni.',
        ],
        'conditions' => [
            ['typ' => 'role', 'text' => 'Přidávat může každý; správu položek (mazání, stavy) má vedení a lidé s právem nákupů.'],
        ],
    ],
    [
        'id' => 'klientsky-portal', 'icon' => 'fa-user-check', 'color' => '#0A84FF',
        'title' => 'Klientský portál (applefix.help) — co říct klientovi',
        'intro' => 'Klient si sám sleduje stav zakázky online. Hodí se znát, když se ptá „jak se to dozvím?".',
        'steps' => [
            'Klient dostane e-mail (a SMS, je-li zapnuta) při přijetí, dokončení a s výzvou k vyzvednutí — s odkazem na <b>applefix.help</b>.',
            'Přihlásí se <b>e-mailem nebo telefonem</b> + <b>PINem zakázky</b> (má ho na zakázkovém listu).',
            'V portálu vidí stav opravy, cenu a podepsané dokumenty.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Interní zakázky do portálu nejdou (nemají kontakt ani PIN pro klienta).'],
        ],
    ],
    [
        'id' => 'role-pobocky', 'icon' => 'fa-shield-halved', 'color' => '#BF5AF2',
        'title' => 'Kdo co smí — role a pobočky (přehled)',
        'intro' => 'Rychlá mapa práv, ať víš, proč někde něco nevidíš.',
        'steps' => [
            '<b>Admin / manažer / Boss (vedení):</b> vše — všechny pobočky, faktury, nastavení skladu, korekce zásob, správa nákupů.',
            '<b>Technik:</b> zakázky (zakládání, stavy, technici, díly skladem), klienti, chat, sklad přes QR.',
            '<b>Brigádník:</b> stejná práva jako technik; odměna se počítá z přihlášeného času, ne ze zakázek.',
            '<b>Pobočky:</b> zaměstnanci Karlína vidí data obou poboček; kolegové z Na Příkopě jen svou pobočku (a nevidí Historii).',
            '<b>Dlaždice Nepřidělené/Nedokončené:</b> vedení vidí součet obou poboček, ostatní jen svou.',
        ],
        'conditions' => [
            ['typ' => 'warn', 'text' => 'Mazání zakázek a nastavení systému = <b>jen administrátor</b>. Faktury a účetnictví = <b>administrátor a Boss</b>. Aktualizace systému = <b>vedení</b> (admin, manažer, Boss).'],
        ],
    ],
];

/* ── Návody Opravy (plní se postupně) ─────────────────────────────────────── */
$guides['opravy'] = [];
?>

<div class="container-fluid" style="max-width: 980px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 text-white"><i class="fas fa-graduation-cap me-2 text-info"></i>Návody</h4>
            <div class="small text-white-75">Jednoduché postupy krok za krokem — jak v CRM správně naklikat běžné činnosti.</div>
        </div>
        <input type="text" id="guideSearch" class="form-control" style="max-width: 260px;" placeholder="🔍 Hledat návod…" autocomplete="off">
    </div>

    <ul class="nav nav-pills mb-3 gap-2">
        <li class="nav-item"><a class="nav-link <?php echo $tab === 'crm' ? 'active' : ''; ?>" href="navody.php?tab=crm"><i class="fas fa-desktop me-1"></i> CRM</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $tab === 'opravy' ? 'active' : ''; ?>" href="navody.php?tab=opravy"><i class="fas fa-wrench me-1"></i> Opravy</a></li>
    </ul>

    <?php if (empty($guides[$tab])): ?>
        <div class="glass-panel p-5 border-secondary text-center">
            <i class="fas fa-wrench fa-3x mb-3 text-white-50"></i>
            <h5 class="text-white">Návody na opravy se připravují</h5>
            <p class="text-white-75 mb-0">Sem budeme postupně přidávat servisní postupy (výměna displeje, baterie, diagnostika…). Máš tip na první návod? Napiš ho do Chatu.</p>
        </div>
    <?php else: ?>
        <div class="accordion afx-guides" id="guidesAcc">
            <?php foreach ($guides[$tab] as $i => $g): ?>
            <div class="accordion-item afx-guide glass-panel border-secondary mb-2" data-search="<?php echo e(mb_strtolower($g['title'] . ' ' . strip_tags($g['intro']))); ?>">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed afx-guide-head" type="button" data-bs-toggle="collapse" data-bs-target="#g<?php echo $i; ?>">
                        <span class="afx-guide-ico" style="--gcol: <?php echo e($g['color']); ?>;"><i class="fas <?php echo e($g['icon']); ?>"></i></span>
                        <span>
                            <span class="d-block fw-semibold"><?php echo e($g['title']); ?></span>
                            <span class="d-block small text-white-75 fw-normal"><?php echo $g['intro']; ?></span>
                        </span>
                    </button>
                </h2>
                <div id="g<?php echo $i; ?>" class="accordion-collapse collapse" data-bs-parent="#guidesAcc">
                    <div class="accordion-body pt-2">
                        <ol class="afx-steps">
                            <?php foreach ($g['steps'] as $s): ?><li><?php echo $s; ?></li><?php endforeach; ?>
                        </ol>
                        <?php foreach ($g['conditions'] as $c):
                            $map = ['info' => ['fa-circle-info', 'rgba(10,132,255,.12)', 'rgba(10,132,255,.35)'],
                                    'warn' => ['fa-triangle-exclamation', 'rgba(255,149,0,.12)', 'rgba(255,149,0,.4)'],
                                    'role' => ['fa-user-shield', 'rgba(191,90,242,.10)', 'rgba(191,90,242,.35)']];
                            [$cIco, $cBg, $cBd] = $map[$c['typ']] ?? $map['info']; ?>
                            <div class="afx-cond" style="background: <?php echo $cBg; ?>; border-color: <?php echo $cBd; ?>;">
                                <i class="fas <?php echo $cIco; ?> me-2"></i><?php echo $c['text']; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="guideNoResults" class="glass-panel p-4 border-secondary text-center text-white-75" style="display:none;">
            Nic nenalezeno — zkus jiné slovo.
        </div>
    <?php endif; ?>
</div>

<style>
.afx-guides .accordion-item { background: transparent; overflow: hidden; border-radius: 14px !important; }
.afx-guide-head { background: transparent !important; color: #fff !important; box-shadow: none !important; display: flex; gap: 14px; align-items: center; padding: 14px 16px; }
.afx-guide-head::after { filter: invert(1) opacity(.6); }
.afx-guide-ico {
    flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center; font-size: 17px;
    color: var(--gcol); background: color-mix(in srgb, var(--gcol) 14%, transparent);
    border: 1px solid color-mix(in srgb, var(--gcol) 35%, transparent);
}
.afx-steps { list-style: none; counter-reset: krok; padding-left: 0; margin: 0 0 6px; }
.afx-steps li { counter-increment: krok; position: relative; padding: 7px 0 7px 42px; line-height: 1.5; color: rgba(255,255,255,.9); }
.afx-steps li + li { border-top: 1px dashed rgba(255,255,255,.08); }
.afx-steps li::before {
    content: counter(krok); position: absolute; left: 0; top: 7px;
    width: 26px; height: 26px; border-radius: 50%; font-size: .8rem; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; background: rgba(10,132,255,.25); border: 1px solid rgba(10,132,255,.5);
}
.afx-cond { border: 1px solid; border-radius: 10px; padding: 8px 12px; font-size: .87rem; color: rgba(255,255,255,.92); margin-top: 8px; }
</style>

<script>
// vyhledávání v návodech (název + úvod)
(function () {
    var inp = document.getElementById('guideSearch');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        var any = false;
        document.querySelectorAll('.afx-guide').forEach(function (el) {
            var hit = q === '' || (el.dataset.search || '').indexOf(q) !== -1;
            el.style.display = hit ? '' : 'none';
            if (hit) any = true;
        });
        var nr = document.getElementById('guideNoResults');
        if (nr) nr.style.display = any ? 'none' : '';
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
