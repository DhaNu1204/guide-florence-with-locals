<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/plain');

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
    echo "ERROR: Bokun configuration not found\n";
    exit();
}

try {
    $bokunAPI = new BokunAPI($config);
    
    echo "=== COMPREHENSIVE BOKUN API TEST ===\n\n";
    echo "Base URL: https://api.bokun.is\n";
    echo "Vendor ID: {$config['vendor_id']}\n";
    echo "Access Key: " . substr($config['access_key'], 0, 8) . "...\n\n";
    
    // Test 1: Try different date ranges including 2024
    echo "1. TESTING DIFFERENT DATE RANGES:\n";
    echo "----------------------------------------\n";
    
    $testRanges = [
        // Current dates (2025)
        ['2025-08-29', '2025-09-12', 'Current 2025 range'],
        ['2025-09-01', '2025-09-07', 'September 2025'],
        
        // Previous year (2024) - Your bookings might be here!
        ['2024-09-01', '2024-09-07', 'September 2024'],
        ['2024-08-01', '2024-09-30', 'Aug-Sep 2024'],
        
        // Recent past
        ['2024-12-01', '2024-12-31', 'December 2024'],
        ['2025-01-01', '2025-01-31', 'January 2025'],
        
        // Wider range
        ['2024-01-01', '2025-12-31', 'Entire 2024-2025']
    ];
    
    foreach ($testRanges as list($start, $end, $description)) {
        echo "Testing: $description ($start to $end)\n";
        try {
            $response = $bokunAPI->getBookings($start, $end, 1, 100);
            $count = count($response['results'] ?? []);
            echo "  Result: $count bookings found\n";
            
            if ($count > 0) {
                echo "  FOUND BOOKINGS! Showing first booking:\n";
                $first = $response['results'][0];
                echo "  - ID: " . ($first['id'] ?? 'N/A') . "\n";
                echo "  - Confirmation: " . ($first['confirmationCode'] ?? 'N/A') . "\n";
                echo "  - Product: " . ($first['productTitle'] ?? 'N/A') . "\n";
                echo "  - Date: " . ($first['startTime'] ?? 'N/A') . "\n";
                echo "  - Status: " . ($first['status'] ?? 'N/A') . "\n";
                echo "  - Customer: " . ($first['customer']['firstName'] ?? '') . " " . ($first['customer']['lastName'] ?? '') . "\n";
                break; // Found bookings, stop searching
            }
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
    // Test 2: Try different booking statuses if supported
    echo "\n2. TESTING BOOKING STATUSES:\n";
    echo "----------------------------------------\n";
    $statuses = ['CONFIRMED', 'PENDING', 'CANCELLED', 'ALL'];
    
    foreach ($statuses as $status) {
        echo "Testing status: $status\n";
        try {
            // Try with status parameter
            $endpoint = "/booking.json/search?start=2024-01-01&end=2025-12-31&status=$status";
            $result = $bokunAPI->makeRequest('GET', $endpoint);
            $count = count($result['results'] ?? []);
            echo "  Result: $count bookings with status $status\n";
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
?>