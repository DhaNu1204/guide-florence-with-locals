<?php
require_once 'config.php';
require_once 'BokunAPI.php';

// Simple auth check function for API endpoints
function checkAuth() {
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
    
    if (!$token) {
        // For development, skip auth check
        return true;
    }
    
    // In development, accept any token
    return true;
}

// Get Bokun configuration
function getBokunConfig() {
    global $conn;

    $result = $conn->query("SELECT * FROM bokun_config LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $config = $result->fetch_assoc();
        // Map production column names to expected names for backward compatibility
        if (isset($config['api_key'])) {
            $config['access_key'] = $config['api_key'];
        }
        if (isset($config['api_secret'])) {
            $config['secret_key'] = $config['api_secret'];
        }
        return $config;
    }
    return null;
}

// Save Bokun configuration
function saveBokunConfig($data) {
    global $conn;

    $accessKey = $data['access_key'] ?? '';
    $secretKey = $data['secret_key'] ?? '';
    $vendorId = $data['vendor_id'] ?? '';
    $syncEnabled = isset($data['sync_enabled']) ? 1 : 0;

    // Check if config exists
    $result = $conn->query("SELECT id FROM bokun_config LIMIT 1");

    if ($result && $result->num_rows > 0) {
        // Update existing - use correct column names from production database
        $stmt = $conn->prepare("
            UPDATE bokun_config
            SET api_key = ?, api_secret = ?, vendor_id = ?,
                sync_enabled = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = (SELECT id FROM bokun_config LIMIT 1)
        ");
        $stmt->bind_param("sssi", $accessKey, $secretKey, $vendorId, $syncEnabled);
    } else {
        // Insert new - use correct column names from production database
        $stmt = $conn->prepare("
            INSERT INTO bokun_config (api_key, api_secret, vendor_id, sync_enabled, api_base_url, booking_channel)
            VALUES (?, ?, ?, ?, 'https://api.bokun.is', 'www.florencewithlocals.com')
        ");
        $stmt->bind_param("sssi", $accessKey, $secretKey, $vendorId, $syncEnabled);
    }

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => $error];
    }
}

