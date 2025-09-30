<?php
/**
 * Check if payment triggers are working correctly
 */

$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'public_html/api/config.php';

echo "Checking Payment System Triggers\n";
echo "================================\n\n";

// Check if triggers exist
echo "1. Checking for triggers in database:\n";
$result = $conn->query("SHOW TRIGGERS LIKE 'payment_transactions'");
if ($result) {
    while ($trigger = $result->fetch_assoc()) {
        echo "✓ Found trigger: " . $trigger['Trigger'] . " - " . $trigger['Event'] . "\n";
    }
} else {
    echo "✗ No triggers found or error: " . $conn->error . "\n";
}
echo "\n";

// Check current payment transactions
echo "2. Current payment transactions:\n";
$result = $conn->query("SELECT pt.*, t.title FROM payment_transactions pt JOIN tours t ON pt.tour_id = t.id ORDER BY pt.created_at DESC LIMIT 5");
if ($result) {
    while ($payment = $result->fetch_assoc()) {
        echo "Payment ID: " . $payment['id'] . " - Tour: " . $payment['title'] . " - Amount: €" . $payment['amount'] . "\n";
    }
} else {
    echo "No payments found\n";
}
echo "\n";

// Check tour payment status
echo "3. Current tour payment status:\n";
$result = $conn->query("SELECT id, title, payment_status, total_amount_paid, paid FROM tours ORDER BY id");
if ($result) {
    while ($tour = $result->fetch_assoc()) {
        echo "Tour ID: " . $tour['id'] . " - " . $tour['title'] . "\n";
        echo "  Payment Status: " . $tour['payment_status'] . "\n";
        echo "  Total Paid: €" . $tour['total_amount_paid'] . "\n";
        echo "  Old Paid Flag: " . ($tour['paid'] ? 'true' : 'false') . "\n\n";
    }
} else {
    echo "No tours found\n";
}

// Manually update tour payment status to test
echo "4. Manually updating tour payment status:\n";
$update_result = $conn->query("
    UPDATE tours t
    SET total_amount_paid = (
        SELECT COALESCE(SUM(amount), 0)
        FROM payment_transactions pt
        WHERE pt.tour_id = t.id
    ),
    payment_status = CASE
        WHEN (SELECT COALESCE(SUM(amount), 0) FROM payment_transactions pt WHERE pt.tour_id = t.id) > 0 THEN 'paid'
        ELSE 'unpaid'
    END,
    paid = CASE
        WHEN (SELECT COALESCE(SUM(amount), 0) FROM payment_transactions pt WHERE pt.tour_id = t.id) > 0 THEN 1
        ELSE 0
    END
");

if ($update_result) {
    echo "✓ Manual update successful\n";
} else {
    echo "✗ Manual update failed: " . $conn->error . "\n";
}

// Check updated status
echo "\n5. Updated tour payment status:\n";
$result = $conn->query("SELECT id, title, payment_status, total_amount_paid, paid FROM tours ORDER BY id");
if ($result) {
    while ($tour = $result->fetch_assoc()) {
        echo "Tour ID: " . $tour['id'] . " - " . $tour['title'] . "\n";
        echo "  Payment Status: " . $tour['payment_status'] . "\n";
        echo "  Total Paid: €" . $tour['total_amount_paid'] . "\n";
        echo "  Old Paid Flag: " . ($tour['paid'] ? 'true' : 'false') . "\n\n";
    }
}

$conn->close();
?>