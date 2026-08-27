<?php
/**
 * MINIMÁLNÍ ZAPISOVAČ XLSX (v3.68.0)
 *
 * Proč vlastní a ne knihovna: CRM nemá composer ani vendor adresář a kvůli
 * exportu sestav se nevyplatí ho zavádět. Potřebujeme jednu tabulku na list,
 * tučnou hlavičku, čísla jako ČÍSLA (aby v Excelu šlo sčítat), data jako data
 * a rozumné šířky sloupců — to všechno je pár set řádků XML v ZIPu.
 *
 * Proč ne CSV: v českém Excelu se CSV pere s oddělovači a desetinnou čárkou
 * a čísla končí jako text. Účetní pak nemůže nic sečíst, což je celý smysl
 * exportu do Excelu.
 *
 * Použití:
 *   $x = new AfxXlsx('Kniha faktur');
 *   $x->header(['Číslo', 'Datum', 'Částka']);
 *   $x->row(['2026001', '2026-08-01', 1234.5], ['text', 'date', 'money']);
 *   $x->send('kniha-faktur.xlsx');
 */

final class AfxXlsx
{
    private string $sheetName;
    /** @var array<int, string> hotové <row> XML */
    private array $rows = [];
    private int $rowIndex = 0;
    /** @var array<int, int> nejdelší text ve sloupci (pro šířku) */
    private array $widths = [];
    private int $headerRows = 0;

    public function __construct(string $sheetName = 'List1')
    {
        // Excel: max 31 znaků a bez : \ / ? * [ ]
        $n = preg_replace('/[:\\\\\/\?\*\[\]]/u', '-', trim($sheetName)) ?: 'List1';
        $this->sheetName = mb_substr($n, 0, 31);
    }

    /** Tučná hlavička (a zmrazení, ať se při rolování drží). */
    public function header(array $cells): void
    {
        $this->addRow($cells, array_fill(0, count($cells), 'text'), true);
        $this->headerRows = $this->rowIndex;
    }

    /**
     * Řádek dat. $types: text | money | number | int | date | bool
     * Neznámý typ se bere jako text.
     */
    public function row(array $cells, array $types = []): void
    {
        $this->addRow($cells, $types, false);
    }

    /** Prázdný oddělovací řádek (třeba před součty). */
    public function blank(): void { $this->row([]); }

    private function addRow(array $cells, array $types, bool $bold): void
    {
        $this->rowIndex++;
        $r = $this->rowIndex;
        $xml = '<row r="' . $r . '">';
        $i = 0;
        foreach ($cells as $value) {
            $ref = self::colLetter($i) . $r;
            $type = (string)($types[$i] ?? 'text');
            $xml .= $this->cell($ref, $value, $type, $bold);
            $len = mb_strlen(is_scalar($value) ? (string)$value : '');
            $this->widths[$i] = max($this->widths[$i] ?? 8, min(60, $len + 2));
            $i++;
        }
        $this->rows[] = $xml . '</row>';
    }

    private function cell(string $ref, $value, string $type, bool $bold): string
    {
        $style = $bold ? 1 : self::styleFor($type);
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        if (in_array($type, ['money', 'number', 'int'], true) && is_numeric(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], (string)$value))) {
            $num = (float)str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], (string)$value);
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.') . '</v></c>';
        }
        if ($type === 'date') {
            $serial = self::dateSerial((string)$value);
            if ($serial !== null) {
                return '<c r="' . $ref . '" s="' . $style . '"><v>' . $serial . '</v></c>';
            }
        }
        // inlineStr: nepotřebuje sdílenou tabulku řetězců a je čitelný
        return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
            . self::esc((string)$value) . '</t></is></c>';
    }

    private static function styleFor(string $type): int
    {
        return match ($type) {
            'money' => 2,     // # ##0,00
            'int'   => 3,     // celé číslo
            'date'  => 4,     // d.m.yyyy
            default => 0,
        };
    }

    /**
     * Datum → sériové číslo Excelu (0 = 30. 12. 1899).
     *
     * POZOR na časové pásmo: převod přes floor(timestamp/86400) je ŠPATNĚ.
     * Půlnoc v Praze je v UTC ještě předchozí den, takže by se každé datum
     * v sestavě posunulo o den zpátky — u data vystavení, DUZP a splatnosti
     * je to chyba, které by si účetní všimla až na finančáku.
     * Proto se počítá rozdíl kalendářních dnů, ne sekund.
     */
    private static function dateSerial(string $value): ?int
    {
        $v = trim($value);
        if ($v === '' || $v === '0000-00-00') { return null; }
        try {
            $d = new DateTimeImmutable($v);
        } catch (Exception $e) {
            return null;
        }
        $den = $d->setTime(0, 0, 0);
        $zaklad = new DateTimeImmutable('1899-12-30 00:00:00', $den->getTimezone());
        $diff = $zaklad->diff($den);
        $dny = (int)$diff->days;
        return $diff->invert ? -$dny : $dny;
    }

    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $mod = ($i - 1) % 26;
            $s = chr(65 + $mod) . $s;
            $i = (int)(($i - $mod) / 26);
        }
        return $s;
    }

    private static function esc(string $s): string
    {
        // XML nesnese řídicí znaky; z dat z DB se občas přinese \r
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function sheetXml(): string
    {
        $cols = '';
        if ($this->widths) {
            $cols = '<cols>';
            foreach ($this->widths as $i => $w) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }
        $freeze = $this->headerRows > 0
            ? '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ySplit="' . $this->headerRows
              . '" topLeftCell="A' . ($this->headerRows + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';
        $auto = $this->headerRows > 0 && $this->widths
            ? '<autoFilter ref="A' . $this->headerRows . ':' . self::colLetter(count($this->widths) - 1) . $this->rowIndex . '"/>'
            : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $freeze . $cols
            . '<sheetData>' . implode('', $this->rows) . '</sheetData>'
            . $auto
            . '</worksheet>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="d\.m\.yyyy"/>'
            . '</numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFEFEF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                  // 0 text
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'      // 1 hlavička
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'          // 2 částka
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'            // 3 celé číslo
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'          // 4 datum
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** Sestaví soubor a pošle ho prohlížeči ke stažení. */
    public function send(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'afxxlsx');
        if ($tmp === false) { throw new RuntimeException('Nepodařilo se připravit dočasný soubor.'); }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Nepodařilo se vytvořit sešit.');
        }
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();

        $name = preg_replace('/[^\w\-. ]+/u', '_', $filename) ?: 'export.xlsx';
        if (PHP_SAPI === 'cli' || headers_sent()) {
            // testy si sešit jen vypíšou do proměnné; hlavičky by tam jen hlásily chybu
            readfile($tmp);
            @unlink($tmp);
            return;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
    }
}
