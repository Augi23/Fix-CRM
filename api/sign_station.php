<?php
/* Poll podpisové stanice: nejstarší čekající požadavek pro pobočku přihlášeného
   zaměstnance (bez pobočky = vše). Vrací data pro podpisovou obrazovku. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false]); exit;
}

ensureSignatureRequestsTable();
ensureComplaintSignatureSupport();

$branchId = (int)getCurrentStaffBranchId();
$sql = "SELECT r.id, r.order_id, r.complaint_id, r.sig_type, r.requested_by,
               o.order_code, o.device_brand, o.device_model, o.estimated_cost, o.final_cost,
               c.preferred_language,
               TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS customer,
               k.complaint_code, k.device AS complaint_device,
               kc.preferred_language AS complaint_pref_lang,
               TRIM(CONCAT(COALESCE(kc.first_name,''),' ',COALESCE(kc.last_name,''))) AS complaint_customer
        FROM signature_requests r
        LEFT JOIN orders o ON o.id = r.order_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN complaints k ON k.id = r.complaint_id
        LEFT JOIN customers kc ON kc.id = k.customer_id
        WHERE r.status = 'pending' AND (o.id IS NOT NULL OR k.id IS NOT NULL)"
        . ($branchId > 0 ? " AND (r.branch_id = " . $branchId . " OR r.branch_id IS NULL)" : "") . "
        ORDER BY r.id ASC LIMIT 5";

try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $requests = [];
    foreach ($rows as $r) {
        $isComplaint = (int)($r['complaint_id'] ?? 0) > 0;
        if ($isComplaint) {
            // Reklamace: klient na stanici podepisuje reklamační protokol.
            $requests[] = [
                'id'           => (int)$r['id'],
                'order_id'     => 0,
                'complaint_id' => (int)$r['complaint_id'],
                'sig_type'     => 'reklamace',
                'order_code'   => trim((string)($r['complaint_code'] ?? '')) !== '' ? (string)$r['complaint_code'] : ('RK #' . (int)$r['complaint_id']),
                'customer'     => trim((string)($r['complaint_customer'] ?? '')) ?: '—',
                'device'       => trim((string)($r['complaint_device'] ?? '')),
                'amount'       => '',
                'requested_by' => trim((string)($r['requested_by'] ?? '')),
                'lang'         => crmCustomerDocLang($r['complaint_pref_lang'] ?? 'cs'),
            ];
            continue;
        }
        $amount = ($r['final_cost'] !== null && $r['final_cost'] !== '') ? (float)$r['final_cost'] : (float)($r['estimated_cost'] ?? 0);
        $requests[] = [
            'id'           => (int)$r['id'],
            'order_id'     => (int)$r['order_id'],
            'complaint_id' => 0,
            'sig_type'     => (string)$r['sig_type'],
            'order_code'   => trim((string)($r['order_code'] ?? '')) !== '' ? (string)$r['order_code'] : ('#' . (int)$r['order_id']),
            'customer'     => trim((string)($r['customer'] ?? '')) ?: '—',
            'device'       => trim(((string)($r['device_brand'] ?? '')) . ' ' . ((string)($r['device_model'] ?? ''))),
            'amount'       => $amount > 0 ? formatMoney($amount) : '',
            'requested_by' => trim((string)($r['requested_by'] ?? '')),
            'lang'         => crmCustomerDocLang($r['preferred_language'] ?? 'cs'), // jazyk dokladů klienta (uk→en) pro podpisovou obrazovku
        ];
    }
    echo json_encode(['ok' => true,
        'request' => $requests[0] ?? null,      // zpětná kompatibilita
        'requests' => $requests,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
