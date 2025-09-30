<?php
   // Database configuration
   $db_host = 'localhost'; // Change to your database host
   $db_user = 'u803853690_guideDhanu';      // Change to your database username
   $db_pass = 'GTIUaaN@88*522**267';  // Change to your database password
   $db_name = 'u803853690_guide'; // Change to your database name


// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// Set UTF-8 encoding
$conn->set_charset("utf8");
?>