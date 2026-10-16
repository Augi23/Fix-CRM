<?php
/**
 * IMPORT BANKOVNÍHO VÝPISU ve formátu GPC/ABO (v3.93.0)
 * Náhrada za KB API, dokud není ostré napojení (kb_env = sandbox): výpis stažený
 * z internetového bankovnictví (KB → Výpisy → formát GPC/ABO) se nahraje v Účetnictví
 * → Banka a pohyby se uloží do bank_transactions pod zdrojem env='prod', account_id='gpc'.
 * Pak proběhne stejné párování jako u API (storna → faktury podle VS → e-shop převody).
 *
 * Formát (Fio, struktura GPC; standard ABO): pevná délka řádku, bez oddělovačů.
 *   074 = hlavička výpisu (datum účtování na pozici 109–114, DDMMRR)
 *   075 = obratová položka: 4–19 vlastní účet, 20–35 protiúčet, 36–48 číslo dokladu,
 *         49–60 částka v haléřích, 61 kód (1 debet, 2 kredit, 4 storno debet, 5 storno kredit),
 *         62–71 VS, 72–81 BBBB+KS (kód banky protiúčtu + konst. symbol), 82–91 SS,
 *         98–117 název protiúčtu, 123–128 datum (DDMMRR)
 *   ostatní řádky (078/079 rozšíření apod.) se přeskakují.
 */
require_once __DIR__ . '/kb_api.php';

const AFX_GPC_ENV = 'prod';
const AFX_GPC_ACCOUNT = 'gpc';

/** Zdroj bankovních pohybů pro přehled/párování: GPC výpisy, nebo KB API. */
function afxBankScope(): array {
    if ((string)get_setting('bank_import_mode', '') === 'gpc') { return [AFX_GPC_ENV, AFX_GPC_ACCOUNT]; }
    return [kbApiEnv(), (string)get_setting('kb_account_id', '')];
}

function afxGpcDate(string $ddmmrr): ?string {
    if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $ddmmrr, $m) || $ddmmrr === '000000') { return null; }
    $y = 2000 + (int)$m[3];
    return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]) : null;
}

/** Číslo účtu z 16 číslic (6 předčíslí + 10 číslo) → „předčíslí-číslo" bez vodících nul. */
function afxGpcAccount(string $d16, string $bank): string {
    $d16 = preg_replace('/\D+/', '', $d16) ?? '';
    if ($d16 === '' || ltrim($d16, '0') === '') { return ''; }
    $d16 = str_pad($d16, 16, '0', STR_PAD_LEFT);
    $pre = ltrim(substr($d16, 0, 6), '0');
    $num = ltrim(substr($d16, 6), '0');
    $bank = ltrim($bank, ' ');
    return ($pre !== '' ? $pre . '-' : '') . $num . (preg_match('/^\d{4}$/', $bank) && $bank !== '0000' ? '/' . $bank : '');
}

/** Rozparsuje GPC soubor → pole pohybů (sloupce bank_transactions). */
function afxParseGpc(string $raw): array {
    // Pozice se počítají v BAJTECH původního souboru (banky posílají Windows-1250,
    // 1 znak = 1 bajt) — převádí se až vytažený název, ne celý soubor.
    $rows = []; $stmtDate = null; $own = '';
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        if (strlen($line) < 61) { continue; }
        $type = substr($line, 0, 3);
        if ($type === '074') {
            $own = ltrim(substr($line, 3, 16), '0');
            $stmtDate = afxGpcDate(substr($line, 108, 6));
            continue;
        }
        if ($type !== '075') { continue; }
        // pozice z 1-číslování specifikace → substr(start-1, délka)
        $code = substr($line, 60, 1);
        if (!in_array($code, ['1', '2', '4', '5'], true)) { continue; }
        $amount = round(((int)ltrim(substr($line, 48, 12), '0')) / 100, 2);
        if ($amount <= 0) { continue; }
        $doc = trim(substr($line, 35, 13));
        $ksField = substr($line, 71, 10);
        $bank = substr($ksField, 2, 4);   // „BBBBKSYM" v 10 znacích s vodícími nulami → 00BBBBKSYM
        $ks = ltrim(substr($ksField, 6, 4), '0');
        $vs = ltrim(substr($line, 61, 10), '0');
        $ss = ltrim(substr($line, 81, 10), '0');
        $name = substr($line, 97, 20);
        $name = trim(mb_check_encoding($name, 'UTF-8') ? $name : (string)@iconv('WINDOWS-1250', 'UTF-8//TRANSLIT', $name));
        // datum je posledních 6 znaků řádku (123–128) — odzadu, kdyby byl soubor v UTF-8
        $date = afxGpcDate(substr(rtrim($line), -6)) ?? $stmtDate;
        $dir = in_array($code, ['2', '5'], true) ? 'in' : 'out';
        $cp = afxGpcAccount(substr($line, 19, 16), $bank);
        $rows[] = [
            // stabilní klíč: číslo dokladu banky + datum + částka + směr (opakovaný import = beze změny)
            'entry_ref' => 'gpc-' . substr(md5(implode('|', [$own, $doc, $date, $amount, $code, $vs, $cp])), 0, 24) . '-' . $doc,
            'booking_date' => $date, 'amount' => $amount, 'direction' => $dir,
            'is_reversal' => in_array($code, ['4', '5'], true) ? 1 : 0,
            'counterparty_name' => $name !== '' ? $name : null,
            'counterparty_account' => $cp !== '' ? $cp : null,
            'vs' => $vs !== '' ? $vs : null, 'ss' => $ss !== '' ? $ss : null, 'ks' => $ks !== '' ? $ks : null,
            'raw' => mb_check_encoding($line, 'UTF-8') ? $line : (string)@iconv('WINDOWS-1250', 'UTF-8//TRANSLIT', $line),
        ];
    }
    return $rows;
}

