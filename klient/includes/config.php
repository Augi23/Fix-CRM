<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APPLEFIX_KLIENT');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
