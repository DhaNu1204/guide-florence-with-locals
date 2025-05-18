<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log the request for debugging
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$requestData = file_get_contents('php://input');
error_log("PAID REQUEST: Method=$requestMethod, URI=$requestUri, Data=$requestData");

// Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if the request method is PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["message" => "Method not allowed"]);
    exit();
}

// Get the tour ID from the URL
$requestUri = $_SERVER['REQUEST_URI'];
$pattern = '/\/tours\/(\d+)\/paid/';
preg_match($pattern, $requestUri, $matches);

if (empty($matches[1])) {
    error_log("Failed to extract tour ID from URI: $requestUri");
    http_response_code(400);
    echo json_encode(["message" => "Tour ID not provided"]);
    exit();
}

$tourId = $matches[1];
error_log("Extracted tour ID: $tourId");

// Get JSON input data
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['paid'])) {
    error_log("Missing paid status in request data");
    http_response_code(400);
    echo json_encode(["message" => "Paid status not provided"]);
    exit();
}

$isPaid = (bool)$data['paid'] ? 1 : 0;
error_log("Paid status to set: $isPaid");

// Include database configuration
require_once 'config.php';

// Connect to database
try {
    // Create database connection using credentials from config.php
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Check connection
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        http_response_code(500);
        echo json_encode(["message" => "Database connection failed: " . $conn->connect_error]);
        exit();
    }
    
    // Update the paid status of the tour
    $stmt = $conn->prepare("UPDATE tours SET paid = ? WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare statement failed: " . $conn->error);
        http_response_code(500);
        echo json_encode(["message" => "Prepare statement failed: " . $conn->error]);
        exit();
    }
    
    $stmt->bind_param("ii", $isPaid, $tourId);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Execute statement failed: " . $stmt->error);
        http_response_code(500);
        echo json_encode(["message" => "Execute statement failed: " . $stmt->error]);
        exit();
    }
    
    error_log("Query executed. Affected rows: " . $stmt->affected_rows);
    
    if ($stmt->affected_rows > 0) {
        error_log("Tour paid status updated successfully");
        echo json_encode([
            "message" => "Tour paid status updated successfully",
            "tour_id" => $tourId,
            "paid" => (bool)$isPaid
        ]);
    } else {
        error_log("Tour not found or no changes made");
        http_response_code(404);
        echo json_encode(["message" => "Tour not found or no changes made"]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["message" => "Database error: " . $e->getMessage()]);
}
?> 