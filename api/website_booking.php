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
$given = (string)($_SERVER['HTTP_X_AFX_KEY'] ?? ($in['key'] ?? ''));
$expected = (string)get_setting('web_booking_key', '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid key']);
    exit;
}

// ── Normalizace ──────────────────────────────────────────────────────────────
$wpId    = trim((string)($in['booking_id'] ?? ''));
$name    = trim((string)($in['name'] ?? ''));
$phone   = trim((string)($in['phone'] ?? ''));
$email   = trim((string)($in['email'] ?? ''));
$device  = trim((string)($in['device'] ?? ''));
$service = trim((string)($in['service'] ?? ''));
$notes   = trim((string)($in['notes'] ?? ''));
$deliv   = trim((string)($in['delivery'] ?? ''));
$statusW = strtolower(trim((string)($in['status'] ?? 'pending')));

$appt = null;
$apptRaw = trim((string)($in['appointment'] ?? ''));
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
            $pdo->prepare("UPDATE web_bookings SET status = 'dismissed' WHERE id = ? AND status = 'new'")
                ->execute([(int)$existing['id']]);
        }
        echo json_encode(['success' => true, 'message' => 'Cancelled noted']);
        exit;
    }

    $payload = json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($existing) {
        $pdo->prepare("UPDATE web_bookings
            SET customer_name = ?, phone = ?, email = ?, device = ?, service = ?, notes = ?,
                appointment_at = ?, delivery_method = ?, raw_payload = ?
            WHERE id = ?")
            ->execute([$name, $phone, $email, $device, $service, $notes, $appt, $deliv, $payload, (int)$existing['id']]);
        echo json_encode(['success' => true, 'message' => 'Updated', 'id' => (int)$existing['id']]);
        exit;
    }

    $pdo->prepare("INSERT INTO web_bookings
        (wp_booking_id, customer_name, phone, email, device, service, notes, appointment_at, delivery_method, raw_payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$wpId !== '' ? $wpId : null, $name, $phone, $email, $device, $service, $notes, $appt, $deliv, $payload]);

    echo json_encode(['success' => true, 'message' => 'Created', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('website_booking: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
