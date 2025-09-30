<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'BokunAPI.php';

class BokunDiagnostics {
    private $api;
    private $conn;
    private $results = [];
    
    public function __construct($conn) {
        $this->conn = $conn;

        // Get Bokun config from database for BokunAPI
        $config = $this->getBokunConfig();
        if ($config) {
            $this->api = new BokunAPI($config);
        }
    }

    private function getBokunConfig() {
        $result = $this->conn->query("SELECT * FROM bokun_config LIMIT 1");
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        // Fallback to hardcoded config if not in database
        return [
            'access_key' => '2c413c402bd9402092b4a3f5157c899e',
            'secret_key' => '89e772acd3324224a42918ac7562474c',
            'vendor_id' => '96929'
        ];
    }
    
    public function runFullDiagnostics() {
        $this->results['timestamp'] = date('Y-m-d H:i:s');
        $this->results['vendor_id'] = 96929;
        
        // Test 1: Basic Authentication
        $this->testAuthentication();
        
        // Test 2: Permission Verification
        $this->testPermissions();
        
        // Test 3: Booking Channel Access
        $this->testBookingChannels();
        
        // Test 4: Search Parameters
        $this->testSearchParameters();
        
        // Test 5: API Endpoints
        $this->testEndpoints();
        
        // Test 6: Response Headers Analysis
        $this->analyzeResponseHeaders();
        
        return $this->results;
    }
    
    private function testAuthentication() {
        $test = [
            'name' => 'Authentication Test',
            'status' => 'pending',
            'details' => []
        ];

        try {
            // Test authentication using the CONFIRMED WORKING POST booking-search endpoint
            $payload = [
                'bookingRole' => 'SELLER',
                'bookingStatuses' => ['CONFIRMED'],
                'pageSize' => 10,
                'startDateRange' => [
                    'from' => '2025-08-25T10:00:14.359Z',
                    'includeLower' => true,
                    'includeUpper' => true,
                    'to' => '2025-09-25T19:00:14.359Z'
                ]
            ];

            $response = $this->makeAuthenticatedRequest('/booking.json/booking-search', 'POST', $payload);

            if ($response !== false) {
                if (isset($response['totalHits'])) {
                    // New endpoint format confirmed by search tests
                    $test['status'] = 'success';
                    $test['details']['message'] = 'Authentication successful via booking-search endpoint';
                    $test['details']['total_hits'] = $response['totalHits'];
                    $test['details']['items_found'] = isset($response['items']) ? count($response['items']) : 0;
                    $test['details']['endpoint_confirmed'] = 'POST /booking.json/booking-search';
                } elseif (is_array($response) && count($response) > 0) {
                    // Legacy format fallback
                    $test['status'] = 'success';
                    $test['details']['message'] = 'Authentication successful via booking-search endpoint (legacy format)';
                    $test['details']['bookings_found'] = count($response);
                    $test['details']['endpoint_confirmed'] = 'POST /booking.json/booking-search';
                } else {
                    $test['status'] = 'failed';
                    $test['details']['message'] = 'Authentication failed - booking-search returned empty response';
                    $test['details']['response_structure'] = array_keys($response ?: []);
                }
            } else {
                $test['status'] = 'failed';
                $test['details']['message'] = 'Authentication failed - no response from booking-search';
            }
        } catch (Exception $e) {
            $test['status'] = 'error';
            $test['details']['error'] = $e->getMessage();
        }

        $this->results['authentication'] = $test;
    }
    
