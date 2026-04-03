<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(__('unauthorized'));
}

header('Location: ../inventory.php');
exit;
