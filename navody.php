<?php
/**
 * NÁVODY — jednoduché klikací postupy pro zaměstnance.
 * Záložky: CRM (funkce systému) a Opravy (servisní postupy — plní se postupně).
 * Návody jsou data-driven (pole níže) — nový návod = přidat položku do pole.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$tab = in_array($_GET['tab'] ?? 'crm', ['crm', 'banka', 'opravy'], true) ? (string)($_GET['tab'] ?? 'crm') : 'crm';

// Přečtené návody TOHOTO pracovníka — nepřečtené mají svítící (glow) ikonku.
// První rozbalení návodu zapíše api/guide_viewed.php a glow zhasne (v2.8.0).
ensureGuideViewsTable();
$__guidesSeen = [];
try {
    $__gvs = $pdo->prepare("SELECT guide_id FROM guide_views WHERE staff_key = ?");
    $__gvs->execute([crmStaffKey()]);
    $__guidesSeen = $__gvs->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $__guidesSeen = []; }

/* ── Návody CRM (odpovídají chování systému k v1.8.2) ─────────────────────── */
$guides = [];
$guides['crm'] = [
    [
        'id' => 'naskladneni-produktu', 'icon' => 'fa-box-open', 'color' => '#0A84FF',
        'title' => 'Naskladnění produktu (bazar) přímo v CRM',
        'intro' => 'Nový kus použité elektroniky naskladníš rovnou v CRM — bez Mac appky. Kus je okamžitě prodejný na Pokladně a připravený pro e-shop.',
        'steps' => [
            'Otevři <b>Sklad → Produkty — e-shop</b> a klikni na zelené <b>Naskladnit produkt</b>.',
            'Vyber <b>typ zařízení a model</b> (nabídka je stejná jako v appce; „✏️ Vlastní…" pro cokoliv mimo seznam), doplň úložiště, barvu, stav, baterii a <b>cenu</b>. Název produktu se skládá sám — vidíš ho živě vpravo.',
            '<b>SN / IMEI naskenuj čtečkou</b> nebo zapiš — systém ho hned prověří v <b>databázi odcizených mobilů Policie ČR</b> (zelená = v pořádku, červená = POZOR). Bez SN se kód vygeneruje automaticky (AFX-…).',
            'Volitelně přilož <b>foto produktu</b> (nahraje se hned) a klikni na <b>Přidat</b>, nebo rovnou <b>Přidat a vytisknout štítek</b> (Ctrl/Cmd+Enter) — cenovka vyjede z Brotheru na Karlíně.',
            'Formulář se vyčistí (typ, stav a prodejna zůstanou) — můžeš hned naskladňovat další kus. Úprava kusu: tužka u řádku v tabulce.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Duplicity hlídá systém:</b> stejné SN/IMEI podruhé nepřidáš (ukáže se, kdy byl kus naskladněn). Odcizené zařízení z databáze PČR jde přidat jen po výslovném potvrzení — a zapíše se to do Historie.'],
            ['typ' => 'info', 'text' => '<b>Mac appka funguje dál souběžně.</b> Kus naskladněný v CRM si CRM chrání — import souboru z appky ho nepřepíše. Jeden fyzický kus ale naskladňuj jen v jednom systému.'],
            ['typ' => 'info', 'text' => '<b>Pro Upgates:</b> tlačítko „CSV pro Upgates" stáhne kompletní sklad ve formátu appky — nahraje se do Upgates stejně jako dřív soubor z appky (párování podle kódu, aktualizace stávajících).'],
            ['typ' => 'role', 'text' => 'Naskladňovat a upravovat smí <b>vedení</b> (admin, Boss, manažer). Tisknout cenový štítek smí každý přihlášený.'],
        ],
    ],
    [
        'id' => 'kasa-pokladna', 'icon' => 'fa-cash-register', 'color' => '#30D158',
        'title' => 'Pokladna (kasa) — prodej přes pult',
        'intro' => 'Přímý prodej produktů (použitá elektronika, příslušenství) a servisních dílů bez zakládání zakázky. Sklad se odečítá automaticky, účtenka se tiskne hned.',
        'steps' => [
            'Otevři <b>Pokladna</b> v horním menu.',
            '<b>Najdi zboží:</b> do velkého pole piš název, model nebo sériové číslo. Nabízí se jen to, co je <b>skutečně skladem</b> — zelený štítek PRODUKT (bazarové zboží), modrý DÍL (servisní díl). Klikem se položka přidá do košíku.',
            '<b>Nebo pípni čtečkou:</b> USB čtečka čárových kódů funguje kdykoli, když je Pokladna otevřená — <b>nemusíš klikat do žádného pole</b>. Načteš kód a položka skočí rovnou do košíku (vysoké pípnutí = přidáno, nízké + hláška = kód nenalezen).',
            '<b>Košík:</b> položek můžeš přidat víc. U každé jde upravit <b>počet kusů</b> i <b>cenu za kus</b> (sleva na místě) — celková částka se přepočítá sama.',
            '<b>Platba:</b> vyber jedno ze tří tlačítek — <b>Hotově</b>, <b>Kartou</b> (terminál jedeš normálně zvlášť, tady se jen eviduje typ platby pro účetnictví) nebo <b>Na fakturu</b> (vyber zákazníka — faktura se vystaví automaticky a objeví se i v Účetnictví).',
            'Klikni na <b>Dokončit prodej</b> — otevře se <b>účtenka k tisku</b>; u platby na fakturu jde vytisknout i faktura. Účtenku jde kdykoli dotisknout ze seznamu „Dnešní prodeje" nebo z Historie.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Sklad se odečítá automaticky</b> v okamžiku prodeje: díl ubere kusy (se záznamem ve skladových pohybech), produkt se přepne na vyprodáno. Produkt prodaný na kase zůstane vyprodaný i po dalším nahrání souboru z naskladňovací appky — systém to ohlídá sám.'],
            ['typ' => 'info', 'text' => '<b>Historie → Kasa prodejna:</b> všechny doklady, souhrn Hotově/Kartou/Fakturou za zvolené období (denní uzávěrka) a dotisk účtenek.'],
            ['typ' => 'info', 'text' => '<b>DPH u použitého zboží (§ 90):</b> na účtence i faktuře se u bazarového zboží DPH nevyčísluje — doklad má správný režim automaticky, nic neřešíš.'],
            ['typ' => 'role', 'text' => '<b>Prodávat smí každý</b> přihlášený zaměstnanec na obou pobočkách. <b>Storno</b> (vrátí zboží na sklad a zruší případnou fakturu) smí jen vedení — admin a Boss, v Historii → Kasa prodejna.'],
            ['typ' => 'info', 'text' => '<b>Zámek kasy:</b> když se kasa 15 minut nepoužívá, obrazovka se zamkne. Stačí zadat <b>svoje heslo</b> (jméno je předvyplněné) a pracuješ dál — nic se neztratí, rozdělaný košík zůstává. Odkaz „Přihlásit jiného zaměstnance" použij při střídání směny.'],
            ['typ' => 'info', 'text' => '<b>10× špatné heslo</b> = dotyčný účet se na 15 minut zablokuje (odemčení i přihlášení). Blokace platí jen pro toho jednoho člověka — kdokoli jiný se mezitím přihlásí a kasa jede dál.'],
        ],
    ],
    [
        'id' => 'vernostni-karta-recepce', 'icon' => 'fa-id-card', 'color' => '#64D2FF',
        'title' => 'Věrnostní karta klienta — sken na recepci',
        'intro' => 'Každý klient má věrnostní kartu s QR kódem (v mobilu v Apple/Google Peněžence). Skenem karty se okamžitě otevře jeho profil se zakázkami a body — bez ručního hledání.',
        'steps' => [
            '<b>Na počítači recepce (jednorázově):</b> na Nástěnce klikni <b>vpravo dole</b> na <b>„Režim recepce"</b> — pilulka zezelená („Recepce poslouchá skeny") a počítač začne poslouchat skeny. Stačí zapnout jednou, vydrží zapnuto (i po zavření a otevření).',
            '<b>Při návštěvě klienta:</b> klient ukáže kartu v Peněžence → naskenuj její QR firemním iPhonem přihlášeným do CRM. <b>Žádná zvláštní aplikace není potřeba</b> — buď klepni v horní liště CRM na <b>ikonu QR kódu</b> (vedle zvonku) a namiř foťák na kód, nebo otevři <b>Fotoaparát</b> iPhonu a klepni na nabídnutý odkaz. Skener v liště je tentýž jako na zakázky — pozná QR zakázky i klientské karty.',
            'Na iPhonu se otevře profil klienta — a do ~3 vteřin <b>vyskočí i na počítači recepce</b>.',
            'Z profilu rovnou: <b>Nová zakázka pro klienta</b> (předvyplní ho), telefon, historie zakázek, věrnostní body.',
            'Alternativa bez iPhonu: USB/Bluetooth 2D čtečka u počítače — kurzor do vyhledávání a pípnout kartu; funguje i sken čísla karty (AFXC-…).',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Jak sken „doskočí" na recepci (když je režim zapnutý):</b> počítač recepce se každé <b>3 vteřiny</b> tiše ptá serveru, jestli někdo neskenoval kartu. Po pípnutí kartou na iPhonu se profil klienta objeví na recepci <b>sám do ~3 vteřin</b> — nemusíš nic přepínat ani klikat.'],
            ['typ' => 'info', 'text' => '<b>Nepřeruší ti rozdělanou práci:</b> když máš zrovna otevřené okno nebo píšeš do políčka, recepce <b>počká</b> a nepřeskočí — klient naskočí, až budeš volný. Každý sken naskočí jen <b>jednou</b>; přenačtení stránky ani Zpět/Vpřed už nic znovu „nevystřelí".'],
            ['typ' => 'info', 'text' => '<b>Platí po pobočkách:</b> sken „doskočí" jen na počítač recepce <b>té pobočky</b>, kde je personál přihlášený — Karlín a druhá pobočka si do skenů neskáčou. Podmínka: iPhone i počítač recepce musí být oba přihlášené do CRM.'],
            ['typ' => 'info', 'text' => 'Karta se klientovi vytvoří automaticky při zadání do systému. Klient si ji přidá do peněženky v klientském portálu (tlačítko „Moje karta").'],
            ['typ' => 'info', 'text' => '<b>Body:</b> za každou vyzvednutou opravu se přičtou automaticky (bonus za zakázku + body z ceny). Interní zakázky body nedostávají. Nastavení: Nastavení → Věrnostní karta.'],
            ['typ' => 'role', 'text' => 'Sken funguje jen pro přihlášený personál — klient skenem vlastní karty na recepci nic neovlivní (uvidí přihlašovací stránku).'],
        ],
    ],
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
        'id' => 'jazyk-klienta', 'icon' => 'fa-language', 'color' => '#64D2FF',
        'title' => 'Jazyk klienta — e-maily a dokumenty v jeho řeči',
        'intro' => 'Každý klient má zvolený „Jazyk komunikace" (čeština / angličtina / ukrajinština). Podle něj pak automaticky odchází e-maily, tisknou se doklady a zobrazuje se klientský portál — při tisku ani odesílání už nic přepínat nemusíš.',
        'steps' => [
            '<b>U nového klienta:</b> jazyk vybereš rovnou při zakládání — v okně <b>Nová zakázka</b> (sekce Klient, pole „Jazyk komunikace") nebo ve formuláři <b>Nový klient</b> na stránce Klienti.',
            '<b>Změna u stávajícího klienta:</b> Klienti → <b>tužka (Upravit)</b> → pole <b>„Jazyk komunikace"</b> → Uložit. Platí hned pro všechny další dokumenty a e-maily (už odeslané se zpětně nemění).',
            '<b>Co se jazykem klienta řídí:</b> zakázkový list (tisk i e-mail, včetně obchodních podmínek), faktura, účtenka, reklamační protokol, podpisová stanice, e-maily (výzva k vyzvednutí, žádost o recenzi, zakázkový list) a celý klientský portál.',
            '<b>Ukrajinština:</b> e-maily odejdou ukrajinsky; tištěné doklady jdou <b>anglicky</b> (ukrajinská tisková verze neexistuje).',
            '<b>Jednorázový tisk v jiném jazyce</b> (bez změny nastavení klienta): k adrese tiskového dokumentu přidej <b>&lang=en</b> / <b>&lang=cs</b> — např. print_order.php?id=123<b>&lang=en</b>.',
        ],
        'conditions' => [
            ['typ' => 'warn', 'text' => '<b>Data zakázky se nepřekládají:</b> popis závady, poznámky apod. se na dokladu tisknou přesně tak, jak jsi je zapsal. U cizojazyčného klienta je proto piš jeho jazykem (nebo anglicky).'],
            ['typ' => 'info', 'text' => '<b>Právní texty:</b> rozhodné je vždy české znění — angličtina/ruština na dokladech je jeho věrný překlad.'],
            ['typ' => 'info', 'text' => 'Klient si v <b>klientském portálu</b> může jazyk kdykoliv přepnout sám; přepnutí platí po dobu přihlášení, po novém přihlášení se vrátí jeho nastavený „Jazyk komunikace".'],
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
            ['typ' => 'warn', 'text' => '<b>„V opravě" vyžaduje technika</b> — nepřiřazená zakázka jde do práce až po výběru technika. Přiřadit zakázek technikovi lze neomezeně, ale <b>aktivně opravovat smí max. 10</b> zakázek současně (stav „Provádí se").'],
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
        'id' => 'umisteni', 'icon' => 'fa-map-location-dot', 'color' => '#30D158',
        'title' => 'Umístění skladu — regály, police a krabičky',
        'intro' => 'Každý díl má v CRM své fyzické místo: regál → police → krabička. Dražší díly mají vlastní kartu a QR, drobné levné díly sdílí krabičku svého modelu.',
        'steps' => [
            'Otevři <b>Sklad → Umístění</b> a založ strukturu: <b>regály</b> (R1, R2…), na nich <b>police</b> (R1-P1…) a <b>krabičky</b> (K001…) — jde jich založit víc najednou.',
            'Vytiskni <b>štítky umístění</b> (u řádku, nebo Arch štítků) a nalep je na regály, police a krabičky.',
            'Ve <b>Skladu → Servis</b> zaškrtej díly (klidně vyfiltrované podle modelu) a v liště dole zvol <b>Přiřadit umístění</b> — díly se „nastěhují" do krabičky. Stejně hromadně nastavíš i <b>model zařízení</b> (iPhone 12…).',
            'Skenem <b>QR na krabičce</b> mobilem uvidíš její obsah — ťukni na díl a rovnou ho naskladníš nebo vydáš na zakázku. Přiřadit díl do krabičky jde i přímo z jejího QR („Přiřadit díl sem").',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => 'Krabička má <b>trvalý kód</b> — když ji přestěhuješ, změníš jí v Umístěních jen polici. Štítek se <b>nepřetiskuje</b>.'],
            ['typ' => 'info', 'text' => 'Drobné levné díly (šroubky, flexy… do pár desítek Kč) můžeš vést <b>jednotlivě se společnou krabičkou</b> (zachová napojení na dodavatele), nebo jako jednu <b>souhrnnou kartu</b> („Drobné díly – iPhone 12") s jednotnou cenou — obojí funguje.'],
            ['typ' => 'role', 'text' => 'Správu umístění a hromadné přiřazování smí, kdo spravuje sklad; <b>obsah krabičky přes QR vidí každý zaměstnanec</b>.'],
            ['typ' => 'info', 'text' => 'Vedení může v obsahu krabičky rovnou <b>opravit počty kusů</b> (inventura, tužtička u dílu) — zapíše se jako korekce do deníku pohybů.'],
        ],
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

