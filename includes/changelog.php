<?php
/**
 * Historie úprav CRM — ručně vedený, lidsky čitelný přehled dokončených
 * vylepšení. Zobrazuje se v Nastavení → Aktualizace pod git changelogem.
 * Nové záznamy přidávat NAHORU (nejnovější první).
 */
return [
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
