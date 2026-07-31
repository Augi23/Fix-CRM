<?php
/**
 * Historie úprav CRM — ručně vedený, lidsky čitelný přehled dokončených
 * vylepšení. Zobrazuje se v Nastavení → Aktualizace pod git changelogem.
 * Nové záznamy přidávat NAHORU (nejnovější první).
 */
return [
    [
        'version' => '3.30.0',
        'date' => '2026-07-31',
        'time' => '14:47',
        'title' => 'Sklad rozdělen na pobočky (Karlín / Černá Růže) — 1. část',
        'items' => [
            '<b>Výběr pobočky:</b> po kliknutí na <b>Sklad</b> se teď nejdřív zobrazí dvě velké karty (Liquid Glass) — <b>Praha 8 - Karlín</b> a <b>Praha 1 - Černá Růže</b>. Teprve po rozkliknutí se otevře sklad té pobočky. Každá pobočka má <b>vlastní oddělené zásoby</b> (Servis, Produkty i Příslušenství).',
            '<b>Data zůstala Karlínu:</b> celý dosavadní sklad patří Karlínu. <b>Černá Růže začíná prázdná</b> a naskladní si svoje.',
            '<b>Práva:</b> do obou skladů smí <b>nahlížet každý</b>, ale <b>přidávat a upravovat</b> smí jen zaměstnanec dané pobočky (admin a Boss obojí). U cizí pobočky se ukáže odznak „jen prohlížení" a tlačítka zmizí.',
            '<b>Fotky modelů</b> (správa e-shopu) zůstávají jen pobočce Karlín.',
            'Pozn.: pobočkové rozlišení u <b>Nákupů, Umístění</b> a na <b>e-shopu</b> (které zboží je na kterém skladu) dorazí v další části.',
        ],
    ],
    [
        'version' => '3.29.0',
        'date' => '2026-07-31',
        'time' => '13:51',
        'title' => 'Výkup a zástava: údaje o klientovi v rozsahu, který ukládá zákon',
        'items' => [
            '<b>Poučení o osobních údajích je teď krátké a odkazuje na plné znění.</b> Podle pokynů evropského sboru pro ochranu údajů stačí na dokladu tzv. první vrstva — kdo je správce, proč údaje zpracováváme, jaká máte práva a to, co může překvapit (uchování 5 let a předání úřadům na vyžádání) — se odkazem na <b>applefix.cz/zasady-ochrany-osobnich-udaju</b>. Doklad je tím čitelnější a poučení zůstává úplné.',
            '<b>Klient poučení vidí i na podpisovém tabletu:</b> nad podpisem je nově věta, že podpisem potvrzuje i převzetí informace o zpracování osobních údajů, a k podpisu se <b>ukládá přesné znění, které klient viděl</b> — jde tak doložit, s čím souhlasil.',
            '<b>Souhlas se skenem dokladu tam záměrně NENÍ.</b> Kopii průkazu totožnosti u výkupu umožňuje přímo zákon (§ 8 odst. 11 zákona č. 253/2008 Sb.) a metodika Finančního analytického úřadu z 1. 1. 2025 výslovně říká, že v takovém případě je žádat souhlas nepřípustné — klient by ho nemohl svobodně odmítnout. Místo souhlasu je proto na dokladu poučení.',
            '<b>Výkupní list i zástavní formulář se teď ptají na všechny údaje, které vyžaduje zákon č. 253/2008 Sb.</b> (§ 5) pro identifikaci klienta — dosud tam část chyběla a doklad by při kontrole neobstál.',
            '<b>Nová pole:</b> rodné číslo, datum narození, pohlaví, místo narození, státní občanství, druh dokladu, kdo doklad vydal (stát / orgán) a do kdy platí.',
            '<b>Rodné číslo je hlavní údaj</b> — datum narození a pohlaví se vyplňují teprve tehdy, když rodné číslo přiděleno nebylo (typicky u cizinců). Stojí to rovnou v popisku pole, ať se to nemusí pamatovat.',
            '<b>Pro živnostníky</b> přibyla tři nepovinná pole: obchodní firma, sídlo a IČO. U běžného klienta se nechají prázdná.',
            '<b>Zástavní formulář dostal stejná pole jako výkup</b> — přijímání věcí do zástavy má podle zákona úplně stejnou povinnost jako výkup, dosud se u něj vyplňovalo jen jméno, adresa a číslo dokladu.',
            'Popisky jsou upřesněné, aby se nepletly: „Datum narození" (dřív „Rodné číslo / datum narození"), „Číslo dokladu" (dřív „Číslo OP / pasu") a „Platnost do" (dřív „Vydal / platnost do", protože „kdo vydal" má teď vlastní pole). Vše česky, anglicky i rusky.',
        ],
    ],
    [
        'version' => '3.28.0',
        'date' => '2026-07-30',
        'time' => '21:30',
        'title' => 'Výkup: sken dokladu totožnosti (jen pro vedení) a poučení o osobních údajích',
        'items' => [
            '<b>Dvě nová nepovinná pole ve výkupním listu:</b> <b>přední</b> a <b>zadní strana</b> dokladu totožnosti prodávajícího, každá zvlášť. Nová strana nahradí předchozí, ať se nehromadí kopie občanek. Slouží k ověření totožnosti podle zákona o obchodu s použitým zbožím (č. 253/2008 Sb.).',
            '<b>Skeny se ukládají MIMO veřejnou část systému</b> — ne do složky s fotkami, kterou by šlo otevřít přímým odkazem. Ven je pouští jen hlídaný přístup po ověření, že jde o vedení, a <b>každé zobrazení i smazání se zapisuje do historie změn</b>.',
            '<b>Nikdy se netisknou ani neposílají klientovi</b> — do výkupního listu ani do e-mailu se nedostanou, ani omylem (mají vlastní druh přílohy, oddělený od fotodokumentace zařízení).',
            '<b>Nová sekce „Doklady totožnosti"</b> (Dokumenty → tlačítko, vidí ho jen admin a Boss): přehled všech skenů s prodávajícím, číslem dokladu, zařízením a datem nahrání, s hledáním a s upozorněním, jak s nimi zacházet.',
            '<b>Poučení o zpracování osobních údajů</b> přibylo dole na výkupním listu (česky, anglicky i rusky) — kdo je správcem, proč se údaje zpracovávají, jak dlouho se uchovávají, komu se předávají a jaká má prodávající práva.',
        ],
    ],
    [
        'version' => '3.27.0',
        'date' => '2026-07-30',
        'time' => '19:20',
        'title' => 'Částečné platby se netolerují — vedení dostane upozornění',
        'items' => [
            '<b>Změna pravidla (rozhodnutí vedení):</b> firma nepřijímá částečné platby, takže je systém <b>už nezaúčtuje sám</b>. Když přijde míň, než je na faktuře, platba jde <b>k prověření</b> a faktura zůstane nezaplacená — automat ji nikdy neoznačí jako uhrazenou z části.',
            '<b>Upozornění pro vedení:</b> každá taková platba se objeví v <b>oznámeních (zvoneček)</b> u admina a Bosse — s částkou, variabilním symbolem, protistranou a důvodem, proč nesedí. Klikem se otevře rovnou seznam plateb k prověření.',
            '<b>Ručně to jde vyřešit dál:</b> vedení může platbu k faktuře přiřadit ručně (třeba po domluvě s klientem) — pak se eviduje jako doložená částka a je vidět, kolik zbývá.',
            '<b>Klientský portál:</b> nezaplacená faktura <b>po splatnosti</b> se klientovi nově zobrazuje (dřív mu z portálu zmizela) a je <b>červeně zvýrazněná</b> s popiskem „po splatnosti, neuhrazeno" — česky, anglicky i rusky.',
        ],
    ],
    [
        'version' => '3.27.0',
        'date' => '2026-07-31',
        'time' => '12:30',
        'title' => 'Sklad: přehlednější lišta + nová kategorie Příslušenství',
        'items' => [
            '<b>Rozdělená lišta Skladu:</b> vlevo zůstaly jen <b>kategorie</b> (Servis — náhradní díly · Produkty — e-shop · Příslušenství), nástroje <b>Fotky modelů · Umístění · Nákupy</b> se odsunuly doprava.',
            '<b>Nová kategorie „Příslušenství":</b> filtrovaný pohled na produkty — kabely, kryty, adaptéry, boxy, sluchátka, MagSafe apod. Rozpozná se automaticky podle názvu, takže i „kryt na iPhone" spadne správně mezi doplňky, ne mezi telefony. Produkty zůstávají beze změny (ukazují vše).',
        ],
    ],
    [
        'version' => '3.26.2',
        'date' => '2026-07-30',
        'time' => '18:15',
        'title' => 'Dvě pojistky kolem zaplacených faktur',
        'items' => [
            '<b>Synchronizace ze starého webu už nesmaže platbu:</b> dosud u navázaných faktur natvrdo nastavovala stav „vystaveno", takže jediné spuštění by shodilo i <b>zaplacené</b> faktury zpět mezi nezaplacené a smazalo datum platby. Nově se srovnává částka jen u faktur <b>bez evidované platby</b>; u ostatních jen upozorní, že se rozcházejí s částkou zakázky.',
            '<b>Změna částky faktury přepočítá zaplacení:</b> po úpravě celkové částky se znovu vyhodnotí, jestli ji platby pokrývají — dřív mohla zůstat „zaplacená" faktura, na kterou už zaplacené peníze nestačí.',
        ],
    ],
    [
        'version' => '3.26.1',
        'date' => '2026-07-30',
        'time' => '17:40',
        'title' => 'Návody → Banka: nový text „Test v sandboxu" s výsledky prvního testu',
        'items' => [
            '<b>Nový návod</b> popisuje, jak si celé napojení vyzkoušet nanečisto v testovacím prostředí banky — od autorizace přes stažení pohybů až po zaplacenou fakturu — a hlavně <b>co první ostrý test odhalil</b>: chybu u plateb v cizí měně (CRM by evidovalo 4,21 Kč místo 99,78 Kč), meze sandboxu (data z roku 2019, takže automatické párování se v něm ověřit nedá) a co je potřeba po testu uklidit.',
        ],
    ],
    [
        'version' => '3.26.0',
        'date' => '2026-07-30',
        'time' => '17:05',
        'title' => 'Platby v cizí měně: CRM bere částku, která opravdu dorazila na účet',
        'items' => [
            '<b>Nález z testu v sandboxu:</b> banka u pohybu posílá <b>dvě částky</b> — a není pevně dané, ve které z nich je měna účtu. U jedné testovací platby přišlo <code>681,81 CZK / 27,28 EUR</code>, u druhé obráceně <code>4,21 USD / 99,78 CZK</code>. CRM dosud bralo vždy tu první, takže u platby ze zahraničí evidovalo <b>4,21 místo 99,78 Kč</b> — a taková platba se navíc tvářila jako cizoměnová, takže se vůbec nedala spárovat s fakturou.',
            '<b>Opraveno:</b> vybírá se ta částka, jejíž měna sedí na <b>měnu napojeného účtu</b> (na účet vždycky dorazí peníze v jeho měně). Původní částka se zapíše do zprávy u pohybu — v seznamu je tedy vidět „(původně 4,21 USD)".',
            '<b>Měna účtu</b> se ukládá automaticky při autorizaci i při načtení účtů v Nastavení.',
        ],
    ],
    [
        'version' => '3.25.6',
        'date' => '2026-07-30',
        'time' => '16:30',
        'title' => 'Návod k napojení doplněn podle skutečného průběhu',
        'items' => [
            '<b>Napojení na banku vyzkoušené celé</b> v testovacím prostředí: autorizace → tokeny → seznam účtů → stažení pohybů → obnova přístupu. Návod (Návody → Banka) teď popisuje i sandboxovou zkratku a pole „Stahovat pohyby od" včetně upozornění, že testovací data KB jsou z roku 2019.',
        ],
    ],
    [
        'version' => '3.25.5',
        'date' => '2026-07-30',
        'time' => '16:10',
        'title' => 'Napojení na banku funguje + volba, odkdy stahovat pohyby',
        'items' => [
            '<b>Napojení na KB je hotové</b> (v testovacím prostředí ověřeno celé: autorizace → tokeny → seznam účtů → čtení pohybů).',
            '<b>Nové pole „Stahovat pohyby od":</b> při prvním načtení si řekneš, jak hluboko do historie se má jít. Bez vyplnění se bere posledních 30 dní; další synchronizace už navazují na tu předchozí (s třídenním přesahem kvůli pozdnímu zaúčtování).',
        ],
    ],
    [
        'version' => '3.25.4',
        'date' => '2026-07-30',
        'time' => '15:50',
        'title' => 'Oprava: banka odmítala poslední krok napojení (chybný tvar identifikátoru)',
        'items' => [
            '<b>Co se dělo:</b> autorizace v bance proběhla, ale výměna kódu za přístup skončila chybou <code>INVALID_HEADER_VALUE</code>. CRM posílalo v identifikátoru požadavku náhodné číslo bez pomlček; KB vyžaduje <b>UUID</b>. Opraveno u všech volání banky (výměna kódu, obnova tokenu, stahování pohybů i software statement).',
        ],
    ],
    [
        'version' => '3.25.3',
        'date' => '2026-07-30',
        'time' => '15:10',
        'title' => 'Sandbox banky: zkratka bez přihlašování do KB (registrace tam nepatří)',
        'items' => [
            '<b>Proč to nešlo:</b> tlačítko „Registrovat aplikaci" posílalo i v testovacím režimu na <b>skutečné přihlášení do KB</b> — proto to po zadání jména vyhodilo zpátky, aniž šlo zadat heslo. Testovací prostředí registraci aplikace vůbec nepoužívá.',
            '<b>Nové tlačítko „Sandbox: nastavit testovací přístup a autorizovat":</b> nastaví testovací client_id a client_secret podle manuálu KB a pošle rovnou na testovací stránku banky, kde se jen napíše jméno testovacího klienta (např. „Klient 1"). Žádné bankovnictví, žádný certifikát.',
            '<b>V sandboxu to CRM samo vysvětlí</b> — nad tlačítky je poznámka, že registrace aplikace je jen pro ostrý účet.',
        ],
    ],
    [
        'version' => '3.25.2',
        'date' => '2026-07-30',
        'time' => '14:45',
        'title' => 'Oprava: banka se vracela na druhou adresu a CRM ji nepoznalo',
        'items' => [
            '<b>Co se dělo:</b> po potvrzení v bance to vyhodilo zpět na odhlášené CRM a v Historii nebyl ani záznam. Příčina: bez software statementu banka nemá kam vracet výsledek registrace, takže použije adresu určenou pro autorizaci — a ta odpověď registrace nečekala a zahodila ji <b>ještě před zápisem do historie</b>.',
            '<b>Opraveno:</b> obě adresy teď vedou do stejného zpracování, které se rozhoduje podle <b>obsahu</b> odpovědi (zašifrované údaje registrace vs. autorizační kód), ne podle toho, kam banka zaklepe. Návrat se vždy nejdřív zapíše do Historie změn — i když se odmítne.',
        ],
    ],
    [
        'version' => '3.25.1',
        'date' => '2026-07-30',
        'time' => '13:55',
        'title' => 'Oprava: návrat z banky se ztrácel (registrace aplikace nikdy nedoběhla)',
        'items' => [
            '<b>Co se dělo:</b> po potvrzení v bankovnictví to člověka vyhodilo zpátky do CRM a napojení zůstalo nedokončené. Banka se vrací na <b>SAML</b> adresu, takže údaje posílá i <b>POSTem z jiné domény</b> — a prohlížeč v takovém případě kvůli pravidlu <b>SameSite</b> nepošle přihlašovací cookie CRM. Callback tedy nikoho neviděl, odklopil na přihlášení a výsledek registrace se zahodil.',
            '<b>Opraveno:</b> callbacky čtou údaje z URL i z POSTu (a tolerují odchylky v názvech), a už nevyžadují přihlášení — stačí, že napojení právě probíhá. Pravost dat potvrdí to, že je CRM dokáže <b>dešifrovat vlastním klíčem</b>; cizí data se dešifrovat nedají, takže se nedá nic podstrčit.',
            '<b>Výsledek se neztratí:</b> hláška („zaregistrováno", nebo proč to selhalo) se ukládá i mimo přihlášení, takže ji uvidíš v Nastavení → Banka i po novém přihlášení.',
            '<b>Diagnostika:</b> každý návrat z banky se zapisuje do Historie změn — metoda (GET/POST) a názvy i délky přijatých parametrů (bez obsahu, jsou v nich tajné údaje). Když se něco nepovede, jde přesně dohledat, co banka poslala.',
        ],
    ],
    [
        'version' => '3.25.0',
        'date' => '2026-07-30',
        'time' => '11:40',
        'title' => 'Napojení na banku na dvě kliknutí (a nikdo nikam nepřepisuje tajné kódy)',
        'items' => [
            '<b>Napojení na KB je teď průvodce v Nastavení → Banka:</b> checklist ukáže, co už je hotové a co chybí, a dvě tlačítka („1. Registrovat aplikaci u KB", „2. Autorizovat přístup k účtu") pošlou jednatele do banky. Po potvrzení KB Klíčem si CRM <b>samo uloží client_id, client_secret a refresh token</b> z odpovědi banky — dosud se musely ručně opisovat, což u zašifrované odpovědi ani nebylo možné.',
            '<b>Výběr účtu bez opisování:</b> po autorizaci CRM hned stáhne seznam autorizovaných účtů; když je jeden, vybere ho sám, u víc účtů je nabídne k zaškrtnutí.',
            '<b>Software statement pro ostrý účet:</b> nový skript <code>scripts/kb_software_statement.php</code> udělá potvrzení aplikace u banky proti kvalifikovanému certifikátu (I.CA/PostSignum). Certifikát ani jeho heslo se nikam neukládají — použijí se jen pro jedno volání.',
            '<b>Kontrola napojení nanečisto:</b> <code>scripts/kb_test_napojeni.php</code> vypíše, co je nastavené a co chybí, a hlavně ověří, že CRM umí <b>rozšifrovat odpověď banky</b> — testuje se na oficiálním vzorku z dokumentace KB. Kdyby to nefungovalo, napojení by ztroskotalo až v momentě, kdy jednatel stojí v bankovnictví.',
            '<b>Opravená obnova tokenu:</b> KB u obnovy přístupu vyžaduje i adresu, kam se aplikace vrací (redirect_uri) — bez ní by po vypršení tříminutového tokenu přestalo stahování pohybů fungovat. Doplněno i dohledávací id požadavku pro podporu KB.',
            '<b>Pojistka při přepnutí prostředí:</b> přepnutím sandbox ↔ produkce se přihlašovací údaje k bance smažou (ze sandboxu v produkci neplatí), takže se napojení netváří jako hotové a synchronizace nepadá na nesrozumitelné chyby.',
            '<b>Nový návod:</b> Návody → Banka → <b>„Napojení CRM na bankovní účet (KB API)"</b> — celý postup včetně toho, co dělá jednatel, co platí 12 měsíců a co se stane při změně domény CRM.',
        ],
    ],
    [
        'version' => '3.24.1',
        'date' => '2026-07-30',
        'time' => '10:30',
        'title' => 'Návody → Banka: jak fungují platby a co dělat, když platba nesedí',
        'items' => [
            '<b>Nová záložka Banka v Návodech</b> se dvěma texty pro vedení: <b>„Jak fungují platby — párování plateb"</b> (čím se automat řídí, co udělá sám a co nechá na člověku, včetně podmínek jako tolerance 1 Kč, časové hranice nebo šetření tarifu banky) a <b>„Když platba nesedí — situace a jejich řešení"</b> — 14 reálných situací (záloha, přeplatek, sdružená platba za víc faktur, platba bez symbolu, překlep ve symbolu, storno, dvojí platba klienta, cizí měna, vklad hotovosti, změna částky faktury…) s tím, co udělá systém a co má udělat obsluha.',
            '<b>Odkaz přímo z Banky:</b> v hlavičce modulu je tlačítko <b>„Jak fungují platby"</b>, které návod otevře — nikdo ho nemusí hledat.',
        ],
    ],
    [
        'version' => '3.24.0',
        'date' => '2026-07-30',
        'time' => '09:40',
        'title' => 'Banka umí splátky a doplatky sama — a u nejasných plateb rovnou napoví',
        'items' => [
            '<b>Splátky a doplatky automaticky:</b> když variabilní symbol sedí právě jedné otevřené faktuře, platba se zapíše sama — i když je menší. Faktuře zůstane zbytek k úhradě a <b>doplatek se navázáním uzavře</b>. Dřív obojí skončilo „k prověření" a účetní to musela klikat ručně.',
            '<b>Automat nikdy nehádá:</b> zapíše jen platbu, kde VS ukazuje na jedinou otevřenou fakturu, platba není starší než faktura a nepřišlo víc, než kolik zbývá. Navíc platbu, která fakturu <b>uzavírá</b>, smí zapsat jen pohyb se skutečnou bankovní referencí — u vkladu hotovosti bez reference nelze vyloučit, že jde o duplikát, tak ho pošle člověku.',
            '<b>Nejasné platby dostanou nápovědu:</b> místo pouhého „k prověření" CRM napíše proč a co navrhuje — <b>číslo faktury vytažené ze zprávy pro příjemce</b>, u platby bez VS <b>fakturu klienta, který dřív platil z téhož účtu</b>, a u přeplatku informaci, že <b>částka odpovídá součtu dvou/tří faktur</b> téhož klienta (typicky firma platí víc faktur jedním převodem).',
            '<b>CRM se učí účty klientů:</b> každé ruční spárování si zapamatuje „z tohoto účtu platí tenhle klient" a příště podle toho nabízí. Slouží to <b>jen jako návrh</b> — z jednoho účtu může platit i třetí osoba, takže automaticky se podle účtu nikdy nic nezaplatí.',
            '<b>Přeplatek se nikdy nezapíše sám:</b> nejčastější příčina přeplatku je platba za víc faktur nebo záloha — automatické uzavření té menší faktury by tu větší poslalo do upomínek.',
            '<b>Test nanečisto</b> pokrývá i tyhle případy (30 kontrol) a součástí každého běhu je kontrola, že u žádné faktury nesedí uložená zaplacená částka jinak než součet evidovaných plateb.',
        ],
    ],
    [
        'version' => '3.23.0',
        'date' => '2026-07-30',
        'time' => '08:30',
        'title' => 'Částečné platby: faktura ví, kolik už došlo a kolik zbývá',
        'items' => [
            '<b>Evidence jednotlivých plateb:</b> dosud faktura znala jen dva stavy — zaplaceno, nebo nezaplaceno. Nešlo zaznamenat „klient poslal 3 000 z 8 500", doplatek se neměl kam navázat. Nově je <b>každá došlá platba samostatný záznam</b> (banka, hotovost, karta) a faktura se označí jako zaplacená teprve tehdy, když ji platby uhradí celou.',
            '<b>V bance jde navázat i částečnou platbu:</b> dřív ruční párování hlásilo „faktura už je zaplacená" a doplatek nebylo kam přiřadit. Teď se menší platba zapíše jako částečná, u faktury zůstane <b>zbytek k úhradě</b> a doplatek se naváže později. Přeplatek se navázat nedá — systém řekne, o kolik je platba vyšší.',
            '<b>Vidíš to všude, kde to potřebuješ:</b> v účetnictví u faktury přibylo „Zaplaceno X z Y", v detailu zakázky odznak <b>Částečně zaplaceno</b> se zbývající částkou a na <b>tištěném dokladu</b> řádek „zaplaceno … zbývá uhradit …". Ve výběru faktury k párování se nabízí zbytek k úhradě, ne celá částka.',
            '<b>QR platba na zbytek:</b> u částečně zaplacené faktury QR kód nabídne <b>zbývající částku</b> — dřív by klient na upomínce zaplatil znovu celou fakturu a vznikl přeplatek.',
            '<b>Datum platby se už nepřepisuje:</b> jakákoli editace faktury dřív vynulovala nebo přepsala datum, kdy peníze reálně přišly. Nově se přebírá. Ruční označení „zaplaceno" (hotově, kartou) si taky zapíše svou platbu, aby faktura neukazovala „zaplaceno 0".',
            '<b>Odpárování je bezpečné:</b> když fakturu krylo víc plateb a jedna se zruší, faktura <b>zůstane zaplacená</b> a jen se zmenší uhrazená částka. Bez ostatních plateb se vrátí mezi nezaplacené. Bankovní platbu nikdy nesmaže ruční klik ve faktuře — jen odpárování v Bance.',
            '<b>Test nanečisto rozšířen</b> na splátky, doplatek a odpárování (celkem 30 kontrol) a doplněn o trojí pojistku, aby po něm v ostré databázi nezůstal ani jeden testovací záznam.',
        ],
    ],
    [
        'version' => '3.22.0',
        'date' => '2026-07-30',
        'time' => '07:20',
        'title' => 'Banka: párování plateb prověřené nanečisto a zabezpečené proti chybám',
        'items' => [
            '<b>Proč to bylo potřeba:</b> napojení na KB čeká na certifikát, ale logika, která označuje faktury jako <b>ZAPLACENÉ</b>, musela být prověřená dřív, než přes ni potečou skutečné peníze. Přibyl test nanečisto (<code>scripts/kb_test_parovani.php</code>), který prožene 20 modelových pohybů ve tvaru odpovědi KB stejným kódem jako ostrý provoz a ověří 24 situací. Celý test běží v transakci, která se vrací zpět — v databázi po něm nic nezůstane.',
            '<b>Zrušené párování zůstane zrušené:</b> dřív se odpárovaná platba vracela do stavu „nespárovaná", takže ji nejbližší synchronizace znovu spárovala a fakturu zase označila zaplacenou — rozhodnutí účetní přebil automat. Nově platba přejde do stavu <b>Vyřazeno z párování</b> (v seznamu i jako filtr) a do automatu ji vrátí jen člověk tlačítkem.',
            '<b>Vrácené a zrušené platby:</b> storno z banky se dřív mlčky zahodilo a faktura zůstala zaplacená penězi, které už na účtu nebyly. Nově se storno dohledá k původní platbě, <b>faktura se vrátí mezi nezaplacené</b> a zapíše se do historie změn. Stejně se řeší platba, kterou banka dodatečně zrušila.',
            '<b>Nikdy dvě platby na jednu fakturu:</b> nárok na fakturu se uplatňuje atomicky, takže ani dvě synchronizace běžící naráz (nebo sync proti ručnímu párování) nemůžou jednou pohledávkou „zaplatit" dvě různé platby. Synchronizace navíc drží výhradní zámek — druhý pokus se dozví, že už běží.',
            '<b>Automat platí jen to, co je jednoznačné:</b> faktura se sama označí zaplacenou pouze když VS sedí <b>právě jedné</b> nezaplacené faktuře, částka odpovídá a platba nemohla přijít dřív než faktura. Částečná platba, přeplatek, kolize VS, platba starší než faktura i drobná částka pod tolerancí jdou <b>k prověření s vysvětlením proč</b> (důvod je vidět přímo v seznamu).',
            '<b>Drobnosti, které by v ostrém provozu bolely:</b> QR platba i párovač teď používají stejný tvar VS (dřív QR posílala prvních 10 číslic, ale párovač porovnával posledních 10 — platba z QR kódu u delšího čísla faktury by nikdy nesedla); platba v cizí měně ani pohyb z jiného účtu se nedá spárovat s korunovou fakturou; dvě shodné platby v jeden den se už neslijí do jedné; nečitelná nebo nulová částka se neuloží jako platba; a ve výběru faktury k ručnímu párování se dá <b>hledat</b> podle čísla i částky.',
        ],
    ],
    [
        'version' => '3.21.0',
        'date' => '2026-07-29',
        'time' => '21:45',
        'title' => 'Fyzická organizace skladu: regály, police a krabičky s QR',
        'items' => [
            '<b>Umístění skladu:</b> nová záložka Sklad → <b>Umístění</b> — založíš regály (R1), police (R1-P2) a krabičky (K001…), vytiskneš na ně <b>štítky s QR</b> a CRM od té chvíle ví, kde každý díl fyzicky leží. Krabička má <b>trvalý kód</b>: při přeskládání skladu se v CRM jen přepne police, štítek se netiskne znovu.',
            '<b>Třídění podle modelu:</b> u dílu jde nastavit model zařízení (iPhone 12, iPad Air…). Ve skladu přibyl filtr podle modelu a umístění + <b>hromadné akce</b>: zaškrtat díly → přiřadit do krabičky / nastavit model / vytisknout štítky vybraných.',
            '<b>QR na krabičce:</b> sken mobilem ukáže <b>obsah krabičky</b> — ťuknutím na díl se rovnou naskladňuje nebo vydává na zakázku (funguje i „Vzít díl skenem QR" z detailu zakázky). Díl jde do krabičky přiřadit i přímo z jejího QR, a kód umístění (K012) jde napsat rovnou do hledání v horní liště.',
            '<b>Drobné levné díly:</b> obě cesty podporované — jednotlivé karty se společnou krabičkou (zachová napojení na dodavatele a doobjednávání), nebo jedna souhrnná karta („Drobné díly – iPhone 12") s jednotnou cenou.',
            '<b>Inventura po krabičkách:</b> vedení může v obsahu krabičky rovnou opravit počty kusů — zapíše se jako korekce do deníku pohybů. Úprava počtu v editaci dílu se nově do deníku píše taky. Štítek dílu ukazuje i kód jeho umístění a výběr dílu k zakázce ukazuje, kde díl leží (📍 K012).',
        ],
    ],
    [
        'version' => '3.20.0',
        'date' => '2026-07-29',
        'time' => '18:10',
        'title' => 'Peníze pod kontrolou: pokladní deník + platba při výdeji zakázky',
        'items' => [
            '<b>Pokladní deník:</b> kasa nově eviduje i hotovost mimo prodeje — <b>výkup placený hotově se automaticky zapíše jako výdej z kasy</b> (s číslem výkupního listu, proklik na dokument), ručně jde přidat <b>vklad/výběr</b>. Hlavička Pokladny ukazuje vklady, výdaje a <b>stav hotovosti</b> (prodeje hotově + vklady − výdaje) + dnešní pohyby.',
            '<b>Platba při výdeji zakázky:</b> v detailu (u dopravy) i v bleskovém „Vydáno" v seznamu se volí <b>hotově / kartou / převodem</b>. Hotově → <b>příjem do pokladny</b>; převodem → <b>vystaví se faktura a odejde klientovi e-mailem s QR platbou</b> (VS se pak sám spáruje s bankou); kartou → záznam na zakázce pro párování s výpisem.',
            'Vše auditované (pohyby kasy, platba při výdeji, odeslané faktury); částky se počítají z konečné ceny zakázky. Když akce selže (např. klient nemá e-mail), výdej se nezastaví — CRM jen řekne, co dodělat ručně.',
        ],
    ],
    [
        'version' => '3.19.3',
        'date' => '2026-07-29',
        'time' => '17:20',
        'title' => 'Rozpracované formuláře se neztratí — auto-uložení konceptu i u zakázek, klientů a skladu',
        'items' => [
            'Auto-uložení rozpracovaného formuláře (jako u dokumentů) je nově i u <b>Nové zakázky</b> (celý průvodce), <b>Nové reklamace</b>, <b>editace zakázky</b>, <b>klienta</b> a <b>skladového dílu</b>. Když formulář omylem zavřete, po znovuotevření se rozepsané údaje <b>obnoví</b> (hláška „Obnoveny rozpracované údaje" s možností Zahodit).',
            'Koncept se <b>smaže až po úspěšném uložení</b> daného formuláře — do té doby jsou data v bezpečí v prohlížeči.',
            'Hesla, PINy a přílohy (soubory) se z bezpečnostních důvodů do konceptu <b>neukládají</b>. (Pokladna je záměrně vynechána — jde o okamžitou transakci.)',
        ],
    ],
    [
        'version' => '3.19.2',
        'date' => '2026-07-29',
        'time' => '17:10',
        'title' => 'Migrace se už nezaseknou na „sloupec už existuje"',
        'items' => [
            'Aktualizace databáze (Nastavení → Systém → Aktualizace) padala na migraci <b>023_customer_language</b> s hláškou „Duplicate column name" — sloupec už v databázi byl, protože ho za běhu založila záchranná funkce v aplikaci. Běh se tím zastavil a <b>všechny novější migrace nikdy neproběhly</b>.',
            'Runner nově pozná chyby typu <b>„už existuje"</b> (tabulka, sloupec, index, klíč), bere je jako splněné, migraci označí za provedenou a <b>pokračuje dál</b>. U takového souboru vypíše <b>OK*</b> s počtem přeskočených příkazů. Skutečná chyba běh pořád zastaví, aby se databáze nerozjela.',
        ],
    ],
    [
        'version' => '3.19.1',
        'date' => '2026-07-29',
        'time' => '17:05',
        'title' => 'Výkupní listy: číselná řada navazuje na papírovou (202600001…)',
        'items' => [
            'Nové výkupní listy dostávají čísla <b>navazující na dosavadní papírovou řadu</b>: první dokument v CRM = <b>202600001</b>, další 202600002 atd. (Zástavní formuláře zůstávají v řadě ZF-0001.)',
            'Opraveno přečíslování v Historii úprav — záznam „Sklad Produkty: Zapůjčeno/Komisní prod." měl omylem verzi 3.9.3, správně je <b>3.19.0</b>; dlaždice verzí kvůli tomu ukazovaly starší číslo.',
        ],
    ],
    [
        'version' => '3.19.0',
        'date' => '2026-07-29',
        'time' => '16:45',
        'title' => 'Sklad Produkty: stav „Zapůjčeno/Komisní prod."',
        'items' => [
            'Když kus fyzicky není u nás (půjčený, u někoho v komisi), řešilo se to nastavením <b>skladem 0</b> — jenže pak vypadal stejně jako vyprodané zboží a z evidence nešlo poznat, že existuje a u koho je.',
            'Sklad → Produkty má nově u každého kusu tlačítko <b>Zapůjčeno / komisní prodej</b>: zeptá se <b>komu</b> a na poznámku, kus označí fialovým odznakem <b>Zapůjčeno/Komisní prod.</b> se jménem a datem, a druhým klikem ho <b>vrátí do skladu</b>.',
            'Zapůjčený kus <b>zůstává ve skladu i v přehledech</b>, ale <b>na e-shop se neposílá</b> — nikdo si ho neobjedná. Do počtu „skladem" a do hodnoty skladu se nepočítá; ve filtru Dostupnost je nová volba <b>Zapůjčeno/Komisní prod.</b> a v hlavičce odkaz s počtem.',
        ],
    ],
    [
        'version' => '3.18.0',
        'date' => '2026-07-29',
        'time' => '16:10',
        'title' => 'Rozpracované dokumenty se neztratí — auto-uložení konceptu do podpisu',
        'items' => [
            'Vyplňovací dokument (výkupní list / zástavní formulář) se nově <b>průběžně ukládá do prohlížeče</b>. Když formulář omylem zavřete, po znovuotevření se <b>rozepsané údaje obnoví</b> (hláška „Obnoveny rozpracované údaje").',
            'Koncept se <b>smaže teprve po podpisu klientem</b> (dokončení dokumentu) — ne při pouhém uložení, tisku ani přepnutí jazyka. Do té doby jsou data v bezpečí.',
            'Draft je vázaný na konkrétní dokument (typ + číslo), takže se rozpracovaný výkup neplete se zástavou.',
        ],
    ],
    [
        'version' => '3.17.0',
        'date' => '2026-07-29',
        'time' => '15:55',
        'title' => 'Fotky ve výkupním listu · filtr dle technika · odebrána Fronta dnes',
        'items' => [
            'Výkupní list (i zástavní formulář) má novou sekci <b>Fotodokumentace</b>: tlačítkem <b>+</b> lze nahrát fotky nebo na telefonu <b>rovnou vyfotit</b> stav zařízení. Fotky se ukládají k dokumentu, zobrazují se v tisku i v e-mailové kopii (do e-mailu max 4 s rozpočtem velikosti) a dají se křížkem smazat.',
            'Na <b>Nástěnce i v seznamu Zakázek</b> přibyl <b>filtr podle technika</b> (rozbalovací nabídka u filtrů) — kombinuje se se stavem, pobočkou i hledáním.',
            'Z pravého sloupce Nástěnky byla <b>odebrána sekce „Fronta dnes"</b> — stejné informace jsou v hlavní tabulce.',
        ],
    ],
    [
        'version' => '3.16.1',
        'date' => '2026-07-29',
        'time' => '15:20',
        'title' => 'Dokumenty: podpis na tabletu jako u zakázkového listu + větší nadpisy',
        'items' => [
            'V dokumentu přibylo tlačítko <b>„Podepsat na tabletu"</b> — funguje stejně jako u zakázkového listu: požadavek odejde na podpisovou stanici (iPad), klientovi se ukáže dokument v jeho jazyce, podepíše se prstem a <b>podpis se vloží nad podpisovou linku</b> („podepsáno elektronicky" + čas). Stránka se po podpisu sama obnoví.',
            'Podpis je vidět i v tisku a v e-mailové kopii. V seznamu Dokumentů mají podepsané dokumenty zelenou značku <b>„podepsáno"</b> a nové oko-tlačítko otevře <b>náhled dokumentu i s podpisem</b>.',
            'Právní ustanovení (§ texty z původních formulářů) jsou součástí dokumentu nad podpisy — nově viditelné i na obrazovce, ne až při tisku.',
            'Vzhled: hlavní nadpis dokumentu zvětšen na úroveň loga, modré podnadpisy sekcí o polovinu větší.',
        ],
    ],
    [
        'version' => '3.16.0',
        'date' => '2026-07-29',
        'time' => '14:49',
        'title' => 'Dokumenty: nová záložka — výkupní listy a zástavní formuláře s ukládáním',
        'items' => [
            'V horní navigaci přibyla záložka <b>Dokumenty</b> s podzáložkami <b>Výkupní listy</b> a <b>Zástavní formuláře</b>. Seznam vypadá jako seznam zakázek: <b>číslo dokumentu, jméno, telefon, e-mail, předmět, cena</b> + vyhledávání; klik otevře dokument.',
            'Výkupní list i zástavní formulář mají nový <b>jednotný vizuál klientských dokladů</b> (A4, SF Pro, modrý akcent — jako zakázkový list a reklamační protokol) a vyplňují se přímo v dokumentu.',
            'Nahoře je <b>přepínač jazyka dokumentu (CS/EN/RU)</b> — překládá popisky a nadpisy polí, vyplněný obsah nechává beze změny. Vedle něj tlačítka <b>Uložit</b>, <b>Tisk</b> a <b>Odeslat e-mailem</b> (kopie jde na e-mail uvedený v dokumentu).',
            'Dokumenty se <b>ukládají automaticky</b> — před tiskem i odesláním; číslo (VL-0001 / ZF-0001) se přiděluje samo při prvním uložení. Do formuláře přibylo pole <b>E-mail klienta</b>.',
            'Staré adresy vykup-zarizeni.php a zastava.php i rychlé odkazy na Nástěnce vedou do nového enginu. Vše se zapisuje do Historie (vytvoření / úprava / odeslání dokumentu).',
        ],
    ],
    [
        'version' => '3.15.4',
        'date' => '2026-07-29',
        'time' => '13:55',
        'title' => 'Historie úprav: definitivní oprava výšky panelu',
        'items' => [
            'Nalezena skutečná příčina (změřeno přímo v prohlížeči): levý sloupec se natahoval podle výšky pravého obsahu, takže synchronizace výšky kopírovala obří hodnotu. Levá karta teď drží přirozenou výšku a <b>Historie úprav má přesně stejnou výšku se scrollbarem uvnitř</b> — ověřeno živě na admin.applefix.cloud.',
        ],
    ],
    [
        'version' => '3.15.3',
        'date' => '2026-07-29',
        'time' => '13:35',
        'title' => 'Oprava: Historie úprav má správnou výšku a scrollbar',
        'items' => [
            'Panel Historie úprav se roztahoval přes celou stránku — teď má <b>přesně výšku levé karty</b> s tlačítky aktualizace a obsah <b>scrolluje uvnitř panelu</b> (drží se i při změnách levé karty, např. otevření diagnostiky).',
            'Tučné zvýraznění v záznamech historie se dřív ukazovalo jako text (<i>&lt;b&gt;</i>) — teď se vykresluje správně.',
        ],
    ],
    [
        'version' => '3.15.2',
        'date' => '2026-07-29',
        'time' => '13:05',
        'title' => 'Editace klienta jedním klikem přímo ze zakázky',
        'items' => [
            'V detailu zakázky je u bloku <b>Klient</b> nové tlačítko <b>„Upravit"</b> — otevře stejnou editaci klienta jako v seznamu klientů, bez hledání.',
            'Tlačítko <b>„Zpět"</b> v editaci klienta tě v tomhle případě vrátí <b>rovnou na zakázku</b>, ze které jsi přišel (ne do seznamu klientů).',
        ],
    ],
    [
        'version' => '3.15.1',
        'date' => '2026-07-29',
        'time' => '12:40',
        'title' => 'Aktualizace: jen Historie úprav (git výpis pryč) + rychlejší vlny při přihlášení',
        'items' => [
            'Z karty Aktualizace zmizel technický výpis git commitů („Poslední změny") — historii teď vede <b>jen Historie úprav</b> s čísly verzí. Zobrazuje <b>posledních 100 záznamů</b> ve scrollovacím panelu vpravo vedle tlačítek aktualizace (stejná výška jako levá karta).',
            'Pauza mezi vlnami uvítacího loadingu zkrácena na 0,05 s — vlny jedou prakticky navazující.',
        ],
    ],
    [
        'version' => '3.15.0',
        'date' => '2026-07-29',
        'time' => '12:10',
        'title' => 'Nové pole „Provedená oprava" — povinné před dokončením zakázky',
        'items' => [
            'Všude pod popisem závady přibylo pole <b>„Provedená oprava"</b> — technik do něj napíše, jak byla zakázka vyřešena. Je v detailu zakázky, v obou editačních oknech (detail i rychlá editace ze seznamu), na <b>zakázkovém listu</b> (jen když je vyplněné), na dílenském listu i v <b>klientském portálu</b>.',
            '<b>Bez vyplněné „Provedené opravy" nejde zakázka dokončit ani vydat</b> („Připraveno k převzetí" / „Vydáno"). Při změně stavu se v takovém případě rovnou otevře okno pro doplnění — vyplníš a stav se změní jedním krokem. Platí to i pro rychlá tlačítka v seznamu zakázek.',
            'E-mail „hotovo k vyzvednutí" nově v souhrnu ukazuje skutečně provedenou opravu (dřív tam byl popis závady). Změny pole se zapisují do historie zakázky.',
            'Technicky: sloupec <code>orders.repair_solution</code> (vytvoří se sám i přes migraci 032), překlady CZ/RU/EN.',
        ],
    ],
    [
        'version' => '3.14.1',
        'date' => '2026-07-29',
        'time' => '11:20',
        'title' => 'Uvítací vlna startuje okamžitě a stihne víc pulzů',
        'items' => [
            'Animace se dosud spouštěla až po odpovědi serveru na přihlášení — teď se rozjede <b>hned po kliknutí na Přihlásit</b>, takže nezačíná pozdě.',
            'Průjezd vlny zrychlen (3 s → <b>2 s</b>) a pauza 0,2 s — během hlášky se stihnou <b>2–3 pulzy</b>. Vlny běží i bez uvítací hlášky, dokud se nenačte dashboard.',
            'Při neúspěšném přihlášení se overlay s animací schová a ukáže se chyba jako dřív.',
        ],
    ],
    [
        'version' => '3.14.0',
        'date' => '2026-07-29',
        'time' => '10:45',
        'title' => 'Uvítací animace při přihlášení: pulzující vlna teček (styl Xiaomi boost)',
        'items' => [
            'Po přihlášení běží místo dosavadního spinneru <b>pole jantarových teček přes celou obrazovku</b>: široká zlato-oranžová vlna projede ze středu k okrajům, pak krátká černá pauza (0,5 s) a další puls — <b>opakuje se, dokud tě to nepustí do dashboardu</b>.',
            'Efekt je <b>responzivní</b> (iPhone, tablet i PC, zvládá otočení displeje), střed vlny je vždy na středu obrazovky. Předloha: rozjezdová animace palubovky Xiaomi.',
            'Animace se ukazuje při přihlášení s <b>uvítací hláškou</b> (uploads/greetings). Respektuje systémové „omezit pohyb" — místo animace ukáže statický snímek vlny.',
        ],
    ],
    [
        'version' => '3.13.0',
        'date' => '2026-07-27',
        'time' => '14:45',
        'title' => 'Přesun zařízení mezi pobočkami (Karlín ↔ Na Příkopě)',
        'items' => [
            'V detailu zakázky (pod výběrem stavu) je nové tlačítko <b>„Přesunout zařízení na Karlín / Na Příkopě"</b> — vždy nabízí opačnou pobočku, než kde zařízení fyzicky je. Dalším kliknutím lze zařízení poslat zpět (návrat na domovskou pobočku značku zruší).',
            'Přesunuté zařízení se ukazuje <b>oranžovou pilulkou s názvem pobočky</b> (ikona 🚚, stejná výška i tvar jako stavová pilulka) hned <b>vedle aktuálního stavu</b> — v seznamu zakázek, na Nástěnce i v detailu zakázky.',
            'Zakázka zůstává své pobočce (čísla ani viditelnost se nemění) — jde o fyzické umístění zařízení. Každý přesun se zapisuje do Historie (audit „Přesun zařízení").',
            'Technicky: sloupec <code>orders.device_branch_id</code> (vytvoří se sám, bez migrací) + endpoint <code>api/move_order_device.php</code>.',
        ],
    ],
    [
        'version' => '3.12.0',
        'date' => '2026-07-27',
        'time' => '13:55',
        'title' => 'Reklamace: příjem jako u zakázky — tisk štítku + podpis protokolu na tabletu',
        'items' => [
            'Po založení reklamace proběhne <b>stejná automatika jako u nové zakázky</b>: na pobočkové tiskárně se vytiskne <b>štítek s RK kódem</b> (Code128, zařízení + důvod + klient) a otevře se okno <b>reklamačního protokolu</b> s volbou „Podepsat na tabletu" / „Vytisknout".',
            'Podpisová stanice (iPad) nově umí i reklamace — klientovi ukáže <b>reklamační protokol</b> v jeho jazyce, podepíše se prstem a podpis se <b>vloží do protokolu</b> (stejně jako u zakázkového listu, včetně „podepsáno elektronicky" s časem).',
            'V detailu reklamace přibylo tlačítko <b>„Přijetí reklamace — štítek + podpis"</b> (obdoba onboardu zákazníka u zakázek) — automatiku lze kdykoli zopakovat.',
            'Technicky: štítek přes <code>print_label_server.php?action=print_complaint</code>, podpisy v nové tabulce <code>complaint_signatures</code> (vytváří se sama), požadavky stanice sdílí <code>signature_requests</code> (sloupec <code>complaint_id</code>).',
        ],
    ],
    [
        'version' => '3.11.3',
        'date' => '2026-07-27',
        'time' => '13:05',
        'title' => 'Verze se už nikdy nerozejde s changelogem',
        'items' => [
            'Dlaždice „Aktuální/Nejnovější verze" v Aktualizacích se dosud plnily ze souboru <code>VERSION</code>, který se při psaní changelogu občas zapomněl zvýšit — a UI pak ukazovalo starší verzi (např. 3.9.2), zatímco Historie úprav už byla dál (3.11.2).',
            'Verze se nově čte přímo z <b>nejnovějšího záznamu Historie úprav</b> (lokálně i pro dostupnou aktualizaci na GitHubu) — jeden zdroj pravdy, nesoulad už nemůže nastat. Soubor <code>VERSION</code> zůstává jen jako záložní.',
        ],
    ],
    [
        'version' => '3.11.2',
        'date' => '2026-07-27',
        'time' => '11:55',
        'title' => 'Push: tabulka push_tokens se vytvoří sama (nezávisle na migracích)',
        'items' => [
            'Tabulka pro APNs tokeny se zakládá lazy (<code>CREATE TABLE IF NOT EXISTS</code>) při první registraci zařízení i před odesláním push — takže push funguje, i když migrační runner uvízl na starší migraci (023).',
        ],
    ],
    [
        'version' => '3.11.1',
        'date' => '2026-07-27',
        'time' => '11:10',
        'title' => 'APNs push: konfigurace přímo ve webovém nastavení (upload .p8)',
        'items' => [
            'Nastavení → Systém → Integrace má novou sekci <b>„Push notifikace do iOS appky (APNs)"</b>: nahraješ tam klíč <code>.p8</code> a vyplníš Key ID / Team ID / Bundle ID / prostředí — bez zásahu do serveru nebo <code>.env</code>.',
            'Klíč se ukládá zabezpečeně v <code>system_settings</code> a už se nezobrazuje; odesílač push si konfiguraci bere odtud (s fallbackem na <code>.env</code>).',
        ],
    ],
    [
        'version' => '3.11.0',
        'date' => '2026-07-25',
        'time' => '22:40',
        'title' => 'Fotky MacBooků podle správné generace — konec sdílené tapety napříč čipy',
        'items' => [
            'Knihovna fotek modelů dosud slučovala <b>všechny generace MacBooků pod jednu fotku</b> (M1 až M5 sdílely stejný render) — novější kusy tak dědily <b>starou tapetu</b> (např. 16″ M5 Max ukazoval render z éry M1).',
            'Klíč modelu (<code>productModelFamily</code>) nově u MacBooků nese <b>designovou éru</b>: samostatně <b>Intel</b>, <b>M1–M3</b>, <b>M4/M5</b> (nová tapeta + Space Black) a <b>flat Air (M2/M3)</b> vs. wedge Air. Každá éra dostává svůj oficiální Apple render ve správné barvě.',
            'Přeseedováno <b>17 kombinací model+barva</b> pokrývajících 64 skladových MacBooků z Apple compare renderů (věrné tapety, čisté průhledné pozadí).',
            'Pozn.: 29 MacBooků má v datech <b>prázdnou barvu/velikost</b> → fotku nelze přiřadit, dokud se údaj nedoplní (datová vada u produktu, ne render).',
        ],
    ],
    [
        'version' => '3.10.0',
        'date' => '2026-07-25',
        'time' => '19:47',
        'title' => 'Push notifikace do iOS appky (základ) — nová zakázka, stav, chat, reklamace, e-shop',
        'items' => [
            'Serverová infrastruktura pro <b>push notifikace</b> do nativní iOS appky: tabulka <code>push_tokens</code>, endpoint <code>api/register_push_token.php</code> a APNs odesílač (<code>includes/push.php</code>, token-auth přes .p8, HTTP/2).',
            'Napojení na události — <b>nová zakázka</b> a <b>změna stavu</b> (centrálně přes <code>crmAuditLog</code>, push přiřazenému technikovi u změny stavu), <b>nová zpráva ve firemním chatu</b>, <b>nová reklamace z klientského portálu</b> a <b>nová e-shop objednávka</b>.',
            'Vše je bezpečný <b>no-op, dokud není nastavený APNs klíč</b> (<code>.env</code>: APNS_KEY_PATH/KEY_ID/TEAM_ID/BUNDLE_ID/ENV) — na běh CRM to zatím nemá žádný vliv. Vyžaduje spuštění migrace <code>029</code>.',
        ],
    ],
    [
        'version' => '3.9.2',
        'date' => '2026-07-25',
        'time' => '19:40',
        'title' => 'Vyhledávání najde i sklad — naskladněný kus podle IMEI, kódu i názvu',
        'items' => [
            'Horní vyhledávání dosud prohledávalo <b>jen zakázky</b>. Když jste na Nástěnce (nebo na kterékoli stránce mimo Zakázky/Klienty/Sklad) zadali <b>IMEI, sériové číslo nebo název naskladněného zařízení</b>, CRM hlásilo „Nenalezeno", přestože kus byl skladem.',
            'Nástěnka nově pod seznamem zakázek zobrazí panel <b>Sklad</b> s nalezenými <b>produkty pro e-shop</b> (název, kód/IMEI, stav, pobočka, dostupnost, cena) i <b>náhradními díly</b> (název, SKU, počet ks, cena). Klik na název otevře příslušnou skladovou stránku s předvyplněným hledáním.',
            'Panel vidí jen ten, kdo má právo na sklad; vyprodané kusy se zobrazují až za skladovými.',
        ],
    ],
    [
        'version' => '3.9.1',
        'date' => '2026-07-25',
        'time' => '11:30',
        'title' => 'Onboard zákazníka — jednoklikový příjem webové rezervace (RepairPlugin)',
        'items' => [
            'Když fyzicky dorazí klient s rezervací z webu, v detailu zakázky (stav <b>Přijato z RepairPluginu</b>) je nově <b>první tlačítko vpravo „Onboard zákazníka"</b>: jedním klikem <b>vytiskne štítek</b> a <b>otevře okno zakázkového listu s podpisem</b> (tablet/tisk/e-mail) — přesně jako při ručním založení zakázky. Konec ručního dohledávání.',
        ],
    ],
    [
        'version' => '3.9.0',
        'date' => '2026-07-25',
        'time' => '02:30',
        'title' => 'Galerie: u každé sekce volíš zaškrtnutím, co se u produktu použije na e-shopu',
        'items' => [
            'Podle původního zadání: každá ze tří sekcí Galerie má <b>zaškrtávátko</b> — <b>1) studiová fotka</b> (hlavní fotka na e-shopu + Meta/Google katalog), <b>2) klasické fotky</b> (galerie na e-shopu), <b>3) 360° prohlídka</b>. Co zaškrtneš, to se u produktu použije; odškrtnutá sekce se na e-shop nepošle (média zůstávají uložená, jen se nezobrazují).',
            'Vypnutí sekce 1 vypne i <b>děděnou</b> fotku modelu u tohoto kusu (katalog pak použije běžnou fotku). Vypnutí sekce 3 schová 360° prohlídku i náhled z ní.',
            'Výchozí stav = vše zaškrtnuto (dosavadní chování). Nastavení se pamatuje per produkt a při editaci se předvyplní.',
        ],
    ],
    [
        'version' => '3.8.2',
        'date' => '2026-07-25',
        'time' => '01:45',
        'title' => 'Počítadla srovnaná s viditelností: přiřazené zakázky se ti počítají všude',
        'items' => [
            'Navazuje na 3.8.1: <b>dlaždice na nástěnce, badge u Zakázek, statistiky v seznamu, výběr zakázky ve Skladu i reporty</b> teď počítají i zakázky přiřazené tobě na jiné pobočce — stejné pravidlo jako viditelnost.',
            'Opraven i <b>živý poller</b>: badge už nepřeblikává zpět na staré číslo a zvonek nové zakázky zazní i u zakázky z druhé pobočky přiřazené tobě.',
            'Srovnání personálu v reportech měří všem <b>stejný pobočkový základ</b> (žádný řádek se nenafukuje cross-branch zakázkami).',
        ],
    ],
    [
        'version' => '3.8.1',
        'date' => '2026-07-25',
        'time' => '01:00',
        'title' => 'Oprava: technik nyní vždy vidí zakázky, které jsou mu přiřazené (i z jiné pobočky)',
        'items' => [
            'Od 1.6.0 lze zakázce přiřadit technika z kterékoliv pobočky, ale pobočkový filtr mu ji pak <b>schovával</b> — např. Khalil (Karlín) neviděl zakázku APFAZ2600871 přijatou Na Příkopě, přestože byla přiřazená jemu.',
            'Opraveno v seznamu zakázek, na nástěnce, ve vyhledávání i v detailu/úpravě/tisku štítku: <b>svoje přiřazené zakázky vidíš vždy</b>, bez ohledu na pobočku. Pobočková izolace pro ostatní zakázky zůstává beze změny.',
        ],
    ],
    [
        'version' => '3.8.0',
        'date' => '2026-07-25',
        'time' => '00:15',
        'title' => 'Fotku pro celý model přidáš přímo u produktu (Galerie)',
        'items' => [
            'V <b>Galerii u produktu</b> (Sklad → Produkty → úprava) je u studiové fotky nové zaškrtávátko <b>„použít pro celý model"</b> (výchozí zapnuto): nahraješ fotku u jednoho kusu a přiřadí se <b>všem</b> kusům toho modelu + barvy (i budoucím). Nemusíš nikam jinam — vše na jednom místě.',
            'Když necháš odškrtnuté, fotka platí jen pro ten konkrétní kus (výjimka/override).',
            'Samostatná stránka <b>Fotky modelů</b> zůstává jako <b>přehled pokrytí</b> a hromadné nahrání; její karty jsou nově plné (animované pozadí přes ně neprosvítá).',
        ],
    ],
    [
        'version' => '3.7.1',
        'date' => '2026-07-24',
        'time' => '23:45',
        'title' => 'Fotky modelů — čitelnost (plné karty, animované pozadí neprosvítá)',
        'items' => [
            'Na stránce <b>Sklad → Fotky modelů</b> byly karty skoro průhledné, takže se přes ně valilo animované pozadí a bylo to nepřehledné. Karty i přehled jsou teď <b>plné (neprůhledné)</b> na klidném podkladu.',
        ],
    ],
    [
        'version' => '3.7.0',
        'date' => '2026-07-24',
        'time' => '23:00',
        'title' => '360° prohlídka z videa — automatické zpracování na serveru (fáze 2)',
        'items' => [
            'Nahraješ v Galerii <b>360° video</b> (dvě otočky) a server z něj <b>sám</b> vyrobí 360° prohlídku: vytáhne 36 snímků = jedno otočení, odstraní pozadí (rembg) a složí průhlednou sadu. Na produktu na e-shopu se objeví <b>otočná 360° prohlídka</b> (tažení, autorotace, lupa) — bez dalšího klikání.',
            'V Galerii u videa je teď <b>stav zpracování</b> („zpracovává se… / hotovo ✓ s náhledem") a tlačítko <b>Přegenerovat</b>.',
            'Technicky: dispatcher na serveru (cron) hlídá nahraná videa a spouští pipeline <code>video_to_360.py</code> → snímky jdou do <code>public/produkty-360/&lt;kód&gt;/</code>, odkud si je detail produktu čte sám. Stav se odvozuje z disku (endpoint <code>api/status_360.php</code>), žádná další DB.',
        ],
    ],
    [
        'version' => '3.6.0',
        'date' => '2026-07-24',
        'time' => '21:30',
        'title' => 'Fotky modelů — hromadné přiřazení studiové fotky (Sklad → Produkty → Fotky modelů)',
        'items' => [
            'Nová záložka <b>Fotky modelů</b> ve Skladu: nahraješ <b>jednu</b> studiovou fotku pro <b>model + barvu</b> a přiřadí se <b>všem</b> takovým kusům naráz — konec klikání u stovek produktů.',
            'Funguje přes <b>dědičnost</b>: studiová fotka se váže na rodinu modelu + barvu (ne na čip/rok/kapacitu), takže jedna fotka „MacBook Pro 16″ Space Gray" pokryje M1 Pro / M2 Max / … i <b>budoucí naskladněné</b> kusy stejného modelu. Vlastní studiovka u konkrétního kusu (Galerie) má vždy přednost.',
            'Přehled pokrytí (kolik produktů má fotku), filtr „jen bez fotky", vyhledávání modelu. Fotka jde na eshop jako hlavní obrázek i do Meta/Google katalogu.',
            'Technicky: tabulka <code>model_photos</code> (klíč <code>rodina|barva</code>), feed <code>eshop_feed.php</code> studiovku zdědí, endpoint <code>api/model_photos.php</code>.',
        ],
    ],
    [
        'version' => '3.5.0',
        'date' => '2026-07-24',
        'time' => '18:30',
        'title' => 'Galerie média u produktu (studiová fotka · klasické fotky · 360° video)',
        'items' => [
            'V naskladnění/úpravě produktu (Sklad → Produkty) přibyla sekce <b>Galerie</b> se třemi částmi: <b>1) studiová fotka</b> (bez pozadí, jako oficiální Apple — hlavní obrázek na eshopu i pro Meta/Google katalog), <b>2) klasické fotky</b> (běžné snímky kusu pro Sbazar/Bazos + galerii na eshopu; 3 pole + ➕ až do 10), <b>3) 360° video</b> (dvě otočky — eshop z něj po naskladnění vyrobí 360° bez pozadí; příprava, zpracování ve fázi 2).',
            'Studiová fotka se ukládá jako <b>průhledné PNG</b> (zachová se odstraněné pozadí); klasické fotky jako JPEG. Vše přes naše úložiště <code>media/products/</code>, video přes nový endpoint <code>upload_product_video.php</code>.',
            'Feed pro e-shop nese nová pole (<code>studio_image</code>, <code>gallery_images</code>, <code>video_360_url</code>, <code>has_360</code>) — produkt na applefix.click používá studiovou fotku jako hlavní obrázek a klasické fotky jako galerii s lightboxem; do Meta/Google katalogu jde studiová fotka.',
            'Import z naskladňovací appky (source=app) tyhle sloupce nikdy nepřepíše (jsou to CRM data).',
        ],
    ],
    [
        'version' => '3.4.10',
        'date' => '2026-07-23',
        'time' => '14:16',
        'title' => 'Limit aktivně opravovaných zakázek na technika zvýšen na 10',
        'items' => [
            'Počet zakázek přidělených technikovi zůstává neomezený, ale <b>aktivně opravovat</b> (stav „Provádí se") smí každý technik současně nejvýše <b>10</b> zakázek (dříve 5). Limit se uplatní všude — změna stavu, rychlá změna i úprava zakázky.',
        ],
    ],
    [
        'version' => '3.4.9',
        'date' => '2026-07-23',
        'time' => '00:05',
        'title' => 'E-shop e-maily: prémiový Apple redesign',
        'items' => [
            'Potvrzovací e-maily z e-shopu dostaly zcela nový, uměřený „Apple účtenka" vzhled — plochá bílá, zarovnání vlevo, jemné vlasové linky, SF Pro s přesnou vahovou hierarchií a tabulární čísla. Pryč jsou gradienty, sklo i stíny; e-mail teď působí luxusně a nadčasově, ne genericky.',
        ],
    ],
    [
        'version' => '3.4.8',
        'date' => '2026-07-22',
        'time' => '23:14',
        'title' => 'E-shop: potvrzovací e-maily objednávek (+ QR platba u převodu)',
        'items' => [
            'Objednávky z e-shopu (applefix.click) teď zákazníkovi automaticky pošlou potvrzovací e-mail — dřív se neposílalo nic, i když web sliboval „potvrzení na e-mail" a „údaje k platbě e-mailem". E-mail obsahuje souhrn položek a celkovou cenu ve stejném vzhledu jako ostatní firemní e-maily.',
            'U platby bankovním převodem e-mail nese platební údaje: číslo účtu, částku, variabilní symbol i QR platbu (SPD/SPAYD) k naskenování v bankovní aplikaci. Účet se bere z Nastavení → Banka (acc_bank_account); když není vyplněný, QR se prostě nezobrazí.',
            'U platby kartou (Stripe) e-mail potvrzuje „zaplaceno online". Odesílá se přesně jednou (idempotentně přes eshop_sale.php — opakovaný webhook ho znovu nepošle) a neblokuje odpověď objednávky (posílá se na pozadí přes fastcgi_finish_request).',
        ],
    ],
    [
        'version' => '3.4.7',
        'date' => '2026-07-22',
        'time' => '16:49',
        'title' => 'E-shop: účty zákazníků — profil a historie objednávek',
        'items' => [
            'Nový endpoint api/eshop_customer.php — přihlášený zákazník na e-shopu (applefix.click, login přes Apple/Google) si podle svého e-mailu načte profil klienta (jméno, telefon — pro předvyplnění pokladny) a historii svých e-shop objednávek. Jen čtení, stejné zabezpečení jako feed (token); žádné zakládání klientů (klient v CRM vzniká až reálnou objednávkou).',
        ],
    ],
    [
        'version' => '3.4.6',
        'date' => '2026-07-21',
        'time' => '19:09',
        'title' => 'Tisk štítků: IP tiskárny zvlášť pro každou pobočku',
        'items' => [
            'Serverový tisk štítků nově rozlišuje pobočky — štítek zakázky vyjede na tiskárně TÉ pobočky, které zakázka patří (dřív šlo vše na jednu tiskárnu v Karlíně). V Nastavení → Tisk štítků má teď každá pobočka vlastní pole na IP tiskárny + testovací štítek a stav.',
            'Karlín beze změny (zdědil dosavadní IP 192.168.1.220). Praha 1 – Na Příkopě má vlastní tiskárnu; IP se dá uložit, ale server na ni musí po síti dosáhnout — protože je pobočka v jiné síti, zprovoznění tisku tam vyžaduje ještě lokálního agenta nebo VPN (chystá se).',
        ],
    ],
    [
        'version' => '3.4.5',
        'date' => '2026-07-21',
        'time' => '18:09',
        'title' => 'Upozornění: nové objednávky z e-shopu (applefix.click)',
        'items' => [
            'Zvoneček upozornění teď hlásí i nové objednávky z vlastního e-shopu (dřív četl jen opravy a reklamace, takže e-shop prodeje byly neviditelné). Nová objednávka se objeví v seznamu upozornění a přehraje zvuk jako u zakázek.',
        ],
    ],
    [
        'version' => '3.4.4',
        'date' => '2026-07-21',
        'time' => '15:52',
        'title' => 'Přihlášení do administrace e-shopu přes účty CRM (jen admin a Boss)',
        'items' => [
            'Nový endpoint api/eshop_admin_auth.php — administrace vlastního e-shopu ověřuje přihlášení proti účtům CRM. Do e-shop adminu se dostanou ZÁMĚRNĚ jen administrátor a Boss; ostatní role (manažer, technik, brigádník) jsou odmítnuty i se správným heslem.',
            'Heslo se ověřuje na straně CRM (stejně jako běžný login), e-shop dostane jen ano/ne + jméno a roli. Volání je chráněné feed tokenem (nemůže brute-forcovat kdokoli).',
        ],
    ],
    [
        'version' => '3.4.3',
        'date' => '2026-07-21',
        'time' => '15:41',
        'title' => 'Čtecí přehled e-shop objednávek pro administraci',
        'items' => [
            'Nový endpoint api/eshop_orders.php — administrace vlastního e-shopu si odsud čte seznam objednávek (tabulka eshop_orders), aby je bylo vidět v sekci Objednávky. Jen čtení, stejné zabezpečení jako feed (token).',
        ],
    ],
    [
        'version' => '3.4.2',
        'date' => '2026-07-21',
        'time' => '12:54',
        'title' => 'E-shop prodej odečítá sklad v CRM (obousměrné propojení)',
        'items' => [
            'Nový endpoint api/eshop_sale.php — když se něco prodá na vlastním e-shopu, kus se rovnou odečte ze Skladu → Produkty v CRM (stejná pojistka proti souběhu jako kasa, takže stejný kus nejde prodat dvakrát na prodejně i online).',
            'Objednávky z e-shopu se ukládají do nové tabulky eshop_orders; opakované doručení téže objednávky (webhook) sklad neodečte podruhé (idempotence přes unikátní číslo objednávky).',
            'Dohromady s feedem (api/eshop_feed.php) tak sklad drží jen CRM: e-shop čte stavy z CRM a prodeje zapisuje zpět.',
        ],
    ],
    [
        'version' => '3.4.1',
        'date' => '2026-07-21',
        'time' => '10:59',
        'title' => 'Naskladňování produktů smí každý s právem Sklad (nejen vedení)',
        'items' => [
            'Kdo má oprávnění „Správa skladu" (manage_inventory) a otevře Sklad → Produkty, teď vidí i tlačítka na naskladnění produktů pro e-shop (přidat produkt, import, foto, PČR, export). Dřív tlačítka viděl jen administrátor, Boss a manažer — brigádníci se správou skladu koukali na prázdnou stránku bez tlačítek.',
            'Sladěno s přístupem na stránku: kdo Sklad smí spravovat, smí i naskladňovat produkty.',
        ],
    ],
    [
        'version' => '3.4.0',
        'date' => '2026-07-21',
        'time' => '07:05',
        'title' => 'Feed produktů pro vlastní e-shop (CRM jako zdroj produktů)',
        'items' => [
            'Nový čtecí JSON endpoint api/eshop_feed.php — vlastní e-shop na stejném serveru si odsud bere aktuální produkty ze Skladu → Produkty (název, cena, sklad, fotka, kompletní parametry). Ceny jsou s DPH; použité zboží nese příznak režimu §90 (e-shop nevyčísluje DPH).',
            'Podporuje inkrementální sync (jen změněné od data), filtr „jen skladem", jeden produkt podle kódu i stránkování. Lokálně (stejný server) funguje bez tokenu, vzdáleně přes token.',
            'Feed URL a token najdete v Nastavení → Systém → Integrace.',
        ],
    ],
    [
        'version' => '3.3.1',
        'date' => '2026-07-20',
        'time' => '22:25',
        'title' => 'Naskladnit jako díl: potvrzovací okno s rozpisem dílů a poznámkami',
        'items' => [
            'Tlačítko „Naskladnit jako díl" teď otevře okno, kde je vidět rozpis dílů použitých na této zakázce (ze skladu) a lze upravit název dílu, počet kusů, nákupní cenu a doplnit poznámky / rozpis toho, co se naskladňuje.',
            'Poznámky se uloží k pohybu skladu a do zakázky (dohledatelnost). Vidí a používá administrátor a Boss.',
        ],
    ],
    [
        'version' => '3.3.0',
        'date' => '2026-07-20',
        'time' => '22:20',
        'title' => 'Naskladnit zakázku jako díl na sklad dílů',
        'items' => [
            'V detailu zakázky (u sekce výdeje/dopravy) je nové tlačítko „Naskladnit jako díl (sklad dílů)" — zařízení, které se nevrací klientovi (neopravitelné, výkup apod.), se jedním klikem přidá na sklad dílů jako 1 ks. Název dílu = značka + model, sériové číslo jde do SKU, nákupní cena se předvyplní z ceny zakázky (upravitelné ve Skladu).',
            'Do zakázky se doplní stopa („naskladněno jako díl …") a akce se zapíše do Historie. Tlačítko vidí administrátor a Boss.',
        ],
    ],
    [
        'version' => '3.2.0',
        'date' => '2026-07-20',
        'time' => '22:10',
        'title' => 'Boss = administrátor + čitelnější zakázky a rychlejší výdej',
        'items' => [
            'BOSS má nově ÚPLNĚ stejná práva jako administrátor — nadřazené pravidlo nad vším ostatním. Vidí a smí vše co admin (obě pobočky, všechna nastavení, změna jazyka klienta, režim recepce atd.).',
            'Seznam zakázek i Nástěnka: aktuální číslo zakázky je výrazně větší a jasně bílé se září; původní „(dříve …)" je menší a tlumené — kontrast je teď jasný. Název modelu zařízení (např. „AirPods Max") je větší a čitelnější (značka zůstává).',
            'Opraveno problikávání a občasná neproklikatelnost rozbalovacího menu u ikony blesku v seznamu zakázek (dýchací animace řádku „věznila" menu za sousedy).',
            'Rychlý výdej: označení „Vydáno" už nikdy neblokuje na výběru dopravy — pokud není zvolená, doplní se automaticky „Osobní odběr". Lze tak vydat zakázku z jakéhokoli stavu jedním klikem (blesk i detail).',
        ],
    ],
    [
        'version' => '3.1.1',
        'date' => '2026-07-20',
        'time' => '20:32',
        'title' => 'Oprava: pobočková práva nezpůsobí pád pro zaměstnance',
        'items' => [
            'Interní oprava v návaznosti na v3.1.0 — v kontrole oprávnění vznikala nekonečná smyčka, která by po nasazení shodila každou stránku pro zaměstnance mimo hlavního administrátora. Odchyceno kontrolou ještě před ostrým během. Model práv zůstává beze změny (admin+Boss globální, manažer i technik jen svá pobočka).',
        ],
    ],
    [
        'version' => '3.1.0',
        'date' => '2026-07-20',
        'time' => '20:24',
        'title' => 'Zjednodušená pobočková práva: manažer i technik vidí jen svou pobočku',
        'items' => [
            'Nové jednoduché pravidlo: OBĚ pobočky vidí a napříč nimi spravuje jen administrátor a Boss. Všichni ostatní (manažer i technik) vidí a řídí JEN svou přidělenou pobočku — symetricky Karlín i Na Příkopě (dřív měl Karlín výjimku a viděl vše).',
            'Manažer má na SVÉ pobočce plná práva (zakázky, pokladna, reklamace, přiřazování techniků), ale do druhé pobočky nevidí. Pokladní uzávěrka, reklamace i výběr techniků jsou nově omezené na pobočku.',
            'Celofiremní přehledy (Historie úprav, Reporty, správa všech zaměstnanců, systémové aktualizace CRM, přehledové Telegram notifikace) zůstávají adminovi a Bossovi.',
            'Telegram: administrátor (účet mimo seznam techniků) může nově dostávat přehledové notifikace přes nastavení admin_telegram_id.',
        ],
    ],
    [
        'version' => '3.0.5',
        'date' => '2026-07-20',
        'time' => '20:03',
        'title' => 'Čitelnost čísla zakázky: aktuální bílé, „dříve" o kousek větší',
        'items' => [
            'Aktuální číslo zakázky v seznamu je teď čistě bílé (#FFFFFF) — lépe vyniká.',
            'Původní číslo „(dříve APFAZ…)" je o něco větší a světlejší, aby bylo čitelnější — v seznamu i v detailu zakázky.',
        ],
    ],
    [
        'version' => '3.0.4',
        'date' => '2026-07-20',
        'time' => '19:43',
        'title' => 'Rychlá akce (blesk): přímý skok na „Vydáno" z každého stavu',
        'items' => [
            'Blesková nabídka u zakázky v seznamu nově vedle dalšího kroku (např. „Přesunout do práce") nabízí i přímé „Označit vydané" — u každé zakázky, která ještě není vydaná ani stornovaná. Recepce tak může zakázku uzavřít jedním klikem bez proklikávání mezikroků.',
            'Platí pro obě pobočky. Rychlé „Vydáno" nevyžaduje vyplnění dopravy (datum výdeje i finální cenu doplní systém sám); pokud chcete zadat způsob odeslání a sledovací číslo, použijte jako dřív detail zakázky.',
        ],
    ],
    [
        'version' => '3.0.3',
        'date' => '2026-07-20',
        'time' => '19:32',
        'title' => 'Výdej zakázky: dopravce „Zásilkovna" správně česky',
        'items' => [
            'Ve výběru dopravy při výdeji zakázky (před označením „Vydáno") se přepravce zobrazoval jako „Zasilkovna" bez diakritiky — teď je správně „Zásilkovna". Ostatní volby (Osobní odběr, Česká pošta, Kurýr servisu, PPL/DPD/GLS) i hlášky výdeje jsou v pořádku česky.',
        ],
    ],
    [
        'version' => '3.0.2',
        'date' => '2026-07-20',
        'time' => '19:28',
        'title' => 'Způsob předání se překládá i v seznamu a na Nástěnce (Self Pickup → Osobní odběr)',
        'items' => [
            'V seznamu zakázek, na Nástěnce i v rychlém náhledu se způsob předání zobrazoval anglicky tak, jak přišel z webu (např. „Self Pickup"). Nově se překládá do jazyka CRM stejnými popisky jako v dropdownu detailu: Self Pickup → Osobní odběr, Ceska Posta → Česká pošta, Courier → Kurýr servisu, Zasilkovna → Zásilkovna.',
            'Opravena záměna významu: „Self Pickup" (zákazník si přijde SÁM = osobní odběr) se nově nepletlo s „Pickup Service" (vyzvedáváme MY u zákazníka = kurýr) — dřív hrozilo přeložení na opačný význam.',
            'Importované zakázky s „Osobní předání na pobočce" se ukazují jako „Osobně na prodejně".',
        ],
    ],
    [
        'version' => '3.0.1',
        'date' => '2026-07-20',
        'time' => '19:22',
        'title' => 'Detail zakázky: původní číslo („dříve …") na vlastním řádku pod kódem',
        'items' => [
            'U přečíslovaných zakázek (z migrace) se původní číslo v detailu zakázky nově zobrazuje jako samostatný řádek „(dříve APFAZ…)" hned pod aktuálním kódem — dřív bylo vmáčknuté inline vedle názvu a splývalo.',
            'Sjednocen výraz na „dříve" i v seznamu zakázek a našeptávači.',
        ],
    ],
    [
        'version' => '3.0.0',
        'date' => '2026-07-20',
        'time' => '19:15',
        'title' => '🎉 Přechod na jediný systém — kompletní migrace ze zakazkovylist.cz',
        'items' => [
            'Všechny zakázky, klienti i reklamace ze zakazkovylist.cz jsou přeneseny do Fix-CRM. Celkem 1707 zakázek (1625 importovaných + 82 našich), 1298 klientů a 64 reklamací. Fix-CRM je teď jediný pracovní systém.',
            'Importované zakázky mají šedý řádek (barva stavu jen v buňce Stav), naše původní jsou plnobarevné. Původní datum přijetí, PIN i technik zůstaly zachované; stavy i reklamace jsou převedené výhradně na náš nový model.',
            'Zakázka na pobočce „Černá růže" = Na Příkopě (432 zakázek). Našich 82 zakázek s kolizním číslem bylo přečíslováno za konec řady (2600878–2600959), původní číslo zůstává dohledatelné jako „(dřív …)" v hledání, detailu i klientském portálu. Nové zakázky pokračují od 2600960.',
            'DŮLEŽITÉ: starý systém zakazkovylist.cz se tímto opouští — nová zakázka se od teď zakládá VÝHRADNĚ ve Fix-CRM (jinak hrozí kolize čísel). Bezpečnostní záloha před migrací: backup_20260720_191239.',
        ],
    ],
    [
        'version' => '2.14.2',
        'date' => '2026-07-20',
        'time' => '19:04',
        'title' => 'Migrace: ostrý importní skript + opravy z bezpečnostní revize',
        'items' => [
            'Nový interní skript scripts/migrace_import.php překlopí zakázky, klienty i reklamace ze zakazkovylist.cz do našeho CRM. Cílové stavy jsou VÝHRADNĚ náš nový model — reklamace se z listových „stavů zakázek" (Vydáno/Nevyzvednuto) mapují na reklamační stavy (Vyřízeno/Zamítnuto/…).',
            'Skript je bezpečný: bez přepínače jede jako simulace (vše v transakci, na konci vrátí zpět), teprve s potvrzením zapíše; hlídá dvojí spuštění a při nesouladu kontrolních počtů vše vrátí.',
            'Opraveno dle nezávislé revize: rozbité řádky klientů ze starého systému (jméno = číslo, e‑mail = telefon) se už neimportují jako paskvil (klientů správně 370 místo 490); prázdná předpokládaná cena zůstává prázdná (ne 0 Kč); reklamace bez jména klienta („…") nezaloží nesmyslného klienta.',
        ],
    ],
    [
        'version' => '2.14.1',
        'date' => '2026-07-20',
        'time' => '18:14',
        'title' => 'Migrace: nástroj pro zkušební porovnání importu (nic nezapisuje)',
        'items' => [
            'Nový interní skript scripts/migrace_dry_run.php — porovná data ze zakazkovylist.cz s naším CRM a vypíše, co by ostrý běh migrace udělal: kolik zakázek/klientů/reklamací by se vložilo, kolik jen dorovnalo stav, návrh přečíslování kolizních čísel, nespárované techniky a sporné případy.',
            'Skript POUZE ČTE — do databáze nikdy nezapisuje. Slouží ke schválení migrace majitelem před ostrým během.',
        ],
    ],
    [
        'version' => '2.14.0',
        'date' => '2026-07-20',
        'time' => '18:02',
        'title' => 'Příprava migrace: statistiky rozlišují naše zakázky a import',
        'items' => [
            'Hlavní čísla ve statistikách (dlaždice Nástěnky i Zakázek, badge u Zakázek v menu, denní tržba, přehled poboček) počítají JEN zakázky vzniklé v našem CRM — včetně těch, které se při migraci přečíslují.',
            'Zakázky importované ze zakazkovylist.cz se ukazují šedě menším písmem v závorce hned za hlavním číslem, např. „12 (38)". Když je importovaných nula, závorka se vůbec nezobrazí — do ostrého běhu migrace tedy vše vypadá jako dosud.',
            'Do denní tržby a měsíčních grafů tržeb vstupují jen naše CRM zakázky; import tržby nikdy nezkreslí.',
        ],
    ],
    [
        'version' => '2.13.0',
        'date' => '2026-07-20',
        'time' => '17:52',
        'title' => 'Příprava migrace: importované zakázky poznáte na první pohled',
        'items' => [
            'Zakázky importované ze starého systému (zakazkovylist.cz) budou mít v seznamu zakázek i na Nástěnce celý řádek tmavě šedý — barvu stavu ponese jen barevná pilulka ve sloupci Stav.',
            'Zakázky vzniklé v našem CRM (včetně těch, které se při migraci přečíslují) zůstávají plnobarevné jako dosud — na první pohled tak poznáte, co je „naše" a co je historie z importu.',
            'Funguje ve tmavém i světlém režimu. Projeví se až po ostrém běhu migrace — do té doby vypadá vše stejně.',
        ],
    ],
    [
        'version' => '2.12.0',
        'date' => '2026-07-20',
        'time' => '17:44',
        'title' => 'Příprava migrace: staré číslo zakázky zůstane dohledatelné',
        'items' => [
            'Zakázka si nově umí pamatovat své PŮVODNÍ číslo (před přečíslováním při přechodu ze zakazkovylist.cz). Zobrazuje se šedě jako „(dřív APFAZ…)" — v seznamu zakázek, v detailu zakázky, v našeptávači hledání i v klientském portálu.',
            'Staré číslo funguje všude, kde se zakázka dohledává: vyhledávání na Nástěnce a v Zakázkách, našeptávač, i sken QR/čárového kódu ze starého papírového zakázkového listu — vše otevře správnou (přečíslovanou) zakázku.',
            'Vizuálně se zatím nic nemění — původní čísla se doplní až při ostrém běhu migrace. Tato verze jen připravuje půdu, aby po přečíslování žádné staré číslo „nezmizelo".',
        ],
    ],
    [
        'version' => '2.11.2',
        'date' => '2026-07-20',
        'time' => '16:43',
        'title' => 'Provozní časy: importované zakázky se do grafů nepočítají',
        'items' => [
            'Grafy příjmu a výdeje (Přehledy → Provozní časy) počítají POUZE zakázky vzniklé v našem CRM. Zakázky z importu starého systému (zakazkovylist.cz) se do nich nikdy nezapočítají — hromadný import by jinak vytvořil obří špičku v den importu a zkreslil ostatní dny.',
            'Technický základ: u zakázky se nově eviduje původ (CRM vs. import) — stejný příznak brzy ponesou i šedé řádky importovaných zakázek a oddělené statistiky (hlavní číslo vs. závorka).',
        ],
    ],
    [
        'version' => '2.11.1',
        'date' => '2026-07-20',
        'time' => '16:41',
        'title' => 'Detail reklamace: panely bez zbytečného prázdného místa + užší sloupec IMEI',
        'items' => [
            'Panely v detailu reklamace (Stav, Řeší technik, Řešení, Přílohy, Dokumenty) měly nesmyslně velkou výšku — natahovalo je obecné pravidlo pro dlaždice Nástěnky. Teď mají přirozenou výšku podle obsahu.',
            'Sloupec IMEI/SN v seznamu reklamací je užší — přesně na šířku čísla (celé viditelné, bez hluchého místa za ním), navíc písmem s pevnou šířkou pro lepší čitelnost.',
        ],
    ],
    [
        'version' => '2.11.0',
        'date' => '2026-07-20',
        'time' => '16:03',
        'title' => 'Přehledy: nová záložka „Provozní časy" — příjem a výdej po hodinách a dnech',
        'items' => [
            'V Přehledech přibyla záložka „Provozní časy": čtyři grafy ukazují, KDY zakázky přicházejí (vytvořeno) a KDY si je klienti vyzvedávají (první přechod do stavu „Vydáno") — po hodinách dne (0–23) a po dnech v týdnu (Po–Ne).',
            'Grafy respektují zvolený datumový rozsah nahoře i pobočkovou viditelnost. Modrá = příjem, zelená = výdej. Pomůže naplánovat směny podle skutečné špičky na pultu.',
        ],
    ],
    [
        'version' => '2.10.0',
        'date' => '2026-07-20',
        'time' => '16:00',
        'title' => 'Reklamace: plnohodnotná práce jako se zakázkami',
        'items' => [
            'DETAIL REKLAMACE: reklamace jde nově rozkliknout (číslo v seznamu nebo ikona složky) — detail ukazuje klienta, zařízení, původní popis problému, stav i původní zakázku.',
            'PŘEVZETÍ TECHNIKEM: technik si reklamaci převezme tlačítkem jako zakázku; vedení (admin/Boss/manažer) může přeřadit na kohokoliv. V seznamu reklamací přibyl sloupec Technik. Vše se zapisuje do Historie.',
            'ŘEŠENÍ / ZÁVĚR: v detailu je pole „Řešení / závěr technika" — uloží se s časem a jménem, kdo reklamaci vyřídil.',
            'PŘÍLOHY: k reklamaci jde nahrávat fotky a PDF (průběh řešení) s galerií v detailu; mazat přílohy smí jen vedení. Je doložitelné, jak se reklamace opravdu řešila.',
            'VYROZUMĚNÍ PRO KLIENTA: nový dokument „Vyrozumění o reklamaci" — původní problém, odpověď technika, datum a fotopřílohy. Jde vytisknout NEBO poslat e-mailem klientovi jedním tlačítkem (v jazyce klienta; po odeslání se ukáže, na jakou adresu odešlo).',
        ],
    ],
    [
        'version' => '2.9.4',
        'date' => '2026-07-20',
        'time' => '12:39',
        'title' => 'Technik může mít rozpracovaných až 5 zakázek (dřív 2)',
        'items' => [
            'Limit současně rozpracovaných zakázek („Provádí se") na jednoho technika zvýšen ze 2 na 5. Platí všude — změna stavu, rychlá změna i úprava zakázky.',
            'Upraveny i hlášky ve všech jazycích a návod „Změna stavu zakázky". Limit je nově na jednom místě v kódu, případná další změna čísla je otázka chvilky.',
        ],
    ],
    [
        'version' => '2.9.3',
        'date' => '2026-07-20',
        'time' => '12:35',
        'title' => 'Hlavní menu: stín pod texty a ikonami',
        'items' => [
            'Texty a ikony všech buněk hlavního menu dostaly jemný tmavý stín — na frosted skle jsou čitelnější a působí plastičtěji. Platí i pro barevné rychlé akce (Nová zakázka · Reklamace · Nákupní seznam · Pokladna), kde se stín kombinuje s jejich barevným zářením.',
        ],
    ],
    [
        'version' => '2.9.2',
        'date' => '2026-07-20',
        'time' => '12:18',
        'title' => 'Hlavní menu: tmavě šedé FROSTED sklo s bílými texty',
        'items' => [
            'Hlavní menu (dok) má nový vzhled „frosted glass": tmavě šedé mléčné sklo, které rozmazává animované pozadí pod sebou, s tenkým světlým okrajem a měkkým stínem. Texty a ikony jsou zpět BÍLÉ, barevné rychlé akce ve svých původních zářivých odstínech.',
            'Světlá varianta z v2.9.1 tím byla nahrazena (nelíbila se). Refrakční „čočkový" efekt je u doku vypnutý — frost = rozmazání, ne lom světla; okna a panely si Liquid Glass nechávají.',
            'Ohledy na přístupnost: prohlížeč bez podpory rozmazání dostane téměř plnou barvu, uživatelé s omezenou průhledností v systému plnou barvu bez efektu.',
        ],
    ],
    [
        'version' => '2.9.1',
        'date' => '2026-07-20',
        'time' => '12:10',
        'title' => 'Hlavní menu ve světle šedé s černými texty (Liquid Glass zachován)',
        'items' => [
            'Plovoucí hlavní menu (dok) je nově SVĚTLE ŠEDÉ sklo s ČERNÝMI texty a ikonami — výrazně čitelnější. Liquid Glass efekt (lom světla, průhlednost, lesk) zůstal beze změny, změnil se jen barevný tón skla.',
            'Aktivní stránka se zvýrazňuje bílou buňkou, najetí myší jemným ztmavením. Oddělovač i kroužky badge počtů přizpůsobeny světlému podkladu.',
            'Barevné rychlé akce (Nová zakázka · Reklamace · Nákupní seznam · Pokladna) mají texty v tmavších odstínech svých barev, aby na světlém skle zůstaly dobře čitelné.',
        ],
    ],
    [
        'version' => '2.9.0',
        'date' => '2026-07-20',
        'time' => '12:02',
        'title' => 'Štíhlejší horní lišta: Nákupy pod Sklad, Historie pod Přehledy',
        'items' => [
            'SKLAD má nově tři podzáložky: Servis — náhradní díly | Produkty — e-shop | Nákupy. Samostatná buňka „Nákupy" z horní lišty zmizela; počet čekajících nákupů (oranžový badge) se teď ukazuje na buňce Sklad i na podzáložce Nákupy.',
            'Kdo nemá právo na sklad, buňku Sklad vidí dál a vede ho rovnou na Nákupy (objednávání dílů zůstává dostupné všem jako dosud — o nic nepřišel).',
            'PŘEHLEDY mají podzáložky Statistiky | Historie úprav — samostatná buňka „Historie" z lišty zmizela. Historii vidí stejný okruh lidí jako dřív.',
            'Horní lišta se tím zkrátila z 12 na 10 položek. Staré přímé odkazy (procurement.php, history.php) fungují beze změny — stránky zůstaly, jen se jinak otevírají z menu. Stejně upraveno i mobilní menu (☰).',
        ],
    ],
    [
        'version' => '2.8.2',
        'date' => '2026-07-20',
        'time' => '10:13',
        'title' => 'Pokladna v liště už jen jednou (fialová rychlá akce)',
        'items' => [
            'Původní šedá položka „Pokladna" v hlavním menu byla odstraněna — po přidání fialové rychlé akce tam byla dvakrát. Platí to i pro mobilní menu (☰). Pokladna je teď v liště jen jednou, jako fialová buňka vedle Nákupního seznamu.',
        ],
    ],
    [
        'version' => '2.8.1',
        'date' => '2026-07-20',
        'time' => '09:45',
        'title' => 'Rychlá akce „Pokladna" v horní liště (fialová)',
        'items' => [
            'K barevným rychlým akcím v horní liště (Nová zakázka · Reklamace · Nákupní seznam) přibyla čtvrtá buňka „Pokladna" ve fialové barvě — jedním klikem rovnou na kasu.',
            'Stejná fialová akce je i v mobilním menu (☰), aby to sedělo i na iPadu/telefonu.',
        ],
    ],
    [
        'version' => '2.8.0',
        'date' => '2026-07-20',
        'time' => '09:41',
        'title' => 'Návody: nepřečtený návod svítí — glow zhasne po prvním otevření',
        'items' => [
            'Ikonka každého návodu, který si daný pracovník ještě NEotevřel, jemně svítí bílým glow — na první pohled je vidět, co je nového k přečtení.',
            'Jakmile si pracovník návod poprvé rozbalí, glow pro něj zhasne (ihned, bez obnovení stránky) a ikonka vypadá jako dřív. Eviduje se to pro každého pracovníka zvlášť a na serveru — platí tedy i při přihlášení z jiného počítače.',
            'Startujeme naostro: nikdo zatím nemá nic přečteno, takže teď všem svítí všechny návody — každému zhasínají postupně, jak si je prochází.',
        ],
    ],
    [
        'version' => '2.7.9',
        'date' => '2026-07-20',
        'time' => '09:37',
        'title' => 'Horní lišta: vyhledávání vycentrované a výraznější (bílý obrys i texty)',
        'items' => [
            'Na počítači (šířka okna od 1200 px) je vyhledávací pole v horní liště nově VYCENTROVANÉ doprostřed obrazovky — dřív bylo zastrčené vpravo u ikon.',
            'Pole je výraznější: obrys, ikona lupy, psaný text, našeptávací text („Klienti (ID, Klient, Telefon…)") i zkratka ⌘K jsou nyní čistě bílé (#ffffff).',
            'Ve světlém motivu (bílá lišta) má pole naopak tmavý obrys a texty, aby nezmizelo. Na užších oknech zůstává vpravo, na mobilu je skryté jako dosud.',
        ],
    ],
    [
        'version' => '2.7.8',
        'date' => '2026-07-20',
        'time' => '09:31',
        'title' => 'Oprava datumů v Historii úprav (18. 7. → 20. 7.)',
        'items' => [
            'Záznamy v2.4.1, v2.4.2 a v2.5.0 měly v Historii úprav chybně napsané datum 18. 7. 2026, přestože byly provedeny 20. 7. 2026 v noci (ověřeno proti historii commitů). Datumy opraveny — časy byly správně.',
        ],
    ],
    [
        'version' => '2.7.7',
        'date' => '2026-07-20',
        'time' => '09:22',
        'title' => 'Sklad Produkty: Historie naskladnění (jako v Mac appce) + kdo produkt naskladnil',
        'items' => [
            'Vedle tlačítka „Naskladnit produkt" přibylo tlačítko „Historie naskladnění" — otevře posledních 20 naskladněných produktů s cenou, kódem, KDO produkt naskladnil (podle přihlášení) a KDY (u naskladnění v CRM včetně času). Stejný přehled, jaký měla naskladňovací Mac appka v panelu „Naposledy přidané".',
            'Ve sloupci „Naskladněno" v tabulce skladu se nově u každého produktu zobrazuje i jméno toho, kdo ho naskladnil.',
            'Import souboru z appky si nově pamatuje, kdo soubor nahrál — nové kusy z importu ponesou jeho jméno (u kusů importovaných dřív, kde autor není znám, se ukazuje „appka").',
        ],
    ],
    [
        'version' => '2.7.6',
        'date' => '2026-07-20',
        'time' => '09:17',
        'title' => 'Nový návod: Jazyk klienta — e-maily a dokumenty v jeho řeči',
        'items' => [
            'Do Návodů přibyla karta „Jazyk klienta": kde se jazyk komunikace nastavuje (nová zakázka, Nový klient, úprava klienta), co všechno se jím řídí (doklady, e-maily, klientský portál), jak funguje ukrajinština (e-maily ukrajinsky, tisk anglicky) a jak jednorázově vytisknout doklad v jiném jazyce přes &lang=en.',
            'Součástí jsou i upozornění: data zakázky (popis závady…) se nepřekládají — u cizojazyčného klienta je piš jeho jazykem; rozhodné znění právních textů je vždy české.',
        ],
    ],
    [
        'version' => '2.7.5',
        'date' => '2026-07-20',
        'time' => '09:14',
        'title' => 'Zakázkový list e-mailem: celý v jazyce klienta + oprava rozsypaného vzhledu v Gmailu',
        'items' => [
            'OPRAVA JAZYKA: e-mail se zakázkovým listem odcházel anglickému klientovi s anglickým předmětem, ale ČESKÝM obsahem. Příčina: při skládání e-mailu se jazyk klienta nedostal k překladům šablony (technicky: include běží uvnitř funkce a _l() čte globální proměnnou) — tělo tak spadlo do jazyka přihlášeného zaměstnance. Nyní jde celý dokument v jazyce klienta.',
            'OPRAVA VZHLEDU V GMAILU: řádky „Zařízení / Heslo / Požadovaná oprava…" se v e-mailu slévaly dohromady („ZařízeníApple iPhone…"), protože Gmail zahazuje část moderního CSS (flex). Řádky jsou nově poskládané jako tabulky — v Gmailu drží popisek vlevo a hodnotu vpravo s oddělovací linkou, v prohlížeči a tisku vypadá dokument stejně jako dosud.',
            'PŘEKLAD PRÁVNÍCH TEXTŮ: obchodní podmínky drobným písmem, věta o nesděleném heslu i podpisová doložka převzetí jsou nyní na anglickém dokladu ANGLICKY (a rusky na ruském) — na přání majitele je celý dokument v jazyce klienta. České znění zůstává beze změny slova od slova.',
            'Způsob převzetí „Osobní předání na pobočce" se na cizojazyčném dokladu také překládá; dynamický <html lang> podle jazyka dokumentu.',
        ],
    ],
    [
        'version' => '2.7.4',
        'date' => '2026-07-20',
        'time' => '08:42',
        'title' => 'Po odeslání zakázkového listu e-mailem se ukáže, na jakou adresu se odeslalo',
        'items' => [
            'Po kliknutí na „Odeslat e-mailem klientovi" hláška „Odesláno na" dřív neukazovala adresu — server ji nevracel. Nově ukáže konkrétní e-mail klienta, na který zakázkový list odešel.',
            'Stejně tak u „Podepsat na tabletu a poslat e-mailem": v hlášce se rovnou zobrazí e-mail klienta, na který se list po podpisu automaticky odešle.',
        ],
    ],
    [
        'version' => '2.7.3',
        'date' => '2026-07-20',
        'time' => '08:31',
        'title' => 'Tiskový doklad „Potvrzení (A4)" přejmenován na „Zakázkový list (A4)"',
        'items' => [
            'V tiskovém menu u zakázky se dokument „Potvrzení (A4)" nově jmenuje „Zakázkový list (A4)" — přesně odpovídá tomu, co se vytiskne. Přejmenováno ve všech jazycích (cs „Zakázkový list (A4)", en „Order sheet (A4)", ru „Заказ-наряд (A4)").',
            'Šlo jen o popisek v nabídce — samotný dokument i jeho tisk zůstávají beze změny (interně to vždy byl zakázkový list).',
        ],
    ],
    [
        'version' => '2.7.2',
        'date' => '2026-07-20',
        'time' => '08:15',
        'title' => 'Sken karty na iPhonu: jasnější, kde je skener (je to ikona QR v liště)',
        'items' => [
            'QR skener v horní liště (ikona vedle zvonku) nově v popisku i v titulku okna uvádí, že skenuje „zakázku NEBO kartu klienta" — dřív psal jen „Skenovat QR zakázky", takže nebylo poznat, že umí i věrnostní karty. Funkčnost byla stejná, jen popis mátl.',
            'Návod k recepci upřesněn: na iPhonu není potřeba žádná zvláštní aplikace — buď se klepne na ikonu QR v liště CRM, nebo se použije Fotoaparát iPhonu a otevře se nabídnutý odkaz. iPhone musí být přihlášený do CRM.',
        ],
    ],
    [
        'version' => '2.7.1',
        'date' => '2026-07-20',
        'time' => '08:00',
        'title' => 'Návod k recepci: jak sken „doskočí" + oprava pozice tlačítka',
        'items' => [
            'Návody → „Věrnostní karta klienta — sken na recepci": doplněno vysvětlení, jak sken funguje při zapnutém režimu — počítač recepce se každé 3 vteřiny ptá serveru a po pípnutí kartou na iPhonu naskočí profil klienta sám do ~3 vteřin.',
            'Doplněno, že recepce nepřeruší rozdělanou práci (když píšeš/máš otevřené okno, počká), každý sken naskočí jen jednou, a že „doskočí" jen na počítač recepce dané pobočky (iPhone i počítač musí být přihlášené).',
            'Opraven návod: tlačítko „Režim recepce" je nově vpravo dole (dřív návod uváděl vlevo dole).',
        ],
    ],
    [
        'version' => '2.7.0',
        'date' => '2026-07-20',
        'time' => '07:55',
        'title' => 'Vícejazyčnost dokončena — klientský portál i všechny doklady v jazyce zákazníka',
        'items' => [
            'DOKLADY: faktura, zakázkový list, účtenka z pokladny, reklamační protokol i podpisová stanice se teď tisknou v jazyce zákazníka (podle volby jazyka u zakázky/klienta; ukrajinština → angličtina). Dřív se řídily jazykem zaměstnance.',
            'FAKTURA: opraveno, že anglickému/ukrajinskému zákazníkovi vycházela faktura česky — nově respektuje jazyk klienta. Zároveň opravena chyba, kdy se při chybě (chybějící doklad) místo hlášky vypsal kus zdrojového kódu.',
            'KLIENTSKÝ PORTÁL: přihlášený zákazník teď vidí celý portál (nástěnka, věrnostní karta, dokumenty, reklamační formulář) automaticky ve svém jazyce. Ruční přepnutí jazyka v portálu vydrží. Zaměstnanecké prostředí to nijak neovlivní.',
            'E-MAILY: předmět e-mailu se zakázkovým listem je nově také v jazyce zákazníka (těla e-mailů už trojjazyčná byla).',
            'Právně závazné české texty (obchodní podmínky, §90 DPH, souhlasy u podpisu) zůstávají česky jako rozhodné znění — anglicky/rusky jsou jen popisky a hlavičky. Kompletní parita překladů CS/EN/RU (1079 textů, chybí 0).',
        ],
    ],
    [
        'version' => '2.6.5',
        'date' => '2026-07-20',
        'time' => '07:52',
        'title' => 'Tlačítko „Režim recepce" přesunuto vpravo dolů',
        'items' => [
            'Plovoucí tlačítko „Režim recepce" na Nástěnce se přesunulo z levého do pravého dolního rohu — už se nepřekrývá s ovladačem zvětšení (zoom 100 % −/+), který je vlevo dole.',
        ],
    ],
    [
        'version' => '2.6.4',
        'date' => '2026-07-20',
        'time' => '07:11',
        'title' => 'Boss má plný přístup do všech Nastavení',
        'items' => [
            'Boss (majitel) teď vidí a může upravovat všech 7 záložek Nastavení stejně jako administrátor — Údaje o společnosti, Věrnostní karta, Banka, Zaměstnanci, Tisk štítků, Správa administrátorů i Systém (Integrace/Databáze/Aktualizace).',
            'Funguje i ukládání a akce v těchto záložkách: serverové zálohy (Zálohovat teď / Obnovit), nastavení a synchronizace Banky (KB), test SMS a Telegramu, synchronizace ceníku.',
            'Přístup je úzce omezený POUZE na Nastavení — mimo Nastavení se Bossovi nic nemění (účetnictví, nákupy, reporty zůstávají jako dosud). Řízeno novou funkcí crmCanManageSettings().',
        ],
    ],
    [
        'version' => '2.6.3',
        'date' => '2026-07-20',
        'time' => '06:49',
        'title' => 'Nastavení přehlednější — nová záložka „Systém" (Integrace · Databáze · Aktualizace)',
        'items' => [
            'Z deseti záložek je sedm. Integrace, Databáze i Aktualizace se sloučily do jedné záložky „Systém" s pod-záložkami — méně hledání, logičtější uspořádání.',
            'ZÁLOHY: databázová karta teď zálohuje na SERVER (celá databáze + soubory + kód, automaticky každých 15 minut, s možností obnovy) — zmizelo zbytečné stahování .sql souboru do prohlížeče. Seznam serverových záloh i tlačítko „Zálohovat teď" najdeš v Systém → Databáze.',
            'Aktualizace zůstávají přístupné i vedení (manažer/Boss) — po sloučení je najdou v Systém → Aktualizace; ostatní pod-sekce zůstávají jen pro administrátory.',
            'Staré odkazy a záložky (na Integrace / Zálohy / Aktualizace) fungují dál — samy se přesměrují do nové záložky Systém.',
        ],
    ],
    [
        'version' => '2.6.2',
        'date' => '2026-07-20',
        'time' => '07:20',
        'title' => 'Jazyky – Fáze 1: Sklad, Účetnictví a Nastavení respektují zvolený jazyk',
        'items' => [
            'SKLAD: hlavička tabulky i okno „Použít k opravě" už nejsou natvrdo anglicky — „Dodavatel", „Dostupnost", „Naše skladem", „ks", „Vybraný díl", „Zakázka", „Množství", „Přiřadit k zakázce" i hledání zakázky se teď zobrazují podle zvoleného jazyka (Čeština / English / Русский).',
            'ÚČETNICTVÍ: potvrzovací dotazy „Smazat fakturu?" a „Vytvořit opravný daňový doklad?" byly dřív jen anglicky, teď jsou přeložené.',
            'NASTAVENÍ: výběr jazyka, tlačítko „Načíst znovu" po aktualizaci, test Telegramu (dřív rusky „Тест уведомления"), nápovědy k Service ID a k Telegram ID zaměstnance a popisky „Admin"/„E-mail" se teď řídí zvoleným jazykem.',
            'NÁKUPY a REPORTY: nápověda „Vyber dodavatele a URL se doplní" i štítek role „Admin" už také respektují jazyk.',
            'Doplněny i chybějící ruské překlady — parita překladů CS/EN/RU je teď úplná, ruskému uživateli se nikde neukáže český text místo ruského.',
        ],
    ],
    [
        'version' => '2.6.1',
        'date' => '2026-07-20',
        'time' => '06:30',
        'title' => 'Jazyk komunikace klienta i ve formuláři „Nový klient"',
        'items' => [
            'Volba jazyka komunikace (Čeština / English / Українська) je teď i v samostatném formuláři „Nový klient" na stránce Klienti — dřív chyběla a nový klient se zakládal vždy s češtinou. Nabídka tří jazyků je tak jednotná všude: nová zakázka, úprava klienta i nový klient.',
            'Podle uloženého jazyka klienta mu automaticky odchází e-mail „Připraveno k vyzvednutí" i žádost o recenzi v jeho jazyce (česky / anglicky / ukrajinsky).',
        ],
    ],
    [
        'version' => '2.6.0',
        'date' => '2026-07-20',
        'time' => '06:16',
        'title' => 'Jazyky: základ pro plnou češtinu i angličtinu (příprava)',
        'items' => [
            'Doplněny všechny chybějící anglické překlady (67 textů) — po přepnutí do angličtiny se už nikde neukáže „název klíče" místo textu. Výchozí jazyk systému je nově čeština (dřív se v některých místech chybně bral jako ruština, kvůli čemuž třeba faktura mohla vyjet rusky).',
            'Toto je základ — v dalších krocích se doplní překlady natvrdo psaných textů ve Skladu/Účetnictví, klientský portál a doklady, ať vše respektuje zvolený jazyk.',
        ],
    ],
    [
        'version' => '2.5.1',
        'date' => '2026-07-20',
        'time' => '04:40',
        'title' => 'Oprava: mezera nad horní lištou + duplicitní zakázky',
        'items' => [
            'Zmizela prázdná mezera nad horní lištou (od minulé úpravy se kus kódu zobrazoval jako text — teď je zpět v pořádku).',
            'OCHRANA PROTI DUPLICITÁM: tlačítko „Dokončit" u nové zakázky se hned po kliknutí zamkne (ukazuje „Ukládám…"), takže víc kliknutí už nezaloží víc zakázek. Navíc server odmítne stejnou zakázku (týž klient, model a závada) podanou do 30 vteřin — pojistka i pro rychlé opakované odeslání. (Dřív při pomalé odezvě serveru mohla vzniknout jedna zakázka i 10×.)',
        ],
    ],
    [
        'version' => '2.5.0',
        'date' => '2026-07-20',
        'time' => '04:06',
        'title' => 'Práva zaměstnanců: všichni mohou opravovat zakázky i kontakty',
        'items' => [
            'Každý přihlášený zaměstnanec teď může upravovat JAKOUKOLIV zakázku (i cizí) a opravovat kontaktní údaje klientů — když se u kasy střídáte a zakládáte zakázky, chyby si každý opraví sám. Kdo co změnil, je vždy dohledatelné v Historii úprav.',
            'MAZÁNÍ zakázek zůstává jen adminovi a Bossovi (je to nevratné). Ostatní zakázky pouze upravují — tlačítko koše ostatním zmizí. Stejně tak mazání klienta smí jen admin a Boss.',
            'Vytváření zakázek a prodej na Pokladně mají všichni zaměstnanci i nadále. Faktury a účetnictví zůstávají vedení (admin, Boss).',
        ],
    ],
    [
        'version' => '2.4.2',
        'date' => '2026-07-20',
        'time' => '03:41',
        'title' => 'Přehledy: výchozí rozsah od začátku roku (ne jen tento týden)',
        'items' => [
            'Sekce Přehledy nově zobrazuje statistiky ve výchozím stavu od 1. ledna do dneška — dřív byl výchozí filtr jen aktuální týden, takže to vypadalo, jako by starší statistiky „zmizely" (nezmizely, jen nebyly ve zvoleném období).',
            'Rozsah jde samozřejmě dál libovolně měnit filtrem. Výchozí začátek roku se sám posouvá s kalendářním rokem.',
        ],
    ],
    [
        'version' => '2.4.1',
        'date' => '2026-07-20',
        'time' => '03:41',
        'title' => 'Konec hlášky „Neplatný token" — místo ní obnovení přihlášení',
        'items' => [
            'Když zaměstnanci po delší nečinnosti vyprší přihlášení, místo záhadné červené hlášky „Neplatný token" (a nefungující akce) vyskočí okno se zadáním hesla. Zaměstnanec zadá heslo, přihlášení se obnoví a pokračuje v práci — rozdělaná věc na stránce se neztratí.',
            'Jméno přihlášeného je předvyplněné; je tam i odkaz „Přihlásit jiného zaměstnance" pro střídání směny. Chráněno stejnou brzdou proti hádání hesla jako přihlašovací stránka.',
            'Zároveň se opravila příčina: bezpečnostní token se teď čte vždy aktuální, takže po obnovení přihlášení fungují všechny další akce bez nutnosti obnovovat stránku.',
        ],
    ],
    [
        'version' => '2.4.0',
        'date' => '2026-07-17',
        'time' => '19:38',
        'title' => 'BANKA — propojení s Komerční bankou (jako Money S3)',
        'items' => [
            'Účetnictví má nově dvě podsekce: FAKTURY a BANKA (stejně jako Sklad má Servis/Produkty). V Bance je napojený účet KB přes oficiální KB API: CRM stahuje bankovní pohyby a příchozí platby AUTOMATICKY páruje s fakturami podle variabilního symbolu — spárovaná faktura se sama označí jako ZAPLACENÁ.',
            'Hlavní menu je přeuspořádané do logických skupin: příjem práce (Zakázky, Reklamace, Klienti) → zboží (Sklad, Nákupy, Pokladna) → peníze (Účetnictví) → Přehledy → ostatní. Nic nepřibylo ani neubylo, jen to dává smysl za sebou.',
            'Platba, kde VS sedí, ale částka ne, se žlutě označí „k prověření"; každou platbu jde spárovat i ručně (a párování zrušit). Přehled: příjmy/výdaje měsíce, filtry, vše se ukládá lokálně.',
            'Faktury mají QR PLATBU (standard ČBA) — zákazník naskenuje kód v bankovní aplikaci a má předvyplněnou částku, účet i VS. Funguje hned, bez čekání na banku.',
            'Nastavení → Banka: průvodce připojením (registrace na developers.kb.cz, klíče, testovací sandbox i ostrý účet), tlačítko „Načíst účty / otestovat". Jednorázově je potřeba certifikát I.CA a potvrzení jednatelem KB Klíčem — souhlas platí 12 měsíců.',
            'Synchronizace se drží bezplatného tarifu KB (interval 61 minut); administrátor může vynutit okamžitou. Vytvořit fakturu „uvnitř banky" KB neumožňuje nikomu — tohle je stejný způsob, jakým to dělá Money S3.',
        ],
    ],
    [
        'version' => '2.3.0',
        'date' => '2026-07-17',
        'time' => '19:09',
        'title' => 'NASKLADNĚNÍ PRODUKTŮ přímo v CRM — náhrada Mac appky',
        'items' => [
            'Sklad → Produkty má zelené tlačítko „Naskladnit produkt": stejný formulář jako v naskladňovací appce (typ, model, úložiště, barva, stav, baterie, cena, SN/IMEI, RAM a jádra u MacBooků) s živým náhledem automaticky složeného názvu.',
            'SN/IMEI se při zadání hned prověří v databázi odcizených mobilů Policie ČR — zelená V POŘÁDKU / červená POZOR–ODCIZENO (přidat jde jen po výslovném potvrzení, se záznamem v Historii). Duplicitní SN systém nepustí.',
            'Foto produktu se nahraje rovnou z prohlížeče; „Přidat a vytisknout štítek" (Ctrl/Cmd+Enter) pošle cenovku na Brother QL-810W — stejný vzhled štítku jako z appky, včetně velkého MacBook formátu.',
            'Naskladněný kus je okamžitě prodejný na Pokladně a připravený pro e-shop (ukládá se i kompletní datový řádek pro Upgates). Formulář se po přidání čistí pro další kus (typ, stav a prodejna zůstávají) — sériové naskladňování jako v appce.',
            'Úprava produktu tužkou u řádku; tisk cenovky ikonkou štítku (smí každý přihlášený). Tlačítko „CSV pro Upgates" stáhne celý sklad ve formátu souboru appky pro dosavadní ruční import — nově se do něj propisují i prodeje z kasy (Vyprodáno).',
            'Mac appka funguje dál souběžně: kusy naskladněné v CRM si CRM chrání — import souboru z appky je nepřepíše (jen ohlásí počet). Jeden fyzický kus naskladňuj jen v jednom systému.',
            'Návod pro zaměstnance: Návody → CRM → „Naskladnění produktu (bazar) přímo v CRM".',
        ],
    ],
    [
        'version' => '2.2.1',
        'date' => '2026-07-17',
        'time' => '17:41',
        'title' => 'Pokladna: mazání položky velkým červeným křížkem',
        'items' => [
            'Tlačítko pro smazání položky z košíku je velký červený křížek — jednodušší a rychlejší na trefení.',
        ],
    ],
    [
        'version' => '2.2.0',
        'date' => '2026-07-17',
        'time' => '17:41',
        'title' => 'Pokladna: USB čtečka čárových kódů + jasné mazání položek',
        'items' => [
            'Zboží jde do košíku načíst USB čtečkou čárových kódů — kdykoli je Pokladna otevřená, bez klikání do jakéhokoli pole. Pípneš kód (sériové číslo produktu nebo SKU dílu) a položka se přidá rovnou do košíku.',
            'Sken potvrdí zvuk a zelená hláška („Přidáno: …"); nenalezený nebo nejednoznačný kód ohlásí nízké pípnutí s červenou hláškou.',
            'U každé položky v košíku přibylo tlačítko pro okamžité smazání položky.',
        ],
    ],
    [
        'version' => '2.1.2',
        'date' => '2026-07-17',
        'time' => '17:36',
        'title' => 'Pokladna: ještě větší Celkem a nadpisy sloupců košíku',
        'items' => [
            'Celková částka v košíku je teď opravdu velká (58 px) i s větším popiskem „Celkem" — na kase ji přečteš přes celý pult.',
            'Nadpisy sloupců nad položkami (Položka / Ks / Cena za ks / Celkem) jsou větší, tučné a verzálkami — lépe se v košíku orientuje.',
        ],
    ],
    [
        'version' => '2.1.1',
        'date' => '2026-07-17',
        'time' => '17:13',
        'title' => 'Zámek kasy: 10 pokusů a blokace jen konkrétní osoby',
        'items' => [
            'Limit špatných hesel při odemykání kasy zvýšen z 5 na 10 pokusů.',
            'Po vyčerpání pokusů se už neukončuje celá kasa — na 15 minut se zablokuje jen účet dotyčného zaměstnance (odemčení i přihlášení). Kdokoli jiný se mezitím normálně přihlásí přes „Přihlásit jiného zaměstnance" a prodává dál.',
            'Blokace se zapisuje do Historie úprav; po 15 minutách vyprší sama, úspěšné odemčení počítadlo nuluje.',
        ],
    ],
    [
        'version' => '2.1.0',
        'date' => '2026-07-17',
        'time' => '17:05',
        'title' => 'Pokladna: zámek obrazovky po 15 minutách nečinnosti',
        'items' => [
            'Když se otevřená kasa 15 minut nepoužívá, obrazovka se automaticky zamkne — obsah kasy pod zámkem není vidět a nejde s ní pracovat.',
            'Odemčení je na pár vteřin: jméno přihlášeného zaměstnance je předvyplněné, stačí zadat heslo a pokračovat. Rozdělaný košík se neztrácí.',
            'Po 5 špatných pokusech se session ukončí a jde se na plné přihlášení (zapíše se do Historie). Při střídání směny slouží odkaz „Přihlásit jiného zaměstnance".',
        ],
    ],
    [
        'version' => '2.0.1',
        'date' => '2026-07-17',
        'time' => '17:02',
        'title' => 'Pokladna: čistší rozhraní jako u skutečné kasy',
        'items' => [
            'Sekce „Dnešní prodeje" z Pokladny zmizela — historie prodejů patří do Historie → Kasa prodejna (odkaz zůstal nahoře vedle denních součtů). Kasa je teď čistě prodejní plocha.',
            'Košík je výrazně větší a čitelnější: větší názvy položek, velká pole pro kusy a cenu, větší řádkové součty, celková částka i platební tlačítka — rozhraní klasické pokladny.',
        ],
    ],
    [
        'version' => '2.0.0',
        'date' => '2026-07-17',
        'time' => '16:14',
        'title' => 'POKLADNA — vlastní kasa přímo v CRM',
        'items' => [
            'Nová sekce Pokladna v horním menu: pultový prodej produktů (použitá elektronika) i servisních dílů bez zakládání zakázky. Vyhledávání nabízí jen zboží, které je skutečně skladem.',
            'Košík s více položkami — množství i cenu za kus lze upravit (sleva na místě). Platba třemi velkými tlačítky: Hotově, Kartou (jen evidence typu pro účetnictví — terminál jede zvlášť) a Na fakturu (s výběrem zákazníka; faktura se vystaví automaticky).',
            'Prodej okamžitě odepíše sklad: díl ubere kusy (se záznamem ve skladových pohybech), produkt se označí jako prodaný. Dvě kasy najednou se nemohou přeprodat — sklad hlídá databáze.',
            'Po zaplacení se rovnou otevře tisk účtenky; u platby na fakturu i faktura. U použitého zboží účtenka i faktura správně uvádějí zvláštní režim DPH § 90 (daň se nevyčísluje).',
            'Historie má novou podsekci „Kasa prodejna": všechny prodeje s doklady, souhrn Hotově/Kartou/Fakturou za zvolené období (denní uzávěrka), dotisk účtenek. Storno smí jen vedení a vrátí zboží na sklad.',
            'Pojistka proti dvojímu prodeji: produkt prodaný na kase zůstane vyprodaný i po nahrání souboru z naskladňovací appky — import konflikt nahlásí, dokud kus nevyřadíš i v appce.',
            'Prodávat může každý přihlášený zaměstnanec na obou pobočkách; každý doklad nese pobočku a jméno prodejce.',
        ],
    ],
    [
        'version' => '1.15.1',
        'date' => '2026-07-17',
        'time' => '15:47',
        'title' => 'Horní lišta s logem a vyhledáváním je neprůhledně černá',
        'items' => [
            'Tenká lišta úplně nahoře (logo, vyhledávání, QR sken, upozornění) má nově sytě černé neprůhledné pozadí — animované vlny do ní už neprosvítají.',
            'Velké menu se sekcemi pod ní zůstalo beze změny; ve světlém motivu je lišta neprůhledně bílá.',
        ],
    ],
    [
        'version' => '1.15.0',
        'date' => '2026-07-17',
        'time' => '15:24',
        'title' => 'Sklad rozdělen: Servis a Produkty (příprava na vlastní e-shop)',
        'items' => [
            'Sekce Sklad má nově dvě záložky: „Servis — náhradní díly" (vše, co tam bylo doteď) a „Produkty — e-shop" (použitá elektronika a příslušenství, které půjdou z CRM na náš vlastní e-shop místo Upgates).',
            'Produkty se nahrávají souborem z naskladňovací aplikace (AppleFix-produkty.csv) — tlačítko „Nahrát soubor z appky". Import podle kódu kusu přidá nové a aktualizuje stávající; nic sám nemaže, takže opakované nahrání je bezpečné.',
            'Produkt, který v naposledy nahraném souboru chyběl, dostane žlutý štítek „není v posledním souboru" — na omyl se přijde, ale nic se neztratí.',
            'Nahrávat a mazat produkty smí jen vedení (admin, Boss, manažer); prohlížet je může každý, kdo vidí Sklad.',
            'Servisní část skladu (výdej dílů na zakázky, QR štítky, nákupy) zůstala beze změny — produkty se do ní nepletou.',
        ],
    ],
    [
        'version' => '1.14.0',
        'date' => '2026-07-17',
        'time' => '12:02',
        'title' => 'Fotky produktů: úložiště pro naskladňovací appku (Upgates + Facebook)',
        'items' => [
            'Server nově přijímá fotky produktů z naskladňovací aplikace a ukládá je veřejně (media/products). Stejná adresa fotky pak funguje jak pro zobrazení v Upgates e-shopu, tak pro import do Facebook / Meta katalogu.',
            'Navazuje úprava naskladňovací appky (tlačítko „Foto produktu") — fotka se nahraje sem a její adresa se uloží do sloupce obrázku.',
        ],
    ],
    [
        'version' => '1.13.3',
        'date' => '2026-07-17',
        'time' => '11:35',
        'title' => 'Endy (Andrea): přístup do Klientů, Skladu a Nákupů',
        'items' => [
            'Andrea (Endy) může nově upravovat údaje klientů — kdyby při zadávání vznikl překlep, opraví si ho sama (sekce Klienti i přímo u zakázky).',
            'Získala přístup do Skladu a plnou práci v Nákupech (objednávání dílů). Návody měla přístupné i dřív.',
            'Aby se práva projevila, musí se Andrea jednou odhlásit a znovu přihlásit.',
        ],
    ],
    [
        'version' => '1.13.2',
        'date' => '2026-07-17',
        'time' => '11:23',
        'title' => 'Aktualizace: čitelnější datum a čas u verzí',
        'items' => [
            'Datum vydání u aktuální a nejnovější verze (Nastavení → Aktualizace) se zobrazuje lidsky: „pátek 17. července 2026 · 11:07 (dnes)" místo strojového „2026-07-17 11:07".',
            'K aktuální verzi přibylo datum jejího nasazení, ať je hned vidět, jak stará je.',
        ],
    ],
    [
        'version' => '1.13.1',
        'date' => '2026-07-17',
        'time' => '11:07',
        'title' => 'Oprava: horní lišta (upozornění, QR sken, hledání) po aktualizaci nereagovala',
        'items' => [
            'Prohlížeč po nasazení někdy držel starou verzi skriptů z paměti a horní lišta pak nereagovala na kliknutí. Nově se skripty (JS/CSS) po každé aktualizaci vždy ověří, takže se tohle už nestane.',
            'Kdyby to u někoho ještě přetrvávalo: stačí jednou tvrdě obnovit stránku (na Macu Cmd+Shift+R, na Windows Ctrl+F5).',
            'Funkce „Režim recepce" navíc pojištěna tak, aby v žádném případě nemohla ovlivnit zbytek ovládání.',
        ],
    ],
    [
        'version' => '1.13.0',
        'date' => '2026-07-17',
        'time' => '10:45',
        'title' => 'Katalogy: více odkazů na jeden katalog + mazání katalogů',
        'items' => [
            'Jeden katalog dodavatele může mít víc vstupních odkazů — přesně pro případy jako MobileSentrix, kde je každý model zvlášť. Při přidávání katalogu tlačítko „Další odkaz" přidá další políčko; není potřeba zakládat dodavatele desetkrát.',
            'Aktualizace z katalogu zvládne víc adres najednou (po řádcích) — po výběru dodavatele se předvyplní všechny jeho odkazy a naimportují se jedním kliknutím.',
            'Katalogy jdou nově odstranit: v okně „Přidat katalog" je seznam stávajících katalogů s košem. Odstranění smaže nenaskladněné položky katalogu; naskladněné a v zakázkách použité díly ve Skladu zůstávají.',
        ],
    ],
    [
        'version' => '1.12.5',
        'date' => '2026-07-17',
        'time' => '10:36',
        'title' => 'Boss má plná práva na Nákupy a Sklad',
        'items' => [
            'Boss nově zvládne v Nákupech a Skladu všechno co administrátor: import a aktualizaci katalogů dodavatelů, přidání nového dodavatele, správu produktů, naskladnění, korekce stavu i objednávky dílů.',
            'Tlačítka „Aktualizovat z katalogu" a „Přidat katalog" se Bossovi nyní zobrazují na stránce Nákupy.',
        ],
    ],
    [
        'version' => '1.12.4',
        'date' => '2026-07-17',
        'time' => '10:19',
        'title' => 'Oprava: CRM „zamrzalo" během importu katalogu · nový dodavatel Jablečnédíly.cz',
        'items' => [
            'Když běžel import katalogu dodavatele, celé CRM v tom samém prohlížeči přestalo reagovat (filtry v Nákupech, jiné záložky) — import držel zámek přihlášení po celou dobu běhu. Opraveno, během importu jde normálně pracovat dál.',
            'Tlačítko importu se po spuštění zamkne a ukazuje „Import běží…" — nejde omylem spustit dvakrát.',
            'Do katalogu v Nákupech přibyl dodavatel Jablečnédíly.cz (Shoptet) — naimportováno přes 1200 dílů s cenami, obrázky a skladovou dostupností. Aktualizace stejně jako u ostatních: Aktualizovat z katalogu → Jablečnédíly.cz.',
        ],
    ],
    [
        'version' => '1.12.3',
        'date' => '2026-07-17',
        'time' => '09:59',
        'title' => 'Nákupy: katalog MobileSentrix (mobilesentrix.eu)',
        'items' => [
            'Import katalogu v Nákupech nově umí MobileSentrix — dřív hlásil 0 produktů, protože jejich e-shop používá jinou strukturu stránek. Načítá název, cenu (bez DPH), obrázek, odkaz i skladovou dostupnost dodavatele (počet kusů).',
            'Produkty dostávají stabilní kód „MS-…", takže opakovaný import stejné kategorie položky aktualizuje (synchronizace cen a dostupnosti), nezakládá duplicity.',
            'Prochází se i podkategorie a všechny stránky výpisu. Tip: importuj po kategoriích (např. díly pro konkrétní model iPhonu) — celý katalog MobileSentrix má desítky tisíc položek a import je omezen na 200 stránek najednou.',
        ],
    ],
    [
        'version' => '1.12.2',
        'date' => '2026-07-17',
        'time' => '04:44',
        'title' => 'Nový design klientské karty v peněžence',
        'items' => [
            'Karta v Apple Wallet dostala nový vzhled: gradientový pás s vodoznakem jablka za jménem klienta, čisté logo bez ořezaného textu vedle něj a sladěné tlumené barvy (tmavé indigo).',
            'Stejnou paletu dostala i karta v klientském portálu („Moje karta") — obě verze teď vypadají jednotně.',
            'Klienti, kteří už kartu v peněžence mají, uvidí nový design po odebrání a opětovném přidání karty z portálu.',
        ],
    ],
    [
        'version' => '1.12.1',
        'date' => '2026-07-17',
        'time' => '03:48',
        'title' => 'Režim recepce: doladění podle bezpečnostní kontroly',
        'items' => [
            'Počítač v režimu recepce už neskočí na klienta uprostřed rozdělané práce — když je otevřený formulář nebo se píše, sken chvíli počká a otevře se hned po dokončení.',
            'Obnovení stránky klienta na iPhonu (potažení dolů, tlačítko Zpět) už znovu „nevystřelí" profil na počítač recepce — posílá se jen skutečně nový sken.',
            'Sken téhož klienta podruhé stránku na počítači obnoví (čerstvé body a zakázky). Podpisová stanice na skeny nereaguje vůbec — podpis klienta nejde přerušit.',
            'Dohledání karty skenem funguje jen pro přihlášený personál (zpřísnění proti hádání čísel karet zvenku).',
        ],
    ],
    [
        'version' => '1.12.0',
        'date' => '2026-07-17',
        'time' => '03:38',
        'title' => 'Režim recepce: sken karty iPhonem otevře klienta na počítači',
        'items' => [
            'Nový „Režim recepce": na počítači recepce se na Nástěnce zapne pilulkou vlevo dole („Režim recepce"). Když pak kdokoliv z personálu naskenuje QR klientské karty firemním iPhonem, profil klienta se do ~3 vteřin otevře i na tomto počítači — bez sahání na klávesnici.',
            'Zapíná se jednou a vydrží zapnuto (zelená pilulka „Recepce poslouchá skeny"). Funguje pro skeny v rámci stejné pobočky.',
            'Čtečka čárových kódů u počítače nově zvládne i číslo věrnostní karty (AFXC-…) napsané „česky" — automaticky se přeloží zpět jako u kódů zakázek.',
            'Do Návodů (CRM) přibyl návod „Věrnostní karta klienta — sken na recepci" s celým postupem.',
        ],
    ],
    [
        'version' => '1.11.3',
        'date' => '2026-07-17',
        'time' => '03:06',
        'title' => 'Oprava: stránka „Moje karta" v klientském portálu padala',
        'items' => [
            'Stránka s věrnostní kartou v klientském portálu používala špatnou cestu k přihlašovací kontrole a končila chybou serveru. Opraveno — karta se načte a nabídne přidání do Apple/Google Peněženky.',
        ],
    ],
    [
        'version' => '1.11.2',
        'date' => '2026-07-17',
        'time' => '02:59',
        'title' => 'Peněženky Apple a Google jsou naostro zapnuté',
        'items' => [
            'Klientská karta jde od teď přidat do Apple Wallet i Google Peněženky — v klientském portálu pod „Moje karta". Certifikáty a účty u Apple/Google jsou nastavené a ověřené.',
            'Šablona karty pro Google (AppleFix Klub) je nově spravovaná přímo v Google konzoli — generátor odkazu byl upraven, aby s ní nekolidoval.',
        ],
    ],
    [
        'version' => '1.11.1',
        'date' => '2026-07-17',
        'time' => '02:22',
        'title' => 'Sklad: jasnější naskladnění · Admin má pobočku Karlín',
        'items' => [
            'Tlačítko pro příjem nového zboží na stránce Sklad se nově jmenuje „Naskladnit nový díl" (dřív jen „Přidat díl") a na mobilu/iPadu se už neschovává mimo obraz. Otevře formulář s názvem dílu, počtem kusů a cenami; pro opakovaný příjem existujícího dílu slouží ikona náklaďáčku v řádku nebo sken QR na regálu.',
            'Účet administrátora má nově přiřazenou výchozí pobočku Karlín — zobrazuje se v seznamu zaměstnanců i při zakládání zakázky (dřív prázdná pomlčka). Na práva to nemá vliv: admin dál vidí a spravuje všechny pobočky.',
        ],
    ],
    [
        'version' => '1.11.0',
        'date' => '2026-07-17',
        'time' => '02:05',
        'title' => 'Novinka: Klientská věrnostní karta (Apple / Google Peněženka)',
        'items' => [
            'Každý klient dostane při zadání do systému vlastní věrnostní kartu s QR kódem — vygeneruje se automaticky, ať už zakládáš klienta se zakázkou, nebo jen samotného klienta.',
            'Klient si kartu přidá do Apple Peněženky nebo Google Peněženky (v klientském portálu tlačítko „Moje karta"). Karta ukazuje počet zařízení, která u nás měl/má, a nasbírané věrnostní body.',
            'Recepce: naskenováním QR z karty (firemní iPhone / čtečka) se okamžitě otevře klient i všechny jeho zakázky — bez ručního hledání. Sken funguje jak čtečkou do vyhledávacího pole, tak fotoaparátem.',
            'Věrnostní body: za každou vyzvednutou opravu se automaticky přičtou body (výchozí 20 bonus + 5 za každých 100 Kč z ceny). Interní zakázky se nepočítají. Vše nastavitelné.',
            'Nové nastavení: Nastavení → Věrnostní karta — zapnutí systému, výše bodů a nahrání certifikátů pro Apple / Google Peněženku.',
            'Pozn.: skutečné „pípnutí" NFC není běžným obchodníkům dostupné; QR sken je jeho okamžitá a spolehlivá náhrada.',
        ],
    ],
    [
        'version' => '1.10.5',
        'date' => '2026-07-16',
        'time' => '01:51',
        'title' => 'Oprava: nefunkční kontrola IMEI na nástěnce',
        'items' => [
            'Widget „Kontrola IMEI" na nástěnce byl kvůli chybě v kódu úplně nefunkční — tlačítko „Zkontrolovat" nedělalo nic (chyběl začátek skriptu, což shodilo celý blok). Odhaleno automatickým auditem tlačítek. Opraveno, ověření telefonu proti policejní databázi zase funguje.',
        ],
    ],
    [
        'version' => '1.10.4',
        'date' => '2026-07-16',
        'time' => '01:33',
        'title' => 'Reklamace: sloupec Zdroj/zakázka je prokliknutelný na zakázku',
        'items' => [
            'V seznamu reklamací je kód zakázky ve sloupci Zdroj/zakázka nově odkaz — klikem se otevře původní zakázka (nebo se vyhledá, pokud reklamace nemá přímou vazbu) a dohledáš k ní všechny detaily.',
        ],
    ],
    [
        'version' => '1.10.3',
        'date' => '2026-07-16',
        'time' => '01:29',
        'title' => 'Úklid matoucích dvojitých tlačítek v detailu zakázky',
        'items' => [
            'Odstraněno tlačítko „Dokončeno 100 % — připravit k převzetí" v panelu předání práce — dělalo přesně totéž co hlavní zelené tlačítko pod ním. Panel předání teď nabízí jen skutečně jinou akci: „Uvolnit dalšímu technikovi" (odebere tebe a vrátí zakázku do fronty).',
            'Odstraněno tlačítko „A4 faktura" v tiskovém boxu — otevíralo úplně stejný náhled jako velké tlačítko „Zobrazit zakázkový list" nad ním. Tisk/e-mail zakázkového listu, servisní příkaz a štítek zůstávají (každé je jiná funkce).',
            'Cíl: každé tlačítko dělá jednu jasnou věc, nic se neopakuje pod jiným názvem.',
        ],
    ],
    [
        'version' => '1.10.2',
        'date' => '2026-07-16',
        'time' => '19:59',
        'title' => 'Kompletní jazykové verze: čeština, angličtina, ruština 100%',
        'items' => [
            'Angličtina byla z velké části jen automaticky odvozená z názvů klíčů — doplněno 484 skutečných překladů (celé CRM: zakázky, sklad, nákupy, reklamace, faktury, nastavení, aktualizace…).',
            'Ruština: doplněno 19 chybějících textů (skenování QR, katalog dílů, štítky) — Roman a Mark už neuvidí české texty v ruském rozhraní.',
            'Čeština: doplněn poslední chybějící text v Nákupech.',
            'Ověřeno strojově: všech 800 textů používaných v systému má překlad ve všech třech jazycích (CS/EN/RU); ukrajinština klientů se mapuje na angličtinu jako dosud.',
        ],
    ],
    [
        'version' => '1.10.1',
        'date' => '2026-07-16',
        'time' => '19:11',
        'title' => 'Oprava časů v Historii úprav',
        'items' => [
            'Časy u záznamů v Historii úprav byly psané ručně a neodpovídaly realitě (např. 23:59 u verze vydané v 18:16). Všech 41 záznamů srovnáno podle skutečných časů vydání (z gitu).',
        ],
    ],
    [
        'version' => '1.10.0',
        'date' => '2026-07-16',
        'time' => '18:16',
        'title' => 'Velká oprava pro iPhony a iPady: scrollování a dostupnost tlačítek',
        'items' => [
            'ZAMRZLÉ SCROLLOVÁNÍ: po návratu „zpět" gestem na iOS se stránka obnovovala se zamčeným scrollem (otevřený modal/menu v mezipaměti) — nově se zámek automaticky odemkne. Douklizeno i po zavření oken (dřív občas zůstal neviditelný zámek).',
            'UŘÍZNUTÁ TABULKA ZAKÁZEK: na telefonech nebyly vidět sloupce Stav/Priorita/Částka/Akce a nešlo k nim doscrollovat — tabulka teď na mobilech vodorovně scrolluje (na počítači beze změny).',
            'MOBILNÍ MENU: tlačítko Odhlásit přetékalo mimo displej (nešlo se odhlásit!), menu nešlo posouvat na menších telefonech — opraveno, včetně odsazení pro dynamic island a home indicator (viewport-fit=cover + safe-area).',
            'iPADY: horní menu přetékalo a poslední položky (Nastavení) byly useknuté — položky se zúžily a menu umí bezpečně scrollovat.',
            'PŘIBLIŽOVÁNÍ NA iOS: formulářová pole měla malé písmo, iPhone při ťuknutí zazoomoval a stránka „ujela" — na dotykových šířkách je písmo polí 16px (iOS už nezoomuje). UI zoom z počítače se na mobilech nově neaplikuje (rozbíjel pevné lišty).',
            'Chat, náhledy dokumentů, podpisový pad, přihlašovací stránka a všechny modaly: výšky přepočítány na skutečně viditelný viewport (dvh) — vstupy a tlačítka dole už nemizí pod lištami; každý modal jde na telefonu odscrollovat k tlačítkům.',
            'Drobnosti: stránkování zakázek se zalamuje, výběr období v Přehledech se vejde na displej, tabulka dílů v detailu zakázky scrolluje.',
        ],
    ],
    [
        'version' => '1.9.7',
        'date' => '2026-07-16',
        'time' => '17:56',
        'title' => 'Návody: doplněno 8 chybějících témat (celkem 22)',
        'items' => [
            'Do záložky CRM přibyly návody: Reklamace, Podpis klienta (příjem/výdej + podpisová stanice), Tisk dokumentů a štítku zakázky, Objednávky z webu, Přehledy a statistiky, Kontrola IMEI, Nákupní seznam a Klientský portál (co říct klientovi).',
            'Sekce Návody teď pokrývá všechny hlavní funkce CRM — 22 postupů krok za krokem.',
        ],
    ],
    [
        'version' => '1.9.6',
        'date' => '2026-07-16',
        'time' => '17:50',
        'title' => 'Zakázky: horní karty správně — jedna řada, bez duplicit',
        'items' => [
            'Odstraněn omylem naklonovaný blok šesti dlaždic z nástěnky (duplikoval čísla, která už na stránce byla).',
            'Nepřidělené a Nedokončené zakázky jsou nově PÁTÁ a ŠESTÁ karta ve stávající horní řadě (Nové, Čeká na zákazníka, Rozdělané, Hotové…) — stejný styl, jedna řada pohromadě bez mezery.',
            'Čísla se počítají stejně jako na nástěnce: vedení vidí obě pobočky, zaměstnanci svou.',
        ],
    ],
    [
        'version' => '1.9.5',
        'date' => '2026-07-16',
        'time' => '17:46',
        'title' => 'Oprava: „neplatný bezpečnostní token" ve staré otevřené záložce',
        'items' => [
            'Když počítač/iPad usnul a přihlášení mezitím vypršelo (4 h neaktivity), stránka vypadala živá, ale akce (nový klient, uložení…) padaly na „neplatný bezpečnostní token" — přesně Tomášův případ.',
            'Nově: CRM pozná vypršelé přihlášení a ukáže nahoře oranžový pruh „Přihlášení vypršelo" s tlačítkem Přihlásit znovu — žádné záhadné chyby.',
            'Dlouho otevřené (aktivní) záložky si navíc bezpečnostní token průběžně samy obnovují, takže na něj nenarazí vůbec.',
        ],
    ],
    [
        'version' => '1.9.4',
        'date' => '2026-07-16',
        'time' => '17:41',
        'title' => 'Aktualizace CRM nově i pro manažera a Bosse',
        'items' => [
            'Záložka Nastavení → Aktualizace je nově dostupná celému vedení (admin, manažer, Boss) — včetně kontroly aktualizací, instalace a diagnostiky serveru.',
            'Bezpečnost: kontrola aktualizací dřív neměla žádné ověření přihlášení — nově vyžaduje přihlášené vedení.',
            'Návod „Kdo co smí" aktualizován: mazání zakázek a nastavení systému = jen admin; faktury = admin a Boss; aktualizace = vedení.',
        ],
    ],
    [
        'version' => '1.9.3',
        'date' => '2026-07-16',
        'time' => '17:36',
        'title' => 'Kompaktnější sloupec Stav v seznamech zakázek',
        'items' => [
            'Buňky ve sloupci Stav (nástěnka i Zakázky) jsou menší: drobnější stavový štítek, menší čip objednaného času, těsnější řádky s časem/technikem a menší štítek pobočky — řádky zaberou méně místa a na obrazovku se toho vejde víc.',
        ],
    ],
    [
        'version' => '1.9.2',
        'date' => '2026-07-16',
        'time' => '17:32',
        'title' => 'Faktury a účetnictví nově i pro Bosse',
        'items' => [
            'Boss má nově přístup k fakturám a účetnictví: stránka Účetnictví, tlačítko účtování v seznamu zakázek, vystavení a úprava faktury v detailu zakázky.',
            'Mazání zakázek, nastavení systému a aktualizace zůstávají jen administrátorovi.',
            'Upravena i poslední věta v návodu „Kdo co smí" — odpovídá novému stavu.',
        ],
    ],
    [
        'version' => '1.9.1',
        'date' => '2026-07-16',
        'time' => '17:29',
        'title' => 'Oprava: rozbitá ikonka u času administrátora ve Statistikách',
        'items' => [
            'Ve Statistikách zaměstnanců se u řádku administrátora (sloupec V systému) zobrazoval prázdný čtvereček — ikona „laptop s kódem" se v Safari nevykreslila. Nahrazena hodinami, stejnými jako u brigádníků (významově sedí: odměna z času).',
        ],
    ],
    [
        'version' => '1.9.0',
        'date' => '2026-07-16',
        'time' => '17:24',
        'title' => 'Nová sekce Návody (CRM + Opravy)',
        'items' => [
            'V horním menu je nová stránka Návody — jednoduché postupy krok za krokem, s vyhledáváním a záložkami CRM / Opravy.',
            'Záložka CRM obsahuje 14 návodů: přidání zakázky, interní zakázka, nový klient, úprava klienta, změna stavu, přiřazení technika, naskladnění, výdej dílu QR skenem, přidání dílu z počítače, tisk QR štítků, Nákupy, chat, Historie a přehled rolí a poboček.',
            'U každého návodu jsou barevně odlišené rámečky s podmínkami: modré = obsahová pravidla polí, oranžové = na co si dát pozor, fialové = kdo akci smí (role/pobočka).',
            'Záložka Opravy je připravená na servisní postupy — budeme ji plnit postupně.',
        ],
    ],
    [
        'version' => '1.8.2',
        'date' => '2026-07-16',
        'time' => '14:56',
        'title' => 'Zakázky: horní dlaždice ve stejném rozvržení jako na Nástěnce',
        'items' => [
            'Stránka Zakázky má nahoře stejnou sadu i rozvržení dlaždic jako Nástěnka (3×2): Aktivní zakázky, Čeká na díly, Opraveno dnes, Denní tržba, Nepřidělené a Nedokončené zakázky.',
            'Dlaždice jsou nově jedna sdílená šablona pro obě stránky — nemohou se už nikdy rozjet (změna se propíše všude).',
        ],
    ],
    [
        'version' => '1.8.1',
        'date' => '2026-07-16',
        'time' => '14:45',
        'title' => 'Sklad: dotažení QR systému po kontrolní revizi (přesnost zásob)',
        'items' => [
            'Tři nezávislí revizoři prověřili QR sklad — nálezy opraveny, skladová čísla teď sedí ve všech kombinacích akcí:',
            'Úprava počtu kusů u dílu na zakázce správně dorovná sklad i u QR-vydaných dílů; přidání dílu na už dokončenou zakázku sklad rovnou odečte (dřív se v těchto situacích mohly tvořit „fantomové" kusy).',
            'Vrácení stavu nákupu z „přijato" zpět kusy zase odečte (dřív každé kolečko přijato↔objednáno přičítalo zásobu znovu). Smazání celé zakázky vrací QR-vydané kusy na sklad.',
            'Připravená zakázka („Vzít díl skenem QR") se nově pamatuje NA UŽIVATELE, ne na prohlížeč — klik na počítači a sken telefonem už funguje dohromady. Na kartě dílu jde připravená zakázka zrušit křížkem.',
            'Sken QR odhlášeným telefonem po přihlášení vrátí rovnou na kartu dílu (neztratí cíl). Výběr zakázky u výdeje má nově rychlé hledání a nabízí 50 posledních aktivních.',
            'Bezpečnost: výdej hlídá pobočku zakázky a blokuje vydané i stornované zakázky; současné naskladnění dvěma lidmi už neztratí kusy (atomické přičítání).',
        ],
    ],
    [
        'version' => '1.8.0',
        'date' => '2026-07-16',
        'time' => '14:26',
        'title' => 'Sklad přes QR kódy: naskladnění i výdej dílů mobilem',
        'items' => [
            'Každý díl má QR štítek na regál (Sklad → tlačítko QR u dílu, nebo „QR štítky" pro tisk archu všech dílů najednou). Sken telefonem otevře kartu dílu.',
            'NASKLADNĚNÍ: naskenuj QR na regálu, zadej počet přijatých kusů, hotovo — sklad se ihned navýší. Rychlé naskladnění jde i z počítače (ikona kamionu u dílu).',
            'VÝDEJ NA ZAKÁZKU: technik u zakázky klikne „Vzít díl skenem QR", dojde ke skladu, naskenuje QR dílu, zadá počet — díl se přidá k zakázce i s cenou a sklad se OKAMŽITĚ odečte. Zakázka je předvybraná (platí 30 minut), jinak se vybere ze seznamu aktivních.',
            'Každý pohyb (příjem/výdej/korekce) se zapisuje do deníku pohybů — na kartě dílu je vidět kdo, kdy a kolik, výdeje s odkazem na zakázku. Vše jde i do Historie.',
            'Smazání QR-vydaného dílu ze zakázky kusy automaticky vrátí na sklad. Díly přidané postaru z počítače se dál odečítají při dokončení zakázky — nic se neodečte dvakrát.',
            'Skener v horní liště CRM umí regálové QR kódy číst také — není potřeba ani kamera telefonu.',
        ],
    ],
    [
        'version' => '1.7.6',
        'date' => '2026-07-16',
        'time' => '13:53',
        'title' => 'Zakázky: dlaždice Nepřidělené a Nedokončené i nahoře na seznamu',
        'items' => [
            'Dlaždice „Nepřidělené zakázky" a „Nedokončené zakázky" z nástěnky se nově zobrazují i nahoře na stránce Zakázky (nad filtry stavů).',
            'Stejná pravidla jako na nástěnce: vedení vidí součet obou poboček, řadoví zaměstnanci jen svou pobočku.',
        ],
    ],
    [
        'version' => '1.7.5',
        'date' => '2026-07-16',
        'time' => '13:50',
        'title' => 'Menu: Klienti a Nákupy prohozeny',
        'items' => [
            'V horním menu (i v mobilním menu) je nově pořadí … Reklamace, Klienti, Nákupy, Sklad … — Klienti a Nákupy si vyměnily místo.',
        ],
    ],
    [
        'version' => '1.7.4',
        'date' => '2026-07-16',
        'time' => '13:48',
        'title' => 'Dlaždice Nepřidělené/Nedokončené: vedení vidí obě pobočky',
        'items' => [
            'Upřesnění pravidla z 1.7.2: administrátoři a Boss (vedení) vidí v dlaždicích „Nepřidělené zakázky" a „Nedokončené zakázky" součet OBOU poboček (s popiskem „Obě pobočky").',
            'Řadoví zaměstnanci vidí dál jen čísla své pobočky — pobočky si tyto údaje navzájem nevidí.',
        ],
    ],
    [
        'version' => '1.7.3',
        'date' => '2026-07-16',
        'time' => '13:08',
        'title' => 'Interní zakázky: vlastní profil, tlačítko v průvodci a odlišení v seznamech',
        'items' => [
            'Duplicitní klient „Interní" smazán (žádné zakázky u něj nebyly) a hlavní profil upraven na „Interní zakázka — AppleFix interní evidence". Nemá telefon ani e-mail, takže mu neodejde žádná klientská notifikace a nejde na něj přihlásit do klientského portálu.',
            'V průvodci novou zakázkou je v kroku Klient nové tlačítko „Interní zakázka" — jedním klikem vybere interní profil a předvyplní PIN, takže průvodce projde bez zádrhelů až k Dokončit.',
            'Interní zakázky se v seznamu zakázek i na nástěnce odlišují fialovým štítkem „INTERNÍ" a barevným proužkem řádku — na první pohled se nepletou s běžnými klientskými zakázkami. Štítek je i v detailu zakázky.',
        ],
    ],
    [
        'version' => '1.7.2',
        'date' => '2026-07-16',
        'time' => '12:55',
        'title' => 'Nástěnka: dlaždice „Nepřidělené zakázky" a „Nedokončené zakázky"',
        'items' => [
            'Nahoře na nástěnce přibyly mezi statistiky dvě dlaždice: „Nepřidělené zakázky" (aktivní zakázky bez technika) a „Nedokončené zakázky" (všechny rozpracované) — s názvem pobočky, ke které se čísla vztahují.',
            'Obě dlaždice počítají VŽDY jen pobočku přihlášeného — Karlín vidí karlínská čísla, Na Příkopě svoje. Údaje druhé pobočky tu nikdo nevidí (na rozdíl od ostatních statistik, kde Karlín vidí vše).',
            'Mřížka statistik přeskládána na 3×2, aby se šest dlaždic vešlo úhledně vedle přehledu poboček.',
        ],
    ],
    [
        'version' => '1.7.1',
        'date' => '2026-07-16',
        'time' => '12:34',
        'title' => 'Oprava: „Nejnovější verze" ukazovala tvoji verzi místo té z GitHubu',
        'items' => [
            'Karta Nejnovější verze a hláška „Aktualizace je k dispozici" ukazovaly nesmysl typu „v1.6.6 → v1.6.6". Číslo nové verze se z GitHubu nenačetlo (čtení šlo mimo interní git vrstvu a na serveru selhávalo) a záložní logika ho chybně nahradila lokální verzí.',
            'Čtení vzdálené verze nově jde stejnou cestou jako ostatní git operace; když se přesto nepovede, ukáže se poctivě označení commitu — nikdy tvoje vlastní verze.',
        ],
    ],
    [
        'version' => '1.7.0',
        'date' => '2026-07-16',
        'time' => '12:30',
        'title' => 'Historie pro všechny + asymetrická viditelnost tržeb podle pobočky',
        'items' => [
            'Záložku Historie nově vidí VŠICHNI zaměstnanci — s výjimkou techniků vedlejších poboček (Roman a Mark z Na Příkopě ji nevidí a stránka je pro ně zamčená).',
            'Tržby a data poboček: zaměstnanci hlavní pobočky (Karlín) nově vidí tržby, zakázky a statistiky OBOU poboček. Roman a Mark vidí jen svoji pobočku (Na Příkopě) — na tržby Karlína nedosáhnou.',
            'Pravidlo je vázané na pobočku, ne na jména — noví zaměstnanci Karlína dostanou plný výhled automaticky, noví lidé na vedlejších pobočkách jen svou pobočku.',
        ],
    ],
    [
        'version' => '1.6.9',
        'date' => '2026-07-16',
        'time' => '12:26',
        'title' => 'Nástěnka a Seznam zakázek: sjednocený vzhled i údaje pro všechny',
        'items' => [
            'Tabulka zakázek na nástěnce má nově úplně stejné sloupce, pořadí i obsah buněk jako Seznam zakázek — admin i technici vidí všude totéž (rozdíl vznikal tím, že nástěnka měla vlastní chudší tabulku).',
            'Sloupec č. zakázky na nástěnce nově ukazuje i přesný čas vytvoření a kdo zakázku založil; u klienta přibyl e-mail; problém se zobrazuje celý (se zkrácením jako v seznamu).',
            'Samostatný sloupec Technik na nástěnce nahrazen údajem v buňce Stav (stejně jako v seznamu) a přibyl sloupec Priorita.',
            'Jediné záměrné výjimky podle role (stejné na obou stránkách): interní náklady navíc vidí jen admin a štítek pobočky jen vedení.',
        ],
    ],
    [
        'version' => '1.6.8',
        'date' => '2026-07-16',
        'time' => '12:19',
        'title' => 'Khalil: jediná hláška „Less talking, more working" každých 15 minut',
        'items' => [
            'Náhodné ambientní hlášky pro Khalila odstraněny — zůstává jediná: „Khalil! Less talking, more working.", opakuje se každých 15 minut.',
            'Stejná hláška nahradila i uvítací zvuk při přihlášení (vyměněno přímo na serveru, funguje hned).',
        ],
    ],
    [
        'version' => '1.6.7',
        'date' => '2026-07-16',
        'time' => '12:09',
        'title' => 'KRITICKÁ oprava: „Order creation failed" při dokončení zakázky',
        'items' => [
            'Vytvoření zakázky s expresním příplatkem / slevou dle priority nebo s opravou vybranou z ceníku končilo bílou stránkou „Order creation failed" — a matoucí je, že zakázka se PŘITOM většinou vytvořila (hrozily duplicity při opakování).',
            'Příčina: zápis rozpisu ceny si uvnitř databázové transakce ověřoval existenci tabulky příkazem, který v MariaDB transakci potichu ukončí — závěrečné potvrzení pak spadlo. Oprava: ověření tabulek se dělá VŽDY před transakcí (rozpis ceny, webové rezervace, log přiřazení).',
            'Pojistka: od potvrzení zápisu už chybu nemůže způsobit ani audit, ani notifikace (Telegram/SMS/e-mail) — úspěšně vytvořená zakázka vždy korektně přesměruje, úspěšná změna stavu vždy vrátí úspěch. Selhání notifikací se jen zaloguje.',
            'Zjištěno reprodukcí přímo na serveru; potvrzeno nezávislou revizí (2 agenti). Zkontrolujte prosím, zda dnes nevznikly duplicitní zakázky opakovanými pokusy.',
        ],
    ],
    [
        'version' => '1.6.6',
        'date' => '2026-07-15',
        'time' => '15:30',
        'title' => 'Pojistka: skryté povinné pole už nikdy tiše nezablokuje odeslání formuláře',
        'items' => [
            'Kontrolní revize (3 nezávislí recenzenti) potvrdila opravu 1.6.5 a doporučila obecnou pojistku: při odesílání formuláře v jakémkoliv okně se neviditelná prázdná povinná pole (sbalené panely, skryté kroky průvodce) automaticky přeskočí — prohlížeč na nich neumí zobrazit chybu a formulář by se jinak bez reakce neodeslal.',
            'Viditelná povinná pole se validují dál normálně; vyplněná skrytá pole zůstávají beze změny. Ověřeno testem: formulář s dřívější chybou se s pojistkou odešle správně.',
            'Zajímavost z revize: na starších iPadech chyba „Dokončit nic nedělá" nebyla — starší Safari validaci obchází. Proto se zdálo, že dřív vše fungovalo.',
        ],
    ],
    [
        'version' => '1.6.5',
        'date' => '2026-07-15',
        'time' => '15:20',
        'title' => 'Oprava: tlačítko „Dokončit" u nové zakázky nic nedělalo',
        'items' => [
            'Při vytváření zakázky s existujícím klientem se po kliknutí na „Dokončit" nic nestalo. Příčina: e-mail ve sbaleném panelu „Nový klient" byl označen jako povinné pole formuláře — prázdné skryté povinné pole potichu blokovalo odeslání celého formuláře.',
            'Povinnost e-mailu při zakládání NOVÉHO klienta zůstává (hlídá ji tlačítko uložení klienta) — jen už neblokuje zakázky s existujícím klientem.',
            'Prověřeno, že stejná past není nikde jinde v aplikaci (žádné další povinné pole ve sbalených panelech).',
        ],
    ],
    [
        'version' => '1.6.4',
        'date' => '2026-07-15',
        'time' => '15:14',
        'title' => 'Oprava: rozbalovací nabídky (Model zařízení aj.) se na iPadu hned zavíraly',
        'items' => [
            'Při vytváření zakázky se po ťuknutí do pole „Model zařízení" (a dalších vyhledávacích polí) nabídka otevřela a okamžitě zase zavřela — dotykové zařízení vyhodnotilo jeden tap dvakrát. Nově se zavření nabídky během první chvilky po otevření ignoruje, takže zůstane otevřená.',
            'Zároveň opraveno, že vyhledávací políčko v rozbalené nabídce nedostalo kurzor (známá chyba kombinace jQuery 3.6 + select2) — na iPadu se teď správně otevře klávesnice a dá se hned psát.',
            'Oprava platí pro všechny vyhledávací rozbalovací nabídky v celém CRM (model, značka, klient, díly ze skladu…).',
        ],
    ],
    [
        'version' => '1.6.3',
        'date' => '2026-07-15',
        'time' => '14:43',
        'title' => 'Oprava: čísla verzí v Aktualizacích se ukazovala rozbitě',
        'items' => [
            'Po kliknutí na „Zkontrolovat aktualizace" se místo čísla verze ukazoval git štítek („vmain @ 1d2cca1"). API totiž do políček s verzí posílalo popis commitů místo čísla ze souboru VERSION.',
            'Nově se VŠUDE konzistentně ukazuje „Verze 1.6.3" (aktuální i nejnovější) — technický údaj o commitu zůstává jen jako doplňkové „Sestavení".',
        ],
    ],
    [
        'version' => '1.6.2',
        'date' => '2026-07-15',
        'time' => '14:38',
        'title' => 'Úklid: indikátor verze už nehlásí „dirty"',
        'items' => [
            'Pomocný skript zálohování (scripts/full_backup.sh), který si server vytváří sám, se nově ignoruje v gitu — stav verze v Aktualizacích už kvůli němu nehlásí „dirty".',
        ],
    ],
    [
        'version' => '1.6.1',
        'date' => '2026-07-15',
        'time' => '14:28',
        'title' => 'Dotažení volnosti techniků (nálezy z kontrolní revize 1.6.0)',
        'items' => [
            'Rychlá změna stavu (blesk) v seznamu zakázek je nově dostupná každému zaměstnanci i u cizích a nepřiřazených zakázek — dřív ji technik viděl jen u svých.',
            'Zakázka už se přiřazením technika NEPŘESOUVÁ na jeho pobočku — zůstává na pobočce, kde je zařízení. (Jinak by si technik přiřazením kolegy z jiné pobočky zakázku sám schoval — zmizela by mu ze seznamu i z detailu.)',
            'Sjednocen i druhý ukládací endpoint (úprava zakázky): odstraněno omezení „technik si smí zakázku jen převzít", které by po 1.6.0 blokovalo to, co UI nabízí.',
            'V detailu zakázky jde technik nově i ODEBRAT (volba „— bez technika —").',
            'Opraveno skryté PHP varování v detailu zakázky (proměnná použitá před definicí) — vedoucím se díky tomu nezobrazovala tlačítka předání práce; teď se zobrazují správně.',
            'Přesnější chybové hlášky při výběru neplatného technika („neexistuje nebo není aktivní" místo matoucí zmínky o pobočce).',
        ],
    ],
    [
        'version' => '1.6.0',
        'date' => '2026-07-15',
        'time' => '14:14',
        'title' => 'Technici: volný výběr technika, volná změna stavu, chat pro všechny',
        'items' => [
            'Při vytváření zakázky si nově KAŽDÝ zaměstnanec (i technik) vybere kteréhokoliv aktivního technika, nebo nechá zakázku bez technika — dřív technik viděl jen kolegy své pobočky a výběr ho blokoval.',
            'Změna stavu zakázky (přijato / v opravě / připraveno / vydáno) je povolena každému přihlášenému zaměstnanci — odstraněna pobočková brána i omezení „technik smí jen převzít nepřiřazenou zakázku", které hlásily „Přístup odepřen".',
            'Technika u zakázky (v detailu i v rychlé editaci ze seznamu) smí změnit každý zaměstnanec; nabídka vždy obsahuje všechny aktivní techniky.',
            'Sekce Chat je nově v menu vidět pro VŠECHNY zaměstnance (dřív omylem jen pro vedení — technici ji neměli).',
            'Nepřečtená zpráva v chatu: ikona Chat v menu dýmá bílým světélkováním, dokud si chat nezobrazíš — pak zhasne.',
        ],
    ],
    [
        'version' => '1.5.0',
        'date' => '2026-07-15',
        'time' => '13:38',
        'title' => 'Nová role Brigádník (Andrea)',
        'items' => [
            'Ve správě personálu je nová role „Brigádník" — stejná práva jako technik, ale odměna se počítá z přihlášeného času v systému (hodiny × sazba), ne ze zakázek. Odměna z času se u této role zapíná automaticky a nejde omylem vypnout.',
            'Brigádník má vlastní zelený štítek s hodinami ve správě personálu, ve statistikách a v hlavičce po přihlášení (už se nezobrazuje jako „Technik").',
            'Andrea je převedena na roli Brigádník (150 Kč/h, pobočka Karlín).',
        ],
    ],
    [
        'version' => '1.4.2',
        'date' => '2026-07-15',
        'time' => '13:22',
        'title' => 'Oprava: technik „uvězněný" na staré pobočce (Přístup odepřen + špatný výběr technika)',
        'items' => [
            'Skutečná příčina hlášení „Přístup odepřen" u karlínských zakázek a toho, že při zakládání zakázky nešel vybrat správný technik: systém si pamatoval pobočku technika jen z okamžiku přihlášení a už ji nikdy neobnovil. Když se technik mezi pobočkami přeřadil (nebo se pobočky reorganizovaly), zůstal „uvězněný" na staré pobočce — nemohl měnit stav zakázek své skutečné pobočky a při zakládání zakázky se mu nabízeli technici cizí (nebo žádní).',
            'Nově se pobočka technika bere vždy z databáze (aktuální stav), ne ze zapamatované hodnoty z přihlášení. Opraví se to samo při dalším načtení stránky — technik se nemusí odhlašovat.',
            'Týká se to jak změny stavu zakázky (přijato/v opravě/připraveno/vydáno), tak výběru technika při zakládání nové zakázky.',
        ],
    ],
    [
        'version' => '1.4.1',
        'date' => '2026-07-15',
        'time' => '12:49',
        'title' => 'Zpřístupnění zakázek bez pobočky + zabezpečení fakturačního API',
        'items' => [
            'Zakázka, která nemá přiřazenou pobočku, nově patří „všem" — smí ji obsloužit kterýkoliv přihlášený zaměstnanec, ne jen administrátor / Boss. Pobočková izolace u zakázek, které pobočku mají, zůstává beze změny. (Pojistka do budoucna; aktuálně jsou všechny zakázky s pobočkou.)',
            'Jednorázové srovnání dat: případným zakázkám bez pobočky se doplní pobočka podle přiřazeného technika (u nepřiřazených hlavní pobočka Praha 8 – Karlín).',
            'Zabezpečení: API s fakturačními údaji (jméno, IČO/DIČ, ceny) je nově výslovně jen pro administrátory — stejně, jako už bylo skryté tlačítko účtování v seznamu zakázek.',
        ],
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-07-15',
        'time' => '11:31',
        'title' => 'SMS klientům přes GoSMS (připraveno k vyzvednutí)',
        'items' => [
            'CRM umí posílat klientům SMS přes bránu GoSMS.cz. První automatická SMS: „zakázka připravena k vyzvednutí" — odchází spolu s e-mailem, v jazyce klienta (čeština/angličtina), každé zakázce jen jednou, bez diakritiky (úspora ceny SMS).',
            'Nastavení → Integrace → sekce „SMS brána GoSMS.cz": Client ID, Client Secret, číslo kanálu, přepínač zapnutí a tlačítko „Poslat testovací SMS".',
            'Každá odeslaná SMS se zapisuje do Historie. Dokud nejsou vyplněné klíče, nic se neposílá a nic se nerozbije.',
        ],
    ],
    [
        'version' => '1.3.1',
        'date' => '2026-07-15',
        'time' => '10:22',
        'title' => 'Chat: jméno odesílatele pod každou bublinou',
        'items' => [
            'Pod každou zprávou v týmovém chatu je nově jméno odesílatele a čas (dřív jméno chybělo, resp. bylo jen nad cizími zprávami).',
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-07-15',
        'time' => '09:56',
        'title' => 'Nový týmový chat (nahrazuje Fixer Chat)',
        'items' => [
            'V menu je nová sekce Chat — jedna společná místnost, kde si píší všichni zaměstnanci mezi sebou. Jednoduché bubliny, oddělovače dnů, odesílání Enterem.',
            'Nová zpráva se hlasitě ohlásí výrazným zvukem KDEKOLIV v CRM (ne jen na stránce chatu) a na ikoně Chat v menu svítí počet nepřečtených zpráv. Vlastní zprávy pochopitelně nezvoní.',
            'Původní sekce Fixer Chat (Telegram) byla odstraněna.',
        ],
    ],
    [
        'version' => '1.2.2',
        'date' => '2026-07-15',
        'time' => '07:03',
        'title' => 'Klientský portál: odebrána účtenka, zůstává podepsaný zakázkový list',
        'items' => [
            'Z klientského portálu zmizela online „Účtenka" — klient má u zakázky zakázkový list (ten, který podepisuje), případně fakturu a reklamační protokol, pokud existují.',
            'Ověřeno: zakázkový list se klientovi zobrazuje VČETNĚ elektronického podpisu (obrázek podpisu + „Podepsáno elektronicky" s datem a časem) — jak u převzetí do opravy, tak u výdeje.',
        ],
    ],
    [
        'version' => '1.2.1',
        'date' => '2026-07-15',
        'time' => '06:48',
        'title' => 'Odstraněn tisk na termotiskárnu (nepoužívá se)',
        'items' => [
            'Z tiskového menu zmizely termo doklady (účtenka termo, příjemka termo, faktura termo) — nepoužíváte je. Odstraněno z detailu zakázky, seznamu zakázek i účetnictví.',
            'Tisk štítků na Brother QL-810W a A4 doklady (zakázkový list, dílenský list, faktura) zůstávají beze změny.',
            'Klientská online „Účtenka" v portálu funguje dál — je to jen zobrazení na obrazovce, ne tisk.',
        ],
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-07-15',
        'time' => '06:29',
        'title' => 'Klientská sekce na vlastní doméně applefix.help',
        'items' => [
            'Klientský portál běží nově i na applefix.help — na této doméně se přihlašují jen klienti (e-mail/telefon + PIN zakázky), zaměstnanecké přihlášení je vypnuté a administrace není z této domény vůbec dosažitelná.',
            'admin.applefix.cloud zůstává beze změny — zaměstnanci i klienti se tam přihlašují jako dosud.',
            'Odkazy na klientský portál v e-mailech a na dokumentech půjdou na applefix.help teprve po ručním potvrzení (setting), aby se nic nerozbilo, dokud nová doména neběží naostro.',
        ],
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-07-15',
        'time' => '04:38',
        'title' => 'Brigádníci: odměna z odpracovaného času místo zakázek',
        'items' => [
            'Na kartě zaměstnance je nový přepínač „Odměna z času v systému (brigádník)" — zaměstnanci se pak počítají hodiny strávené v CRM × jeho sazba, ne zakázky. Přepínat smí jen administrátor.',
            'Ve statistikách má takový zaměstnanec u času v systému ikonu hodin a odměna se počítá z něj.',
            'Andrea (brigádnice, 150 Kč/h) je takto rovnou nastavená.',
        ],
    ],
    [
        'version' => '1.0.2',
        'date' => '2026-07-15',
        'time' => '04:26',
        'title' => 'Oprava odměn: admin z času v systému (300/h), Boss ze zakázek',
        'items' => [
            'Administrátor má ve statistikách vlastní řádek — odměna se mu počítá z času stráveného v systému × 300 Kč/h (tvorba a správa CRM), bez potřeby zakázek.',
            'Tomáš (Boss) se odměňuje klasicky ze zakázek jako technici — sazba vrácena na 500 Kč/h. Khalil má nově 400 Kč/h.',
            'Sloupec „V systému" zůstává u všech informativní; z času v systému se odměňuje jen admin.',
        ],
    ],
    [
        'version' => '1.0.1',
        'date' => '2026-07-15',
        'time' => '04:22',
        'title' => 'Statistiky: admin/Boss v hlavní tabulce, bez duplicit, sazba 300 Kč/h',
        'items' => [
            'Zvláštní panel „Čas strávený v systému" zrušen — čas v systému je nově sloupec přímo v hlavní tabulce statistik zaměstnanců u každého.',
            'Tomáš Zahradník se už neukazuje dvakrát (admin + Boss) — aktivita z administrátorského účtu se počítá do jeho jediného řádku.',
            'Bossovi/adminovi se odměna počítá z času v systému × sazba (nastaveno 300 Kč/h) — i bez jediné přijaté zakázky. Technikům zůstává odměna ze zakázek, čas v systému mají informativně.',
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-07-15',
        'time' => '04:05',
        'title' => 'Zavedeno normální číslování verzí (1.0.0)',
        'items' => [
            'CRM má nově srozumitelné verze místo technických kódů: opravy a drobnosti zvyšují poslední číslo (1.0.1), nové funkce prostřední (1.1.0), zásadní přestavby první (2.0.0).',
            'Verze je vidět v Nastavení → Aktualizace (technický kód sestavení zůstává malým písmem pro servisní účely) a u každého záznamu v historii úprav.',
            'Dnešní stav systému je výchozí Verze 1.0.0.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '23:40',
        'title' => 'Nové pozadí: futuristické tečkové vlny (generované, ostré v každém rozlišení)',
        'items' => [
            'Pozadí CRM tvoří tmavě modrá plocha s tečkovými víry a vlnami v modro-azurovém přechodu (halftone particle efekt). Negeneruje se z obrázku — kreslí se živě přímo v prohlížeči přesně na míru okna, takže při JAKÉKOLIV změně rozlišení či měřítka se okamžitě překreslí a nikdy se neořeže ani nerozmaže.',
            'Kompozice je navržená pro práci: víry žijí v rozích, střed obrazovky (kde jsou tabulky a karty) zůstává klidný a tmavý kvůli čitelnosti.',
            'Odstraněno dřívější SVG pozadí, které se při změně měřítka ořezávalo. Světlý režim zůstává čistě světlý.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '22:40',
        'title' => 'Údaje klienta smí měnit i technik — s výrazným záznamem v Historii',
        'items' => [
            'Zámek „vyplněné údaje klienta mění jen administrátor" je zrušen: jméno, příjmení, telefon i e-mail klienta nově změní každý zaměstnanec, stejně tak klienta u zakázky.',
            'Každý přepis vyplněného údaje se ale v Historii výrazně zvýrazní (oranžový řádek s perem) jako „RUČNĚ ZMĚNĚNY údaje klienta" / „RUČNĚ ZMĚNĚN klient zakázky" — vždy s původní i novou hodnotou a kým. Zpětně tak jde dohledat každý zásah.',
            'Doplnění prázdného údaje (např. chybějící e-mail) se zvýrazněně neoznačuje — je to běžná práce.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '22:10',
        'title' => 'Statistiky: čas strávený v systému + odměna pro Bosse/adminy',
        'items' => [
            'CRM nově automaticky měří aktivní čas každého zaměstnance v systému (pauzy se nepočítají). V Přehledech → Statistiky zaměstnanců je nový panel „Čas strávený v systému".',
            'Rolím Boss a Administrátor se z tohoto času počítá odměna (hodiny × sazba z karty zaměstnance) — jejich práce je správa a vývoj CRM, ne zakázky. Odměna běží i bez přijaté zakázky.',
            'Technici mají čas v systému jen informativně — jejich odměna se dál počítá ze zakázek (žádné dvojí započtení).',
            'Tip: nastav si hodinovou sazbu na kartě zaměstnance (Nastavení → Zaměstnanci → upravit → Odměna za hodinu), jinak se odměna počítá z nuly.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '21:45',
        'title' => 'Správa administrátorů ukazuje VŠECHNY adminy',
        'items' => [
            'Ve Správě administrátorů se dosud ukazovaly jen samostatné admin účty — chyběli zaměstnanci s rolí Admin (např. přihlašující se zaměstnaneckým účtem). Nově jsou v seznamu obě skupiny; zaměstnanecké admin účty mají označení „zaměstnanecký účet".',
            'U zaměstnaneckého admina lze admin práva odebrat (role se změní na Technik, účet zůstává) — s potvrzením, ne sám sobě, zapisuje se do Historie. Heslo a údaje se u nich dál spravují na kartě zaměstnance.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '21:25',
        'title' => 'Správa administrátorů: možnost odstranit administrátora',
        'items' => [
            'Ve Správě administrátorů lze nově odstranit administrátorský přístup (ikona koše). Výchozí administrátor „admin" je chráněný — u něj možnost odstranění není (záchranný účet). Vlastní účet si odstranit nejde (požádej druhého admina).',
            'Odstranění maže jen adminský přístup — účet zaměstnance (pokud existuje) zůstává. Akce se zapisuje do Historie.',
            'Oprava k tomu: potvrzovací dotazy u mazání (zaměstnanec, administrátor) se dosud vůbec nezobrazovaly — mazalo se na jeden klik. Nově se CRM vždy nejdřív zeptá.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '21:05',
        'title' => 'Zaměstnanec musí mít vybranou pobočku (povinné)',
        'items' => [
            'U zaměstnance je pobočka nově povinná — při vytváření i úpravě. Formulář bez vybrané pobočky nepustí uložení a server ověří, že jde o platnou aktivní pobočku (žádné tiché dosazení).',
            'Technik, který dosud pobočku neměl, se při nejbližší úpravě karty musí zařadit — nabídka ukáže „vyber pobočku (povinné)".',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '20:45',
        'title' => 'Globální oprava „pasti prvního vybraného" ve výběrových polích',
        'items' => [
            'Po záměně klientů proběhla důkladná kontrola VŠECH výběrových polí v CRM, jestli nemohou tiše přeskočit na první položku a při uložení přepsat data. Nalezeny a opraveny 4 případy:',
            'Úprava zakázky: značka zařízení mimo číselník (napsaná ručně) by se uložením tiše přepsala na první značku v abecedě — aktuální značka je teď vždy předvybraná.',
            'Karta zaměstnance: technik bez pobočky / se zrušenou pobočkou by se uložením tiše přeřadil na první pobočku A přesunuly by se i všechny jeho zakázky — opraveno (aktuální pobočka vždy v nabídce; hromadný přesun zakázek jen při skutečné změně pobočky).',
            'Nová zakázka i nový zaměstnanec: pobočka se předvybírá podle toho, kde uživatel je (dřív tiše první v seznamu).',
            'Ostatní výběrová pole (stavy, technik v rychlém náhledu, modely, sklad, faktury) kontrola potvrdila jako bezpečná.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '20:20',
        'title' => 'Stavová tlačítka v detailu zakázky: jasnější a pulzující',
        'items' => [
            'Tlačítka „Přesunout do práce" / „Označit jako hotové" / „Označit jako vydané" jsou nově nepřehlédnutelná — jasnější barva podle cílového stavu (jantarová/světle zelená/tmavě zelená), ikona a jemné dýchání (pulz záře).',
            'Stejné zvýraznění dostalo tlačítko „Dokončeno 100 % opravy" u předávání mezi techniky.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '20:05',
        'title' => 'Nová zakázka: povinné údaje klienta a PIN',
        'items' => [
            'Při zakládání zakázky jsou nově povinné: jméno a příjmení klienta, telefon a e-mail (u nového klienta) a PIN/heslo zařízení. Bez nich průvodce nepustí dál.',
            'PIN je vyžadován i na serveru — zakázku bez něj nejde založit ani obejitím formuláře.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '19:35',
        'title' => 'OPRAVA: nový klient v zakázce — technici + konec duplicit',
        'items' => [
            'Řadoví technici nemohli ve wizardu nové zakázky založit nového klienta (skryté právo „úprava klientů" jim to tiše blokovalo) — založení klienta je nově povoleno každému přihlášenému zaměstnanci. Ochrana zůstává: přepis vyplněných údajů smí jen administrátor, mazání jen s právem.',
            'Na stránce Zakázky se každé uložení nového klienta odesílalo DVAKRÁT (dva skryté obslužné skripty na jednom tlačítku) → vznikali duplicitní klienti. Opraveno — odesílá se jednou. Tohle je pravděpodobný původ dosavadních dubletů klientů.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '19:05',
        'title' => 'Nová zakázka: technik se už nepředvybírá automaticky',
        'items' => [
            'Při vytváření zakázky se do pole Technik automaticky předvyplňoval ten, kdo zakázku zakládal (typicky Tomáš) — zakázky mu tak nechtěně padaly. Pole je nově vždy prázdné.',
            'Zakázku lze založit bez technika — přidělí se později, nebo si ji technik vezme sám (to už fungovalo).',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '02:05',
        'title' => 'Po vydání zakázky: děkovný e-mail s žádostí o Google recenzi',
        'items' => [
            'Když se zakázka označí jako Vydáno, klientovi automaticky odejde elegantní děkovný e-mail (skleněný vzhled, zlaté hvězdy) s poděkováním za důvěru a zdvořilou prosbou o ohodnocení našich služeb.',
            'Tlačítko v e-mailu otevře klientovi přímo okno psaní recenze na Google — pobočka Křižíkova 29, Karlín (žádné hledání, jedno kliknutí).',
            'E-mail odchází v jazyce klienta (čeština/angličtina/ukrajinština), každé zakázce jen jednou, a neposílá se u interních/bezejmenných zakázek ani klientům bez e-mailu.',
            'Odkaz na recenzi lze změnit či vypnout v Nastavení → Firemní údaje (vymazáním pole se e-maily vypnou).',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '01:10',
        'title' => 'Automatické zálohy celého CRM každých 15 minut + obnova jedním kliknutím',
        'items' => [
            'CRM se nyní samo kompletně zálohuje každých 15 minut: celá databáze (zakázky, klienti, faktury, historie úprav…), nahrané soubory (fotky, podpisy, přílohy) i kód aplikace (ten jen při změně verze). Není potřeba nic nastavovat na serveru.',
            'Nová záložka Nastavení → Zálohy (jen administrátor): seznam záloh s časem, velikostí a obsahem (počet zakázek/klientů v záloze), tlačítko „Zálohovat teď" a u každé zálohy „Obnovit".',
            'Obnova vrátí databázi i soubory přesně do stavu zálohy — kdyby se cokoliv pokazilo, smazalo nebo přepsalo (jako včerejší záměna klientů), stačí se vrátit o pár minut zpět. Před každou obnovou se automaticky uloží pojistná kopie aktuálního stavu, takže ani obnovu nejde pokazit.',
            'Zálohy starší 48 hodin se mažou samy (vždy ale zůstává minimálně 10 posledních). Ukládají se mimo veřejně přístupný prostor webu; obnova se zapisuje do Historie.',
        ],
    ],
    [
        'date' => '2026-07-14',
        'time' => '00:25',
        'title' => 'Historie úprav pokrývá i faktury, sklad, nákupy, reklamace a podpisy',
        'items' => [
            'Po důkladné kontrole doplněno zaznamenávání dalších úkonů: faktury a dobropisy (vystavení/úprava/smazání/změna stavu vč. expresních), skladové díly (naskladnění/úprava/smazání), nákupní požadavky (vytvoření/objednání/naskladnění/přiřazení k zakázce/smazání), reklamace (vytvoření/změna stavu), podpisy klientů, díly na zakázce, uvolnění zakázky dalšímu technikovi, zpětná změna datumů zakázky, změny nastavení (u API klíčů a hesel se hodnoty nezaznamenávají) a aktualizace systému.',
            'Vypnuta stará skrytá stránka pro úpravu zakázky, která obcházela historii i ochranu klienta — přesměruje na běžný detail zakázky, kde všechny pojistky platí.',
            'Fotky HEIC (z iPhonu) jdou nově nahrát i při zakládání zakázky (dřív jen při dodatečném uploadu).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '23:55',
        'title' => 'Seznam zakázek: přesný čas vytvoření a kdo zakázku založil',
        'items' => [
            'V levém sloupci seznamu zakázek je pod datem nově i přesný čas vytvoření a pod ním malým písmem jméno zaměstnance, který zakázku založil (u webových objednávek „Web (applefix.cz)").',
            'Jméno tvůrce se ukládá od teď — starší zakázky ho nemají (zpětně ho nelze spolehlivě dohledat, tak raději nic než špatné jméno).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '23:40',
        'title' => 'Nová sekce Historie úprav (jen pro administrátora)',
        'items' => [
            'V horním menu přibyla položka „Historie" (vidí ji jen administrátor). Spolehlivě zaznamenává, kdo a kdy co v systému provedl — ve formátu čas — úkon — kdo — čeho se týká — detail.',
            'Zaznamenává se: přihlášení a odhlášení, vytvoření/úprava/změna stavu/smazání zakázky (i zakázky z webu), vytvoření/úprava/smazání klienta, vytvoření/úprava/smazání zaměstnance, změna oprávnění, povýšení na administrátora a změna hesla administrátora. Akce administrátorů se logují úplně stejně.',
            'Jméno toho, kdo akci provedl, se ukládá natvrdo — historie zůstane čitelná i kdyby se účet později smazal. Zápis do historie nikdy neshodí samotnou akci.',
            'Stránka umí filtrovat podle úkonu, jména, textu a data, se stránkováním. Slouží k dohledání, kde kdo co pokazil nebo zbytečně zasáhl.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '22:55',
        'title' => 'Výpis zaměstnanců ukazuje i administrátory (sjednotně se sekcí zvuků)',
        'items' => [
            'V Nastavení → Zaměstnanci se ve výpisu dřív ukazovali jen technici, kdežto níže u přiřazení uvítacích zvuků i administrátoři — proto tam bylo víc záznamů. Nově se administrátoři (účty jen v Administrátorech) zobrazí i v hlavním výpisu zaměstnanců, takže sedí.',
            'Seznam u přiřazení zvuků se navíc zbavil duplicit — kdo měl účet technika i administrátora se stejným přihlášením, počítal se dřív dvakrát.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '22:25',
        'title' => 'Přejmenování kategorie zakázek „K odsouhlasení" → „Čeká na zákazníka"',
        'items' => [
            'Horní kategorie (dlaždice a filtr) v Zakázkách se nově jmenuje „Čeká na zákazníka" místo „K odsouhlasení". Nově sedí i s názvem stavu u jednotlivých zakázek. Význam a chování zůstávají stejné.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '22:05',
        'title' => 'Vyhledávání klienta najde i celé jméno („Barbara Ozima")',
        'items' => [
            'Vyhledávání zákazníka dřív hledalo zadaný text jen v jednotlivých polích zvlášť — když jste napsal/vložil celé jméno „Křestní Příjmení", nenašlo nic, protože křestní jméno a příjmení jsou uložené odděleně.',
            'Nově se dotaz rozdělí na slova a najde klienta i podle celého jména (v obou pořadích), podle firmy i e-mailu. Zkopírované celé jméno tak funguje.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '21:40',
        'title' => 'Přiřazení klienta ke starší zakázce bez klienta',
        'items' => [
            'Když má zakázka jen zástupného/nevyplněného klienta („Neznámý" nebo prázdné jméno), lze k ní klienta přiřadit i bez administrátora — typicky u starší zakázky, kde se pravý klient vložil do systému až později. Pole Klient je pak v editaci odemčené.',
            'Změna skutečného, už vyplněného klienta zůstává jen pro administrátora (ochrana proti záměně).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '21:15',
        'title' => 'Admin: trvalé smazání zakázky přímo v seznamu',
        'items' => [
            'V seznamu zakázek má administrátor u každé zakázky vpravo červené tlačítko koše — zakázku lze nejen stornovat, ale i trvale odstranit (viditelné jen adminovi, u libovolného stavu).',
            'Mazání je nevratné a jen pro administrátora; nejdřív se zeptá na potvrzení. Smaže i všechny navázané položky, přílohy (včetně souborů), podpisy a historii; webová rezervace se jen odpojí (zůstane v evidenci).',
            'Pojistka: zakázku s vystavenou fakturou nebo navázanou reklamací nelze smazat — CRM na to upozorní, ať se nezničí účetní/reklamační data.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '20:40',
        'title' => 'Nová hodnost „Boss" (nad manažerem)',
        'items' => [
            'Přidána role Boss — má stejná práva jako manažer a navíc smí přiřadit jakéhokoliv technika k jakékoliv zakázce (i napříč pobočkami) a změnit technika i tehdy, když se sám technik z rozpracované zakázky neuvolnil. Tato práva má samozřejmě i administrátor.',
            'Roli Boss lze nastavit zaměstnanci v Nastavení → tým (výběr role), zobrazuje se žlutou visačkou.',
            'Tomáš Zahradník převeden z administrátora na hodnost Boss (přihlašuje se stejným jménem/heslem, nově jako Boss).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '20:10',
        'title' => 'Ochrana údajů klienta: vyplněné jméno/telefon/e-mail mění jen administrátor',
        'items' => [
            'Jednou vyplněné jméno, příjmení, telefon a e-mail klienta už NELZE přepsat běžným zaměstnancem — změnit je smí pouze administrátor. Prázdný údaj (nebo jen „-") smí kdokoli doplnit.',
            'V detailu zakázky: pole Klient je pro zaměstnance zamčené (výběr jiného klienta = admin). Klienta si vybírá při zakládání, dodatečná záměna je na administrátorovi.',
            'V úpravě klienta: vyplněné údaje jsou pro zaměstnance jen ke čtení (visačka zámku), prázdné jde doplnit. Pokus o změnu se neuloží a zobrazí se upozornění.',
            'Serverová i vizuální pojistka — společně s dnešní opravou tichého přepisu klienta to záměně klienta spolehlivě zabrání.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '19:30',
        'title' => 'OPRAVA CHYBY: editace zakázky mohla přepsat klienta na cizího',
        'items' => [
            'Našli jsme příčinu, proč se u některých zakázek „samo" přepsalo jméno klienta na jiného zákazníka: pole Klient v editaci nabízelo jen prvních 500 zákazníků (dle příjmení). Pokud klient zakázky do těch 500 nespadl, políčko se tiše přepnulo na úplně prvního zákazníka v seznamu a uložení editace ho zapsalo — proto se víc zakázek „slévalo" na jednoho a téhož klienta.',
            'Nově se klient v editaci vyhledává živě přes všechny zákazníky (ne jen 500) a aktuální klient zakázky je vždy správně předvyplněný — omylem už ho nejde přepsat.',
            'Přidána i serverová pojistka: klient zakázky se změní jen na skutečně existujícího zákazníka, jinak zůstane původní.',
            'Pozn.: zakázky, u kterých k záměně už došlo, je potřeba ručně vrátit na správného klienta (týká se např. zakázek APFAZ2600499, 2600500, 2600503).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '16:05',
        'title' => 'Oprava nahrávání fotek z počítače',
        'items' => [
            'Zvýšen limit velikosti nahrávaných souborů — server měl výchozí limit jen 2 MB na soubor, takže běžná fotka z počítače neprošla. Nově až 64 MB na soubor (80 MB na jedno odeslání).',
            'Přidána podpora fotek HEIC/HEIF (formát fotek z iPhonu) — dosud je upload odmítal.',
            'Když je soubor i tak příliš velký, CRM nově napíše srozumitelně „soubor je větší než limit serveru" místo obecné chyby.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '15:58',
        'title' => 'Oprava: zakázku šlo upravit až po vyplnění finální ceny',
        'items' => [
            'Úprava zakázky s prázdnou finální cenou (typicky zakázky z webu) padala na chybě databáze — prázdná cena se nově ukládá správně jako „nevyplněno".',
            'Totéž platí pro předběžnou cenu a vícenáklady; funguje i desetinná čárka (např. „1 250,50").',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '15:20',
        'title' => 'Zobrazit zakázkový list, rozbalené tiskové menu a odkaz na stanici',
        'items' => [
            'V detailu zakázky je nad tiskem nové tlačítko „Zobrazit zakázkový list" — dokument si prohlédneš bez tisknutí.',
            'Tiskové menu už není rozklikávací — všechny volby (list, tisk/e-mail, dílenský list, štítek, účtenka) jsou pořád viditelné pod nadpisem Tisk.',
            'V horní liště (ikona pera) a v mobilním menu je odkaz „Podpisová stanice" — na tabletu ji tak otevřeš bez psaní adresy.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '14:19',
        'title' => 'Pojistky proti podpisu cizí zakázky na tabletu',
        'items' => [
            'Když ve frontě čeká víc podpisů najednou (od různých zaměstnanců), tablet se nejdřív zeptá „Kdo jde podepsat?" — klient klepne na kartu se SVÝM jménem a zařízením, teprve pak se otevře jeho zakázkový list.',
            'Jméno klienta je nejvýraznějším prvkem každého kroku: na výběrové kartě, v hlavičce dokumentu i na podpisovém plátně („Podepisuje: Jan Novák"). U karty je vidět i kdo z personálu podpis poslal.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '14:16',
        'title' => 'Tablet ukazuje při podpisu celý zakázkový list',
        'items' => [
            'Když pošleš zakázku na podpisový tablet, klientovi se nejdřív zobrazí CELÝ zakázkový list (v jeho jazyce, s cenami i podmínkami) — může si ho přečíst a teprve pak klepne Podepsat. Po podpisu tablet ukáže potvrzení „Podepsáno — uloženo a odesláno na e-mail" a vrátí se do čekání.',
            'Podepsaná verze listu je automaticky všude: u zakázky, v tisku, v e-mailu i v klientské sekci na webu — klient tam vidí dokument s podpisem místo nepodepsaného.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '14:12',
        'title' => 'Nová zakázka → podpis na tabletu → podepsaný list e-mailem',
        'items' => [
            'Dialog po založení zakázky má novou hlavní volbu „Podepsat na tabletu a poslat e-mailem": požadavek odejde na podpisový tablet, klient podepíše a zakázkový list se mu automaticky pošle e-mailem UŽ S PODPISEM — žádný tisk, sken ani ruční posílání.',
            'Tablet je sdílený pro celou pobočku po Wi-Fi: požadavky z více počítačů se řadí do fronty a odbavují postupně — u pultu nevzniká fronta u jednoho PC.',
            'Když klient e-mail nemá, systém to rovnou řekne a podpis se aspoň uloží k zakázce (list s podpisem jde kdykoliv vytisknout).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '14:07',
        'title' => 'Tisk štítků nově přes server — bez můstku, z čehokoliv',
        'items' => [
            'Štítky na Brother QL-810W tiskne přímo server CRM — tlačítko „Tisk štítku" teď funguje z jakéhokoliv počítače, iPadu, telefonu i ze Safari. Žádná instalace můstku na počítačích už není potřeba.',
            'V Nastavení → Tisk štítků je nová sekce „Tisk přes server": stav spojení s tiskárnou, IP tiskárny a testovací štítek. Původní můstek zůstává jen jako záložní řešení.',
            'Ověřeno ostrým tiskem: server poslal testovací štítek na tiskárnu v Karlíně.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '11:58',
        'title' => 'Oprava tlačítka Odhlásit',
        'items' => [
            'Odhlášení po ranním prodloužení přihlášení nefungovalo (rušilo session ve starém úložišti, skutečné přihlášení přežilo). Nyní odhlášení maže správné přihlášení i cookie — funguje všem.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '11:51',
        'title' => 'Předávání zakázky mezi techniky + časy po technicích',
        'items' => [
            'Technik s rozpracovanou zakázkou má v detailu dvě volby: „Dokončeno 100 % — připravit k převzetí" (klasické dokončení), nebo „Uvolnit dalšímu technikovi" — když je hotová jen jeho specializovaná část.',
            'Uvolněná zakázka přejde do nového tyrkysového stavu „Čeká na technika" a je bez přiřazení — další technik si ji převezme sám (pole Technik) a pokračuje.',
            'Časy se počítají po technicích: uvolněním se čas prvnímu pozastaví, druhému začne běžet převzetím do opravy. V detailu zakázky je nový přehled „Čas techniků na zakázce" — kolik kdo na zakázce strávil (běžící čas označen zelenou tečkou).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '11:05',
        'title' => 'Oprava kontroly štítkového můstku (Chrome ho blokoval)',
        'items' => [
            'Chrome nově tiše blokuje komunikaci webu s programy běžícími na počítači (nové zabezpečení „Private Network Access") — proto CRM můstek neviděl, i když v Terminálu běžel. Můstek nyní posílá potřebnou hlavičku.',
            'Na pokladním počítači stačí spustit instalační příkaz z Nastavení → Tisk štítků ještě jednou — stáhne se opravená verze a stav naskočí.',
            'Hláška „můstek neběží" nově poradí co dál — a v Safari rovnou řekne, že je potřeba Chrome (Safari tuhle komunikaci nepovoluje vůbec).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '10:38',
        'title' => 'Technik si může sám převzít volnou zakázku',
        'items' => [
            'Pole Technik v detailu zakázky bylo pro techniky bez práva úprav zamčené — teď se u NEPŘIŘAZENÉ zakázky odemkne: technik v něm vidí sám sebe, vybere se, uloží a zakázka je jeho (s nápovědou přímo u pole).',
            'Přeřazování mezi techniky zůstává vedoucím/adminům — hlídá to i server, nejen formulář.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '10:11',
        'title' => 'Podpisová stanice pro iPad (2. etapa podpisů)',
        'items' => [
            'Nová stránka sign_station.php — na iPadu u pultu ji otevřeš, přidáš na plochu a stanice čeká. Z detailu zakázky pošleš podpis tlačítkem s ikonou tabletu — iPad se do 3 vteřin sám probudí s údaji zakázky a klient podepíše.',
            'Po podpisu se stanice vrátí do čekání a tobě se zakázka sama obnoví s uloženým podpisem. Stanice obsluhuje svou pobočku (Karlín / Černá růže dle přihlášeného účtu).',
            'Postup nasazení: na iPadu se přihlas do CRM → otevři admin.applefix.cloud/sign_station.php → Sdílet → Přidat na plochu.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '10:08',
        'title' => 'Elektronický podpis klienta (1. etapa)',
        'items' => [
            'V detailu zakázky je nový blok „Podpis klienta" — Příjem do opravy a Převzetí hotové zakázky. Tlačítko Podepsat otevře celoobrazovkové podpisové plátno: klient se podepíše prstem na tabletu/iPadu, podpis se uloží k zakázce s datem a časem.',
            'Podepsaná zakázka tiskne podpis přímo na zakázkovém listu nad podpisovou čarou (s poznámkou „Podepsáno elektronicky" a časem) — v tisku i v e-mailové verzi listu.',
            'Opakovaný podpis nahradí předchozí; každý řádek ukazuje stav (zelené ✓ s časem).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '10:03',
        'title' => 'Tlačítko „Přidat katalog" u dodavatelů na Nákupech',
        'items' => [
            'Vedle „Aktualizovat katalog" je nové tlačítko Přidat katalog — stačí název a adresa stránky s díly. Nový dodavatel se hned objeví ve výběru katalogů, ve filtrech i v aktualizaci skladu z katalogu.',
            'Stávající tři katalogy (Mobilnidily.cz, refurb.zone, fixshop.cz) zůstávají beze změny.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:57',
        'title' => 'Automatické odhlášení výrazně prodlouženo (4 hodiny)',
        'items' => [
            'Přihlášení nově vydrží 4 hodiny nečinnosti. Odhalili jsme přitom, že server dosud mazal přihlášení už po ~30 minutách kvůli systémovému úklidu, který ignoroval nastavení aplikace — CRM má teď vlastní úložiště přihlášení, na které systém nesahá.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:37',
        'title' => 'Počítadlo i u Nákupů v horním menu',
        'items' => [
            'Záložka Nákupy ukazuje počet aktivních položek (čekají na objednání nebo na doručení). Aktualizuje se živě jako ostatní počítadla; když není co řešit, číslovka zmizí.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:36',
        'title' => 'Zvuková upozornění: nová zakázka, změna stavu, přidělení technikovi',
        'items' => [
            'Když přibyde nová zakázka (třeba z webu), CRM přehraje decentní vzestupný tón; při změně stavu zakázky krátké ťuknutí. Počítadla v horním menu se přitom aktualizují samy, bez obnovení stránky.',
            'Technik při přidělení zakázky slyší výraznější trojtón společně s vyskakovacím oknem (to už fungovalo, nově se zvukem).',
            'Zvuky jsou syntetizované (žádné soubory) a hrají jen jednou i při více otevřených kartách. Prohlížeč pouští zvuk až po prvním kliknutí do stránky — první upozornění po přihlášení se případně přehraje při nejbližší interakci.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:31',
        'title' => 'Počítadla v horním menu sedí na ikonách + počítadlo reklamací',
        'items' => [
            'Číselný ukazatel (např. počet aktivních zakázek) už se nebije s názvem záložky — sedí vždy na pravém horním rohu ikony, jako na iPhonu.',
            'Reklamace mají nově vlastní oranžové počítadlo — ukazuje počet reklamací, které nejsou vyřízené ani zamítnuté.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:28',
        'title' => 'Více oprav na jedné zakázce z ceníku',
        'items' => [
            'Pole „Oprava a cena dle ceníku" nyní přidává položky — vybereš displej, pak baterii… Každá se ukáže jako štítek s cenou (křížkem jde odebrat), popis závady i celková cena se skládají samy.',
            'Na zakázkovém listu se všechny vybrané opravy rozepíšou po položkách, včetně expresního příplatku. Když cenu ručně upravíš, na listu se objeví řádek „Úprava ceny", aby rozpis vždy seděl na celkovou částku.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:25',
        'title' => 'Ceník ve wizardu zjednodušen — žádné druhé pole modelu',
        'items' => [
            'Model se vybírá jen v původním poli Značka + Model, žádná duplicita. Jakmile model vybereš, nabídne se jediné pole „Oprava a cena dle ceníku z webu" nad popisem závady — výběr doplní závadu i cenu. Když model v ceníku není, pole to jen tiše oznámí.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:16',
        'title' => 'Systémové e-maily odcházejí ze servis@applefix.cz',
        'items' => [
            'Veškeré e-maily odesílané systémem CRM (Připraveno k vyzvednutí, zakázkový list e-mailem…) nyní odcházejí z adresy servis@applefix.cz s odesílatelem „Servis | AppleFix.cz". Adresa info@applefix.cz zůstává vyhrazená pro dotazy klientů.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:12',
        'title' => 'Urgentní štítek u vydaných zakázek zešedne + správná role adminů',
        'items' => [
            'Když je urgentní zakázka už vydaná, její štítek priority v seznamu zešedne (bez ohně) — červeně svítí jen to, co ještě hoří.',
            'Záložka Zaměstnanci nyní ukazuje roli Admin i u zaměstnanců povýšených ve Správě administrátorů (dřív dál svítili jako Manažer/Technik, i když admin přístup měli).',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:09',
        'title' => 'Výběr opravy z ceníku přímo ve wizardu zakázky',
        'items' => [
            'V kroku „Zařízení" nového wizardu jsou dvě nová pole: vyhledáš model (např. „iPhone 15 Pro"), vybereš opravu s variantou dílu — a typ zařízení, značka, model, popis závady i cena se vyplní samy podle ceníku z webu.',
            'Funguje po načtení ceníku v Nastavení → Integrace. Pole jsou volitelná — zakázku jde dál vyplnit i ručně.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '09:07',
        'title' => 'Ceník oprav z applefix.cz v CRM',
        'items' => [
            'CRM si umí stáhnout kompletní ceník oprav z objednávkového formuláře na webu (RepairPlugin) — všechny Apple modely, opravy i varianty dílů (Originál / Repas…) s cenami.',
            'Načtení: Nastavení → Integrace → „Načíst ceník Apple z webu" (trvá 1–2 minuty, projede Smartphone, Tablet, Notebook i Stolní počítače). Po změně cen na webu stačí kliknout znovu.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '08:56',
        'title' => 'Zakázkový list e-mailem odchází v jazyce klienta',
        'items' => [
            'Ruční odeslání zakázkového listu e-mailem (tlačítko u zakázky) nově respektuje jazyk zvolený u klienta — dřív odcházel vždy česky.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '08:30',
        'title' => 'CRM jako aplikace na telefonu a tabletu',
        'items' => [
            'CRM si nově přidáš na plochu telefonu jako plnohodnotnou aplikaci — s ikonou AppleFix (bílé jablko se zeleným křížem na tmavém podkladu) a spouštěním na celou obrazovku bez adresního řádku.',
            'iPhone/iPad: otevři admin.applefix.cloud v Safari → tlačítko Sdílet → „Přidat na plochu". Android: Chrome → menu ⋮ → „Přidat na plochu" (nebo nabídka „Nainstalovat aplikaci").',
            'Funguje pro administraci i klientský portál; žádné ukládání dat do mezipaměti — appka vždy ukazuje živá data.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '07:34',
        'title' => 'Expresní příplatek / sleva u priority i při ručním založení zakázky',
        'items' => [
            'Když ve wizardu vybereš prioritu Urgentní, objeví se pole „Expresní příplatek" — částka se přičte k ceně opravy a na zakázkovém listu se vytiskne rozepsaně (oprava + příplatek + celkem). U priority Klidná jde stejně zadat sleva. Stejná logika, jakou má objednávka z webu.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '07:32',
        'title' => 'Zakázkový list s rozepsanou cenou (příplatky a slevy)',
        'items' => [
            'Když má zakázka příplatek nebo slevu (např. expresní priorita z objednávky na webu, kupón), zakázkový list vytiskne rozpis po položkách a celkovou cenu. U zakázek z RepairPluginu se rozpis přenáší automaticky včetně slev.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:55',
        'title' => 'Dokumenty: klikací kontakty na obrazovce + vždy A4 na výšku',
        'items' => [
            'Telefon, web, e-mail a adresa portálu jsou ve všech klientských dokumentech klikací, když se dokument prohlíží na obrazovce (náhled, e-mail) — na papíře se vytisknou jako běžný text, žádná falešná tlačítka.',
            'Reklamační protokol má nyní stejně jako zakázkový list vynucený tisk na A4 na výšku.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:44',
        'title' => 'Reklamační protokol sladěn s novým vzhledem dokumentů',
        'items' => [
            'Reklamační protokol přešel z hnědé hlavičky na jednotný vzhled zakázkového listu — světlá hlavička s logem, modrý akcent, písmo SF Pro a adresa s kontakty v patičce dole.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:42',
        'title' => 'Doklad o platbě (termo) česky a v jednotném vzhledu',
        'items' => [
            'Termo doklad z účetnictví je kompletně česky (dřív „RECEIPT", „Customer", „Items"…), v novém vzhledu s logem a SF Pro. IČO/DIČ a adresa pobočky jsou v patičce; ruská tlačítka nahrazena českými.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:41',
        'title' => 'Účtenka při vydání v novém jednotném vzhledu',
        'items' => [
            'Účtenka (termo) sladěná se zakázkovým listem — logo, SF Pro, přehledné položky a celková částka, adresa pobočky v patičce.',
            'Odstraněny pozůstatky původní šablony: cizí doména servis.expert, anglické věty a ruské tlačítko „Печать". QR kód vede na naše online sledování zakázky.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:39',
        'title' => 'Příjmový akt v novém jednotném vzhledu',
        'items' => [
            'Příjmový akt (termo) má nový vzhled sladěný se zakázkovým listem — logo AppleFix, písmo SF Pro, přehledná typografie a adresa pobočky v patičce dole.',
            'QR kód nově vede na naše online sledování zakázky (admin.applefix.cloud) místo cizí domény servis.expert, která na dokladu strašila z původní šablony. V patičce je číslo zakázky místo interního čísla.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:38',
        'title' => 'Zakázkový list: písmo SF Pro a adresa v patičce',
        'items' => [
            'Zakázkový list používá firemní písmo SF Pro s jemnější typografií (tučné titulky, lehké popisky). Adresa a kontakty firmy se přesunuly z horní části do elegantní patičky na konci dokumentu.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:10',
        'title' => 'Dokumenty pro klienta se tisknou v jeho jazyce',
        'items' => [
            'Zakázkový list, účtenka, příjmový akt i reklamační protokol se automaticky otevírají v jazyce zvoleném u klienta — bez ručního vybírání. Ruční volba jazyka při tisku má nadále přednost.',
            'Ukrajinským klientům se dokumenty zatím tisknou anglicky (překlad dokumentů do ukrajinštiny lze doplnit); e-maily jim odcházejí plně ukrajinsky.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:09',
        'title' => 'Jazyky zákazníka: čeština / angličtina / ukrajinština',
        'items' => [
            'Výběr jazyka klienta je nově Čeština / English / Українська (ruština nahrazena ukrajinštinou dle zadání). E-mail „Připraveno k vyzvednutí" odchází i ukrajinsky.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '06:06',
        'title' => 'Jazyk zákazníka (CS/EN/RU) + e-maily v jeho jazyce',
        'items' => [
            'Při zakládání zakázky se u údajů klienta nově vybírá jazyk komunikace (čeština / angličtina / ruština) — pole je vedle telefonu. U stávajících klientů jde jazyk změnit v editaci klienta.',
            'E-mail „Připraveno k vyzvednutí" odchází automaticky v jazyce zákazníka včetně předmětu, otevírací doby i dnů v týdnu.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '05:53',
        'title' => 'E-mail klientům s originálním logem AppleFix',
        'items' => [
            'Hlavička e-mailu „Připraveno k vyzvednutí" nese originální AppleFix logo (černý nápis s jablkem) místo samotného jablka s dopsaným textem.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '05:15',
        'title' => 'Všechny dokumenty se vystavují s adresou pobočky zakázky',
        'items' => [
            'Zakázkový list, účtenka, příjmový akt, faktura (termo) i reklamační protokol nesou adresu, telefon a e-mail POBOČKY, pod kterou zakázka vznikla — zakázka od zaměstnance z Karlína má Karlín (Křižíkova 177/29), z Černé růže má Na Příkopě 853. Zakázky z webu spadají pod výchozí pobočku automaticky.',
            'Reklamační protokol bere pobočku přihlášeného zaměstnance (případně dohledá podle sériového čísla zařízení).',
            'Když pobočka nemá údaje vyplněné, použijí se globální firemní údaje z Nastavení — nic nezůstane prázdné.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '05:05',
        'title' => 'Nový e-mail „Připraveno k vyzvednutí" s kontakty pobočky',
        'items' => [
            'Klient dostane po dokončení opravy elegantní e-mail v novém světlém designu AppleFix — souhrn zakázky (zařízení, oprava, cena), tlačítko pro online sledování zakázky s PINem a kontakty.',
            'Kontaktní blok se bere z POBOČKY zakázky: Karlín (Křižíkova 177/29, +420 704 011 939) nebo Černá růže (Na Příkopě 853, +420 705 926 236) — včetně otevírací doby a odkazu na mapu. Údaje poboček se předvyplnily automaticky a dají se upravit v databázi poboček.',
            'E-mail se posílá jen jednou (při prvním přepnutí do „Připraveno k převzetí") a jen pokud má klient vyplněný e-mail.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:57',
        'title' => 'Historie úprav ukazuje i čas',
        'items' => [
            'Každý záznam v Historii úprav má vedle data nově i čas dokončení. Časy jsou doplněné zpětně i ke starším záznamům z posledních dní.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:53',
        'title' => 'Nová ikona CRM v záložce prohlížeče',
        'items' => [
            'CRM má v záložce prohlížeče (favicon) logo AppleFix — černé jablko se zeleným křížem. Platí pro administraci, přihlášení i klientský portál.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:51',
        'title' => 'Oprava chyby při změně stavu / mazání zakázek bez technika',
        'items' => [
            'U zakázek bez přiřazeného technika (typicky nové zakázky z webu) končila změna stavu, stornování či úprava chybou „Integrity constraint violation… technician_id". Systém se pokoušel uložit neexistujícího technika č. 0 — nyní správně ukládá „bez technika".',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:44',
        'title' => 'Drobné texty v tabulkách už nejsou verzálkami',
        'items' => [
            'E-mail, způsob dopravy a další drobné texty v řádcích zakázek se ukazovaly VELKÝMI PÍSMENY (pravidlo určené pro statistické karty dopadalo i na tabulky). Nyní jsou normálním textem v čitelné velikosti.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:40',
        'title' => 'Kompaktnější sloupec Priorita',
        'items' => [
            'Štítky priority (Klidná / Normální / Urgentní) jsou menší a sloupec Priorita zabírá jen šířku nejširšího štítku — víc místa zbývá pro popis závady a stav.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:39',
        'title' => 'Opravena ikona tisku ve sloupci Akce',
        'items' => [
            'Ikona tiskárny u tlačítka tisku se zobrazovala zdeformovaně (zmenšená pravidlem pro drobné texty v tabulce). Nyní má správnou velikost jako ostatní ikony akcí.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:38',
        'title' => 'E-mail klienta přímo v seznamu zakázek',
        'items' => [
            'Ve sloupci Klient je pod telefonem nově i e-mail klienta (pokud je vyplněný) — kliknutím se rovnou otevře nový e-mail.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:38',
        'title' => 'Dnešní objednané termíny z webu jemně pulzují',
        'items' => [
            'Pokud je oprava z webu objednaná na dnešek, štítek s časem lehce pulzuje (světelný nádech). Termíny na jiné dny zůstávají klidné.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:37',
        'title' => '„Vydáno" už není černé — řádek splývá s pozadím okna',
        'items' => [
            'Řádky vydaných zakázek měly tmavší (skoro černé) pozadí než zbytek okna. Nově jsou průhledné, takže mají přesně stejnou barvu jako karta za nimi; stav „Vydáno" zůstává tmavě zelený štítek.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '03:37',
        'title' => 'Větší štítek s objednaným časem u zakázek z webu',
        'items' => [
            'Modrý štítek s časem, na který si klient objednal opravu, je nyní stejně velký jako štítek stavu „Přijato z RepairPluginu" — lépe viditelný v seznamu zakázek i na nástěnce.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '02:31',
        'title' => 'Odstraněny rušivé svislé čáry v seznamu zakázek',
        'items' => [
            'Urgentní a firemní zakázky vykreslovaly barevný proužek na začátku každé buňky řádku — vypadalo to jako červené (resp. modré) svislé oddělovací čáry přes celý řádek. Proužky jsou pryč; priorita je vidět ve svém sloupci a stav má nadále barevný proužek jen na levém okraji řádku.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '00:49',
        'title' => 'Zakázky z webu mezi běžnými zakázkami + nové barvy řádků',
        'items' => [
            'Sekce „Rezervace z webu" zmizela — zakázky z RepairPluginu se zobrazují jako běžné zakázky mezi ostatními (v seznamu i na nástěnce).',
            'Objednaný čas od klienta je u zakázek z webu vidět přímo v seznamu zakázek i na nástěnce jako modrý štítek vedle stavu (dnešní termín svítí).',
            '„Vydáno" už nebarví celý řádek — vyřízené zakázky mají jen tmavě zelený štítek stavu, řádek zůstává tmavý.',
            '„Připraveno k převzetí" je nově světle zelené — celý řádek i štítek stavu (dřív oranžová).',
            '„Přijato z RepairPluginu" barví celý řádek sytě fialově podle štítku.',
            'Opraveno: barvy řádků definovaly dva soubory najednou a přebíjely se — teď je vlastní jediné místo, takže změny se už neztratí.',
        ],
    ],
    [
        'date' => '2026-07-13',
        'time' => '00:14',
        'title' => 'Rezervace z webu: nástěnka, časy, zrušení z webu a čísla objednávek',
        'items' => [
            'Na nástěnce je nový panel „Rezervace z webu" s nadcházejícími termíny — objednávkový čas je vidět na první pohled (dnešní termíny svítí modře).',
            'Číslo objednávky z webu (RepairPlugin) se nyní zobrazuje v detailu zakázky pod zakázkovým číslem CRM, včetně termínu; zapisuje se i do poznámky technika.',
            'Nikde se už neukazuje interní číslo typu „#1240" — zakázka založená z webu vždy dostane řádné zakázkové číslo (APFAZ…), a starší zakázka bez čísla si ho sama doplní při otevření.',
            'Zrušení nebo smazání objednávky v RepairPluginu se propíše do CRM: rezervace zmizí z panelu, čerstvá zakázka se stornuje a rozpracovaná dostane výrazné upozornění v poznámce. Událost se smaže i z firemního kalendáře.',
            'Způsob předání („Come by our store", „Ship Device", „Pickup Service") se v CRM překládá podle zvoleného jazyka (Osobně na prodejně / Zaslání poštou / Vyzvednutí u zákazníka).',
        ],
    ],
    [
        'date' => '2026-07-12',
        'time' => '23:33',
        'title' => 'Historie stavů: opraveny divné symboly u nově založených zakázek',
        'items' => [
            'U zakázek založených rovnou (např. z RepairPluginu) se v historii stavů zobrazoval prázdný rámeček a rozbitá šipka. Nově se u první položky ukáže „Vytvořeno“ + samotný stav a mezi stavy je čitelná šipka „→“.',
        ],
    ],
    [
        'date' => '2026-07-12',
        'time' => '23:28',
        'title' => 'Tisk zakázkového listu vždy na A4 na výšku',
        'items' => [
            'Zakázkový list se dřív u některých tiskáren vytiskl na šířku — obsah byl širší (222 mm) než A4 na výšku (210 mm), tak se orientace otočila. Nově je natvrdo vynuceno A4 na výšku a list je omezen na šířku stránky.',
        ],
    ],
    [
        'date' => '2026-07-12',
        'time' => '23:20',
        'title' => 'Priorita zakázek: Klidná / Normální / Urgentní + přenos z webu',
        'items' => [
            'Nová třetí priorita „Klidná" — pro zákazníky, kteří nespěchají. V přidání zakázky je místo zaškrtávátka „urgentní" rozbalovací výběr priority.',
            'V tabulce zakázek je nový sloupec Priorita (před sloupcem Částka) — urgentní červeně 🔥, klidná v tyrkysové, normální neutrálně.',
            'Rezervace z webu: volba priority v RepairPluginu (normal / express / nespěchám) se automaticky přenese do priority zakázky a už se nepropisuje do popisu závady.',
            'Passcode zařízení vyplněný zákazníkem na webu se přenáší do pole PIN/heslo zakázky.',
            'Řádky v tabulce zakázek se nyní barví podle stavu výrazněji — tmavší odstíny (modrá, jantarová, šedá) dřív na tmavém pozadí splývaly a vypadaly nenabarveně.',
        ],
    ],
    [
        'date' => '2026-07-12',
        'time' => '20:31',
        'title' => 'Nový stav „Přijato z RepairPluginu" (fialový)',
        'items' => [
            'Zakázky automaticky založené z webové rezervace mají nový stav „Přijato z RepairPluginu" ve fialové barvě — na první pohled je vidět, že přišly z webu.',
            'Stav se počítá jako aktivní/nová zakázka (fronta, statistiky, filtry) a je i v angličtině a ruštině.',
        ],
    ],
    [
        'date' => '2026-07-12',
        'title' => 'Rezervace z webu → rovnou zakázka + zákazník',
        'items' => [
            'Nová rezervace z applefix.cz teď automaticky založí zakázku „Přijato" — už se nemusí ručně klikat „Vytvořit zakázku".',
            'Pokud zákazník v databázi ještě není, založí se z údajů z rezervace (jméno, telefon, e-mail, adresa/firma). Existující se dohledá podle telefonu nebo e-mailu.',
            'Přenese se zařízení, typ opravy, odhad ceny, IMEI/SN a do poznámky technikovi termín z webu + způsob předání + poznámka zákazníka.',
            'Kdyby se automatické založení nepovedlo, rezervace zůstane nahoře v Zakázkách k ručnímu převzetí (pojistka).',
            'Oddělený přehled „Rezervace z webu" zůstává zachovaný i po založení zakázky — u převedené rezervace je rovnou odkaz na její zakázku (APFAZ…) a čas objednávky.',
        ],
    ],
    [
        'date' => '2026-07-12',
        'title' => 'Propojení s RepairPluginem: nativní webhooky',
        'items' => [
            'CRM endpoint nově rozumí formátu „Trigger Webhooks" přímo z RepairPluginu (adaptér polí, klíč v URL) — appointmenty z applefix.cz se propisují bez dalšího kódu na WordPressu.',
            'Zrušený/smazaný appointment na webu rezervaci v CRM automaticky skryje.',
            'V Nastavení → Integrace je připravená webhook URL s klíčem ke zkopírování a diagnostika posledního přijatého payloadu.',
        ],
    ],
    [
        'date' => '2026-07-11',
        'title' => 'Rezervace z webu → firemní kalendář (CalDAV)',
        'items' => [
            'Každá rezervace opravy z webu se může automaticky zapsat do firemního kalendáře přes CalDAV — nová rezervace vytvoří událost, změna ji přepíše, zrušení smaže.',
            'Zapíná se v Nastavení → Integrace → Firemní kalendář (CalDAV): zapnout přepínač a vyplnit CalDAV URL kalendáře, uživatele a heslo/app password (+ délku události).',
            'Tato integrace vznikla přímo na serveru; nyní je bezpečně sloučena do gitu, takže přežije všechny budoucí aktualizace.',
        ],
    ],
    [
        'date' => '2026-07-11',
        'title' => 'Angličtina: přeložené stavy zakázek + 150 textů',
        'items' => [
            'Stavy zakázek se v anglickém (i ruském) rozhraní nově překládají — Přijato → Received, V opravě → In Repair, Připraveno k převzetí → Ready for Pickup, Vydáno → Collected… V databázi zůstávají česky, takže se nic nerozbije.',
            'Celoplošný audit našel 110+ natvrdo česky napsaných textů (menu, notifikace, reklamace, nákupy, nákupní seznam, wizard, popupy) — převedeny na překladové klíče CS/EN/RU, celkem ~150 náhrad.',
            'Opraveny i obrácené případy (anglické texty v českém rozhraní na stránce Nákupy).',
            'Zbývá dopřekládat administrátorské stránky (Nastavení, Klienti, Sklad, Účetnictví) — následující kolo.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Dashboard: zarovnané sloupce, tabulka na maximum',
        'items' => [
            'Pravý sloupec nástěnky (pobočky, Tržby po měsících, Kontrola IMEI, Fronta dnes) má pevnou šířku 360 px — levý sloupec s dlaždicemi a tabulkou zakázek dostane všechno ostatní místo a končí na jedné svislé hraně.',
            'Pobočková pole nahoře lícují se sloupcem panelů pod nimi.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Oprava: tabulka zakázek se zase vejde celá (bez scrollu)',
        'items' => [
            'Redesign omezil šířku obsahu na 1560 px, čímž se tabulka posledních zakázek přestala vcházet a musela se posouvat do stran. Obsah nyní opět využívá celou šířku okna jako dřív — řádek zakázky je vidět celý najednou.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Dashboard: pobočková pole už nenatahují výšku řádku',
        'items' => [
            'Obsah pobočkových polí vpravo se skládal pod sebe (název / čísla), pole rostla do výšky a natáhla i čtyři statistické dlaždice. Obsah je nově v jednom řádku (název vlevo, čísla vpravo), pole jsou nízká a výšku řádku určují dlaždice.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Oprava: Khalilovy ambientní zvuky se nespouštěly',
        'items' => [
            'Náhodné hlášky po ~10 minutách se nikdy nespustily — přehrávač startoval dřív, než se stihla načíst konfigurace se seznamem zvuků (pořadí skriptů). Opraveno; první hláška zazní ~10 minut po přihlášení, pak každých ~10 minut.',
            'Pokud prohlížeč blokuje automatické přehrání, hláška zazní při nejbližším kliknutí.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Menu: černější pozadí doku',
        'items' => [
            'Horní horizontální menu má výrazně černější, méně průhledné pozadí — lepší kontrast a čitelnost; skleněný lom na hranách zůstává.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Oprava: popup okna šla překrýt neprokliknutelnou vrstvou',
        'items' => [
            'Po redesignu mohla potvrzovací a další vyskakovací okna na stránkách (detail zakázky, zakázky, nákupy, nastavení, klienti, sklad, účetnictví, reporty) skončit pod ztmavovací vrstvou — nešlo kliknout na Potvrdit/Zrušit. Opraveno globálně pro celé CRM.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Dashboard: statistiky a pobočky na jednom řádku',
        'items' => [
            'Čtyři velké statistické dlaždice jsou lehce zúžené a vedle nich se nově vešla obě pole poboček — kompaktně nad sebou, dohromady stejně vysoká jako dlaždice.',
            'Pobočková pole mají elegantní zhuštěný design (název, aktivní/hotovo, celkem + tržby) a při najetí jemně modře svítí; na menších displejích se srovnají pod statistiky.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Menu: barevné akce mají stálé pozadí',
        'items' => [
            'Tři barevné položky vpravo v horním menu (Nová zakázka, Reklamace, Nákupní seznam) mají nově trvale podbarvené pozadí s jemným barevným rámečkem; při najetí myší se rozsvítí o stupeň víc.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Správa administrátorů: přidání ze zaměstnanců',
        'items' => [
            'V Nastavení → Administrátoři lze nově povýšit stávajícího zaměstnance na administrátora — vybereš ho ze seznamu, přihlašovací jméno se předvyplní a nastavíš adminské heslo (min. 8 znaků).',
            'Účet zaměstnance zůstává beze změny; vznikne samostatný adminský přístup. Stejné přihlašovací jméno je v pořádku — role se pozná podle hesla.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Doladění horního menu',
        'items' => [
            'Vlevo nahoře už jen logo AppleFix (bez textu) — bílé v tmavém motivu, černé ve světlém.',
            'Horní dok má méně průhledné pozadí (lepší čitelnost nad obsahem).',
            'Položky menu při najetí myší jemně bíle září.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Rezervace z webu (servis-online) v Zakázkách',
        'items' => [
            'Objednávky z rezervačního formuláře na applefix.cz (RepairPlugin) se nově zobrazují nahoře v Zakázkách — seřazené dle termínu vzestupně, s jemným oddělením od běžných zakázek.',
            '„Vytvořit zakázku" předvyplní wizard údaji z rezervace (jméno, telefon, e-mail, zařízení, závada) a po dokončení rezervaci automaticky označí jako převzatou. ✓ = vyřízeno/skrýt.',
            'Webhook endpoint api/website_booking.php se sdíleným klíčem (Nastavení → Integrace); zrušené rezervace z webu se samy skryjí.',
            'Zbývá aktivovat můstek na WordPressu (docs/wordpress-webhook/) — potřeba přístup na hosting applefix.cz.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Nový vzhled: horní dok, Liquid Glass, saténové pozadí',
        'items' => [
            'Levé menu nahrazeno plovoucím skleněným dokem z buněk nahoře (Apple styl) + tenkým servisním řádkem s hledáním (⌘K), skenerem, notifikacemi, přepínačem vzhledu, jazykem a profilem.',
            'Na mobilu a tabletu (do 1080 px) spodní tab bar ve stylu iOS s velkým ＋ (nová zakázka) a Menu, které otevře sheet zdola — s plnohodnotným hledáním (konečně i na mobilu), barevnými akcemi a všemi odkazy.',
            'Nové pozadí: tmavý grafitovo-zelený satén (SVG vlny) — elegantní struktura, na které je vidět lom skla.',
            'Nový Liquid Glass engine s fyzikální refrakcí (Snellův zákon): dok i modální okna reálně lámou pozadí (Chrome/Edge); Safari má matné sklo.',
            'Sytější zářivá paleta: ledová azurová, mátový smaragd, mandarinka, orchidej.',
            'Tlačítka mají „liquid press morph" — hloubkové kliknutí: stisk tlačítko zanoří do skla, puštění se pružně vrátí (jako iOS).',
            'Záloha původního vzhledu: větev backup/pred-redesignem-2026-07-09 na GitHubu.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Typografie: SF Pro všude + váhová hierarchie',
        'items' => [
            'Celé CRM (včetně přihlášení a klientské sekce) nově píše jednotně fontem SF Pro Display z vlastních souborů — Google font Inter odstraněn (rychlejší načítání, žádná závislost na Google).',
            'Zavedena logická hierarchie tlouštěk: 400 běžný text · 500 labely a navigace · 600 nadpisy a tlačítka · 700 titulky a čísla. Labely ustoupí, hodnoty a titulky vyniknou.',
            'Částky a počty v tabulkách používají tabulární číslice — čísla se zarovnají přesně pod sebe.',
            'Monospace (kódy, ID) nově přes systémový SF Mono.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Detail zakázky: Doprava/Výdej přesunuta do Akcí',
        'items' => [
            'Sekce „Doprava / Výdej" už není samostatná karta dole v pravém sloupci — je přímo v okně „Akce", hned nahoře nad tlačítkem „Označit jako vydané". Vše pro výdej je tak na jednom místě.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Nákupní seznam v levém menu',
        'items' => [
            'Nová položka „Nákupní seznam" v levém menu (zelené tlačítko). Objevují se v ní díly přidané ze stránky Nákupy — manažer/admin je jednoduše schválí a objedná, přijme na sklad, zamítne nebo smaže.',
            'Tři přehledové dlaždice (Ke schválení / Objednáno / Přijato) a badge s počtem čekajících položek přímo v menu.',
            'Položka „Nová reklamace" v menu dostala plný oranžový design (dřív jen oranžový text), aby ladila s „Nová zakázka" a „Nákupní seznam".',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Objednat díl: tlačítko reaguje + hlásí chyby',
        'items' => [
            'Tlačítko „Objednat" v modalu dílu nově vždy reaguje — při úspěchu potvrdí a přenačte, při chybě zobrazí konkrétní hlášku (dřív se při chybové odpovědi serveru nestalo nic).',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Objednávka dílu: předvyplní naposledy otevřenou zakázku',
        'items' => [
            'Modal „Objednat díl" nově předvyplní naposledy otevřenou zakázku a zobrazuje správný kód zakázky (APFAZ…) místo interního čísla (#1236).',
            'Dropdown zakázek je řazený od nejnovějších.',
            'Opraven popisek „proc_optional" → „Nepovinné".',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Příjem zakázky: focení stavu + oprava tlačítka Dokončit',
        'items' => [
            'Tlačítko „Dokončit" se nově zobrazí až v posledním kroku po vyplnění všech údajů (dřív prosvítalo hned na 1. kroku).',
            'U pole „Vzhled / Příslušenství" (krok 2) lze nově nahrát/vyfotit stav zařízení při příjmu — dokumentace stavu, v jakém zařízení přišlo, kvůli pozdějším dohadům.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Zakázkový list — nové přehledné rozvržení',
        'items' => [
            'Firemní údaje na jednom řádku v záhlaví; vlevo výrazný klient (velké jméno + kontakt), vpravo zařízení a oprava.',
            'Přidáno pole „Datum ukončení opravy" (jen den).',
            'Obsah i kompletní text podmínek drobným písmem zůstávají beze změny.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Vzhled + IČO/DIČ + barva stavu + čitelnost',
        'items' => [
            'Skutečnější Apple Liquid Glass na loginu i v klientské sekci — silnější refrakce (Chrome) a výrazné specular/edge odlesky (Safari fallback), zaoblenější karty.',
            'V Nastavení → Údaje o společnosti nově pole IČO, DIČ, e-mail a web — propíšou se do zakázkového listu (IČO 24588571 doplněno).',
            'Stav „Připraveno k převzetí" má novou živější jantarovou barvu místo azurové.',
            'Popis závady v seznamu zakázek je větší a čitelnější.',
            'Z klientské sekce odstraněn nadpis „Stav opravy a cena na jednom místě".',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Cenová pole zakázky bez haléřů',
        'items' => [
            'Vstupní pole Cena práce, Předpokládaná cena, Finální cena i Další výdaje se zobrazují jako celé koruny (bez „,00") — v detailu zakázky, editaci i rychlé úpravě.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Elegantnější Apple Liquid Glass (login + klientská sekce)',
        'items' => [
            'Přihlašovací obrazovka (tmavá) je více „liquid glass" — zaoblenější rohy, silnější sklo s refrakcí a lesklou hranou, zaoblené vstupy i tlačítko.',
            'Klientská sekce zmodernizována: zaoblenější karty a topbar, jemná refrakce skla — svěžejší a elegantnější vzhled.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Spolehlivější skenování čárových kódů',
        'items' => [
            'Otevírání zakázky po skenu je odolnější — server zkusí víc variant přepisu (české QWERTZ: číslice↔háčky i Y↔Z), takže se zakázka najde, i když čtečka/klávesnice kód přepíše nekonzistentně.',
            'Mobilní skener: knihovna se přednačte, aby se dotaz na kameru spolehlivě zobrazil (hlavně iPhone/iPad při prvním použití); jasnější hláška při odepření kamery i mimo HTTPS.',
            'HW čtečka: tolerantnější časování, aby se dlouhý kód nerozdělil doprostřed skenu.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Klientský portál: jednotné označení zakázek',
        'items' => [
            'V klientské sekci se u zakázek už nezobrazuje interní číslo (#ID) — všude je jednotný kód zakázky (APFAZ…, převzatý z importu a pokračující v číselné řadě).',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Ceny v Kč bez desetinných míst',
        'items' => [
            'Částky v Kč se všude zobrazují zaokrouhlené na celé koruny (bez haléřů) — nástěnka, zakázky, přehledy, účetnictví, klientský portál, e-maily.',
            'Formální daňové doklady (faktura, účtenka) zatím ponechány s haléři kvůli DPH; lze upravit na vyžádání.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Popup o nově přidělené zakázce technikovi',
        'items' => [
            'Když technik dostane přidělenou zakázku, na zařízení s otevřeným CRM mu vyskočí popup se základními údaji o zakázce a zařízení (klient, závada, priorita, odkaz na otevření).',
            'Popup se NEzobrazí, pokud si zakázku přidělil technik sám sobě.',
            'Doručuje se automaticky (kontrola každých ~20 s), ve stylu Apple / liquid glass.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Skrývání bočního panelu + přehlednější Akce u zakázky',
        'items' => [
            'Tlačítko ☰ nahoře nově skryje/vysune celý boční panel i na iPadu a MacBooku (obsah se roztáhne). Stav se pamatuje.',
            'V detailu zakázky je panel „Akce" rovnou rozbalený (technik, stav, cena, tisk) — tlačítko „Další akce" tím odpadlo.',
            'Mezi čárovým kódem a panelem „Akce" přidán prostor.',
            'Ve wizardu přidání zakázky vráceno tlačítko „Zpět" (pro opravu kroku).',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Automatický e-mail „Připraveno k vyzvednutí"',
        'items' => [
            'Jakmile zakázka přejde do stavu „Připraveno k vyzvednutí", pošle se klientovi automaticky e-mail (jen jednou) — hotovo, na které pobočce a otevírací doba.',
            'Elegantní vzhled ve stylu Apple s liquid-glass panely.',
            'Adresu a otevírací dobu poboček nastavíš v Nastavení → Údaje o společnosti.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Přidání zakázky: čistší tlačítka wizardu',
        'items' => [
            'Odebráno nadbytečné tlačítko „Zpět" ve spodní liště wizardu.',
            'Na každém kroku je teď dole jen jedno tlačítko: „Další" v průběhu a „Dokončit" v posledním kroku (které zakázku založí a přesune do seznamu).',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Fronta dnes: dominantní název zařízení',
        'items' => [
            'V panelu „Fronta dnes" na nástěnce je nově dominantní název zařízení a jméno klienta menší pod ním — v servisu se řídíme hlavně tím, co se opravuje.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Zakázkový list dle firemního vzoru',
        'items' => [
            'Zakázkový list nově obsahuje kompletní text podmínek drobným písmem (odpovědnost, uskladnění, zadržovací právo dle §1395 obč. zák., souhlas s obchodními podmínkami) přesně dle firemního vzoru.',
            'Typy polí dle vzoru, naplněné našimi údaji: Číslo zakázky, PIN / heslo zařízení, Zařízení, Přijetí do opravy, Heslo zařízení / Kód obrazovky, Požadovaná oprava, Předpokládaná cena, Kontakt na zákazníka, Převzetí zařízení + podpisové řádky (přijetí i převzetí hotové zakázky).',
            'Identita zhotovitele se bere z Nastavení → Údaje o společnosti.',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Klientský portál: doklady a reklamace opravy',
        'items' => [
            'V otevřené zakázce klienta přibyla sekce „Dokumenty": zakázkový list (vždy), faktura a účtenka (jen když byly vystaveny), reklamační protokol (když existuje reklamace). Klient vidí výhradně své vlastní doklady.',
            'U dokončené a vydané zakázky je tlačítko „Reklamovat opravu" — reklamace se rovnou propíše do CRM a upozornění odejde na info@applefix.cz.',
            'Nová klientská reklamace se v sekci Reklamace drží úplně nahoře a jemně pulzuje, dokud ji technik/manažer nepřevezme (změnou stavu); poté se řadí klasicky.',
            'V sekci Reklamace přibyl zdroj (badge „Klient"), napojení na zakázku, změna stavu přímo v seznamu a tisk reklamačního protokolu.',
            'Sekce fotek u klienta přejmenována z „Fotky od technika" na „Fotodokumentace".',
        ],
    ],
    [
        'date' => '2026-07-09',
        'title' => 'Detail zakázky: přehlednější údaje a čárový kód nahoře',
        'items' => [
            'Základní údaje o klientovi i zařízení (jméno, kontakt, model, sériové číslo) jsou výrazně větší a čitelnější.',
            'Čárový kód pro naskenování/otevření zakázky je nově úplně nahoře v pravém sloupci.',
            'Okénko s čárovým kódem už není zbytečně vysoké — končí hned pod kódem.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Reklamace v CRM (nová sekce)',
        'items' => [
            'V levém menu přibyla položka „Nová reklamace" (oranžová) a přehled Reklamace.',
            'Formulář reklamace včetně vyfocení reklamovaného kusu přímo z mobilu.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Zakázkový list ke každé zakázce (tisk / e-mail)',
        'items' => [
            'Ke každé zakázce se generuje zakázkový list s volbou vytisknout nebo poslat e-mailem.',
            'Vzhled dokumentu byl sjednocen; pole i drobný text podmínek zůstaly beze změny.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Filtr zakázek podle stavu',
        'items' => [
            'Seznam zakázek lze nově filtrovat podle stavu.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Skenování kódů (ruční skener i mobil)',
        'items' => [
            'Naskenování kódu, když je kurzor ve vyhledávacím poli, hned otevře příslušnou zakázku.',
            'Nově lze zakázku naskenovat i mobilem přes fotoaparát (Code128).',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Responzivní CRM (mobil / iPad / počítač)',
        'items' => [
            'Celé CRM se přizpůsobí displeji telefonu, iPadu i počítače.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Upozornění (zvoneček) — reálný přehled',
        'items' => [
            'Zvoneček ukazuje skutečné dění: zaseknuté zakázky, změny stavů a nové reklamace.',
            'Opravená čitelnost panelu na světlém motivu.',
            'Pulzování/„dýchání" se týká jen dění od spuštění naostro — importované zakázky neblikají.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Přihlašovací obrazovka — světlý motiv',
        'items' => [
            'Světlá varianta přihlášení má nové pozadí a jemný „skleněný" efekt oken (styl macOS).',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Barevné řádky zakázek podle stavu',
        'items' => [
            'Řádky v seznamech jsou výrazněji barevně odlišené podle stavu zakázky.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Odesílání e-mailů (SMTP)',
        'items' => [
            'Nastaveno odesílání e-mailů z adresy info@applefix.cz (zakázkové listy, upozornění na reklamace).',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Pravidelné zálohy CRM',
        'items' => [
            'Celé CRM včetně databáze se zálohuje 3× denně; zálohy starší než jeden měsíc se automaticky mažou.',
        ],
    ],
    [
        'date' => '2026-07-08',
        'title' => 'Obnovení technika',
        'items' => [
            'Vrácen technik Zdenda (pobočka Karlín) včetně jeho uvítacího zvuku po přihlášení.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Mluvené uvítání při přihlášení',
        'items' => [
            'Po kliknutí na Přihlásit se každému zaměstnanci přehraje jeho osobní uvítací zvuk (s elegantní Apple načítací animací), pak systém pokračuje dál.',
            'Zvuky (mp3/m4a/wav, ~5 s) nahrává admin v Nastavení → Zaměstnanci → Uvítací zvuky, včetně poslechu a smazání.',
            'Připravené zvuky všech zaměstnanců (Tomáš, Lukáš, Zdeněk, Khalil, Roman, admin) se přiřadily automaticky.',
            'Bez nahraného zvuku se přihlášení chová jako dřív; klientů se uvítání netýká.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Nastavení → Tisk štítků (samoobslužná instalace)',
        'items' => [
            'Nová záložka v Nastavení dostupná všem zaměstnancům: ukáže, jestli na aktuálním počítači běží tiskový můstek.',
            'Když neběží, nabídne instalaci jedním zkopírovaným příkazem — instaluje se jednou, běží trvale.',
            'Tlačítka Náhled štítku a Zkušební tisk pro ověření bez zakládání zakázky.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Automatický tisk štítků zakázek (Brother QL-810W)',
        'items' => [
            'Po založení zakázky se hned automaticky vytiskne štítek: čárový kód s číslem zakázky + jméno a příjmení klienta + krátký popis závady + datum přijetí.',
            'Naskenování kódu (ruční skener nebo QR tlačítko v CRM) otevře zakázku.',
            'Tlačítko „Štítek — Brother QL" i v detailu zakázky (menu tisků).',
            'Tiskne stejná tiskárna a stejnou cestou jako aplikace Naskladnění produktů — lokální můstek na prodejním MacBooku (instalace jedním příkazem, návod v print-bridge/README).',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Hlídání zakázek bez pohybu',
        'items' => [
            'Zakázka, u které se dlouho nic neděje, začne v seznamech decentně „dýchat" svou stavovou barvou — pomalu při první prodlevě, rychleji při delší.',
            'Limity: Přijato 1h/2h · V opravě 1h/2h · Externí servis 24h · Autorizovaný servis 48h · Čeká na díl 1h/12h · Vydáno - čeká na platbu 24h.',
            'Najetím myší se dýchání zastaví; popisek ukáže, jak dlouho zakázka stojí (např. „Bez pohybu: 3h 20min").',
            'Jakákoli úprava zakázky nebo změna stavu časomíru vynuluje.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Nový stavový model zakázek',
        'items' => [
            'Nové zakázky používají 10 přehledných stavů: Přijato · V opravě · V opravě - v externím servisu · V opravě - v autorizovaném servisu · Čeká na díl · Připraveno k převzetí · Vydáno - čeká na platbu · Vydáno · Nevyzvednuto · Stornováno.',
            'Starší a importované zakázky si ponechávají původní stavy — nic se nepřevádí, jen už je nelze nově vybrat.',
            'Historie pohybu ukazuje u „V opravě" i jméno technika (V opravě: Martin) — v CRM i na klientském portálu.',
            'Klientský portál nově zobrazuje celou historii zakázky (datum + stav).',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Pravidlo pro import ze starého zakázkového listu',
        'items' => [
            'Budoucí importy převádí staré značky korektně na nový model: „černá růže" = stav Přijato + pobočka Praha 1 - Na Příkopě.',
            'Původní hodnota se vždy uchová v poznámkách zakázky; import má vestavěný samotest převodů.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Zrušen stav „Černá růže"',
        'items' => [
            'Historická značka zakázek druhé pobočky — dnes to řeší oddělené pobočky.',
            'Staré zakázky s tímto stavem automaticky převedeny na „Přijato" + pobočku Praha 1 - Na Příkopě.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Čas na zakázkách po technicích (Přehledy)',
        'items' => [
            'Čas technika na zakázce běží od přidělení/přijetí do předání jinému technikovi nebo dokončení — při předání se jednomu zastaví a druhému začne.',
            'Denně se započítává jen doba, kdy byl technik skutečně přítomný v systému (žádné noci a víkendy).',
            'V Přehledech nová tabulka: datum, pracovník, č. zakázky, čas — za zvolené období.',
            'Oprava: evidence přítomnosti nyní správně počítá i techniky a manažery (dřív jen administrátory).',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Historie úprav přímo v sekci Aktualizace',
        'items' => [
            'Tenhle přehled — každé dokončené vylepšení systému tu bude poznamenané.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Evidence přítomnosti v systému (Přehledy)',
        'items' => [
            'CRM počítá aktivní čas přihlášení každého pracovníka (technik, manažer, admin) — po dnech.',
            'V Přehledech nová sekce: denní záznamy + součet za zvolené období, formát „2h 6min".',
            'Mezery v aktivitě nad 10 minut se nepočítají; klienti se neevidují.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Menu: Nová zakázka nahoru + zvýraznění',
        'items' => [
            '„Nová zakázka" je první položka sekce Práce, s elegantním dýchajícím modrým zvýrazněním.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Číslování zakázek dle importované řady',
        'items' => [
            'Všude se zobrazuje jen číslo v původním tvaru ze zakázkového listu (APFAZ…), interní „ID #" odstraněno.',
            'Sloupec přejmenován na „Č. zakázky / Vytvořeno".',
            'Nové zakázky automaticky navazují na číselnou řadu (APFAZ2600485 → APFAZ2600486).',
            'Kód zakázky i v nadpisu detailu, čárovém kódu a všech tiskových výstupech.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Oprava našeptávačů v oknech',
        'items' => [
            'Výběr klienta/dílů v modálních oknech (nová zakázka, detail, nákup, sklad) se zobrazoval mimo pole — opraveno.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Barevné stavy přes celé řádky',
        'items' => [
            'Řádek zakázky nese barvu svého stavu v celé šířce + barevný proužek vlevo (dashboard i seznam zakázek).',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Světlý a tmavý režim',
        'items' => [
            'Přepínač ☀️/🌙 vpravo nahoře (i na loginu a klientském portálu), volba se každému pamatuje.',
            'Světlý režim v Apple stylu (stříbrná, bílé sklo); kontrast obou režimů prošel automatickým auditem bez chyb.',
        ],
    ],
    [
        'date' => '2026-07-07',
        'title' => 'Nový vzhled: Liquid Glass',
        'items' => [
            'Kompletní redesign v duchu Apple Liquid Glass — skutečná refrakce skla, odlesky reagující na myš.',
            'Nová paleta: grafit + Apple modrá (nahradila fialovou).',
            'Čitelnost dat na prvním místě: tabulky téměř plné, sklo jen na chromu.',
        ],
    ],
];
