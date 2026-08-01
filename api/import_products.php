<?php
/**
 * Import produktů ze souboru Mac appky — ODSTRANĚN (1.8.2026 na přání majitele).
 * Produkty se naskladňují výhradně přímo v CRM (products.php → Naskladnit produkt).
 * Endpoint zůstává jen jako záchytná hláška pro případné staré otevřené stránky.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'Import z appky byl odstraněn — produkty naskladňuj přímo v CRM (tlačítko „Naskladnit produkt").',
], JSON_UNESCAPED_UNICODE);
