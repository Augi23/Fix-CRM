<?php
/**
 * Upozornění na duplicitního klienta při zakládání (v3.93.0):
 * GET phone, email → klienti se stejným telefonem (posledních 9 číslic) nebo e-mailem.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
session_write_close();

$out = [];
foreach (crmFindDuplicateCustomers((string)($_GET['phone'] ?? ''), (string)($_GET['email'] ?? ''), 0, 3) as $c) {
    $name = trim((string)$c['company']) !== '' ? (string)$c['company'] : trim($c['first_name'] . ' ' . $c['last_name']);
    $out[] = ['id' => (int)$c['id'], 'name' => $name, 'phone' => (string)$c['phone'], 'email' => (string)$c['email'], 'orders' => (int)$c['orders_count']];
}
echo json_encode(['ok' => true, 'matches' => $out], JSON_UNESCAPED_UNICODE);
