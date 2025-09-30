<?php
// Fix payment system tables and issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'api/config.php';

echo "=== FIXING PAYMENT SYSTEM ===\n";

if ($conn->connect_error) {
    echo "❌ Database connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "✅ Database connection successful\n\n";

// Create guide_payments table if it doesn't exist
echo "=== CREATING GUIDE_PAYMENTS TABLE ===\n";
$createGuidePayments = "
CREATE TABLE IF NOT EXISTS guide_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guide_id INT NOT NULL,
    guide_name VARCHAR(100) NOT NULL,
    total_amount DECIMAL(10,2) DEFAULT 0,
    total_payments INT DEFAULT 0,
    last_payment_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE
)";

if ($conn->query($createGuidePayments)) {
    echo "✅ Guide_payments table created/verified\n";
} else {
    echo "❌ Error creating guide_payments table: " . $conn->error . "\n";
}

// Check current tables
echo "\n=== CURRENT TABLES ===\n";
$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_array()) {
        echo "- " . $row[0] . "\n";
    }
}

// Initialize guide_payments data from existing guides
echo "\n=== INITIALIZING GUIDE PAYMENT DATA ===\n";
$result = $conn->query("SELECT id, name FROM guides");
if ($result) {
    while ($guide = $result->fetch_assoc()) {
        // Check if guide payment record exists
        $checkStmt = $conn->prepare("SELECT id FROM guide_payments WHERE guide_id = ?");
        $checkStmt->bind_param("i", $guide['id']);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$exists) {
            // Create initial guide payment record
            $insertStmt = $conn->prepare("
                INSERT INTO guide_payments (guide_id, guide_name, total_amount, total_payments)
                VALUES (?, ?, 0, 0)
            ");
            $insertStmt->bind_param("is", $guide['id'], $guide['name']);

            if ($insertStmt->execute()) {
                echo "✅ Created payment record for guide: " . $guide['name'] . "\n";
            } else {
                echo "❌ Error creating payment record for guide " . $guide['name'] . ": " . $conn->error . "\n";
            }
            $insertStmt->close();
        } else {
            echo "- Payment record already exists for guide: " . $guide['name'] . "\n";
        }
    }
}

// Create some sample payment data for testing
echo "\n=== CREATING SAMPLE PAYMENT DATA ===\n";
$samplePayments = [
    [
        'tour_id' => 1,
        'guide_id' => 1,
        'guide_name' => 'Marco Rossi',
        'amount' => 150.00,
        'payment_method' => 'Cash',
        'payment_date' => '2025-09-25',
        'payment_time' => '14:30:00',
        'description' => 'Payment for Uffizi Gallery tour',
        'status' => 'completed'
    ],
    [
        'tour_id' => 2,
        'guide_id' => 2,
        'guide_name' => 'Sofia Bianchi',
        'amount' => 120.00,
        'payment_method' => 'Bank Transfer',
        'payment_date' => '2025-09-26',
        'payment_time' => '16:00:00',
        'description' => 'Payment for Accademia Gallery tour',
        'status' => 'completed'
    ]
];

foreach ($samplePayments as $payment) {
    // Check if payment already exists
    $checkPayment = $conn->prepare("SELECT id FROM payments WHERE tour_id = ? AND guide_id = ?");
    $checkPayment->bind_param("ii", $payment['tour_id'], $payment['guide_id']);
    $checkPayment->execute();
    $paymentExists = $checkPayment->get_result()->fetch_assoc();
    $checkPayment->close();

    if (!$paymentExists) {
        $insertPayment = $conn->prepare("
            INSERT INTO payments (tour_id, guide_id, guide_name, amount, payment_method, payment_date, payment_time, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertPayment->bind_param("iisdsssss",
            $payment['tour_id'], $payment['guide_id'], $payment['guide_name'], $payment['amount'],
            $payment['payment_method'], $payment['payment_date'], $payment['payment_time'],
            $payment['description'], $payment['status']
        );

        if ($insertPayment->execute()) {
            echo "✅ Created sample payment for " . $payment['guide_name'] . " - €" . $payment['amount'] . "\n";
        } else {
            echo "❌ Error creating sample payment: " . $conn->error . "\n";
        }
        $insertPayment->close();
    } else {
        echo "- Payment already exists for tour " . $payment['tour_id'] . "\n";
    }
}

// Update guide_payments summary
echo "\n=== UPDATING GUIDE PAYMENT SUMMARIES ===\n";
$updateSummary = "
    UPDATE guide_payments gp
    JOIN (
        SELECT
            guide_id,
            SUM(amount) as total_amount,
            COUNT(*) as total_payments,
            MAX(payment_date) as last_payment_date
        FROM payments
        WHERE status = 'completed'
        GROUP BY guide_id
    ) p ON gp.guide_id = p.guide_id
    SET
        gp.total_amount = p.total_amount,
        gp.total_payments = p.total_payments,
        gp.last_payment_date = p.last_payment_date
";

if ($conn->query($updateSummary)) {
    echo "✅ Guide payment summaries updated\n";
} else {
    echo "❌ Error updating summaries: " . $conn->error . "\n";
}

// Final verification
echo "\n=== FINAL VERIFICATION ===\n";
$paymentCount = $conn->query("SELECT COUNT(*) as total FROM payments")->fetch_assoc()['total'];
$guidePaymentCount = $conn->query("SELECT COUNT(*) as total FROM guide_payments")->fetch_assoc()['total'];

echo "Total payments: $paymentCount\n";
echo "Guide payment records: $guidePaymentCount\n";

if ($paymentCount > 0 && $guidePaymentCount > 0) {
    echo "✅ Payment system is now ready!\n";
} else {
    echo "⚠️  Payment system may need additional setup\n";
}

$conn->close();
echo "\n=== PAYMENT SYSTEM FIX COMPLETE ===\n";
?>