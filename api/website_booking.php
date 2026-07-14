<?php
/**
 * Webhook pro rezervace z applefix.cz (RepairPlugin Pro).
 * WordPress sem POSTne novou/změněnou rezervaci; autentizace sdíleným klíčem
 * (settings 'web_booking_key' — zobrazen v Nastavení → Integrace).
 *
 * Přijímá JSON i form-data:
 *   key            sdílený klíč (nebo hlavička X-AFX-KEY)
 *   booking_id     ID rezervace ve WordPressu (deduplikace/aktualizace)
 *   name           jméno zákazníka
 *   phone, email
 *   device         zařízení (např. "iPhone 13 128GB")
 *   service        požadovaná oprava/služba
 *   notes          poznámka zákazníka
 *   appointment    termín "YYYY-MM-DD HH:MM" (nebo ISO 8601)
 *   delivery       způsob předání (přinese / kurýr / pošle…)
 *   status         pending|approved|cancelled (cancelled → skryje rezervaci)
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

ensureWebBookingsSchema();

// ── Vstup: JSON tělo nebo form-data ─────────────────────────────────────────
$raw = file_get_contents('php://input');
$in = [];
if ($raw !== '' && str_starts_with(trim($raw), '{')) {
    $in = json_decode($raw, true) ?: [];
}
if (empty($in)) { $in = $_POST; }

// ── Autentizace sdíleným klíčem ──────────────────────────────────────────────
// Hlavička X-AFX-KEY (náš mu-plugin) NEBO ?key= v URL (RepairPlugin Trigger
// Webhooks neumí vlastní hlavičky — klíč se předává v URL webhooků).
$given = (string)($_SERVER['HTTP_X_AFX_KEY'] ?? ($in['key'] ?? ($_GET['key'] ?? '')));
$expected = (string)get_setting('web_booking_key', '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid key']);
    exit;
}

// Diagnostika: poslední přijatý payload (zobrazeno v Nastavení → Integrace)
try {
    $dbg = $raw !== '' ? $raw : json_encode($_POST, JSON_UNESCAPED_UNICODE);
    set_setting('web_booking_last_payload', date('Y-m-d H:i:s') . "\n" . mb_substr((string)$dbg, 0, 20000));
} catch (Throwable $e) { /* jen diagnostika */ }

// ── Adaptér: podpora formátu RepairPluginu (Trigger Webhooks) i našeho ───────
/** Vybere první neprázdnou skalární hodnotu z kandidátních klíčů. */
function wbPick(array $a, array $keys): string {
    foreach ($keys as $k) {
        if (isset($a[$k]) && is_scalar($a[$k]) && trim((string)$a[$k]) !== '') {
            return trim((string)$a[$k]);
        }
    }
    return '';
}

// Zploštit běžná vnoření (data/appointment/customer/booking/client/device…)
$flat = $in;
foreach (['data', 'payload', 'appointment', 'booking', 'customer', 'client', 'device', 'fields'] as $nest) {
    if (isset($in[$nest]) && is_array($in[$nest])) {
        foreach ($in[$nest] as $k => $v) {
            if (!isset($flat[$k])) { $flat[$k] = $v; }
        }
    }
}

// ── Normalizace ──────────────────────────────────────────────────────────────
// Reálná pole RepairPluginu (Trigger Webhooks) ověřená z „Example Payload":
//   appointment_number, event_start_datetime, customer_first_name/last_name/name,
//   customer_email/phone/notes/imei, brand/model/color, items[].name, service_method,
//   appointment_status, action.
$wpId    = wbPick($flat, ['booking_id', 'appointment_number', 'appointment_id', 'appointmentid', 'id', 'ID', 'reference', 'ref', 'order_id']);
$name    = wbPick($flat, ['name', 'customer_name', 'client_name', 'full_name', 'fullname', 'contact_name']);
if ($name === '') {
    $name = trim(wbPick($flat, ['first_name', 'firstname', 'customer_first_name']) . ' ' . wbPick($flat, ['last_name', 'lastname', 'surname', 'customer_last_name']));
}
$phone   = wbPick($flat, ['phone', 'customer_phone', 'phone_number', 'phonenumber', 'tel', 'telephone', 'mobile']);
$email   = wbPick($flat, ['email', 'customer_email', 'mail', 'email_address']);
$device  = wbPick($flat, ['device', 'device_name', 'device_model', 'model_name']);
if ($device === '') {
    $device = trim(implode(' ', array_filter([
        wbPick($flat, ['brand', 'device_brand', 'manufacturer', 'category', 'category_name']),
        wbPick($flat, ['model', 'device_model', 'model_name']),
        wbPick($flat, ['color', 'colour']),
    ])));
}
$service = wbPick($flat, ['service', 'repair_name', 'repair', 'services', 'service_name']);
if ($service === '') {
    // RepairPlugin: items[] (položky oprav), fallback repairs[].
    // Do popisu opravy patří jen skutečné opravy/produkty — poplatky (extra_fee,
    // platební metoda) a zvolená priorita (normal/express/nespěchám) se vynechávají;
    // priorita se přenáší zvlášť do pole priority zakázky (crmExtractWebPriority).
    foreach (['items', 'repairs', 'line_items'] as $arrKey) {
        if ($service === '' && isset($flat[$arrKey]) && is_array($flat[$arrKey])) {
            $names = [];
            foreach ($flat[$arrKey] as $r) {
                if (is_scalar($r)) {
                    if (crmDetectWebPriority((string)$r) === null) { $names[] = (string)$r; }
                } elseif (is_array($r) && crmWebItemIsService($r)) {
                    $n = wbPick($r, ['name', 'title', 'repair_name', 'label', 'product_name']);
                    if ($n !== '') { $names[] = $n; }
                }
            }
            $service = implode(', ', array_filter($names));
        }
    }
}
$notes   = wbPick($flat, ['notes', 'note', 'message', 'comments', 'comment', 'customer_note', 'customer_notes', 'remarks']);
$imei    = wbPick($flat, ['customer_imei', 'imei', 'serial', 'sn', 'serial_number']);
if ($imei !== '') { $notes = trim($notes . ($notes !== '' ? ' · ' : '') . 'IMEI/SN: ' . $imei); }
$deliv   = wbPick($flat, ['service_method', 'delivery', 'delivery_method', 'appointment_type', 'type', 'shipping_method']);
$trigger = strtolower(wbPick($flat, ['action', 'trigger', 'event', 'hook', 'webhook_trigger']));
$statusW = strtolower(wbPick($flat, ['status', 'appointment_status', 'booking_status']) ?: 'pending');
// Trigger *_cancelled / *_deleted → rezervaci v CRM skrýt
if ($trigger !== '' && (str_contains($trigger, 'cancel') || str_contains($trigger, 'delete'))) {
    $statusW = 'cancelled';
}
if (str_contains($statusW, 'cancel') || str_contains($statusW, 'delete') || $statusW === 'trashed') {
    $statusW = 'cancelled';
}

