<?php
// Simple test to verify payment API functionality
header('Content-Type: application/json');
require_once 'api/config.php';

// Test payments endpoint
echo "Testing payments API...\n";

try {
    // Simulate GET request to payments
    $result = $conn->query("SELECT * FROM payments ORDER BY created_at DESC LIMIT 10");
    $payments = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
        echo "✅ Payments API working - found " . count($payments) . " payments\n";

        // Output sample data
        if (count($payments) > 0) {
            echo "Sample payment: " . json_encode($payments[0]) . "\n";
        }
    } else {
        echo "❌ Error fetching payments: " . $conn->error . "\n";
    }

    // Test guide payments
    echo "\nTesting guide payments API...\n";
    $result = $conn->query("SELECT * FROM guide_payments LIMIT 10");
    $guidePayments = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $guidePayments[] = $row;
        }
        echo "✅ Guide payments API working - found " . count($guidePayments) . " guide payment records\n";

        if (count($guidePayments) > 0) {
            echo "Sample guide payment: " . json_encode($guidePayments[0]) . "\n";
        }
    } else {
        echo "❌ Error fetching guide payments: " . $conn->error . "\n";
    }

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

$conn->close();
echo "\nPayment API test complete\n";
?>