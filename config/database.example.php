<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = 'localhost';
$dbName = 'ecms_edu';
$dbUser = 'root';
$dbPassword = '';

try {
    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    http_response_code(500);
    exit('Database connection failed. Please check that XAMPP MySQL is running and ecms_edu exists.');
}
