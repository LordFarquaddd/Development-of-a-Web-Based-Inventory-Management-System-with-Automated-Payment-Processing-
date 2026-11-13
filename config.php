<?php

$DB_HOST = 'localhost';
$DB_NAME = 'uniform_store_mgmt_system';
$DB_USER = 'root';    
$DB_PASS = '';         

try {
    $pdo = new PDO(
        
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    date_default_timezone_set('Asia/Manila');
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('GCASH_QR_IMAGE', 'assets/gcash_qr.png');
define('GCASH_ACCOUNT_NAME', 'HARIBONified Store');
define('GCASH_ACCOUNT_NUMBER', '0917-000-0000');

define('MAYA_QR_IMAGE', 'assets/maya_qr.png');
define('MAYA_ACCOUNT_NAME', 'HARIBONified Store');
define('MAYA_ACCOUNT_NUMBER', '0917-000-0000');

function qr_image_or_null(string $relativePath): ?string
{
    return file_exists(__DIR__ . '/' . $relativePath) ? $relativePath : null;
}