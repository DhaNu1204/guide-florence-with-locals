<?php
// Add missing payment_status column
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'public_html/api/config.php';

echo "Adding missing payment_status column...\n";

// Add payment_status column
$sql = "ALTER TABLE tours ADD COLUMN payment_status ENUM('unpaid', 'partial', 'paid', 'overpaid') NOT NULL DEFAULT 'unpaid'";
if ($conn->query($sql)) {
    echo "✓ payment_status column added successfully\n";
} else {
    echo "✗ Error adding payment_status column: " . $conn->error . "\n";
}

// Add index for payment_status
$sql = "CREATE INDEX idx_tours_payment_status ON tours(payment_status)";
if ($conn->query($sql)) {
    echo "✓ Index for payment_status created successfully\n";
} else {
    echo "✗ Error creating index (might already exist): " . $conn->error . "\n";
}

// Update payment_status based on existing paid field
$sql = "UPDATE tours SET payment_status = CASE WHEN paid = 1 THEN 'paid' ELSE 'unpaid' END";
if ($conn->query($sql)) {
    echo "✓ Updated existing tours payment_status based on paid field\n";

    // Show update results
    $result = $conn->query("SELECT payment_status, COUNT(*) as count FROM tours GROUP BY payment_status");
    if ($result) {
        echo "Payment status distribution:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  {$row['payment_status']}: {$row['count']} tours\n";
        }
    }
} else {
    echo "✗ Error updating payment_status: " . $conn->error . "\n";
}

// Now create the views
echo "\nCreating payment summary views...\n";

$sql = "DROP VIEW IF EXISTS guide_payment_summary";
if ($conn->query($sql)) {
    echo "✓ Dropped existing guide_payment_summary view\n";
}

$sql = "CREATE VIEW guide_payment_summary AS
SELECT
    g.id as guide_id,
    g.name as guide_name,
    g.email as guide_email,
    COUNT(DISTINCT t.id) as total_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_tours,
    COALESCE(SUM(pt.amount), 0) as total_payments_received,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END), 0) as cash_payments,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END), 0) as bank_payments,
    COUNT(DISTINCT pt.id) as total_payment_transactions,
    MIN(pt.payment_date) as first_payment_date,
    MAX(pt.payment_date) as last_payment_date
FROM guides g
LEFT JOIN tours t ON g.id = t.guide_id
LEFT JOIN payment_transactions pt ON t.id = pt.tour_id
GROUP BY g.id, g.name, g.email";

if ($conn->query($sql)) {
    echo "✓ guide_payment_summary view created successfully\n";
} else {
    echo "✗ Error creating guide_payment_summary view: " . $conn->error . "\n";
}

echo "\nPayment system setup completed!\n";

$conn->close();
?>