/**
 * Uloží pohyby z výpisu a spustí párování. Vrací souhrn pro UI.
 * Duplicitní řádky (stejný výpis nahraný 2×) se přeskočí díky UNIQUE(env, account_id, entry_ref).
 */
function afxImportGpc(string $raw): array {
    global $pdo;
    ensureBankTables();
    $rows = afxParseGpc($raw);
    if (!$rows) { return ['ok' => false, 'error' => 'V souboru nejsou žádné pohyby ve formátu GPC/ABO (řádky 075). Stáhni výpis z KB ve formátu GPC.']; }
    $ins = $pdo->prepare("INSERT IGNORE INTO bank_transactions
            (entry_ref, env, account_id, booking_date, amount, currency, direction,
             counterparty_name, counterparty_account, vs, ss, ks, message, tx_status, is_reversal, raw)
        VALUES (?, ?, ?, ?, ?, 'CZK', ?, ?, ?, ?, ?, ?, NULL, 'BOOK', ?, ?)");
    $new = 0;
    foreach ($rows as $r) {
        $ins->execute([mb_substr($r['entry_ref'], 0, 150), AFX_GPC_ENV, AFX_GPC_ACCOUNT, $r['booking_date'], $r['amount'], $r['direction'],
            $r['counterparty_name'] ? mb_substr($r['counterparty_name'], 0, 190) : null, $r['counterparty_account'],
            $r['vs'], $r['ss'], $r['ks'], $r['is_reversal'], $r['raw']]);
        $new += $ins->rowCount();
    }
    set_setting('bank_import_mode', 'gpc');
    set_setting('bank_gpc_last_import_at', date('Y-m-d H:i:s'));
    $reversed = 0; $match = ['matched' => 0, 'review' => 0]; $eshop = 0;
    try { $reversed = kbApplyReversals(AFX_GPC_ENV, AFX_GPC_ACCOUNT); } catch (Throwable $e) { error_log('gpc reversals: ' . $e->getMessage()); }
    try { [$mm, $mr] = kbAutoMatchInvoices(AFX_GPC_ENV, AFX_GPC_ACCOUNT); $match = ['matched' => (int)$mm, 'review' => (int)$mr]; } catch (Throwable $e) { error_log('gpc match: ' . $e->getMessage()); }
    try { if (function_exists('afxEshopAutoMatchTransfers')) { $eshop = afxEshopAutoMatchTransfers(AFX_GPC_ENV, AFX_GPC_ACCOUNT); } } catch (Throwable $e) { error_log('gpc eshop: ' . $e->getMessage()); }
    try {
        crmAuditLog('bank.gpc_import', ['entity_type' => 'bank', 'summary' => 'Nahrán bankovní výpis (GPC): ' . count($rows) . ' pohybů, nových ' . $new
            . ', spárováno faktur ' . (int)($match['matched'] ?? 0) . ', k prověření ' . (int)($match['review'] ?? 0) . ', e-shop ' . (int)$eshop]);
    } catch (Throwable $e) {}
    return ['ok' => true, 'error' => '', 'rows' => count($rows), 'new' => $new, 'matched' => (int)($match['matched'] ?? 0),
            'review' => (int)($match['review'] ?? 0), 'eshop' => (int)$eshop, 'reversed' => (int)$reversed];
}
