<?php
require_once __DIR__ . '/../includes/auth.php';
doLogout();
header('Location: ' . BASE_URL . '/pages/login.php');
exit;
