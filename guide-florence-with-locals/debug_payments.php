<?php
// Debug script to test payment API endpoints
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'api/config.php';

echo "=== PAYMENT API DEBUG ===\n";

// Test database connection
if ($conn->connect_error) {
    echo "❌ Database connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "✅ Database connection successful\n\n";

// Check if payments table exists
echo "=== CHECKING PAYMENTS TABLE ===\n";
$result = $conn->query("SHOW TABLES LIKE 'payments'");
if ($result->num_rows > 0) {
    echo "✅ Payments table exists\n";

    // Check table structure
    echo "\nPayments table structure:\n";
    $structure = $conn->query("DESCRIBE payments");
    while ($row = $structure->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }

    // Count payments
    $count = $conn->query("SELECT COUNT(*) as total FROM payments");
    $total = $count->fetch_assoc()['total'];
    echo "\nTotal payments: $total\n";

} else {
    echo "❌ Payments table does not exist\n";
}

// Check if guide_payments table exists
echo "\n=== CHECKING GUIDE_PAYMENTS TABLE ===\n";
$result = $conn->query("SHOW TABLES LIKE 'guide_payments'");
if ($result->num_rows > 0) {
    echo "✅ Guide_payments table exists\n";

    $count = $conn->query("SELECT COUNT(*) as total FROM guide_payments");
    $total = $count->fetch_assoc()['total'];
    echo "Total guide payments: $total\n";

} else {
    echo "❌ Guide_payments table does not exist\n";
}

// Test payments.php endpoint
echo "\n=== TESTING PAYMENTS.PHP ENDPOINT ===\n";
echo "Testing GET request to payments.php...\n";

// Simulate GET request
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

ob_start();
try {
    include 'api/payments.php';
    $output = ob_get_contents();
    echo "✅ Payments.php executed successfully\n";
    echo "Output: " . substr($output, 0, 200) . (strlen($output) > 200 ? '...' : '') . "\n";
} catch (Exception $e) {
    echo "❌ Error in payments.php: " . $e->getMessage() . "\n";
}
ob_end_clean();

// Test guide-payments.php endpoint
echo "\n=== TESTING GUIDE-PAYMENTS.PHP ENDPOINT ===\n";
echo "Testing GET request to guide-payments.php...\n";

ob_start();
try {
    include 'api/guide-payments.php';
    $output = ob_get_contents();
    echo "✅ Guide-payments.php executed successfully\n";
    echo "Output: " . substr($output, 0, 200) . (strlen($output) > 200 ? '...' : '') . "\n";
} catch (Exception $e) {
    echo "❌ Error in guide-payments.php: " . $e->getMessage() . "\n";
}
ob_end_clean();

// Test payment-reports.php endpoint
echo "\n=== TESTING PAYMENT-REPORTS.PHP ENDPOINT ===\n";
echo "Testing GET request to payment-reports.php...\n";

ob_start();
try {
    include 'api/payment-reports.php';
    $output = ob_get_contents();
    echo "✅ Payment-reports.php executed successfully\n";
    echo "Output: " . substr($output, 0, 200) . (strlen($output) > 200 ? '...' : '') . "\n";
} catch (Exception $e) {
    echo "❌ Error in payment-reports.php: " . $e->getMessage() . "\n";
}
ob_end_clean();

$conn->close();
echo "\n=== PAYMENT DEBUG COMPLETE ===\n";
?>