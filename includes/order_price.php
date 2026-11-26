<?php
/**
 * Cena zakázky při příjmu: „jen diagnostika" × odhad ceny opravy (v4.5.0).
 *
 * Proč: odhad ceny se při výdeji bere jako částka k zaplacení (final_cost ?: estimated_cost).
 * Když někdo do odhadu napsal jen cenu diagnostiky a oprava pak proběhla, zakázka se vydala
 * za cenu diagnostiky. Proto má diagnostika vlastní sloupec a zakázka „jen na diagnostiku"
 * nemá žádný odhad — dokud se cena nestanoví, nejde dokončit ani vydat.
 *
 *   orders.diag_fee       cena diagnostiky domluvená při příjmu (NULL = běžná zakázka s odhadem)
 *   orders.price_pending  1 = cena opravy zatím není stanovená → blokuje „hotovo" i výdej
 *
 * Cenu stanoví JEDINĚ crmOrderResolvePrice() (okno „Stanovit cenu" v detailu zakázky),
 * kde obsluha výslovně řekne, jestli oprava proběhla, nebo se účtuje jen diagnostika.
 */

const AFX_DIAG_FEE_PHONE = 490;   // telefony
const AFX_DIAG_FEE_OTHER = 990;   // tablety, počítače a ostatní

/** Sloupce v orders — DDL, volat PŘED beginTransaction(). */
function ensureOrderDiagColumns(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    if ($pdo->inTransaction()) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $add = [];
        if (!in_array('diag_fee', $cols, true))      { $add[] = "ADD COLUMN diag_fee DECIMAL(10,2) NULL DEFAULT NULL"; }
        if (!in_array('price_pending', $cols, true)) { $add[] = "ADD COLUMN price_pending TINYINT(1) NOT NULL DEFAULT 0"; }
        if ($add) { $pdo->exec("ALTER TABLE orders " . implode(', ', $add)); }
        $done = true;
    } catch (Throwable $e) { error_log('ensureOrderDiagColumns: ' . $e->getMessage()); }
}

/** Cena diagnostiky podle typu zařízení (dá se přepsat nastavením diag_fee_phone / diag_fee_other). */
function crmDiagFee(string $deviceType): float {
    $phone = (float)get_setting('diag_fee_phone', (string)AFX_DIAG_FEE_PHONE);
    $other = (float)get_setting('diag_fee_other', (string)AFX_DIAG_FEE_OTHER);
    if ($phone <= 0) { $phone = AFX_DIAG_FEE_PHONE; }
    if ($other <= 0) { $other = AFX_DIAG_FEE_OTHER; }
    return $deviceType === 'Phone' ? $phone : $other;
}

/** Zakázka přijatá jen na diagnostiku, u které ještě nikdo nestanovil cenu. */
function crmOrderPricePending(array $order): bool {
    return (int)($order['price_pending'] ?? 0) === 1;
}

/** Stavy, do kterých zakázka bez stanovené ceny nesmí: hotovo / nevyzvednuto / vydáno. */
function crmPricePendingBlocksStatus(string $status): bool {
    return $status === 'Vydáno - čeká na platbu' || in_array($status, getOrderStatusList('done'), true);
}

function crmPricePendingMessage(array $order): string {
    $fee = (float)($order['diag_fee'] ?? 0);
    return 'Zakázka byla přijata jen na diagnostiku' . ($fee > 0 ? ' (' . formatMoney($fee) . ')' : '')
        . ' a cena opravy ještě není stanovená. Nejdřív v detailu zakázky klikni na „Stanovit cenu“'
        . ' a zvol, jestli oprava proběhla, nebo se účtuje jen diagnostika.';
}

/**
 * Stanoví cenu zakázky přijaté na diagnostiku.
 *   repair    — oprava proběhne / proběhla, $amount = celková cena pro klienta (> 0)
 *   diag_only — bez opravy, účtuje se jen diagnostika (uložená cena diagnostiky)
 *   free      — neúčtuje se nic (záruka, reklamace, vstřícné gesto)
 * Vrací [ok, zpráva].
 */
function crmOrderResolvePrice(int $orderId, string $mode, ?float $amount = null): array {
    global $pdo;
    ensureOrderDiagColumns();
    $st = $pdo->prepare("SELECT id, order_code, status, branch_id, technician_id, diag_fee, price_pending, estimated_cost, final_cost FROM orders WHERE id = ?");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { return [false, 'Zakázka nenalezena.']; }
    if (!crmOrderPricePending($o)) { return [false, 'U téhle zakázky už cena stanovená je.']; }
    $fee = round((float)($o['diag_fee'] ?? 0), 2);

    if ($mode === 'repair') {
        $price = round((float)$amount, 2);
        if ($price <= 0) { return [false, 'Zadej cenu opravy.']; }
        // cena po diagnostice = odhad, se kterým klient souhlasil; konečnou cenu doplní výdej jako u každé zakázky
        $pdo->prepare("UPDATE orders SET estimated_cost = ?, final_cost = NULL, price_pending = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND price_pending = 1")
            ->execute([$price, $orderId]);
        $what = 'oprava za ' . formatMoney($price);
    } elseif ($mode === 'diag_only') {
        if ($fee <= 0) { return [false, 'U zakázky není uložená cena diagnostiky.']; }
        $pdo->prepare("UPDATE orders SET estimated_cost = ?, final_cost = ?, price_pending = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND price_pending = 1")
            ->execute([$fee, $fee, $orderId]);
        // rozpis z příjmu (příplatek za spěch k opravě, která neproběhla) už neplatí
        try {
            ensureOrderPriceLinesTable();
            $pdo->prepare("DELETE FROM order_price_lines WHERE order_id = ?")->execute([$orderId]);
            crmAddOrderPriceLine($orderId, 'Diagnostika (bez opravy)', $fee, 0);
        } catch (Throwable $e) {}
        $what = 'jen diagnostika ' . formatMoney($fee) . ', bez opravy';
    } elseif ($mode === 'free') {
        $pdo->prepare("UPDATE orders SET estimated_cost = 0, final_cost = 0, price_pending = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND price_pending = 1")
            ->execute([$orderId]);
        try { ensureOrderPriceLinesTable(); $pdo->prepare("DELETE FROM order_price_lines WHERE order_id = ?")->execute([$orderId]); } catch (Throwable $e) {}
        $what = 'bez úhrady (' . formatMoney(0) . ')';
    } else {
        return [false, 'Neznámá volba.'];
    }

    $code = trim((string)($o['order_code'] ?? '')) !== '' ? (string)$o['order_code'] : ('#' . $orderId);
    try {
        crmAuditLog('order.price_resolve', [
            'entity_type' => 'order', 'entity_id' => $orderId, 'entity_label' => $code,
            'summary' => 'Stanovena cena zakázky ' . $code . ' po diagnostice — ' . $what,
            'details' => ['mode' => $mode, 'amount' => $amount, 'diag_fee' => $fee],
            'branch_id' => $o['branch_id'] ?? null,
        ]);
    } catch (Throwable $e) {}
    return [true, 'Cena je stanovená: ' . $what . '.'];
}