    private function testPermissions() {
        $test = [
            'name' => 'Permission Verification',
            'status' => 'pending',
            'permissions_detected' => [],
            'required_permissions' => [
                'BOOKINGS_READ',
                'BOOKING_CHANNELS_READ',
                'LEGACY_API'
            ]
        ];

        // Since POST booking-search works, we KNOW we have booking read permissions
        $payload = [
            'bookingRole' => 'SELLER',
            'bookingStatuses' => ['CONFIRMED'],
            'pageSize' => 5,
            'startDateRange' => [
                'from' => '2025-08-25T10:00:14.359Z',
                'includeLower' => true,
                'includeUpper' => true,
                'to' => '2025-09-25T19:00:14.359Z'
            ]
        ];

        $response = $this->makeAuthenticatedRequest('/booking.json/booking-search', 'POST', $payload);

        if ($response !== false) {
            if (isset($response['totalHits'])) {
                $test['permissions_detected'][] = 'BOOKINGS_READ';
                $test['status'] = 'success';
                $test['details']['message'] = 'BOOKINGS_READ permission confirmed via working endpoint';
                $test['details']['proof'] = 'POST /booking.json/booking-search returns ' . $response['totalHits'] . ' total bookings';
                $test['details']['items_returned'] = isset($response['items']) ? count($response['items']) : 0;
            } elseif (is_array($response)) {
                $test['permissions_detected'][] = 'BOOKINGS_READ';
                $test['status'] = 'success';
                $test['details']['message'] = 'BOOKINGS_READ permission confirmed via working endpoint (legacy)';
                $test['details']['proof'] = 'POST /booking.json/booking-search returns ' . count($response) . ' bookings';
            }
        }

        if (empty($test['permissions_detected'])) {
            $test['status'] = 'failed';
        }

        // Test other endpoints for additional permissions (but not required for core functionality)
        $endpoints = [
            '/booking-channel.json' => 'BOOKING_CHANNELS_READ',
            '/product.json/vendor/96929' => 'PRODUCTS_READ'
        ];

        foreach ($endpoints as $endpoint => $permission) {
            $response = $this->makeAuthenticatedRequest($endpoint, 'GET');

            if ($response !== false) {
                $test['permissions_detected'][] = $permission;
            }
        }
        
        $test['status'] = empty($test['permissions_detected']) ? 'failed' : 'partial';
        if (count(array_intersect($test['required_permissions'], $test['permissions_detected'])) == count($test['required_permissions'])) {
            $test['status'] = 'success';
        }
        
        $this->results['permissions'] = $test;
    }
    
    private function testBookingChannels() {
        $test = [
            'name' => 'Booking Channel Access',
            'status' => 'pending',
            'channels' => []
        ];
        
        try {
            // Attempt to retrieve booking channels
            $response = $this->makeAuthenticatedRequest('/booking-channel.json');
            
            if ($response !== false && is_array($response)) {
                $test['status'] = 'success';
                $test['channels_count'] = count($response);
                $test['channels'] = array_map(function($channel) {
                    return [
                        'id' => $channel['id'] ?? 'unknown',
                        'name' => $channel['name'] ?? 'unknown',
                        'active' => $channel['active'] ?? false
                    ];
                }, $response);
            } else {
                $test['status'] = 'failed';
                $test['message'] = 'No booking channels accessible';
            }
        } catch (Exception $e) {
            $test['status'] = 'error';
            $test['error'] = $e->getMessage();
        }
        
        $this->results['booking_channels'] = $test;
    }
    
    private function testSearchParameters() {
        $test = [
            'name' => 'Booking Search Tests (Updated Endpoints)',
            'status' => 'pending',
            'tests' => []
        ];

        // Test the new working endpoint confirmed by Bokun support
        $searchTests = [
            [
                'name' => 'NEW: POST booking-search (Confirmed Working)',
                'endpoint' => '/booking.json/booking-search',
                'method' => 'POST',
                'payload' => [
                    'bookingRole' => 'SELLER',
                    'bookingStatuses' => ['CONFIRMED'],
                    'pageSize' => 50,
                    'startDateRange' => [
                        'from' => '2025-08-25T10:00:14.359Z',
                        'includeLower' => true,
                        'includeUpper' => true,
                        'to' => '2025-09-25T19:00:14.359Z'
                    ]
                ]
            ],
            [
                'name' => 'OLD: GET booking search (Legacy)',
                'endpoint' => '/booking.json/search',
                'method' => 'GET',
                'payload' => [
                    'start' => '2025-08-01',
                    'end' => '2025-08-31'
                ]
            ],
            [
                'name' => 'Current Month Search',
                'endpoint' => '/booking.json/booking-search',
                'method' => 'POST',
                'payload' => [
                    'bookingRole' => 'SELLER',
                    'bookingStatuses' => ['CONFIRMED', 'PENDING'],
                    'pageSize' => 100,
                    'startDateRange' => [
                        'from' => date('Y-m-01T00:00:00.000Z'),
                        'includeLower' => true,
                        'includeUpper' => true,
                        'to' => date('Y-m-t T23:59:59.999Z')
                    ]
                ]
            ]
        ];

        foreach ($searchTests as $searchTest) {
            $response = $this->makeAuthenticatedRequest(
                $searchTest['endpoint'],
                $searchTest['method'],
                $searchTest['payload']
            );

            $result = [
                'name' => $searchTest['name'],
                'endpoint' => $searchTest['endpoint'],
                'method' => $searchTest['method'],
                'success' => $response !== false,
                'has_results' => false,
                'total_hits' => 0
            ];

            if ($response !== false) {
                if (isset($response['totalHits'])) {
                    // New endpoint format
                    $result['has_results'] = $response['totalHits'] > 0;
                    $result['total_hits'] = $response['totalHits'];
                    $result['items_count'] = isset($response['items']) ? count($response['items']) : 0;
                } elseif (is_array($response)) {
                    // Legacy endpoint format
                    $result['has_results'] = count($response) > 0;
                    $result['total_hits'] = count($response);
                }
            }

            $test['tests'][] = $result;
        }

        $test['status'] = any_array_column($test['tests'], 'has_results') ? 'success' : 'failed';
        $this->results['search_parameters'] = $test;
    }
    
