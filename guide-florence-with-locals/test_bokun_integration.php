<?php
// Test script for Bokun integration with the corrected endpoint

require_once 'public_html/api/config.php';
require_once 'public_html/api/BokunAPI.php';

echo "=================================\n";
echo "BOKUN INTEGRATION TEST\n";
echo "=================================\n\n";

// Get Bokun configuration
$result = $conn->query("SELECT * FROM bokun_config LIMIT 1");
$config = $result->fetch_assoc();

if (!$config) {
    die("ERROR: No Bokun configuration found in database\n");
}

echo "Configuration loaded:\n";
echo "- Vendor ID: " . $config['vendor_id'] . "\n";
echo "- API Key: " . substr($config['access_key'], 0, 8) . "...\n";
echo "- Base URL: " . $config['base_url'] . "\n\n";

// Initialize Bokun API
$bokunAPI = new BokunAPI($config);

// Test the new booking-search endpoint
echo "Testing /booking.json/booking-search endpoint...\n";
echo "-----------------------------------------\n";

$startDate = date('Y-m-d', strtotime('-30 days'));
$endDate = date('Y-m-d', strtotime('+30 days'));

echo "Date range: $startDate to $endDate\n\n";

try {
    // Call the getBookings method which now uses the correct endpoint
    $response = $bokunAPI->getBookings($startDate, $endDate);

    if (isset($response['items'])) {
        $totalHits = $response['totalHits'] ?? 0;
        $itemCount = count($response['items']);

        echo "✅ SUCCESS! API is working correctly!\n";
        echo "- Total bookings found: $totalHits\n";
        echo "- Items in this page: $itemCount\n\n";

        if ($itemCount > 0) {
            echo "Sample Bookings:\n";
            echo "----------------\n";

            foreach (array_slice($response['items'], 0, 3) as $booking) {
                echo "\n📋 Booking ID: " . $booking['id'] . "\n";
                echo "   Confirmation: " . $booking['confirmationCode'] . "\n";

                if (isset($booking['productBookings'][0])) {
                    $product = $booking['productBookings'][0];
                    echo "   Product: " . ($product['product']['title'] ?? 'N/A') . "\n";
                    echo "   Status: " . ($product['status'] ?? 'N/A') . "\n";
                }

                if (isset($booking['customer'])) {
                    echo "   Customer: " . $booking['customer']['firstName'] . " " . $booking['customer']['lastName'] . "\n";
                    echo "   Email: " . $booking['customer']['email'] . "\n";
                }

                if (isset($booking['channel'])) {
                    echo "   Channel: " . $booking['channel']['title'] . "\n";
                }

                echo "   Created: " . date('Y-m-d H:i', $booking['creationDate'] / 1000) . "\n";
            }
        }

        echo "\n🎯 INTEGRATION STATUS: READY TO USE!\n";
        echo "The Bokun integration is now fully functional.\n";
        echo "You can sync bookings through the web interface at:\n";
        echo "http://localhost:5173/bokun-integration\n";

    } elseif (is_array($response) && !empty($response)) {
        echo "⚠️ Unexpected response format, but data received:\n";
        echo "Response keys: " . implode(', ', array_keys($response)) . "\n";
        print_r($response);
    } else {
        echo "❌ No bookings found or empty response\n";
        echo "Response: " . json_encode($response) . "\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n\n";

    if ($e->getCode() == 303) {
        echo "This is a permission issue. The API key needs BOOKINGS_READ permission.\n";
    }
}

echo "\n=================================\n";
echo "TEST COMPLETE\n";
echo "=================================\n";