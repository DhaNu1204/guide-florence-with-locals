<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'HttpClient.php';

// Test basic HTTP functionality without cURL
function testHttpClient() {
    try {
        $httpClient = new HttpClient();
        
        // Test with a simple HTTP endpoint
        $response = $httpClient->get('https://httpbin.org/get');
        
        return [
            'success' => true,
            'http_code' => $response['http_code'],
            'response_length' => strlen($response['body']),
            'has_response' => !empty($response['body'])
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Test with Bokun test endpoint (should return 401 without credentials)
function testBokunEndpoint() {
    try {
        $httpClient = new HttpClient();
        
        // This should return 401 Unauthorized (which is expected without credentials)
        $response = $httpClient->get('https://api.bokuntest.com/activity.json/search');
        
        return [
            'success' => true,
            'http_code' => $response['http_code'],
            'response_length' => strlen($response['body']),
            'response_preview' => substr($response['body'], 0, 200),
            'expected_401' => $response['http_code'] === 401
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'extensions' => [
        'openssl' => extension_loaded('openssl'),
        'json' => function_exists('json_encode'),
        'stream_context' => function_exists('stream_context_create'),
        'file_get_contents' => function_exists('file_get_contents')
    ],
    'tests' => [
        'http_client' => testHttpClient(),
        'bokun_endpoint' => testBokunEndpoint()
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT);
?>