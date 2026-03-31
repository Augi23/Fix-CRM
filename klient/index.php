<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (clientIsLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: ../login.php');
}
exit;
