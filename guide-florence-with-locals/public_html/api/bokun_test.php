<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'BokunAPI.php';

// Basic connectivity test
function testBasicConnectivity() {
    $testUrl = 'https://api.bokuntest.com/';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Florence-Guides/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'url' => $testUrl,
        'http_code' => $httpCode,
        'curl_error' => $error,
        'response_length' => strlen($response),
        'curl_version' => curl_version(),
        'ssl_support' => in_array('https', curl_version()['protocols'])
    ];
}

// Test PHP extensions
function testPHPSupport() {
    return [
        'php_version' => PHP_VERSION,
        'curl_enabled' => function_exists('curl_init'),
        'openssl_enabled' => extension_loaded('openssl'),
        'json_enabled' => function_exists('json_encode'),
        'hash_enabled' => function_exists('hash_hmac'),
        'date_default_timezone' => date_default_timezone_get(),
        'current_time' => date('Y-m-d H:i:s'),
        'current_utc_time' => gmdate('Y-m-d H:i:s')
    ];
}

// Test database connection
function testDatabaseConnection() {
    global $conn;
    
    if (!$conn) {
        return ['error' => 'Database connection failed'];
    }
    
    try {
        // Test if bokun_config table exists
        $result = $conn->query("SHOW TABLES LIKE 'bokun_config'");
        $tableExists = $result->num_rows > 0;
        
        $configCount = 0;
        if ($tableExists) {
            $result = $conn->query("SELECT COUNT(*) as count FROM bokun_config");
            if ($result) {
                $row = $result->fetch_assoc();
                $configCount = $row['count'];
            }
        }
        
        return [
            'connected' => true,
            'bokun_config_table_exists' => $tableExists,
            'config_records_count' => $configCount,
            'mysql_version' => $conn->server_info
        ];
    } catch (Exception $e) {
        return ['error' => 'Database test failed: ' . $e->getMessage()];
    }
}

// Test with sample credentials (invalid, just for signature generation test)
function testSignatureGeneration() {
    try {
        $config = [
            'access_key' => 'test_access_key',
            'secret_key' => 'test_secret_key',
            'vendor_id' => 'test_vendor'
        ];
        
        $bokunAPI = new BokunAPI($config);
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($bokunAPI);
        $method = $reflection->getMethod('generateSignature');
        $method->setAccessible(true);
        
        $testDate = '2025-01-29 10:00:00';
        $testMethod = 'POST';
        $testPath = '/activity.json/search';
        
        $signature = $method->invoke($bokunAPI, $testDate, $testMethod, $testPath);
        
        return [
            'signature_generated' => !empty($signature),
            'signature_length' => strlen($signature),
            'test_signature' => $signature,
            'test_input' => $testDate . 'test_access_key' . $testMethod . $testPath
        ];
    } catch (Exception $e) {
        return ['error' => 'Signature test failed: ' . $e->getMessage()];
    }
}

// Run all tests
$tests = [
    'php_support' => testPHPSupport(),
    'connectivity' => testBasicConnectivity(),
    'database' => testDatabaseConnection(),
    'signature' => testSignatureGeneration()
];

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => $tests
], JSON_PRETTY_PRINT);
?>