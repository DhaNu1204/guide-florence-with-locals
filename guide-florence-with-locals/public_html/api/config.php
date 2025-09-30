<?php
// Final Production MySQL database configuration for Hostinger
// Ready for immediate deployment

$db_host = 'localhost'; // Hostinger MySQL host
$db_user = 'u803853690_withlocals'; // Actual Hostinger database username
$db_pass = 'YY!C~W2frt*5'; // Actual Hostinger database password
$db_name = 'u803853690_withlocals'; // Actual Hostinger database name

// Production security headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// CORS configuration for production domain
$allowed_origins = [
    'https://withlocals.deetech.cc',
    'http://withlocals.deetech.cc'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Fallback for subdomain access
    header("Access-Control-Allow-Origin: https://withlocals.deetech.cc");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create database connection with error handling
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Check connection
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['error' => 'Database connection failed']);
        exit();
    }

    // Set UTF-8 encoding
    $conn->set_charset("utf8");

} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database connection error']);
    exit();
}

// Production environment flag
define('ENVIRONMENT', 'production');
define('DEBUG', false);

// Enable error logging (but not display)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
?>