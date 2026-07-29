<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// LEGACY — stránka vypnuta (13.7.2026): obcházela auditní historii i ochranu
// klienta (raw customer_id bez pojistek, žádný crmAuditLog/logOrderStatusChange).
// Z UI na ni nevede žádný odkaz; editace se dělá v detailu (view_order.php),
// který všechny pojistky má. Přímé URL přesměrujeme na detail zakázky.
// Původní formulář + POST handler byly odstraněny 29.7.2026 (mrtvý kód bez
// validací „Provedené opravy" a stavového zámku — v3.15.0); plné znění je
// v git historii (naposledy commit 11e836b).
$__rid = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
header('Location: ' . ($__rid > 0 ? 'view_order.php?id=' . $__rid : 'orders.php'));
exit;
