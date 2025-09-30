<?php
// Check current table structure
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'public_html/api/config.php';

echo "Checking current table structure...\n\n";

// Check tours table structure
echo "TOURS TABLE STRUCTURE:\n";
$result = $conn->query("DESCRIBE tours");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']} - {$row['Default']}\n";
    }
}

echo "\n\nCHECKING PAYMENT COLUMNS:\n";
$columns_to_check = ['payment_status', 'total_amount_paid', 'expected_amount', 'payment_notes'];
foreach ($columns_to_check as $col) {
    $result = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'florence_guides' AND TABLE_NAME = 'tours' AND COLUMN_NAME = '$col'");
    if ($result && $result->num_rows > 0) {
        echo "✓ Column '$col' exists\n";
    } else {
        echo "✗ Column '$col' missing\n";
    }
}

echo "\n\nCHECKING PAYMENT_TRANSACTIONS TABLE:\n";
$result = $conn->query("SHOW TABLES LIKE 'payment_transactions'");
if ($result && $result->num_rows > 0) {
    echo "✓ payment_transactions table exists\n";
    $result = $conn->query("DESCRIBE payment_transactions");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    }
} else {
    echo "✗ payment_transactions table missing\n";
}

$conn->close();
?>