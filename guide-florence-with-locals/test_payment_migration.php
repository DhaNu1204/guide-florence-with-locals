<?php
/**
 * Test script for Payment System Migration
 * This script tests the database migration and verifies all payment system components
 */

// Disable REQUEST_METHOD check for CLI execution
$_SERVER['REQUEST_METHOD'] = 'GET';

// Include database configuration
require_once 'public_html/api/config.php';

echo "<h1>Payment System Migration Test</h1>\n";
echo "<p>Testing database migration for Florence Tours Payment System</p>\n";

// Test 1: Check if payment_transactions table exists
echo "<h2>Test 1: Check payment_transactions table</h2>\n";
$result = $conn->query("DESCRIBE payment_transactions");
if ($result) {
    echo "<p style='color: green;'>✓ payment_transactions table exists</p>\n";
    echo "<table border='1' style='border-collapse: collapse;'>\n";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>\n";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>\n";
    }
    echo "</table>\n";
} else {
    echo "<p style='color: red;'>✗ payment_transactions table does not exist: " . $conn->error . "</p>\n";
}

// Test 2: Check if tours table has new payment columns
echo "<h2>Test 2: Check tours table enhancements</h2>\n";
$result = $conn->query("DESCRIBE tours");
$payment_columns = [];
if ($result) {
    echo "<p style='color: green;'>✓ tours table accessible</p>\n";
    while ($row = $result->fetch_assoc()) {
        if (in_array($row['Field'], ['payment_status', 'total_amount_paid', 'expected_amount', 'payment_notes'])) {
            $payment_columns[] = $row['Field'];
        }
    }

    $expected_columns = ['payment_status', 'total_amount_paid', 'expected_amount', 'payment_notes'];
    foreach ($expected_columns as $col) {
        if (in_array($col, $payment_columns)) {
            echo "<p style='color: green;'>✓ Column '$col' exists</p>\n";
        } else {
            echo "<p style='color: red;'>✗ Column '$col' missing</p>\n";
        }
    }
} else {
    echo "<p style='color: red;'>✗ Cannot access tours table: " . $conn->error . "</p>\n";
}

// Test 3: Check if views exist
echo "<h2>Test 3: Check payment summary views</h2>\n";
$views = ['guide_payment_summary', 'monthly_payment_summary'];
foreach ($views as $view) {
    $result = $conn->query("SELECT COUNT(*) as count FROM information_schema.views WHERE table_schema = 'florence_guides' AND table_name = '$view'");
    if ($result && $result->fetch_assoc()['count'] > 0) {
        echo "<p style='color: green;'>✓ View '$view' exists</p>\n";
    } else {
        echo "<p style='color: red;'>✗ View '$view' missing</p>\n";
    }
}

// Test 4: Check existing data
echo "<h2>Test 4: Check existing data</h2>\n";
$result = $conn->query("SELECT COUNT(*) as count FROM tours");
if ($result) {
    $tour_count = $result->fetch_assoc()['count'];
    echo "<p>Total tours in database: <strong>$tour_count</strong></p>\n";
}

$result = $conn->query("SELECT COUNT(*) as count FROM guides");
if ($result) {
    $guide_count = $result->fetch_assoc()['count'];
    echo "<p>Total guides in database: <strong>$guide_count</strong></p>\n";
}

$result = $conn->query("SELECT COUNT(*) as count FROM payment_transactions");
if ($result) {
    $payment_count = $result->fetch_assoc()['count'];
    echo "<p>Total payment transactions: <strong>$payment_count</strong></p>\n";
} else {
    echo "<p style='color: orange;'>Payment transactions table not yet populated</p>\n";
}

// Test 5: Test guide payment summary view
echo "<h2>Test 5: Test guide payment summary view</h2>\n";
$result = $conn->query("SELECT * FROM guide_payment_summary LIMIT 5");
if ($result) {
    echo "<p style='color: green;'>✓ guide_payment_summary view working</p>\n";
    echo "<table border='1' style='border-collapse: collapse;'>\n";
    echo "<tr><th>Guide Name</th><th>Total Tours</th><th>Paid Tours</th><th>Unpaid Tours</th><th>Total Payments</th></tr>\n";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['guide_name']}</td><td>{$row['total_tours']}</td><td>{$row['paid_tours']}</td><td>{$row['unpaid_tours']}</td><td>€{$row['total_payments_received']}</td></tr>\n";
    }
    echo "</table>\n";
} else {
    echo "<p style='color: red;'>✗ Error accessing guide_payment_summary: " . $conn->error . "</p>\n";
}

// Test 6: Sample payment transaction
echo "<h2>Test 6: Test payment transaction insertion</h2>\n";
$result = $conn->query("SELECT id, title, guide_id FROM tours WHERE payment_status = 'unpaid' LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $tour_id = $row['id'];
    $guide_id = $row['guide_id'];
    $title = $row['title'];

    // Insert test payment
    $stmt = $conn->prepare("INSERT INTO payment_transactions (tour_id, guide_id, amount, payment_method, payment_date, notes) VALUES (?, ?, ?, 'cash', CURDATE(), 'Test payment from migration script')");
    $test_amount = 100.00;
    $stmt->bind_param("iid", $tour_id, $guide_id, $test_amount);

    if ($stmt->execute()) {
        echo "<p style='color: green;'>✓ Test payment transaction inserted successfully</p>\n";
        echo "<p>Tour: $title (ID: $tour_id)</p>\n";
        echo "<p>Amount: €$test_amount</p>\n";

        // Check if tour status was updated by trigger
        $result = $conn->query("SELECT payment_status, total_amount_paid FROM tours WHERE id = $tour_id");
        if ($result && $updated = $result->fetch_assoc()) {
            echo "<p>Updated payment status: <strong>{$updated['payment_status']}</strong></p>\n";
            echo "<p>Total amount paid: <strong>€{$updated['total_amount_paid']}</strong></p>\n";
        }

        // Clean up test data
        $conn->query("DELETE FROM payment_transactions WHERE tour_id = $tour_id AND notes = 'Test payment from migration script'");
        echo "<p style='color: blue;'>Test payment transaction cleaned up</p>\n";
    } else {
        echo "<p style='color: red;'>✗ Error inserting test payment: " . $stmt->error . "</p>\n";
    }
} else {
    echo "<p style='color: orange;'>No unpaid tours found for testing</p>\n";
}

echo "<h2>Migration Test Summary</h2>\n";
echo "<p>If all tests show green checkmarks, the payment system migration was successful!</p>\n";
echo "<p>Next steps: Implement API endpoints and frontend interface</p>\n";

$conn->close();
?>