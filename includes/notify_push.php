<?php
/**
 * Push notifikace na události CRM — sestaví český titulek/tělo a zavolá APNs
 * odesílač (includes/push.php). Vše je bezpečný no-op, dokud není nastavený
 * APNs klíč (apnsConfigured()), a je obalené try/catch, takže push nikdy
 * neshodí business logiku CRM.
 *
 * Napojení:
 *  - crmPushDispatch()  ← volá crmAuditLog() (order.create / order.status_change / complaint.create)
 *  - crmPushChat()      ← api/chat.php
 *  - crmPushComplaint() ← klient/api/create_complaint.php
 *  - crmPushEshopOrder()← api/eshop_sale.php
 */
require_once __DIR__ . '/push.php';

/** Základní info o zakázce pro push (kód, zařízení, klient, technik, stav). */
function crmPushOrderInfo(PDO $pdo, int $orderId): array {
    if ($orderId <= 0) return [];
    try {
        $st = $pdo->prepare(
            "SELECT o.order_code, o.device_brand, o.device_model, o.status, o.technician_id,
                    c.first_name, c.last_name
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ? LIMIT 1");
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
    if (!$r) return [];
    return [
        'code'          => (string)($r['order_code'] ?: ('#' . $orderId)),
        'device'        => trim((string)($r['device_brand'] ?? '') . ' ' . (string)($r['device_model'] ?? '')),
        'customer'      => trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
        'status'        => (string)($r['status'] ?? ''),
        'technician_id' => (int)($r['technician_id'] ?? 0),
    ];
}

/** Dispatcher volaný z crmAuditLog() — podle akce pošle push. */
function crmPushDispatch(PDO $pdo, string $action, array $opts): void {
    if (!apnsConfigured()) return;
    // klíč toho, kdo akci udělal — (int)$_SESSION['user_id'] by u technika („t15")
    // dalo 0 a vyloučení autora by tiše přestalo fungovat
    $actorKey = function_exists('crmStaffKey') ? crmStaffKey() : '';
    try {
        if ($action === 'order.create') {
            $id   = (int)($opts['entity_id'] ?? 0);
            $info = crmPushOrderInfo($pdo, $id);
            $code = (string)($opts['entity_label'] ?? ($info['code'] ?? ('#' . $id)));
            $body = trim(($info['device'] ?? '') . (!empty($info['customer']) ? ' — ' . $info['customer'] : ''));
            // Důraznější tón než běžná chat zpráva (order.caf v bundlu appky;
            // starší buildy bez souboru přehrají systémový default).
            pushToAll($pdo, 'Nová zakázka ' . $code, $body !== '' ? $body : 'Byla založena nová zakázka.',
                ['sound' => 'order.caf', 'data' => ['url' => 'view_order.php?id=' . $id], 'collapse' => 'order-' . $id], [$actorKey]);

        } elseif ($action === 'order.status_change') {
            $id   = (int)($opts['entity_id'] ?? 0);
            $info = crmPushOrderInfo($pdo, $id);
            $tech = (int)($info['technician_id'] ?? 0);
            $code = (string)($opts['entity_label'] ?? ($info['code'] ?? ('#' . $id)));
            if ($tech > 0 && ('tech:' . $tech) !== $actorKey) {
                pushToTechnician($pdo, $tech, 'Zakázka ' . $code,
                    !empty($info['status']) ? ('Stav: ' . $info['status']) : 'Změna stavu zakázky',
                    ['data' => ['url' => 'view_order.php?id=' . $id], 'collapse' => 'order-' . $id]);
            }

        } elseif ($action === 'complaint.create') {
            $label = (string)($opts['entity_label'] ?? '');
            pushToAll($pdo, 'Nová reklamace', trim('Reklamace ' . $label),
                ['data' => ['url' => 'reklamace.php'], 'collapse' => 'complaint'], [$actorKey]);
        }
    } catch (Throwable $e) { /* push nikdy neshodí CRM */ }
}

/** Firemní chat — push všem kromě odesílatele. */
function crmPushChat(PDO $pdo, int $senderUserId, string $author, string $message): void {
    if (!apnsConfigured()) return;
    $body = function_exists('mb_substr') ? mb_substr(trim($message), 0, 120) : substr(trim($message), 0, 120);
    try {
        pushToAll($pdo, $author !== '' ? $author : 'Chat', $body !== '' ? $body : 'Nová zpráva',
            ['thread' => 'chat', 'collapse' => 'chat'], [$senderUserId]);
    } catch (Throwable $e) {}
}

/** Nová reklamace z klientského portálu — push všem. */
function crmPushComplaint(PDO $pdo, string $code, string $orderCode, string $device): void {
    if (!apnsConfigured()) return;
    $body = trim($device . ($orderCode !== '' ? ' · ' . $orderCode : ''));
    try {
        pushToAll($pdo, 'Nová reklamace ' . $code, $body !== '' ? $body : 'Klient založil reklamaci.',
            ['data' => ['url' => 'reklamace.php'], 'collapse' => 'complaint']);
    } catch (Throwable $e) {}
}

/** Nová e-shop objednávka — push všem. */
function crmPushEshopOrder(PDO $pdo, int $orderId, string $orderRef, $total, string $customer): void {
    if (!apnsConfigured()) return;
    $amount = is_numeric($total) ? (' · ' . number_format((float)$total, 0, ',', ' ') . ' Kč') : '';
    $body = trim(($customer !== '' ? $customer : ('Objednávka ' . $orderRef)) . $amount);
    try {
        pushToAll($pdo, 'Nová e-shop objednávka', $body !== '' ? $body : ('Objednávka ' . $orderRef),
            ['sound' => 'order.caf', 'data' => ['url' => 'orders.php'], 'collapse' => 'eshop']);
    } catch (Throwable $e) {}
}
