<?php
// Create monthly payment summary view
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'public_html/api/config.php';

echo "Creating monthly_payment_summary view...\n";

$sql = "CREATE VIEW monthly_payment_summary AS
SELECT
    YEAR(pt.payment_date) as payment_year,
    MONTH(pt.payment_date) as payment_month,
    MONTHNAME(pt.payment_date) as month_name,
    g.id as guide_id,
    g.name as guide_name,
    COUNT(DISTINCT pt.tour_id) as tours_paid,
    COUNT(pt.id) as payment_transactions,
    SUM(pt.amount) as total_amount,
    SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END) as cash_amount,
    SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END) as bank_amount,
    AVG(pt.amount) as avg_payment_amount
FROM payment_transactions pt
JOIN guides g ON pt.guide_id = g.id
GROUP BY YEAR(pt.payment_date), MONTH(pt.payment_date), g.id
ORDER BY payment_year DESC, payment_month DESC, g.name";

if ($conn->query($sql)) {
    echo "✓ monthly_payment_summary view created successfully\n";
} else {
    echo "✗ Error creating monthly_payment_summary view: " . $conn->error . "\n";
}

$conn->close();
?>