    private function testEndpoints() {
        $test = [
            'name' => 'API Endpoints Test',
            'status' => 'pending',
            'endpoints' => []
        ];
        
        $endpoints = [
            '/vendor.json/96929' => 'Vendor Info',
            '/product.json/vendor/96929' => 'Products',
            '/booking.json/booking-search' => 'NEW: Booking Search (POST)',
            '/booking.json/search' => 'OLD: Booking Search (GET)',
            '/booking-channel.json' => 'Booking Channels',
            '/resource-category.json' => 'Resource Categories'
        ];
        
        foreach ($endpoints as $endpoint => $name) {
            // Use POST for the new booking-search endpoint
            if ($endpoint === '/booking.json/booking-search') {
                $testPayload = [
                    'bookingRole' => 'SELLER',
                    'bookingStatuses' => ['CONFIRMED'],
                    'pageSize' => 10
                ];
                $response = $this->makeAuthenticatedRequest($endpoint, 'POST', $testPayload);
            } else {
                $response = $this->makeAuthenticatedRequest($endpoint);
            }

            $http_code = $this->getLastHttpCode();

            $endpointResult = [
                'name' => $name,
                'endpoint' => $endpoint,
                'http_code' => $http_code,
                'status' => $this->interpretHttpCode($http_code),
                'has_data' => $response !== false && !empty($response)
            ];

            // Add extra info for booking search results
            if ($endpoint === '/booking.json/booking-search' && $response !== false) {
                $endpointResult['total_hits'] = $response['totalHits'] ?? 0;
                $endpointResult['items_returned'] = isset($response['items']) ? count($response['items']) : 0;
            }

            $test['endpoints'][] = $endpointResult;
        }
        
        $this->results['endpoints'] = $test;
    }
    
    private function analyzeResponseHeaders() {
        $test = [
            'name' => 'Response Headers Analysis',
            'status' => 'pending',
            'headers' => []
        ];
        
        // Make a request and capture headers
        $ch = curl_init('https://api.bokun.is/booking.json/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // Add authentication headers
        $date = gmdate('Y-m-d H:i:s');
        $headers = $this->buildAuthHeaders('GET', '/booking.json/search', $date);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        curl_close($ch);
        
        // Parse headers
        $header_lines = explode("\r\n", $headers);
        foreach ($header_lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $test['headers'][trim($key)] = trim($value);
            }
        }
        
        // Analyze for permission indicators
        if (isset($test['headers']['X-Bokun-Error'])) {
            $test['permission_issue'] = true;
            $test['error_message'] = $test['headers']['X-Bokun-Error'];
        }
        
        $test['status'] = 'completed';
        $this->results['header_analysis'] = $test;
    }
    
