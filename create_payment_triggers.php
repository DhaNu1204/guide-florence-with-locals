<?php
/**
 * Create payment triggers manually
 */

$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'public_html/api/config.php';

echo "Creating Payment System Triggers\n";
echo "================================\n\n";

// Drop existing triggers if they exist
$drop_triggers = [
    "DROP TRIGGER IF EXISTS update_tour_payment_status_after_payment_insert",
    "DROP TRIGGER IF EXISTS update_tour_payment_status_after_payment_update",
    "DROP TRIGGER IF EXISTS update_tour_payment_status_after_payment_delete"
];

foreach ($drop_triggers as $sql) {
    if ($conn->query($sql)) {
        echo "✓ Dropped existing trigger\n";
    }
}

// Create insert trigger
$insert_trigger = "
CREATE TRIGGER update_tour_payment_status_after_payment_insert
AFTER INSERT ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = NEW.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = NEW.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END
    WHERE id = NEW.tour_id;
END";

if ($conn->query($insert_trigger)) {
    echo "✓ Created INSERT trigger\n";
} else {
    echo "✗ Failed to create INSERT trigger: " . $conn->error . "\n";
}

// Create update trigger
$update_trigger = "
CREATE TRIGGER update_tour_payment_status_after_payment_update
AFTER UPDATE ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = NEW.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = NEW.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END
    WHERE id = NEW.tour_id;
END";

if ($conn->query($update_trigger)) {
    echo "✓ Created UPDATE trigger\n";
} else {
    echo "✗ Failed to create UPDATE trigger: " . $conn->error . "\n";
}

// Create delete trigger
$delete_trigger = "
CREATE TRIGGER update_tour_payment_status_after_payment_delete
AFTER DELETE ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = OLD.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = OLD.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END
    WHERE id = OLD.tour_id;
END";

if ($conn->query($delete_trigger)) {
    echo "✓ Created DELETE trigger\n";
} else {
    echo "✗ Failed to create DELETE trigger: " . $conn->error . "\n";
}

echo "\nTriggers creation complete!\n";

// Test the triggers by creating a test payment
echo "\nTesting triggers with a new payment...\n";
$test_payment = $conn->prepare("INSERT INTO payment_transactions (tour_id, guide_id, amount, payment_method, payment_date, notes) VALUES (2, 2, 200.00, 'bank_transfer', CURDATE(), 'Test trigger functionality')");

if ($test_payment->execute()) {
    echo "✓ Test payment created\n";

    // Check if tour 2 status was updated
    $result = $conn->query("SELECT id, title, payment_status, total_amount_paid FROM tours WHERE id = 2");
    if ($row = $result->fetch_assoc()) {
        echo "Tour 2 status after payment:\n";
        echo "  Payment Status: " . $row['payment_status'] . "\n";
        echo "  Total Paid: €" . $row['total_amount_paid'] . "\n";
    }

    // Clean up test payment
    $conn->query("DELETE FROM payment_transactions WHERE notes = 'Test trigger functionality'");
    echo "✓ Test payment cleaned up\n";
} else {
    echo "✗ Failed to create test payment\n";
}

$conn->close();
?>