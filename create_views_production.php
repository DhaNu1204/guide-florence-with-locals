<?php
/**
 * Create Payment Views for Production Database
 * Date: October 26, 2025
 * Purpose: Create views using 'payments' table (not 'payment_transactions')
 */

// Production database credentials
$host = 'localhost';
$dbname = 'u803853690_withlocals';
$username = 'u803853690_withlocals';
$password = 'YY!C~W2frt*5';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected to production database successfully!\n\n";

// Drop existing views
echo "Step 1: Dropping existing views (if any)...\n";
$conn->query("DROP VIEW IF EXISTS guide_payment_summary");
$conn->query("DROP VIEW IF EXISTS monthly_payment_summary");
echo "✓ Existing views dropped\n\n";

// Create guide_payment_summary view using 'payments' table
echo "Step 2: Creating guide_payment_summary view...\n";
$sql1 = "CREATE VIEW guide_payment_summary AS
SELECT
    g.id as guide_id,
    g.name as guide_name,
    g.email as guide_email,
    COUNT(DISTINCT t.id) as total_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_tours,
    COALESCE(SUM(p.amount), 0) as total_payments_received,
    COALESCE(SUM(CASE WHEN p.payment_method = 'cash' OR p.payment_method = 'Cash' THEN p.amount ELSE 0 END), 0) as cash_payments,
    COALESCE(SUM(CASE WHEN p.payment_method = 'bank_transfer' OR p.payment_method = 'Bank Transfer' THEN p.amount ELSE 0 END), 0) as bank_payments,
    COUNT(DISTINCT p.id) as total_payment_transactions,
    MIN(p.payment_date) as first_payment_date,
    MAX(p.payment_date) as last_payment_date
FROM guides g
LEFT JOIN tours t ON g.id = t.guide_id
LEFT JOIN payments p ON t.id = p.tour_id
GROUP BY g.id, g.name, g.email";

if ($conn->query($sql1)) {
    echo "✓ guide_payment_summary view created successfully\n";
} else {
    die("✗ Error creating guide_payment_summary: " . $conn->error . "\n");
}

// Create monthly_payment_summary view using 'payments' table
echo "\nStep 3: Creating monthly_payment_summary view...\n";
$sql2 = "CREATE VIEW monthly_payment_summary AS
SELECT
    YEAR(p.payment_date) as payment_year,
    MONTH(p.payment_date) as payment_month,
    MONTHNAME(p.payment_date) as month_name,
    g.id as guide_id,
    g.name as guide_name,
    COUNT(DISTINCT p.tour_id) as tours_paid,
    COUNT(p.id) as payment_transactions,
    SUM(p.amount) as total_amount,
    SUM(CASE WHEN p.payment_method = 'cash' OR p.payment_method = 'Cash' THEN p.amount ELSE 0 END) as cash_amount,
    SUM(CASE WHEN p.payment_method = 'bank_transfer' OR p.payment_method = 'Bank Transfer' THEN p.amount ELSE 0 END) as bank_amount,
    p.payment_method,
    AVG(p.amount) as avg_payment_amount
FROM payments p
JOIN guides g ON p.guide_id = g.id
GROUP BY YEAR(p.payment_date), MONTH(p.payment_date), g.id, p.payment_method
ORDER BY payment_year DESC, payment_month DESC, g.name";

if ($conn->query($sql2)) {
    echo "✓ monthly_payment_summary view created successfully\n";
} else {
    die("✗ Error creating monthly_payment_summary: " . $conn->error . "\n");
}

// Verify views
echo "\n===========================================\n";
echo "Verification:\n";
echo "===========================================\n";

$result = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
if ($result) {
    echo "Views in database:\n";
    $count = 0;
    while ($row = $result->fetch_array()) {
        echo "  ✓ " . $row[0] . "\n";
        $count++;
    }
    echo "\nTotal views: $count\n";
} else {
    echo "Error checking views: " . $conn->error . "\n";
}

// Test the guide_payment_summary view
echo "\n===========================================\n";
echo "Testing guide_payment_summary view:\n";
echo "===========================================\n";

$result = $conn->query("SELECT COUNT(*) as count FROM guide_payment_summary");
if ($result) {
    $row = $result->fetch_assoc();
    echo "✓ View works! Found " . $row['count'] . " guides\n";

    // Show sample data
    $result = $conn->query("SELECT guide_name, total_tours, total_payments_received, cash_payments, bank_payments FROM guide_payment_summary LIMIT 3");
    if ($result) {
        echo "\nSample guide payment data:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  - " . $row['guide_name'] .
                 " | Tours: " . $row['total_tours'] .
                 " | Total Payments: €" . number_format($row['total_payments_received'], 2) .
                 " (Cash: €" . number_format($row['cash_payments'], 2) .
                 ", Bank: €" . number_format($row['bank_payments'], 2) . ")\n";
        }
    }
} else {
    echo "✗ Error testing view: " . $conn->error . "\n";
}

$conn->close();

echo "\n===========================================\n";
echo "✅ DEPLOYMENT SUCCESSFUL!\n";
echo "===========================================\n";
echo "Payment views created using 'payments' table.\n";
echo "Test the payments page at:\n";
echo "https://withlocals.deetech.cc/payments\n";
?>
