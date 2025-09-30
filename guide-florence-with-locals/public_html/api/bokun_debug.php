<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';
require_once 'BokunAPI.php';

// Get Bokun configuration
function getBokunConfig() {
    global $conn;
    
    $result = $conn->query("SELECT * FROM bokun_config LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

$config = getBokunConfig();
if (!$config) {
    echo json_encode(['error' => 'Bokun configuration not found']);
    exit();
}

try {
    $bokunAPI = new BokunAPI($config);
    
    // Test different API endpoints
    $startDate = '2025-08-29';
    $endDate = '2025-09-12';
    
    echo "Testing Bokun API endpoints...\n\n";
    
    // 1. Test basic activities search
    echo "1. Testing activities search:\n";
    try {
        $activitiesResponse = $bokunAPI->searchActivities(1, 5);
        echo "Activities found: " . count($activitiesResponse['results'] ?? []) . "\n";
        echo "Response keys: " . implode(', ', array_keys($activitiesResponse)) . "\n\n";
    } catch (Exception $e) {
        echo "Activities search failed: " . $e->getMessage() . "\n\n";
    }
    
    // 2. Test bookings endpoint
    echo "2. Testing bookings endpoint:\n";
    try {
        $bookingsResponse = $bokunAPI->getBookings($startDate, $endDate);
        echo "Bookings raw response type: " . gettype($bookingsResponse) . "\n";
        if (is_array($bookingsResponse)) {
            echo "Response keys: " . implode(', ', array_keys($bookingsResponse)) . "\n";
            echo "Bookings count: " . count($bookingsResponse['results'] ?? []) . "\n";
            if (isset($bookingsResponse['results'])) {
                echo "Results type: " . gettype($bookingsResponse['results']) . "\n";
            }
        }
        echo "Raw response preview: " . substr(json_encode($bookingsResponse), 0, 500) . "\n\n";
    } catch (Exception $e) {
        echo "Bookings search failed: " . $e->getMessage() . "\n\n";
    }
    
    // 3. Test different date formats
    echo "3. Testing with different date formats:\n";
    $testDates = [
        ['2025-09-01', '2025-09-07'],
        ['2025-09-01', '2025-09-02'],
        [date('Y-m-d'), date('Y-m-d', strtotime('+3 days'))]
    ];
    
    foreach ($testDates as $dates) {
        try {
            echo "Testing dates: {$dates[0]} to {$dates[1]}\n";
            $testResponse = $bokunAPI->getBookings($dates[0], $dates[1]);
            $count = count($testResponse['results'] ?? []);
            echo "Found: $count bookings\n";
        } catch (Exception $e) {
            echo "Failed: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Debug test failed: " . $e->getMessage() . "\n";
}
?>