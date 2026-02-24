<?php
// Get the requested endpoint
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Enable debug logging
error_log("API Request: " . $request_uri);

// Remove '/api/' from the path
$endpoint = str_replace('/api/', '', $path);
// Handle multiple possible prefixes
$endpoint = preg_replace('#^/?(api/)+#', '', $endpoint);

// Handle root endpoint
if (empty($endpoint)) {
    echo json_encode(['message' => 'API is working']);
    exit();
}

// Strip trailing slashes
$endpoint = rtrim($endpoint, '/');

// Check for tour sub-routes (paid/cancelled status updates)
if (preg_match('/tours\/(\d+)\/(cancelled|paid)$/', $endpoint)) {
    include_once "tours.php";
    exit();
}

// Check if it's a request with ID
if (preg_match('/(guides|tours|tickets)\/(\d+)$/', $endpoint, $matches)) {
    $resource = $matches[1];
    $id = $matches[2];
    
    // Redirect to the appropriate PHP file
    include_once "{$resource}.php";
    exit();
}

// Handle standard API endpoints
if (in_array($endpoint, ['guides', 'tours', 'tickets'])) {
    include_once "{$endpoint}.php";
    exit();
}

// If no valid endpoint is found
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
?> 