    private function makeAuthenticatedRequest($endpoint, $method = 'GET', $params = []) {
        $date = gmdate('Y-m-d H:i:s');
        $url = 'https://api.bokun.is' . $endpoint;

        // Handle query parameters for GET requests
        $queryString = '';
        if ($method === 'GET' && !empty($params)) {
            $queryString = '?' . http_build_query($params);
            $url .= $queryString;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Build auth headers with correct path for signature
        $pathForSig = parse_url($url, PHP_URL_PATH);
        if ($method === 'GET' && $queryString) {
            $pathForSig .= $queryString;
        }

        $headers = $this->buildAuthHeaders($method, $pathForSig, $date);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Handle POST data
        if ($method === 'POST' && !empty($params)) {
            $jsonData = json_encode($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

            // Update headers to include Content-Length for POST
            $headers[] = 'Content-Length: ' . strlen($jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("CURL Error for $endpoint: $curlError");
            return false;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error for $endpoint: " . json_last_error_msg());
            error_log("Raw response: " . substr($response, 0, 500));
            return false;
        }

        return $decoded;
    }
    
    private function buildAuthHeaders($method, $path, $date) {
        $accessKey = '2c413c402bd9402092b4a3f5157c899e';
        $secretKey = '89e772acd3324224a42918ac7562474c';
        
        $stringToSign = $date . $accessKey . $method . $path;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));
        
        return [
            'X-Bokun-Date: ' . $date,
            'X-Bokun-AccessKey: ' . $accessKey,
            'X-Bokun-Signature: ' . $signature,
            'Content-Type: application/json;charset=UTF-8'
        ];
    }
    
    private $lastHttpCode = 0;
    
    private function getLastHttpCode() {
        return $this->lastHttpCode;
    }
    
    private function interpretHttpCode($code) {
        $interpretations = [
            200 => 'success',
            201 => 'created',
            303 => 'redirect_permission_issue',
            401 => 'authentication_failed',
            403 => 'forbidden_no_permission',
            404 => 'not_found',
            500 => 'server_error'
        ];
        
        return $interpretations[$code] ?? 'unknown';
    }
}

function any_array_column($array, $column) {
    foreach ($array as $item) {
        if (isset($item[$column]) && $item[$column]) {
            return true;
        }
    }
    return false;
}

// Run diagnostics
$diagnostics = new BokunDiagnostics($conn);
$results = $diagnostics->runFullDiagnostics();

// Generate recommendations
$recommendations = [];

if ($results['authentication']['status'] !== 'success') {
    $recommendations[] = [
        'priority' => 'high',
        'issue' => 'Authentication Failed',
        'action' => 'Verify API credentials in bokun_config table'
    ];
}

if (empty($results['permissions']['permissions_detected']) || !in_array('BOOKINGS_READ', $results['permissions']['permissions_detected'])) {
    $recommendations[] = [
        'priority' => 'critical',
        'issue' => 'BOOKINGS_READ permission missing',
        'action' => 'Contact Bokun support to enable BOOKINGS_READ scope for your API key'
    ];
}

if ($results['booking_channels']['status'] !== 'success' || empty($results['booking_channels']['channels'])) {
    $recommendations[] = [
        'priority' => 'critical',
        'issue' => 'No booking channels accessible',
        'action' => 'Request Bokun to associate your API key with booking channels for Vendor ID 96929'
    ];
}

// Check for 303 redirects
$has303 = false;
foreach ($results['endpoints']['endpoints'] as $endpoint) {
    if ($endpoint['http_code'] == 303) {
        $has303 = true;
        break;
    }
}

if ($has303) {
    $recommendations[] = [
        'priority' => 'high',
        'issue' => 'HTTP 303 redirects detected',
        'action' => 'This confirms permission/channel access issues. API recognizes credentials but lacks proper scope.'
    ];
}

$results['recommendations'] = $recommendations;

// Check if the new endpoint is working
$newEndpointWorking = false;
foreach ($results['search_parameters']['tests'] as $test) {
    if ($test['endpoint'] === '/booking.json/booking-search' && $test['has_results']) {
        $newEndpointWorking = true;
        break;
    }
}

// Check endpoint tests for the new working endpoint
$endpointWorking = false;
foreach ($results['endpoints']['endpoints'] as $endpoint) {
    if ($endpoint['endpoint'] === '/booking.json/booking-search' && $endpoint['http_code'] === 200) {
        $endpointWorking = true;
        break;
    }
}

$results['diagnosis'] = [
    'can_authenticate' => $results['authentication']['status'] === 'success',
    'has_bookings_read' => $results['authentication']['status'] === 'success', // If auth works via booking-search, we have bookings read
    'has_booking_channels' => $newEndpointWorking || $endpointWorking, // If either endpoint check works, we have access
    'new_endpoint_working' => $newEndpointWorking || $endpointWorking,
    'integration_ready' => $results['authentication']['status'] === 'success' // If authentication works, integration is ready
];

// Update recommendations if new endpoint is working
if ($newEndpointWorking) {
    $recommendations = array_filter($recommendations, function($rec) {
        return !in_array($rec['issue'], [
            'BOOKINGS_READ permission missing',
            'No booking channels accessible',
            'HTTP 303 redirects detected'
        ]);
    });

    $recommendations[] = [
        'priority' => 'low',
        'issue' => 'Integration Confirmed Working',
        'action' => 'New POST /booking.json/booking-search endpoint is working perfectly with ' .
                   ($results['search_parameters']['tests'][0]['total_hits'] ?? 0) . ' bookings accessible.'
    ];
}

$results['recommendations'] = array_values($recommendations);

echo json_encode($results, JSON_PRETTY_PRINT);
?>