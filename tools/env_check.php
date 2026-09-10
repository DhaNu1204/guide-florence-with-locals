<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script (step 0.2): never reachable over HTTP
// (was public_html/api/check_environment.php - renamed in step 0.2 because a scanner on the owner's PC blocks that filename)
require_once __DIR__ . '/../public_html/api/config.php';

// Return environment information
$response = [
    'environment' => ENVIRONMENT,
    'debug' => DEBUG,
    'base_url' => BASE_URL,
    'api_url' => API_URL,
    'server_info' => [
        'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'not set',
        'server_addr' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'not set',
        'document_root' => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : 'not set',
    ],
    'database' => [
        'connected' => isset($conn) && $conn->ping(),
        'host' => $db_host ?? 'not set',
        'database' => $db_name ?? 'not set',
        'user' => $db_user ?? 'not set'
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>
