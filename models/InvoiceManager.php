<?php
/**
 * InvoiceManager Class
 * Handles all logic for Invoices, Items, Statuses and Conversions.
 */

class InvoiceManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get single invoice with items and customer data
     */
    public function getInvoice($id) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, c.first_name, c.last_name, c.company, c.address, c.ico, c.dic 
            FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
    if ($invoice) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map item_name to name for JS frontend consistency
        foreach ($items as &$item) {
            $item['name'] = $item['item_name'];
        }
        $invoice['items'] = $items;

        // Map status to readable format
        $invoice['status_badge'] = $this->getInvoiceStatusBadge($invoice['status']);

        // Check for linked order
        if (!empty($invoice['order_id'])) {
            $stmt_o = $this->pdo->prepare("SELECT device_brand, device_model FROM orders WHERE id = ?");
            $stmt_o->execute([$invoice['order_id']]);
            $ord = $stmt_o->fetch();
            if ($ord) {
                $invoice['order_display'] = "#" . $invoice['order_id'] . " (" . $ord['device_brand'] . " " . $ord['device_model'] . ")";
            }
        }
    }

    return $invoice;
}

private function getInvoiceStatusBadge($status) {
    switch ($status) {
        case 'draft': return 'Черновик';
        case 'issued': return 'Выставлен';
        case 'paid': return 'Оплачен';
        case 'overdue': return 'Просрочен';
        case 'cancelled': return 'Отменен';
        default: return $status;
    }
}

    public function saveInvoice($data) {
        if (!hasPermission('admin_access')
            && !(function_exists('crmCanUseInvoices') && crmCanUseInvoices())
            && !(function_exists('crmCanAccountingEdit') && crmCanAccountingEdit())) {
            return ['success' => false, 'error' => __('access_denied_simple')];
        }
        // schéma pro fakturu bez klienta a evidenci odeslání (DDL PŘED transakcí)
        if (function_exists('afxEnsureInvoiceAdhocBuyer')) { afxEnsureInvoiceAdhocBuyer(); }
        if (function_exists('afxEnsureInvoiceEmailColumns')) { afxEnsureInvoiceEmailColumns(); }
        if (function_exists('afxEnsureInvoiceBranch')) { afxEnsureInvoiceBranch(); }
        try {
            $this->pdo->beginTransaction();

            $id = !empty($data['id']) ? (int)$data['id'] : null;
            $invoice_number = $data['invoice_number'] ?? '';
            $customer_id = (int)($data['customer_id'] ?? 0);
            // faktura pro jednorázového odběratele klienta v CRM nemá — 0 by porušila cizí klíč
            $customer_id = $customer_id > 0 ? $customer_id : null;
            $date_issue = !empty($data['date_issue']) ? $data['date_issue'] : date('Y-m-d');
            $date_tax = !empty($data['date_tax']) ? $data['date_tax'] : $date_issue;
            $date_due = !empty($data['date_due']) ? $data['date_due'] : date('Y-m-d', strtotime('+14 days'));
            
            $status = !empty($data['status']) ? $data['status'] : 'issued';
            $is_vat_payer = (isset($data['is_vat_payer']) && ($data['is_vat_payer'] == '1' || $data['is_vat_payer'] === true)) ? 1 : 0;
            $payment_method = !empty($data['payment_method']) ? $data['payment_method'] : 'bank_transfer';
            // u existující faktury se datum platby přebírá (viz updateStatus) — editace
            // položek nesmí přepsat datum, kdy peníze reálně došly
            $payment_date = null;
            if ($status == 'paid') {
                $payment_date = date('Y-m-d');
                if ($id) {
                    $pv = $this->pdo->prepare("SELECT payment_date FROM invoices WHERE id = ?");
                    $pv->execute([$id]);
                    $payment_date = (string)$pv->fetchColumn() ?: $payment_date;
                }
            }

            // UZÁVĚRKA — kontrola v modelu, ne jen ve volajících: sem vedou i cesty
            // bez pre-checku (auto-faktura při dokončení zakázky). Jsme uvnitř
            // transakce — výjimka = rollback, žádný částečný zápis.
            if (function_exists('afxAccountingAssertOpen')) {
                afxAccountingAssertOpen($date_issue, 'fakturu');
                afxAccountingAssertOpen($date_tax, 'fakturu (DUZP)');
                if ($id) {
                    $pv0 = $this->pdo->prepare("SELECT date_issue, date_tax FROM invoices WHERE id = ?");
                    $pv0->execute([$id]);
                    $pvR = $pv0->fetch(PDO::FETCH_ASSOC) ?: [];
                    afxAccountingAssertOpen((string)($pvR['date_issue'] ?? '') ?: date('Y-m-d'), 'fakturu');
                    afxAccountingAssertOpen((string)($pvR['date_tax'] ?? '') ?: date('Y-m-d'), 'fakturu (DUZP)');
                }
                if ($status == 'paid' && $payment_date !== null) {
                    // sync po commitu zapíše platbu k tomuto datu — hlídat TEĎ, ne až
                    // po commitu (jinak faktura uložená + chyba = riziko duplicity).
                    // Doplatek při změně částky ale sync zapisuje k DNEŠKU, proto
                    // se hlídá i ten (zamčený aktuální měsíc = vzácný, ale reálný).
                    afxAccountingAssertOpen($payment_date, 'platbu');
                    afxAccountingAssertOpen(date('Y-m-d'), 'platbu');
                }
            }

            $currency = !empty($data['currency']) ? $data['currency'] : 'Kč';
            $notes = $data['notes'] ?? '';
            $variable_symbol = !empty($data['variable_symbol']) ? $data['variable_symbol'] : $invoice_number;
            
            // Manual customer override fields
            $cust_name = !empty($data['cust_name']) ? $data['cust_name'] : null;
            $cust_address = !empty($data['cust_address']) ? $data['cust_address'] : null;
            $cust_ico = !empty($data['cust_ico']) ? $data['cust_ico'] : null;
            $cust_dic = !empty($data['cust_dic']) ? $data['cust_dic'] : null;
            // e-mail odběratele (faktura bez klienta v CRM jinak nemá kam odejít).
            // Nesmysl radši odmítnout, než potichu uložit adresu, na kterou nic nedorazí.
            $cust_email = trim((string)($data['cust_email'] ?? ''));
            if ($cust_email !== '' && !filter_var($cust_email, FILTER_VALIDATE_EMAIL)) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'E-mail odběratele nemá platný tvar.'];
            }
            $cust_email = $cust_email !== '' ? $cust_email : null;
            // doklad musí vědět, komu patří: buď klient z CRM, nebo ručně vyplněný odběratel
            if ($customer_id === null && ($cust_name === null || trim((string)$cust_name) === '')) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Faktura nemá odběratele — vyber zákazníka, nebo vyplň název odběratele ručně.'];
            }

            $items = [];
            if (!empty($data['items'])) {
                $items = is_string($data['items']) ? json_decode($data['items'], true) : $data['items'];
            }
            if (!is_array($items)) $items = [];

            // Calculate totals
            $totals = $this->calculateTotals($items, $is_vat_payer);
            $total_amount = (float)$totals['total'];
            $vat_amount = $is_vat_payer ? (float)$totals['vat'] : 0;

            if ($id) {
                $stmt = $this->pdo->prepare("
                    UPDATE invoices SET 
                        invoice_number = ?, variable_symbol = ?, customer_id = ?, order_id = ?, date_issue = ?, date_tax = ?, date_due = ?, 
                        total_amount = ?, vat_amount = ?, is_vat_payer = ?, status = ?, 
                        payment_method = ?, payment_date = ?, currency = ?, notes = ?,
                        cust_name_override = ?, cust_address_override = ?, cust_ico_override = ?, cust_dic_override = ?,
                        cust_email_override = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $invoice_number, $variable_symbol, $customer_id, !empty($data['order_id']) ? (int)$data['order_id'] : null, $date_issue, $date_tax, $date_due, 
                    $total_amount, $vat_amount, $is_vat_payer, $status, 
                    $payment_method, $payment_date, $currency, $notes,
                    $cust_name, $cust_address, $cust_ico, $cust_dic, $cust_email,
                    $id
                ]);
                $invoice_id = $id;
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO invoices (
                        invoice_number, variable_symbol, customer_id, order_id, date_issue, date_tax, date_due, 
                        total_amount, vat_amount, is_vat_payer, status, payment_method, payment_date, currency, notes,
                        cust_name_override, cust_address_override, cust_ico_override, cust_dic_override,
                        cust_email_override, branch_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoice_number, $variable_symbol, $customer_id, !empty($data['order_id']) ? (int)$data['order_id'] : null, $date_issue, $date_tax, $date_due, 
                    $total_amount, $vat_amount, $is_vat_payer, $status, $payment_method, $payment_date, $currency, $notes,
                    $cust_name, $cust_address, $cust_ico, $cust_dic, $cust_email,
                    function_exists('crmInvoiceBranchForNew')
                        ? crmInvoiceBranchForNew(!empty($data['order_id']) ? (int)$data['order_id'] : null) : null
                ]);
                $invoice_id = $this->pdo->lastInsertId();
            }

            // Always refresh items
            $this->pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
            
            if (!empty($items)) {
                $stmt = $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($items as $item) {
                    $itemName = $item['name'] ?? ($item['item_name'] ?? '');
                    $qty = $item['quantity'] ?? ($item['qty'] ?? 1);
                    $unit = $item['unit'] ?? 'ks';
                    $price = $item['price'] ?? 0;
                    $vatRate = $item['vat_rate'] ?? ($item['vat'] ?? 0);

                    $stmt->execute([$invoice_id, $itemName, $qty, $unit, $price, $vatRate]);
                }
            }

            $this->pdo->commit();
            // změna položek mohla změnit celkovou částku → srovnat evidenci plateb
            // (bez allowUnpay: ruční „zaplaceno" se editací nesmí zrušit)
            if (function_exists('afxInvoiceRecalcPaid')) {
                // Zvýšení celkové částky u faktury s evidovanými platbami znamená, že už
                // není celá uhrazená → smí spadnout zpět mezi nezaplacené. U faktur BEZ
                // evidovaných plateb (hotovost, starší doklady) se stav nesnižuje nikdy.
                $pc = $this->pdo->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?");
                $pc->execute([$invoice_id]);
                afxInvoiceRecalcPaid((int)$invoice_id, (int)$pc->fetchColumn() > 0);
                if ($status === 'paid') { afxInvoiceSyncManualStatus((int)$invoice_id, 'paid', $payment_method); }
            }
            return ['success' => true, 'id' => $invoice_id];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update only status and related payment data
     */
    public function updateStatus($id, $status, $payment_method = null) {
        // datum platby se PŘEBÍRÁ, když už faktura zaplacená byla — dřív se při každé
        // změně stavu přepsalo na dnešek (nebo vynulovalo), takže se ztrácela informace,
        // kdy peníze skutečně přišly
        $prev = $this->pdo->prepare("SELECT status, payment_date FROM invoices WHERE id = ?");
        $prev->execute([$id]);
        $before = $prev->fetch(PDO::FETCH_ASSOC) ?: [];
        $payment_date = ($status == 'paid')
            ? ((string)($before['payment_date'] ?? '') ?: date('Y-m-d'))
            : null;

        // UZÁVĚRKA před zápisem: sync plateb by jinak vyhodil výjimku až PO UPDATE
        // a faktura by zůstala napůl přestavěná („zaplaceno 0 z …"). Hlídá se datum
        // platby, které sync použije (u odznačení datum evidované platby).
        if (function_exists('afxAccountingAssertOpen')) {
            if ($status == 'paid' && $payment_date !== null) {
                afxAccountingAssertOpen($payment_date, 'platbu');
            } elseif ((string)($before['payment_date'] ?? '') !== '') {
                afxAccountingAssertOpen((string)$before['payment_date'], 'platbu');
            }
        }

        $sql = "UPDATE invoices SET status = ?, payment_date = ?";
        $params = [$status, $payment_date];

        if ($payment_method) {
            $sql .= ", payment_method = ?";
            $params[] = $payment_method;
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        // UPDATE + srovnání evidence plateb ATOMICKY — kdyby sync spadl, stav
        // faktury se vrátí (žádné rozporné „zaplaceno, ale bez platby")
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) { $this->pdo->beginTransaction(); }
        try {
            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute($params);
            // evidence plateb musí odpovídat stavu (jinak by faktura hlásila „zaplaceno 0 z …")
            if ($ok && function_exists('afxInvoiceSyncManualStatus')) {
                afxInvoiceSyncManualStatus((int)$id, (string)$status, $payment_method);
            }
            if ($ownTx) { $this->pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
        return $ok;
    }

    /**
     * Create Credit Note (Opravný daňový doklad) from existing invoice
     */
    public function createCreditNote($invoice_id) {
        $original = $this->getInvoice($invoice_id);
        if (!$original) return ['success' => false, 'error' => 'Original invoice not found'];
        // schéma (DDL) VŽDY před transakcí — uvnitř by udělalo implicitní COMMIT
        if (function_exists('afxEnsureInvoiceBranch')) { afxEnsureInvoiceBranch(); }

        try {
            $this->pdo->beginTransaction();

            // UZÁVĚRKA: dobropis vzniká s dnešním datem — v zamčeném aktuálním
            // měsíci vzniknout nesmí (výjimka → rollback → chybová hláška)
            if (function_exists('afxAccountingAssertOpen')) {
                afxAccountingAssertOpen(date('Y-m-d'), 'dobropis');
            }

            // Check if prefix exists in settings
            $prefix = get_setting('acc_credit_note_prefix', 'ODD' . date('Y'));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?");
            $stmt->execute([$prefix . '%']);
            $count = $stmt->fetchColumn();
            $new_number = $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            $stmt = $this->pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, customer_id, date_issue, date_tax, date_due, 
                    total_amount, vat_amount, is_vat_payer, status, payment_method, currency, 
                    parent_id, invoice_type, notes,
                    cust_name_override, cust_address_override, cust_ico_override, cust_dic_override,
                    cust_email_override, supplier, branch_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, 'credit_note', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $new_number, $original['customer_id'], date('Y-m-d'), date('Y-m-d'), date('Y-m-d'),
                $original['total_amount'], $original['vat_amount'], $original['is_vat_payer'],
                $original['payment_method'], $original['currency'],
                $original['id'], "Opravný k faktuře " . $original['invoice_number'],
                $original['cust_name_override'], $original['cust_address_override'], 
                $original['cust_ico_override'], $original['cust_dic_override'],
                $original['cust_email_override'] ?? null,
                // dobropis musí vystavit TENTÝŽ subjekt jako původní fakturu (sro|ico)
                (string)($original['supplier'] ?? 'sro') ?: 'sro',
                // a patří na tutéž pobočku (jinak by manažerovi zmizel z výpisu)
                $original['branch_id'] ?? null
            ]);
            
            $new_id = $this->pdo->lastInsertId();

            // Copy items
            $stmt = $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($original['items'] as $item) {
                $stmt->execute([
                    $new_id, 
                    $item['item_name'], 
                    $item['quantity'], 
                    $item['unit'], 
                    $item['price'], 
                    $item['vat_rate']
                ]);
            }

            $this->pdo->commit();
            return ['success' => true, 'id' => $new_id];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Helper to calculate totals
     */
    private function calculateTotals($items, $is_vat_payer) {
        $subtotal = 0;
        $vat_total = 0;
        
        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? ($item['qty'] ?? 0));
            $price = (float)($item['price'] ?? 0);
            $vatRate = (float)($item['vat_rate'] ?? ($item['vat'] ?? 0));

            $line_sub = $price * $qty;
            $subtotal += $line_sub;
            
            if ($is_vat_payer) {
                $vat_total += $line_sub * ($vatRate / 100);
            }
        }
        
        return [
            'subtotal' => $subtotal,
            'vat' => $vat_total,
            'total' => $subtotal + $vat_total
        ];
    }
}
