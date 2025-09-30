<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5175');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

class BokunEnhanced {
    private $accessKey;
    private $secretKey;
    private $baseUrl = 'https://api.bokun.is';
    private $vendorId = 96929;
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->loadCredentials();
    }
    
    private function loadCredentials() {
        $sql = "SELECT * FROM bokun_config WHERE id = 1";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $config = $result->fetch_assoc();
            $this->accessKey = $config['access_key'];
            $this->secretKey = $config['secret_key'];
        }
    }
    
    private function generateSignature($method, $path, $date) {
        $stringToSign = $date . $this->accessKey . $method . $path;
        return base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
    }
    
    private function makeRequest($endpoint, $method = 'GET', $data = null, $queryParams = []) {
        $date = gmdate('Y-m-d H:i:s');
        $path = $endpoint;
        
        // Build query string for GET requests
        if ($method === 'GET' && !empty($queryParams)) {
            $path .= '?' . http_build_query($queryParams);
        }
        
        $url = $this->baseUrl . $path;
        $signature = $this->generateSignature($method, $path, $date);
        
        $headers = [
            'X-Bokun-Date: ' . $date,
            'X-Bokun-AccessKey: ' . $this->accessKey,
            'X-Bokun-Signature: ' . $signature
        ];
        
        if ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        return [
            'http_code' => $httpCode,
            'headers' => $this->parseHeaders($headers),
            'body' => json_decode($body, true) ?: $body,
            'raw_body' => $body
        ];
    }
    
    private function parseHeaders($headerString) {
        $headers = [];
        $lines = explode("\r\n", $headerString);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        return $headers;
    }
    
    public function searchBookings($params = []) {
        // Default parameters as recommended by Bokun support - using 2025 dates
        $defaultParams = [
            'start' => date('Y-m-d', strtotime('2025-08-01')),
            'end' => date('Y-m-d', strtotime('2025-08-31')),
            'page' => 1,
            'pageSize' => 50
        ];
        
        $params = array_merge($defaultParams, $params);
        
        // Try multiple approaches as recommended
        $approaches = [
            [
                'method' => 'GET',
                'endpoint' => '/booking.json/search',
                'params' => $params,
                'description' => 'GET with query parameters'
            ],
            [
                'method' => 'POST',
                'endpoint' => '/booking.json/search',
                'body' => $params,
                'description' => 'POST with JSON body'
            ],
            [
                'method' => 'GET',
                'endpoint' => '/booking.json/search',
                'params' => [
                    'start' => $params['start'],
                    'end' => $params['end']
                ],
                'description' => 'GET with minimal parameters'
            ],
            [
                'method' => 'GET',
                'endpoint' => '/booking.json/search',
                'params' => [],
                'description' => 'GET with no parameters'
            ]
        ];
        
        $results = [];
        
        foreach ($approaches as $approach) {
            if ($approach['method'] === 'GET') {
                $response = $this->makeRequest(
                    $approach['endpoint'],
                    'GET',
                    null,
                    $approach['params'] ?? []
                );
            } else {
                $response = $this->makeRequest(
                    $approach['endpoint'],
                    'POST',
                    $approach['body'] ?? null
                );
            }
            
            $results[] = [
                'approach' => $approach['description'],
                'http_code' => $response['http_code'],
                'has_data' => !empty($response['body']) && is_array($response['body']),
                'data_count' => is_array($response['body']) ? count($response['body']) : 0,
                'headers' => $response['headers'],
                'body' => $response['body']
            ];
            
            // If we get successful data, return it
            if ($response['http_code'] === 200 && !empty($response['body']) && is_array($response['body'])) {
                return [
                    'success' => true,
                    'bookings' => $response['body'],
                    'count' => count($response['body']),
                    'approach_used' => $approach['description']
                ];
            }
        }
        
        // If no approach worked, return diagnostic information
        return [
            'success' => false,
            'message' => 'No bookings retrieved. See diagnostic results.',
            'diagnostic_results' => $results,
            'recommendation' => 'API authentication is working but BOOKINGS_READ permission or booking channel access is missing.'
        ];
    }
    
    public function getBookingChannels() {
        $response = $this->makeRequest('/booking-channel.json');
        
        if ($response['http_code'] === 200) {
            return [
                'success' => true,
                'channels' => $response['body'],
                'count' => is_array($response['body']) ? count($response['body']) : 0
            ];
        }
        
        return [
            'success' => false,
            'http_code' => $response['http_code'],
            'message' => 'Unable to retrieve booking channels',
            'body' => $response['body']
        ];
    }
    
    public function getProducts() {
        $response = $this->makeRequest('/product.json/vendor/' . $this->vendorId);
        
        if ($response['http_code'] === 200) {
            return [
                'success' => true,
                'products' => $response['body'],
                'count' => is_array($response['body']) ? count($response['body']) : 0
            ];
        }
        
        return [
            'success' => false,
            'http_code' => $response['http_code'],
            'message' => 'Unable to retrieve products',
            'body' => $response['body']
        ];
    }
    
    public function verifyPermissions() {
        $permissions = [
            'authentication' => false,
            'vendor_access' => false,
            'bookings_read' => false,
            'booking_channels' => false,
            'products_read' => false
        ];
        
        // Test authentication with vendor endpoint
        $vendorResponse = $this->makeRequest('/vendor.json/' . $this->vendorId);
        if ($vendorResponse['http_code'] === 200) {
            $permissions['authentication'] = true;
            $permissions['vendor_access'] = true;
        }
        
        // Test bookings access
        $bookingResponse = $this->searchBookings();
        if ($bookingResponse['success']) {
            $permissions['bookings_read'] = true;
        }
        
        // Test booking channels
        $channelResponse = $this->getBookingChannels();
        if ($channelResponse['success'] && $channelResponse['count'] > 0) {
            $permissions['booking_channels'] = true;
        }
        
        // Test products
        $productResponse = $this->getProducts();
        if ($productResponse['success']) {
            $permissions['products_read'] = true;
        }
        
        return [
            'permissions' => $permissions,
            'all_permissions_granted' => !in_array(false, $permissions),
            'missing_permissions' => array_keys(array_filter($permissions, function($v) { return !$v; }))
        ];
    }
}

// Handle API requests
if (isset($_GET['action'])) {
    $bokun = new BokunEnhanced($conn);
    
    switch ($_GET['action']) {
        case 'search':
            $params = [];
            if (isset($_GET['start'])) $params['start'] = $_GET['start'];
            if (isset($_GET['end'])) $params['end'] = $_GET['end'];
            if (isset($_GET['page'])) $params['page'] = intval($_GET['page']);
            if (isset($_GET['pageSize'])) $params['pageSize'] = intval($_GET['pageSize']);
            
            echo json_encode($bokun->searchBookings($params));
            break;
            
        case 'channels':
            echo json_encode($bokun->getBookingChannels());
            break;
            
        case 'products':
            echo json_encode($bokun->getProducts());
            break;
            
        case 'verify':
            echo json_encode($bokun->verifyPermissions());
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} else {
    echo json_encode(['error' => 'No action specified']);
}
?>