$appt = null;
$apptRaw = wbPick($flat, ['event_start_datetime', 'appointment', 'appointment_datetime', 'scheduled_at', 'datetime']);
if ($apptRaw === '') {
    $d = wbPick($flat, ['appointment_date', 'booking_date', 'date', 'appointmentdate']);
    $t = wbPick($flat, ['appointment_time', 'booking_time', 'time', 'appointmenttime', 'time_slot', 'timeslot']);
    // time_slot bývá „14:00 - 14:30" → vzít začátek
    if ($t !== '' && preg_match('/(\d{1,2}[:.]\d{2})/', $t, $m)) { $t = str_replace('.', ':', $m[1]); }
    $apptRaw = trim($d . ' ' . $t);
}
if ($apptRaw !== '') {
    $ts = strtotime($apptRaw);
    if ($ts !== false) { $appt = date('Y-m-d H:i:s', $ts); }
}

if ($wpId === '' && $name === '' && $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty booking']);
    exit;
}

try {
    // Existuje už rezervace s tímto WP ID? → aktualizace
    $existing = null;
    if ($wpId !== '') {
        $stmt = $pdo->prepare("SELECT id, status FROM web_bookings WHERE wp_booking_id = ? LIMIT 1");
        $stmt->execute([$wpId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($statusW === 'cancelled') {
        if ($existing) {
            // Zrušení/smazání na webu → skrýt rezervaci (i převedenou) a stornovat
            // čerstvou zakázku; rozpracovanou jen označit poznámkou. + smazat z kalendáře.
            crmCancelWebBooking((int)$existing['id']);
        }
        echo json_encode(['success' => true, 'message' => 'Cancelled noted', 'id' => $existing ? (int)$existing['id'] : null]);
        exit;
    }

    $payload = json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($existing) {
        $pdo->prepare("UPDATE web_bookings
            SET customer_name = ?, phone = ?, email = ?, device = ?, service = ?, notes = ?,
                appointment_at = ?, delivery_method = ?, raw_payload = ?
            WHERE id = ?")
            ->execute([$name, $phone, $email, $device, $service, $notes, $appt, $deliv, $payload, (int)$existing['id']]);
        crmSyncWebBookingToCalDav((int)$existing['id']);
        // Rezervace ještě nebyla převzata → rovnou z ní založit zákazníka + zakázku
        $orderId = crmCreateOrderFromWebBooking((int)$existing['id']);
        echo json_encode(['success' => true, 'message' => 'Updated', 'id' => (int)$existing['id'], 'order_id' => $orderId]);
        exit;
    }

    $pdo->prepare("INSERT INTO web_bookings
        (wp_booking_id, customer_name, phone, email, device, service, notes, appointment_at, delivery_method, raw_payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$wpId !== '' ? $wpId : null, $name, $phone, $email, $device, $service, $notes, $appt, $deliv, $payload]);

    $newId = (int)$pdo->lastInsertId();
    crmBackupMaybeSchedule();   // zálohy běží i v noci, když chodí jen webové objednávky
    crmSyncWebBookingToCalDav($newId);
    // Automaticky založit zákazníka (pokud nový) + zakázku „Přijato" z webové rezervace
    $orderId = crmCreateOrderFromWebBooking($newId);

    echo json_encode(['success' => true, 'message' => 'Created', 'id' => $newId, 'order_id' => $orderId]);
} catch (Throwable $e) {
    error_log('website_booking: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
