<?php
// Add missing columns to tours table for Bokun integration
require_once 'config.php';

echo "Adding missing columns to tours table for Bokun integration...\n";

// Test connection
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "Database connection successful\n";

// Add missing columns one by one
$alterQueries = [
    "ALTER TABLE tours ADD COLUMN external_id VARCHAR(100) NULL AFTER id",
    "ALTER TABLE tours ADD COLUMN bokun_booking_id VARCHAR(100) NULL AFTER external_id",
    "ALTER TABLE tours ADD COLUMN bokun_confirmation_code VARCHAR(100) NULL AFTER bokun_booking_id",
    "ALTER TABLE tours ADD COLUMN total_amount_paid DECIMAL(10,2) NULL AFTER price",
    "ALTER TABLE tours ADD COLUMN expected_amount DECIMAL(10,2) NULL AFTER total_amount_paid",
    "ALTER TABLE tours ADD COLUMN external_source VARCHAR(50) NULL DEFAULT 'manual' AFTER booking_channel",
    "ALTER TABLE tours ADD COLUMN needs_guide_assignment TINYINT(1) NULL DEFAULT 0 AFTER external_source",
    "ALTER TABLE tours ADD COLUMN bokun_data TEXT NULL AFTER notes",
    "ALTER TABLE tours ADD COLUMN last_synced TIMESTAMP NULL AFTER bokun_data"
];

foreach ($alterQueries as $query) {
    echo "Executing: $query\n";
    if ($conn->query($query)) {
        echo "✓ Success\n";
    } else {
        echo "✗ Error: " . $conn->error . "\n";
        // Continue with other queries even if one fails (column might already exist)
    }
}

echo "\nChecking final table structure:\n";
$structure = $conn->query("DESCRIBE tours");
while ($row = $structure->fetch_assoc()) {
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

$conn->close();
echo "\nDone!\n";
?>