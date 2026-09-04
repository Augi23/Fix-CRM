<?php
/**
 * VLASTNÍ HODNOTY V NABÍDKÁCH — test (v3.75.0).
 *
 * Co hlídá: když se do formuláře napíše výrobce / typ zařízení / model, který
 * v nabídce nebyl, musí být od PŘÍŠTÍHO zadání v seznamu — a to na obou
 * místech, kde se zařízení zadává:
 *   · Sklad → „Naskladnit produkt" (pole „✏️ Vlastní…"),
 *   · „Nová zakázka" → značka a model (volný text v select2).
 * A zároveň že se do seznamů nesype nic navíc: vestavěné hodnoty, placeholder
 * „✏️ Vlastní…" ani jednopísmenné překlepy se neukládají.
 *
 * Test běží BEZ databáze — místo PDO má paměťovou napodobeninu tabulky
 * product_catalog_custom (včetně case-insensitive UNIQUE, jak ji má MySQL
 * s kolací utf8mb4_czech_ci). Testuje se tím logika, ne server.
 *
 * Spuštění z kořene CRM:  php scripts/catalog_custom_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

/** Napodobenina PDO nad jedinou tabulkou product_catalog_custom. */
class AfxFakeCatalogStmt {
    private int $affected = 0;
    public function __construct(private AfxFakeCatalogPdo $db, private string $sql) {}
    public function execute(array $p = []): bool {
        $this->affected = 0;
        if (str_contains($this->sql, 'INSERT IGNORE INTO product_catalog_custom')) {
            foreach ($this->db->rows as $r) {
                if (mb_strtolower($r['kind']) === mb_strtolower((string)$p[0])
                    && mb_strtolower($r['context']) === mb_strtolower((string)$p[1])
                    && mb_strtolower($r['value']) === mb_strtolower((string)$p[2])) return true;   // UNIQUE
            }
            $this->db->rows[] = ['kind' => (string)$p[0], 'context' => (string)$p[1], 'value' => (string)$p[2]];
            $this->affected = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->affected; }
}
class AfxFakeCatalogPdo {
    public array $rows = [];
    public function exec(string $sql): int { return 0; }                     // CREATE TABLE IF NOT EXISTS
    public function query(string $sql): array {
        $r = $this->rows;
        usort($r, static fn($a, $b) => strcmp($a['value'], $b['value']));     // ORDER BY value
        return $r;
    }
    public function prepare(string $sql): AfxFakeCatalogStmt { return new AfxFakeCatalogStmt($this, $sql); }
}

/** Číselník značek z CRM (device_brands) — v testu pevný, bez databáze. */
function getDeviceBrands(): array { return ['Apple', 'Samsung', 'Other']; }

$pdo = new AfxFakeCatalogPdo();
require_once dirname(__DIR__) . '/includes/product_catalog.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

/** Nabízí formulář naskladnění tuhle trojici? (přesně jak ji poskládá products.php) */
function nabidkaSkladu(string $manuf, string $typ, string $model): array {
    $m = afxProductCatalogMerged();
    $def = null; $generic = null;
    foreach ($m['types'] as $t) {
        if ($t['id'] !== $typ) continue;
        if (($t['manuf'] ?? '') === $manuf) { $def = $t; break; }
        if (($t['manuf'] ?? '') === '' && $generic === null) $generic = $t;
    }
    $def = $def ?? $generic;
    return [
        'vyrobce' => afxCatalogListHas($m['manufacturers'], $manuf),
        'typ' => $def !== null,
        'model' => $def !== null && afxCatalogListHas($def['models'] ?? [], $model),
    ];
}
function nabidkaZakazky(string $brand, string $type, string $model): array {
    return [
        'znacka' => afxCatalogListHas(crmOrderBrands(), $brand),
        'model' => afxCatalogListHas(crmOrderModelCatalog()[$brand][$type] ?? [], $model),
    ];
}

head('Sklad → „Naskladnit produkt": vlastní hodnoty se pamatují');
$skladCases = [
    'vlastní model u vestavěné dvojice' => ['manufacturer' => 'Apple', 'typ' => 'iPhone', 'model' => 'iPhone 18 Pro Max', 'color' => ''],
    'vlastní výrobce + obecný typ' => ['manufacturer' => 'OnePlus', 'typ' => 'Telefon', 'model' => 'OnePlus 12', 'color' => 'Emerald'],
    'vestavěný výrobce + vlastní typ' => ['manufacturer' => 'Apple', 'typ' => 'Studio Display', 'model' => 'Studio Display 27', 'color' => 'Silver'],
    'všechno vlastní' => ['manufacturer' => 'Garmin', 'typ' => 'Sporttester', 'model' => 'Forerunner 265', 'color' => ''],
];
foreach ($skladCases as $name => $in) {
    $pred = nabidkaSkladu($in['manufacturer'], $in['typ'], $in['model']);
    afxCatalogRegisterCustomValues($in, 'product');                 // ← uložení produktu
    $po = nabidkaSkladu($in['manufacturer'], $in['typ'], $in['model']);
    ok("$name — model do nabídky přibyl", !$pred['model'] && $po['model']);
    ok("$name — výrobce i typ jsou v nabídce", $po['vyrobce'] && $po['typ']);
}

head('Vestavěné hodnoty se neukládají (seznam se nenafukuje)');
$pocetPred = count($pdo->rows);
afxCatalogRegisterCustomValues(['manufacturer' => 'Apple', 'typ' => 'iPhone', 'model' => 'iPhone 15', 'color' => 'Black'], 'product');
ok('Apple / iPhone / iPhone 15 nepřidalo nic', count($pdo->rows) === $pocetPred, 'přibylo ' . (count($pdo->rows) - $pocetPred));

head('Placeholder a překlepy se ignorují');
$pocetPred = count($pdo->rows);
afxCatalogCustomAdd('manufacturer', '', '✏️ Vlastní…');   // placeholder z nabídky
crmCatalogRegisterOrderDevice('X', 'Phone', 'Xperia 1 VI');   // jednopísmenná značka
crmCatalogRegisterOrderDevice('Fairphone', 'Phone', 'Q');     // jednopísmenný model
ok('přibyla jen značka Fairphone, nic jiného', count($pdo->rows) === $pocetPred + 1, 'přibylo ' . (count($pdo->rows) - $pocetPred));
ok('model „Q" se neuložil', !afxCatalogListHas(crmOrderModelCatalog()['Fairphone']['Phone'] ?? [], 'Q'));
ok('značka „X" ani placeholder v nabídce nejsou',
    !afxCatalogListHas(crmOrderBrands(), 'X') && !afxCatalogListHas(crmOrderBrands(), '✏️ Vlastní…'));

head('Nová zakázka: značka a model dopsané rukou');
$pred = nabidkaZakazky('Nothing', 'Phone', 'Phone (2a)');
crmCatalogRegisterOrderDevice('Nothing', 'Phone', 'Phone (2a)');   // ← uložení zakázky
$po = nabidkaZakazky('Nothing', 'Phone', 'Phone (2a)');
ok('značka přibyla do nabídky', !$pred['znacka'] && $po['znacka']);
ok('model přibyl do nabídky', !$pred['model'] && $po['model']);
ok('u jiného typu zařízení se model nenabízí', !afxCatalogListHas(crmOrderModelCatalog()['Nothing']['Notebook'] ?? [], 'Phone (2a)'));

head('Malá/velká písmena nedělají druhou položku');
$pocetPred = count($pdo->rows);
crmCatalogRegisterOrderDevice('nothing', 'Phone', 'phone (2a)');
ok('„nothing / phone (2a)" nezaložilo duplicitu', count($pdo->rows) === $pocetPred, 'přibylo ' . (count($pdo->rows) - $pocetPred));
$znacky = crmOrderBrands();
ok('značka zůstala jen jednou', count(array_filter($znacky, static fn($b) => mb_strtolower($b) === 'nothing')) === 1);

head('Sklad a zakázky sdílejí, co se naučily');
ok('vlastní výrobce ze skladu je i mezi značkami zakázky', afxCatalogListHas(crmOrderBrands(), 'Garmin'));
ok('vlastní model ze skladu je i v nabídce zakázky (iPhone → Phone)',
    afxCatalogListHas(crmOrderModelCatalog()['Apple']['Phone'] ?? [], 'iPhone 18 Pro Max'));
ok('vestavěné značky zůstávají', afxCatalogListHas(crmOrderBrands(), 'Apple') && afxCatalogListHas(crmOrderBrands(), 'Samsung'));

head('Neznámý typ zakázky spadne do „Other" (kontext se nerozbije)');
crmCatalogRegisterOrderDevice('Nothing', 'Nesmysl', 'Ear (a)');
ok('model se uložil pod Other', afxCatalogListHas(crmOrderModelCatalog()['Nothing']['Other'] ?? [], 'Ear (a)'));

echo "\n" . ($fail === 0 ? "✅ Vše prošlo" : "❌ NEPROŠLO") . ": $pass ok, $fail chyb\n";
exit($fail === 0 ? 0 : 1);
