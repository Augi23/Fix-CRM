<?php
class AccountingExporter {
    private $pdo;
    private $exportDir;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->exportDir = 'temp/exports/';
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    public function exportToPohoda($id) {
        $invoice = $this->getFullInvoice($id);
        // Pohoda import páruje dataPack proti IČO účetní jednotky — faktury OSVČ
        // („Faktura IČO" z kasy) do účetnictví s.r.o. NEPATŘÍ a export by je
        // orazítkoval cizím IČO. OSVČ si vede evidenci zvlášť.
        if ((string)($invoice['supplier'] ?? 'sro') === 'ico') {
            throw new Exception('Faktura ' . $invoice['invoice_number'] . ' je vystavená OSVČ (Faktura IČO) — do Pohoda exportu s.r.o. nepatří.');
        }
        $company_name = get_setting('acc_company_name');
        $ico = get_setting('acc_ico');
        
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack id="INV' . $invoice['id'] . '" ico="' . $ico . '" application="Service" version="2.0" note="Export faktury" 
            xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" 
            xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" 
            xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"></dat:dataPack>');

        // SimpleXML::addChild NEescapuje „&" — „Novák & syn s.r.o." z ARESu by
        // vyrobil nevalidní XML a import do Pohody by spadl (nález prověrky 25.8.)
        $x = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $item = $xml->addChild('dat:dataPackItem');
        $item->addAttribute('version', '2.0');
        $item->addAttribute('id', $invoice['invoice_number']);

        $inv = $item->addChild('inv:invoice', null, 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $inv->addAttribute('version', '2.0');

        $header = $inv->addChild('inv:invoiceHeader');
        $header->addChild('inv:invoiceType', 'issuedInvoice');
        $header->addChild('inv:number', $x($invoice['invoice_number']));
        $header->addChild('inv:date', $invoice['date_issue']);
        $header->addChild('inv:dateTax', $invoice['date_tax']);
        $header->addChild('inv:dateDue', $invoice['date_due']);
        $header->addChild('inv:text', 'Faktura za opravu zařízení');

        // Supplier (My Company)
        // Note: In Pohoda, supplier is often set in the profile, but we can include it
        
        // Partner (Customer)
        $partner = $header->addChild('inv:partnerIdentity');
        $address = $partner->addChild('typ:address', null, 'http://www.stormware.cz/schema/version_2/type.xsd');
        $address->addChild('typ:company', $x($invoice['customer']['company'] ?: trim($invoice['customer']['first_name'] . ' ' . $invoice['customer']['last_name'])));
        $address->addChild('typ:city', $x($this->parseCity($invoice['customer']['address'])));
        $address->addChild('typ:street', $x($this->parseStreet($invoice['customer']['address'])));
        if ($invoice['customer']['ico']) $address->addChild('typ:ico', $x($invoice['customer']['ico']));
        if ($invoice['customer']['dic']) $address->addChild('typ:dic', $x($invoice['customer']['dic']));

        $header->addChild('inv:paymentType', $this->mapPaymentMethod($invoice['payment_method']));
        
        // Items
        $invItems = $inv->addChild('inv:invoiceDetail');
        foreach ($invoice['items'] as $row) {
            $invItem = $invItems->addChild('inv:invoiceItem');
            $invItem->addChild('inv:text', $x($row['item_name']));
            $invItem->addChild('inv:quantity', $row['quantity']);
            $invItem->addChild('inv:unit', $row['unit']);
            $invItem->addChild('inv:payVat', $invoice['is_vat_payer'] ? 'true' : 'false');
            $invItem->addChild('inv:rateVAT', $this->mapVatRate($row['vat_rate']));
            
            $homeCurr = $invItem->addChild('inv:homeCurrency');
            $homeCurr->addChild('typ:unitPrice', $row['price'], 'http://www.stormware.cz/schema/version_2/type.xsd');
        }

        $filename = 'Pohoda_' . str_replace('/', '-', $invoice['invoice_number']) . '_' . date('YmdHis') . '.xml';
        $xml->asXML($this->exportDir . $filename);
        return $filename;
    }

    public function exportToS3Money($id) {
        $invoice = $this->getFullInvoice($id);
        $filename = 'S3Money_' . str_replace('/', '-', $invoice['invoice_number']) . '_' . date('YmdHis') . '.csv';
        
        $fp = fopen($this->exportDir . $filename, 'w');
        // Simple S3 Money CSV header
        fputcsv($fp, ['CisloDokladu', 'DatumVystaveni', 'DatumSplatnosti', 'Partner', 'Text', 'Castka', 'DPH']);
        
        foreach ($invoice['items'] as $item) {
            fputcsv($fp, [
                $invoice['invoice_number'],
                $invoice['date_issue'],
                $invoice['date_due'],
                $invoice['customer']['company'] ?: ($invoice['customer']['first_name'] . ' ' . $invoice['customer']['last_name']),
                $item['item_name'],
                $item['price'] * $item['quantity'],
                $item['vat_rate']
            ]);
        }
        
        fclose($fp);
        return $filename;
    }

    private function getFullInvoice($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // odběratel: karta klienta, ale ručně vyplněné údaje NA FAKTUŘE mají přednost
        // (faktura bez klienta v CRM má customer_id NULL a jen cust_*_override —
        //  bez tohohle by export do Pohody spadl nebo poslal prázdného odběratele)
        $cust = [];
        if (!empty($invoice['customer_id'])) {
            $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$invoice['customer_id']]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $invoice['customer'] = [
            'company'    => (string)($invoice['cust_name_override'] ?? '') ?: (string)($cust['company'] ?? ''),
            'first_name' => (string)($cust['first_name'] ?? ''),
            'last_name'  => (string)($cust['last_name'] ?? ''),
            'address'    => (string)($invoice['cust_address_override'] ?? '') ?: (string)($cust['address'] ?? ''),
            'ico'        => (string)($invoice['cust_ico_override'] ?? '') ?: (string)($cust['ico'] ?? ''),
            'dic'        => (string)($invoice['cust_dic_override'] ?? '') ?: (string)($cust['dic'] ?? ''),
            'email'      => (string)($invoice['cust_email_override'] ?? '') ?: (string)($cust['email'] ?? ''),
        ];
        
        $stmt = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$id]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $invoice;
    }

    private function mapPaymentMethod($method) {
        switch ($method) {
            case 'bank_transfer': return 'draft';
            case 'cash': return 'cash';
            case 'card': return 'card';
            default: return 'draft';
        }
    }

    private function mapVatRate($rate) {
        if ($rate >= 21) return 'high';
        if ($rate >= 10) return 'low';
        return 'none';
    }

    /** Adresa se zadává volně — dělí se čárkou i odřádkováním (kasa má textareu). */
    private function addressParts($address): array {
        $parts = preg_split('/[\r\n,]+/', (string)$address) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
        return $parts;
    }

    private function parseCity($address) {
        $p = $this->addressParts($address);
        return $p ? end($p) : '';
    }

    private function parseStreet($address) {
        $p = $this->addressParts($address);
        return $p[0] ?? '';
    }
}