/* ── Návody Banka (platby, párování) ─────────────────────────────────────── */
$guides['banka'] = [
    [
        'id' => 'banka-parovani-plateb', 'icon' => 'fa-building-columns', 'color' => '#30D158',
        'title' => 'Jak fungují platby — párování plateb',
        'intro' => 'CRM si stahuje pohyby z firemního účtu a došlé platby samo navazuje na faktury. Tady je, čím se přitom řídí, co udělá bez tebe a co zůstává na člověku.',
        'steps' => [
            'Otevři <b>Účetnictví → Banka</b> a klikni na <b>Synchronizovat</b>. Systém stáhne nové pohyby z účtu (s třídenním přesahem, aby neuteklo nic, co banka zaúčtovala později) a hned je zkusí navázat na faktury.',
            'Nejdřív se vyřídí <b>vrácené a zrušené platby</b>, teprve pak se páruje. Pořadí je schválně takové — faktura zaplacená penězi, které se mezitím vrátily, nesmí zůstat jako zaplacená.',
            '<b>Co je jednoznačné, systém zapíše sám.</b> V seznamu má platba zelený štítek s číslem faktury a poznámku <b>auto</b> (např. „Částečná platba — na faktuře 2026010 zbývá 2 500 Kč").',
            '<b>Co jednoznačné není, dostane žlutý štítek „K prověření"</b> — a hned pod ním <b>důvod i návrh</b>, například „přišlo víc, než na faktuře zbývá · částka odpovídá součtu faktur 2026010 + 2026011". Nikdy se přitom nic neoznačí jako zaplacené.',
            '<b>Ruční spárování:</b> u platby klikni na 🔗, vyber fakturu (jde <b>hledat podle čísla i částky</b> a nabízí se <b>zbytek k úhradě</b>, ne celá částka) a potvrď. Menší platba se zapíše jako částečná — dozvíš se, kolik na faktuře zbývá.',
            '<b>Odpárování:</b> u spárované platby klikni na přeškrtnutý odkaz. Faktura se vrátí mezi nezaplacené (pokud ji nekryjí další platby) a platba se <b>vyřadí z automatického párování</b>, aby ji systém sám znovu nespároval. Zpět do automatu ji pustíš tlačítkem ↺.',
            'Přehled si filtruj podle stavu párování: <b>Spárované</b> · <b>K prověření</b> · <b>Nespárované</b> · <b>Vyřazené z párování</b>. Dlaždice „K prověření" nahoře ukazuje, kolik věcí čeká na člověka.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Hlavní pravidlo:</b> automaticky se platba zapíše <b>jen když variabilní symbol sedí právě jedné otevřené faktuře</b>. Porovnává se i VS složený jen z číslic (posledních 10) — díky tomu se najde i platba z QR kódu u faktury s nečíselným číslem.'],
            ['typ' => 'info', 'text' => '<b>Částka:</b> nesmí přijít víc, než na faktuře zbývá. Menší platba se zapíše jako <b>částečná</b> a faktuře zůstane zbytek k úhradě. Tolerance na zaokrouhlení je <b>1 Kč</b>; u faktur pod 100 Kč se musí trefit přesně.'],
            ['typ' => 'info', 'text' => '<b>Faktura je zaplacená teprve tehdy, když ji platby pokryjí celou.</b> Do té doby vidíš v Účetnictví „Zaplaceno X z Y", v detailu zakázky odznak <b>Částečně zaplaceno</b> a na dokladu řádek „zbývá uhradit" — a <b>QR platba nabízí jen zbytek</b>, ne celou částku.'],
            ['typ' => 'info', 'text' => '<b>Čas hraje roli:</b> platba nemůže být starší než faktura (5 dní tolerance na zálohy a datum zaúčtování) a platba starší než <b>180 dní</b> se automaticky nepáruje — číselné řady faktur se v dalších letech opakují a starý symbol by mohl sednout na novou fakturu.'],
            ['typ' => 'warn', 'text' => '<b>Platbu, která fakturu uzavírá, systém zapíše sám jen když má z banky vlastní referenci.</b> U pohybu bez ní (typicky <b>vklad hotovosti na účet</b>) nelze vyloučit, že se tentýž vklad načetl dvakrát — takovou platbu proto vždy pošle člověku.'],
            ['typ' => 'warn', 'text' => '<b>Automaticky se NIKDY nezapíše:</b> přeplatek · platba v cizí měně · platba bez variabilního symbolu · symbol sedící víc fakturám · platba na už uhrazenou fakturu · platba starší než faktura. Všechno tohle jde <b>k prověření</b>.'],
            ['typ' => 'info', 'text' => '<b>Bere se jen firemní účet a jen příchozí platby v korunách.</b> Odchozí platby se nepárují. Testovací (sandbox) prostředí nemůže sáhnout na ostré faktury — pohyby jsou oddělené.'],
            ['typ' => 'info', 'text' => '<b>Dvě synchronizace naráz si neškodí</b> — druhá počká, než první doběhne. Jedna platba se nemůže zapsat dvakrát a jednu fakturu nemůžou omylem „zaplatit" dvě různé platby.'],
            ['typ' => 'warn', 'text' => '<b>Rozhodnutí člověka má vždy přednost.</b> Co jednou odpáruješ, to už systém sám nespáruje — dokud platbu výslovně nevrátíš do automatického párování.'],
            ['typ' => 'info', 'text' => '<b>Všechno je dohledatelné:</b> každé spárování, odpárování i vrácení platby se zapisuje do <b>Historie změn</b> — kdo, kdy, jaká částka a jaká faktura.'],
            ['typ' => 'info', 'text' => '<b>Proč se nesynchronizuje pořád:</b> banka účtuje podle počtu dotazů, proto je mezi synchronizacemi <b>61 minut</b>. Admin může vynutit stažení dřív (systém se zeptá, protože se to počítá do tarifu).'],
            ['typ' => 'role', 'text' => 'Banku vidí a páruje <b>jen vedení</b> (admin, Boss) — stejná hranice jako u Účetnictví. Ostatní zaměstnanci se do modulu nedostanou.'],
            ['typ' => 'warn', 'text' => '<b>Než začnou pohyby chodit:</b> napojení na Komerční banku se jednorázově dokončuje (kvalifikovaný certifikát a potvrzení přístupu jednatelem v KB, souhlas pak platí 12 měsíců). Do té doby je seznam pohybů prázdný a párování nemá co dělat.'],
        ],
    ],
    [
        'id' => 'banka-napojeni-kb', 'icon' => 'fa-plug-circle-check', 'color' => '#0A84FF',
        'title' => 'Napojení CRM na bankovní účet (KB API)',
        'intro' => 'Jednorázové nastavení, po kterém si CRM samo stahuje pohyby z firemního účtu. Banka nikomu nedává heslo do bankovnictví — jednatel jen dvakrát potvrdí přístup ve svém internetovém bankovnictví.',
        'steps' => [
            '<b>Portál pro vývojáře</b> (<a href="https://developers.kb.cz" target="_blank" class="text-info">developers.kb.cz</a>): u aplikace „AppleFix CRM" musí být odebrané tři služby — <b>Account Direct Access</b>, <b>OAuth2</b> a <b>Client Registration</b> — a ke každé vygenerovaný <b>API klíč</b>. Klíče se vkládají do <b>Nastavení → Banka</b> (každý má své políčko). Klíč je vidět jen jednou; při ztrátě se vygeneruje nový.',
            '<b>Jen pro ostrý účet: kvalifikovaný certifikát</b> (I.CA nebo PostSignum) na firmu. S ním se na serveru jednou spustí <code>php scripts/kb_software_statement.php --p12=cesta.p12 --pass=HESLO</code> — vznikne „software statement", potvrzení, že aplikace patří nám (platí 12 měsíců). <b>V testovacím prostředí (sandbox) certifikát není potřeba</b> a tento krok se přeskočí.',
            '<b>V testovacím prostředí (sandbox)</b> se registrace aplikace NEDĚLÁ — použij zelené tlačítko <b>„Sandbox: nastavit testovací přístup a autorizovat"</b>. CRM si nastaví testovací přihlašovací údaje a pošle tě na testovací stránku KB, kde stačí napsat jméno testovacího klienta (např. <b>Klient 1</b>). Žádné bankovnictví, žádný certifikát.',
            '<b>Pro ostrý účet: Nastavení → Banka → „1. Registrovat aplikaci u KB".</b> CRM tě přesměruje do banky, jednatel se přihlásí a potvrdí spojení <b>KB Klíčem</b>. Banka pošle zašifrovanou odpověď zpět do CRM a to si z ní samo uloží <b>client_id a client_secret</b> — nikdo nic nepřepisuje.',
            '<b>„2. Autorizovat přístup k účtu".</b> Jednatel znovu potvrdí rozsah přístupu (jen čtení pohybů) a <b>vybere účty</b>, ke kterým CRM pustí. Po návratu má CRM <b>refresh token</b> (platí 12 měsíců) a rovnou nabídne seznam účtů — vyber ten, který má sledovat, a ulož.',
            '<b>Zkouška:</b> Účetnictví → Banka → <b>Synchronizovat</b>. Měly by naskočit pohyby a u plateb s variabilním symbolem se rovnou spárovat faktury (viz návod „Jak fungují platby").',
            '<b>Kontrola bez banky:</b> na serveru jde kdykoli spustit <code>php scripts/kb_test_napojeni.php</code> — vypíše, co je nastavené, co chybí, a ověří, že CRM umí rozšifrovat odpověď banky (testuje se na oficiálním vzorku z dokumentace KB).',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Co dělá jednatel:</b> dvakrát se přihlásí do KB a potvrdí KB Klíčem (krok 3 a 4). Nikam nezadává heslo do CRM a CRM naopak nezná jeho přihlášení do banky — dostane jen povolení <b>číst pohyby</b> na vybraných účtech.'],
            ['typ' => 'info', 'text' => '<b>Podmínky u banky:</b> firma musí mít <b>MojeBanka (Business)</b> a <b>KB Klíč</b>. Přístup je jen pro čtení — CRM z účtu nemůže poslat platbu.'],
            ['typ' => 'warn', 'text' => '<b>Refresh token platí 12 měsíců.</b> Pak se pohyby přestanou stahovat, dokud jednatel neprojde znovu krok <b>„2. Autorizovat přístup k účtu"</b> (registraci aplikace opakovat nemusí). Stejně tak <b>software statement</b> platí 12 měsíců — hlídej i platnost certifikátu.'],
            ['typ' => 'warn', 'text' => '<b>Autorizační kód z banky platí jen 2 minuty.</b> Když se návrat protáhne (třeba hledáním KB Klíče), CRM to napíše — prostě klikni na tlačítko znovu.'],
            ['typ' => 'info', 'text' => '<b>První stažení historie:</b> v Nastavení → Banka je pole <b>„Stahovat pohyby od"</b> — určuje, jak hluboko do historie se má jít při prvním načtení (bez vyplnění posledních 30 dní). Další synchronizace už navazují na tu předchozí. Pozor: <b>testovací data KB jsou z roku 2019</b>, takže pro vyzkoušení v sandboxu tam patří datum kolem 1. 1. 2019, jinak se nenačte nic.'],
            ['typ' => 'info', 'text' => '<b>Sandbox vs. ostrý účet:</b> sandbox je testovací prostředí s umělými daty a bez certifikátu — hodí se na vyzkoušení celého řetězce. <b>Přepnutí prostředí smaže přihlašovací údaje k bance</b>, protože sandboxové client_id v produkci neplatí; napojení se pak projde znovu. Sandboxové pohyby se nikdy nemíchají s ostrými.'],
            ['typ' => 'warn', 'text' => '<b>Dvě adresy nesmí změnit:</b> <code>redirectUris</code> a <code>registrationBackUri</code> (jsou vypsané v Nastavení → Banka). Jsou zapsané v software statementu a banka se vrací jen na ně — při změně domény CRM je potřeba udělat nový statement.'],
            ['typ' => 'info', 'text' => '<b>Tarif KB:</b> do 50 volání za měsíc zdarma, pak 100 Kč (dotaz nejdřív po 61 minutách) nebo 500 Kč (po 10 minutách). Proto má synchronizace v CRM odstup 61 minut — admin ji může vynutit dřív.'],
            ['typ' => 'role', 'text' => 'Napojení nastavuje <b>admin</b> (Nastavení). Potvrzení v bance dělá <b>jednatel</b> (majitel účtu) — a musí to být pokaždé tentýž člověk, jinak banka tokeny nevydá. Modul Banka pak používá vedení (admin, Boss).'],
            ['typ' => 'info', 'text' => '<b>Kde to skončilo:</b> každý krok napojení se zapisuje do Historie změn (spuštění registrace, získání client_id, získání tokenu, obnovení souhlasu) — vždy je dohledatelné, kdy a kdo napojení dělal.'],
        ],
    ],
    [
        'id' => 'banka-test-sandbox', 'icon' => 'fa-flask', 'color' => '#BF5AF2',
        'title' => 'Test v sandboxu — jak si napojení vyzkoušet nanečisto (a co ukázal)',
        'intro' => 'Komerční banka má testovací prostředí s umělými daty. Dá se v něm projít celý koloběh — od autorizace po zaplacenou fakturu — bez certifikátu a bez rizika, že se sáhne na skutečné peníze. Tady je postup i to, co první ostrý test odhalil.',
        'steps' => [
            '<b>Nastavení → Banka</b>: prostředí nech na <b>Sandbox</b> a klikni na zelené <b>„Sandbox: nastavit testovací přístup a autorizovat"</b>. Na stránce banky napiš jméno testovacího klienta (např. <b>Klient 1</b>) a potvrď. CRM si samo uloží přístup i seznam testovacích účtů.',
            '<b>Vyplň „Stahovat pohyby od"</b> na <b>1. 1. 2019</b> a ulož. Testovací data KB jsou totiž z roku 2019 — s výchozím oknem posledních 30 dní by se nenačetlo nic.',
            '<b>Účetnictví → Banka → Synchronizovat.</b> Naskočí testovací pohyby přímo z banky (příchozí i odchozí, včetně poplatků a zahraniční platby).',
            '<b>Vystav testovací fakturu</b> na částku některé <b>příchozí</b> platby a dej jí odpovídající variabilní symbol. Pak u platby klikni na <b>🔗</b> a spáruj ji — faktura se označí jako zaplacená, u platby se objeví zelený štítek s jejím číslem.',
            '<b>Po testu ukliď:</b> smaž testovací fakturu i její platbu a vymaž <b>naučený účet klienta</b> (jinak by CRM u ostrých plateb navrhovalo klienta podle testovacího účtu). Přepnutím na <b>Produkce</b> se sandboxové pohyby přestanou zobrazovat a přihlašovací údaje k testovacímu prostředí se smažou.',
            '<b>Kdykoli bez banky:</b> na serveru jde spustit <code>php scripts/kb_test_napojeni.php</code> (zkontroluje nastavení a že CRM umí rozšifrovat odpověď banky) a <code>php scripts/kb_test_parovani.php</code> (32 kontrol párování na modelových pohybech).',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Výsledek prvního testu (30. 7. 2026):</b> prošlo stažení pohybů z banky, ruční spárování, označení faktury jako zaplacené, <b>datum platby podle banky</b> (ne podle dne, kdy se klikalo), evidence konkrétní platby s vazbou na bankovní pohyb, zapamatování účtu klienta i zápis do Historie změn. Obnova přístupu (bez ní by se po třech minutách přestaly stahovat pohyby) taky funguje a opakovaná synchronizace nic nezdvojila.'],
            ['typ' => 'warn', 'text' => '<b>Nález, kvůli kterému se test vyplatil — platby v cizí měně.</b> Banka u pohybu posílá <b>dvě částky</b> a není pevně dané, ve které z nich je měna účtu: u jedné testovací platby přišlo <code>681,81 CZK / 27,28 EUR</code>, u druhé obráceně <code>4,21 USD / 99,78 CZK</code>. CRM bralo vždy tu první, takže by u zahraniční platby zapsalo <b>4,21 Kč místo 99,78 Kč</b> — a navíc by ji vyřadilo z párování jako cizoměnovou. Opraveno: bere se částka <b>v měně účtu</b>, původní se ukáže ve zprávě jako „(původně 4,21 USD)". V ostrém provozu by tohle dělalo tichý rozdíl v účetnictví u každé platby ze zahraničí.'],
            ['typ' => 'warn', 'text' => '<b>Automatické párování v sandboxu ověřit nejde.</b> Všechna testovací data KB jsou z roku 2019 a automat zásadně nebere platby starší než <b>180 dní</b> (pojistka, aby starý symbol nesedl na novou fakturu). V testu se korektně zdržel — ověřit ho jde jen testem nanečisto (<code>kb_test_parovani.php</code>), který jede přes stejný kód.'],
            ['typ' => 'info', 'text' => '<b>Co v sandboxu čekat:</b> pět testovacích účtů a jen pár pohybů na každém. <b>Variabilní symbol má jediná příchozí platba</b> z celého prostředí (99,78 Kč na účtu CZ4401…0227), takže na zkoušení párování je jen ona. Data jsou umělá a od ostrých se drží odděleně — sandboxová platba nemůže zaplatit skutečnou fakturu.'],
            ['typ' => 'info', 'text' => '<b>Sandbox se nepřihlašuje do bankovnictví.</b> Registrace aplikace se v něm nedělá (vedla by na skutečné přihlášení do KB), přihlašovací údaje jsou testovací a autorizační kód vydá stránka, kde stačí napsat jméno klienta. Ostrý účet naproti tomu potřebuje kvalifikovaný certifikát a dvě potvrzení jednatelem.'],
            ['typ' => 'info', 'text' => '<b>Další věci, které test odhalil a jsou opravené:</b> banka se vrací na obě naše adresy (ne jen na tu „registrační"), při návratu neposílá prohlížeč přihlašovací cookie (takže se výsledek dřív zahazoval) a identifikátor požadavku musí být ve tvaru UUID — jinak banka poslední krok odmítne. Každý návrat z banky se proto zapisuje do <b>Historie změn</b> i s tím, co přišlo za údaje.'],
            ['typ' => 'role', 'text' => 'Test smí spustit <b>admin</b> (Nastavení → Banka). Uklizení testovacích faktur je důležité — jinak se objeví v účetnictví, statistikách i v přehledu tržeb.'],
        ],
    ],
    [
        'id' => 'banka-situace-plateb', 'icon' => 'fa-circle-question', 'color' => '#FF9F0A',
        'title' => 'Když platba nesedí — situace a jejich řešení',
        'intro' => 'Přehled všeho, co u plateb reálně nastává: co s tím udělá systém sám a co po tobě chce. Podle tohohle se dá vyřídit celý sloupec „K prověření".',
        'steps' => [
            'Otevři <b>Účetnictví → Banka</b> a v filtru <b>Párování</b> vyber <b>K prověření</b>.',
            'U každé platby si přečti <b>důvod pod žlutým štítkem</b> — je tam napsané, proč to systém nezapsal sám, a většinou i návrh faktury.',
            'Podle situace níže rozhodni: <b>🔗 spárovat</b> s nabídnutou (nebo jinou) fakturou, nebo platbu nechat ležet a vyřešit s klientem.',
        ],
        'conditions' => [
            ['typ' => 'info', 'text' => '<b>Klient poslal méně (záloha, splátka).</b> → Systém platbu <b>zapíše sám</b>, faktura zůstane nezaplacená a je u ní vidět zbytek. <b>Ty neděláš nic</b> — až přijde doplatek se stejným symbolem, faktura se uzavře sama.'],
            ['typ' => 'warn', 'text' => '<b>Klient poslal víc, než na faktuře zbývá.</b> → Platba jde <b>k prověření</b> a systém napoví, jestli částka neodpovídá součtu víc faktur. <b>Ty rozhodneš:</b> spárovat se správnou fakturou, nebo přeplatek vrátit. Navázat přeplatek na fakturu schválně nejde — v evidenci by vznikla faktura zaplacená víc, než měla být.'],
            ['typ' => 'info', 'text' => '<b>Jedna platba za víc faktur (firemní klient platí souhrnně).</b> → K prověření s nápovědou „<i>částka odpovídá součtu faktur 2026010 + 2026011</i>". <b>Ty</b> platbu spáruješ s jednou z nich (zapíše se jako částečná) a zbytek dořešíš s účetní. Rozdělit jednu platbu mezi víc faktur systém zatím neumí.'],
            ['typ' => 'info', 'text' => '<b>Platba bez variabilního symbolu.</b> → K prověření. Když je <b>číslo faktury napsané ve zprávě pro příjemce</b>, systém ji navrhne; když klient dřív platil <b>ze stejného účtu</b>, navrhne jeho otevřenou fakturu. <b>Ty jen zkontroluješ a potvrdíš.</b> Samo se to nikdy nezaplatí — z jednoho účtu může platit i někdo jiný.'],
            ['typ' => 'info', 'text' => '<b>Symbol sedí dvěma fakturám</b> (opakované číslo, ručně přepsaný symbol). → K prověření se seznamem, kterých faktur se to týká. <b>Ty vybereš správnou</b> ručně.'],
            ['typ' => 'info', 'text' => '<b>Klient napsal symbol s překlepem.</b> → Platba se nenaváže na nic a zůstane <b>nespárovaná</b>. <b>Ty</b> ji spáruješ ručně — ve výběru faktury se dá hledat i podle částky.'],
            ['typ' => 'warn', 'text' => '<b>Platba se vrátila nebo ji banka zrušila (storno).</b> → Systém sám <b>vrátí fakturu mezi nezaplacené</b>, platbu vyřadí z párování a zapíše to do Historie. <b>Ty</b> zkontroluješ a případně pošleš klientovi upomínku — peníze na účtu nejsou.'],
            ['typ' => 'warn', 'text' => '<b>Systém spároval platbu se špatnou fakturou.</b> → <b>Odpáruj ji</b> (přeškrtnutý odkaz u platby). Faktura se vrátí mezi nezaplacené (pokud ji nekryje jiná platba) a platba se vyřadí z automatu, aby ji systém nevrátil zpět. Pak ji spáruj se správnou fakturou.'],
            ['typ' => 'info', 'text' => '<b>Platba v eurech nebo jiné měně.</b> → Neplatí korunovou fakturu a k párování se vůbec nenabízí. <b>Ty</b> to vyřešíš v účetnictví ručně (přepočet kurzem).'],
            ['typ' => 'info', 'text' => '<b>Vklad hotovosti na účet.</b> → Jde <b>k prověření</b>, protože u něj chybí bankovní reference a nedá se vyloučit dvojí načtení. <b>Ty</b> ho po kontrole potvrdíš ručně.'],
            ['typ' => 'warn', 'text' => '<b>Klient zaplatil dvakrát.</b> → Druhá platba jde <b>k prověření</b> s poznámkou, že faktura je už uhrazená. Nikdy se nepřipíše. <b>Ty</b> ji vrátíš klientovi, nebo (po dohodě) použiješ na jinou jeho fakturu.'],
            ['typ' => 'info', 'text' => '<b>Faktura zaplacená hotově nebo kartou na kase.</b> → Banka o ní neví, platba se eviduje z kasy a faktura je zaplacená. Kdyby klient totéž poslal <b>ještě převodem</b>, přijde to k prověření jako duplicitní platba (viz výše).'],
            ['typ' => 'info', 'text' => '<b>Změna částky na faktuře, na které už visí platby.</b> → Systém stav <b>přepočítá</b>: když platby nově nekryjí celek, faktura se vrátí mezi nezaplacené se správným zbytkem. Faktury bez evidované platby (starší doklady, hotovost) se nikdy samy „neodzaplatí".'],
            ['typ' => 'role', 'text' => 'Párovat i odpárovat smí <b>vedení</b> (admin, Boss). Každý krok je vidět v Historii změn, takže je vždy dohledatelné, kdo platbu k faktuře přiřadil nebo ji odebral.'],
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
        <li class="nav-item"><a class="nav-link <?php echo $tab === 'banka' ? 'active' : ''; ?>" href="navody.php?tab=banka"><i class="fas fa-building-columns me-1"></i> Banka</a></li>
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
            <?php $__gNew = !in_array($g['id'], $__guidesSeen, true); ?>
            <div class="accordion-item afx-guide glass-panel border-secondary mb-2<?php echo $__gNew ? ' afx-guide-new' : ''; ?>" data-guide-id="<?php echo e($g['id']); ?>" data-search="<?php echo e(mb_strtolower($g['title'] . ' ' . strip_tags($g['intro']))); ?>">
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

/* Nepřečtený návod: ikonka jemně svítí bíle, dokud si ho pracovník poprvé neotevře */
.afx-guide-new .afx-guide-ico {
    border-color: rgba(255,255,255,.95);
    animation: afxGuideGlow 2.4s ease-in-out infinite;
}
@keyframes afxGuideGlow {
    0%, 100% { box-shadow: 0 0 6px rgba(255,255,255,.45); }
    50%      { box-shadow: 0 0 18px rgba(255,255,255,.95); }
}
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

// glow „nepřečteného" návodu: první rozbalení ho zapíše na server a zhasne hned
(function () {
    var acc = document.getElementById('guidesAcc');
    if (!acc) return;
    acc.addEventListener('show.bs.collapse', function (e) {
        var item = e.target.closest('.afx-guide');
        if (!item || !item.classList.contains('afx-guide-new')) return;
        item.classList.remove('afx-guide-new');
        var fd = new FormData();
        fd.append('guide_id', item.dataset.guideId || '');
        fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        fetch('api/guide_viewed.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
