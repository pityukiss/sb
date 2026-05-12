<?php
include_once __DIR__ . '/config.php';

$host = (string) shobidConfig('DB_HOST', 'localhost');
$user = (string) shobidConfig('DB_USER', '');
$pass = (string) shobidConfig('DB_PASS', '');
$db = (string) shobidConfig('DB_NAME', '');
$port = intval(shobidConfig('DB_PORT', '3306'));

if ($user === '' || $db === '') {
    http_response_code(500);
    die('Server configuration error.');
}

$conn = @new mysqli($host, $user, $pass, $db, $port > 0 ? $port : 3306);
if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Temporary server error.');
}

$conn->set_charset('utf8mb4');