// Sync bookings from Bokun
function syncBookings($startDate = null, $endDate = null) {
    global $conn;
    
    $config = getBokunConfig();
    if (!$config || !$config['sync_enabled']) {
        return ['error' => 'Bokun sync is not configured or disabled'];
    }
    
    // Default to next 14 days if no dates provided (to catch more bookings)
    if (!$startDate) {
        $startDate = date('Y-m-d');
    }
    if (!$endDate) {
        $endDate = date('Y-m-d', strtotime('+14 days'));
    }
    
    try {
        // Initialize Bokun API
        $bokunAPI = new BokunAPI($config);
        
        // Get bookings from Bokun
        error_log("Bokun Sync: Requesting bookings from $startDate to $endDate");
        $bookingsResponse = $bokunAPI->getBookings($startDate, $endDate);
        error_log("Bokun Sync: Raw API response: " . json_encode($bookingsResponse));

        // getBookings() now returns the items array directly after our fix
        $bookings = $bookingsResponse;
        $totalHits = count($bookings);
        
        error_log("Bokun Sync: Found " . count($bookings) . " bookings to process");
        
        $syncedCount = 0;
        $errors = [];
        $apiBookingsCount = count($bookings);
        
        foreach ($bookings as $booking) {
            try {
                // Transform booking to our tour format
                $tourData = $bokunAPI->transformBookingToTour($booking);
                
                // Check if tour already exists and get current date/time for rescheduling detection
                $stmt = $conn->prepare("SELECT id, date, time, rescheduled, original_date, original_time FROM tours WHERE bokun_booking_id = ? OR external_id = ?");
                $stmt->bind_param("ss", $tourData['bokun_booking_id'], $tourData['external_id']);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    // Check if this is a rescheduling (date or time changed)
                    $isRescheduled = false;
                    $originalDate = $existing['original_date'] ?: $existing['date'];
                    $originalTime = $existing['original_time'] ?: $existing['time'];

                    if (($existing['date'] !== $tourData['date']) || ($existing['time'] !== $tourData['time'])) {
                        $isRescheduled = true;
                        error_log("Rescheduling detected for {$tourData['external_id']}: {$existing['date']} {$existing['time']} → {$tourData['date']} {$tourData['time']}");

                        // If this is the first rescheduling, save the original date/time
                        if (!$existing['rescheduled']) {
                            $originalDate = $existing['date'];
                            $originalTime = $existing['time'];
                        }
                    }

                    // Update existing tour with rescheduling information
                    $stmt = $conn->prepare("
                        UPDATE tours SET
                        title = ?, date = ?, time = ?, duration = ?, language = ?,
                        customer_name = ?, customer_email = ?, customer_phone = ?,
                        participants = ?, booking_channel = ?, total_amount_paid = ?,
                        expected_amount = ?, payment_status = ?, paid = ?,
                        cancelled = ?, bokun_data = ?, last_sync = ?,
                        rescheduled = ?, original_date = ?, original_time = ?,
                        rescheduled_at = " . ($isRescheduled ? "NOW()" : "rescheduled_at") . ",
                        updated_at = NOW()
                        WHERE id = ?
                    ");
                    $rescheduledFlag = ($isRescheduled || $existing['rescheduled']) ? 1 : 0;
                    $stmt->bind_param("ssssssssisddsiississi",
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync'],
                        $rescheduledFlag, $originalDate, $originalTime, $existing['id']
                    );
                } else {
                    // Insert new tour
                    $stmt = $conn->prepare("
                        INSERT INTO tours (
                            external_id, bokun_booking_id, bokun_confirmation_code, title, date, time, duration, language,
                            customer_name, customer_email, customer_phone, participants,
                            booking_channel, total_amount_paid, expected_amount, payment_status, paid,
                            external_source, needs_guide_assignment, guide_id, cancelled,
                            bokun_data, last_sync, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    // Count: 23 parameters (external_id, bokun_booking_id, bokun_confirmation_code, title, date, time, duration, language,
                    //        customer_name, customer_email, customer_phone, participants, booking_channel, total_amount_paid,
                    //        expected_amount, payment_status, paid, external_source, needs_guide_assignment, guide_id,
                    //        cancelled, bokun_data, last_sync)
                    $stmt->bind_param("sssssssssssisddsisiiiss",
                        $tourData['external_id'], $tourData['bokun_booking_id'], $tourData['bokun_confirmation_code'],
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['external_source'], $tourData['needs_guide_assignment'], $tourData['guide_id'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync']
                    );
                }
                
                if ($stmt->execute()) {
                    $syncedCount++;
                } else {
                    $errors[] = "Failed to save booking: " . $tourData['external_id'];
                }
                $stmt->close();
                
            } catch (Exception $e) {
                $errors[] = "Error processing booking: " . $e->getMessage();
            }
        }
        
        // Update last sync timestamp
        $conn->query("UPDATE bokun_config SET last_sync = NOW()");
        
        return [
            'success' => true,
            'synced_count' => $syncedCount,
            'total_bookings' => $apiBookingsCount,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'errors' => $errors,
            'debug_info' => [
                'api_response_keys' => array_keys($bookingsResponse),
                'raw_booking_count' => $apiBookingsCount
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'error' => 'Bokun API Error: ' . $e->getMessage(),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }
}

// Get unassigned Bokun tours
function getUnassignedTours() {
    global $conn;
    
    $result = $conn->query("
        SELECT t.*, g.name as guide_name 
        FROM tours t
        LEFT JOIN guides g ON t.guide_id = g.id
        WHERE t.external_source = 'bokun' 
        AND t.needs_guide_assignment = 1
        AND t.cancelled = 0
        AND t.date >= CURDATE()
        ORDER BY t.date, t.time
    ");
    
    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    
    return $tours;
}

// Auto-assign guide based on rules
function autoAssignGuide($tourId) {
    global $conn;
    
    // Get tour details
    $stmt = $conn->prepare("SELECT * FROM tours WHERE id = ?");
    $stmt->bind_param("i", $tourId);
    $stmt->execute();
    $tour = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tour) {
        return ['error' => 'Tour not found'];
    }
    
    // Find available guide for this date/time
    $stmt = $conn->prepare("
        SELECT g.id, g.name, 
               COALESCE(ga.assigned_tours, 0) as assigned_tours,
               COALESCE(ga.max_tours, 2) as max_tours
        FROM guides g
        LEFT JOIN guide_availability ga ON g.id = ga.guide_id 
            AND ga.date = ? 
            AND (ga.time_slot = ? OR ga.time_slot IS NULL)
        WHERE (ga.available = 1 OR ga.available IS NULL)
        AND (ga.assigned_tours < ga.max_tours OR ga.assigned_tours IS NULL)
        ORDER BY assigned_tours ASC
        LIMIT 1
    ");
    
    $stmt->bind_param("ss", $tour['date'], $tour['time']);
    $stmt->execute();
    $guide = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($guide) {
        // Assign guide to tour
        $stmt = $conn->prepare("UPDATE tours SET guide_id = ?, needs_guide_assignment = 0 WHERE id = ?");
        $stmt->bind_param("ii", $guide['id'], $tourId);
        $stmt->execute();
        $stmt->close();
        
        // Update guide availability
        $stmt = $conn->prepare("
            INSERT INTO guide_availability (guide_id, date, time_slot, assigned_tours)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE assigned_tours = assigned_tours + 1
        ");
        $stmt->bind_param("iss", $guide['id'], $tour['date'], $tour['time']);
        $stmt->execute();
        $stmt->close();
        
        return ['success' => true, 'guide' => $guide];
    }
    
    return ['error' => 'No available guide found'];
}

// Test Bokun API connection
function testBokunConnection() {
    // First check PHP capabilities
    if (!function_exists('curl_init') && !function_exists('file_get_contents')) {
        return [
            'success' => false,
            'error' => 'Neither cURL nor file_get_contents is available for HTTP requests',
            'solution' => 'Please enable cURL extension in PHP or contact your system administrator'
        ];
    }
    
    // Test basic HTTP functionality first
    if (!function_exists('curl_init')) {
        // Test if HTTPS wrapper is enabled
        $wrappers = stream_get_wrappers();
        if (!in_array('https', $wrappers)) {
            return [
                'success' => false,
                'error' => 'HTTPS wrapper is not enabled in PHP',
                'solution' => 'Please enable allow_url_fopen and openssl extension in php.ini',
                'current_wrappers' => $wrappers
            ];
        }
        
        // Test basic connectivity
        try {
            $testContext = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            $testResult = @file_get_contents('https://www.google.com', false, $testContext);
            if ($testResult === false) {
                return [
                    'success' => false,
                    'error' => 'HTTPS requests are blocked or not working',
                    'solution' => 'Please check your firewall, proxy settings, or enable cURL extension'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Network connectivity test failed: ' . $e->getMessage(),
                'solution' => 'Please check your internet connection and firewall settings'
            ];
        }
    }
    
    $config = getBokunConfig();
    if (!$config) {
        return ['success' => false, 'error' => 'Bokun configuration not found in database'];
    }
    
    // Validate required configuration
    if (empty($config['access_key'])) {
        return ['success' => false, 'error' => 'Access Key is required'];
    }
    if (empty($config['secret_key'])) {
        return ['success' => false, 'error' => 'Secret Key is required'];
    }
    if (empty($config['vendor_id'])) {
        return ['success' => false, 'error' => 'Vendor ID is required'];
    }
    
    try {
        error_log("Testing Bokun connection with config: " . json_encode([
            'access_key' => substr($config['access_key'], 0, 8) . '...',
            'vendor_id' => $config['vendor_id'],
            'sync_enabled' => $config['sync_enabled']
        ]));
        
        $bokunAPI = new BokunAPI($config);
        $result = $bokunAPI->testConnection();
        
        error_log("Bokun test result: " . json_encode($result));
        return $result;
    } catch (Exception $e) {
        error_log("Bokun test exception: " . $e->getMessage());
        
        $errorMsg = $e->getMessage();
        $solution = '';
        
        if (strpos($errorMsg, 'operation failed') !== false) {
            $solution = 'Network request failed. Please check: 1) Internet connection, 2) Firewall settings, 3) Enable cURL extension in PHP';
        } elseif (strpos($errorMsg, 'SSL') !== false) {
            $solution = 'SSL/TLS issue. Please ensure OpenSSL extension is enabled and up to date';
        }
        
        return [
            'success' => false,
            'error' => 'Connection test failed: ' . $errorMsg,
            'solution' => $solution,
            'debug' => [
                'php_version' => PHP_VERSION,
                'curl_enabled' => function_exists('curl_init'),
                'openssl_enabled' => extension_loaded('openssl'),
                'allow_url_fopen' => ini_get('allow_url_fopen'),
                'https_wrapper' => in_array('https', stream_get_wrappers())
            ]
        ];
    }
}

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'config':
                $config = getBokunConfig();
                echo json_encode($config ?: ['configured' => false]);
                break;
                
            case 'unassigned':
                echo json_encode(getUnassignedTours());
                break;
                
            case 'test':
                echo json_encode(testBokunConnection());
                break;

            case 'sync':
                $startDate = $_GET['start_date'] ?? null;
                $endDate = $_GET['end_date'] ?? null;
                echo json_encode(syncBookings($startDate, $endDate));
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        switch ($action) {
            case 'config':
                echo json_encode(saveBokunConfig($data));
                break;
                
            case 'sync':
                $startDate = $data['start_date'] ?? null;
                $endDate = $data['end_date'] ?? null;
                echo json_encode(syncBookings($startDate, $endDate));
                break;
                
            case 'auto-assign':
                $tourId = $data['tour_id'] ?? 0;
                echo json_encode(autoAssignGuide($tourId));
                break;
                
            default:
                echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
?>