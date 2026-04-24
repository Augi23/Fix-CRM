<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/klient/includes/auth.php';

$lang = $_GET['lang'] ?? $_POST['lang'] ?? null;
if ($lang !== null) {
    crm_set_language((string)$lang);
}

$defaultTarget = 'login.php';
if (clientIsLoggedIn()) {
    $defaultTarget = 'klient/dashboard.php';
} elseif (!empty($_SESSION['user_id'])) {
    $defaultTarget = 'index.php';
}

$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$redirect = trim((string)$redirect);

if ($redirect !== '') {
    $parts = parse_url($redirect);
    $isAbsolute = $parts !== false && (isset($parts['scheme']) || isset($parts['host']));
    $isProtocolRelative = str_starts_with($redirect, '//');
    $hasNewlines = str_contains($redirect, "\n") || str_contains($redirect, "\r");

    if (!$isAbsolute && !$isProtocolRelative && !$hasNewlines) {
        header('Location: ' . $redirect);
        exit;
    }
}

header('Location: ' . $defaultTarget);
exit;
