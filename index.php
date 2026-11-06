<?php

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /ecms_v1/dashboard.php');
} else {
    header('Location: /ecms_v1/login.php');
}
exit;
