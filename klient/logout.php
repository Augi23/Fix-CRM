<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

clientLogout();
header('Location: ../login.php');
exit;
