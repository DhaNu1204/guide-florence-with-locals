<?php
require_once 'public_html/api/config.php';

echo "Fixing Bokun-related columns in tours table...\n\n";

// Add the missing last_sync column
echo "Adding last_sync column...\n";
$result = $conn->query("SHOW COLUMNS FROM tours LIKE 'last_sync'");
if ($result->num_rows > 0) {
    echo "✓ last_sync column already exists\n";
} else {
    $sql = "ALTER TABLE tours ADD COLUMN last_sync TIMESTAMP NULL AFTER bokun_data";
    if ($conn->query($sql)) {
        echo "✓ Added last_sync column successfully\n";
    } else {
        echo "✗ Error adding last_sync column: " . $conn->error . "\n";
    }
}

// Copy data from last_synced to last_sync if both exist
echo "\nSyncing data between last_synced and last_sync...\n";
$sql = "UPDATE tours SET last_sync = last_synced WHERE last_synced IS NOT NULL AND last_sync IS NULL";
if ($conn->query($sql)) {
    $affected = $conn->affected_rows;
    echo "✓ Synced $affected records from last_synced to last_sync\n";
} else {
    echo "✗ Error syncing data: " . $conn->error . "\n";
}

// Check if bokun_config table exists
echo "\nChecking bokun_config table...\n";
$result = $conn->query("SHOW TABLES LIKE 'bokun_config'");
if ($result->num_rows > 0) {
    echo "✓ bokun_config table exists\n";

    // Check if it has data
    $result = $conn->query("SELECT COUNT(*) as count FROM bokun_config");
    $row = $result->fetch_assoc();
    echo "   Records in bokun_config: " . $row['count'] . "\n";
} else {
    echo "✗ bokun_config table missing - creating it...\n";

    $sql = "CREATE TABLE IF NOT EXISTS bokun_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id VARCHAR(100) NOT NULL,
        api_access_key VARCHAR(255) NOT NULL,
        api_secret_key VARCHAR(255) NOT NULL,
        api_base_url VARCHAR(255) DEFAULT 'https://api.bokun.is',
        webhook_url VARCHAR(255),
        booking_channel VARCHAR(255),
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";

    if ($conn->query($sql)) {
        echo "✓ Created bokun_config table\n";

        // Insert default config for development
        $sql = "INSERT INTO bokun_config (vendor_id, api_access_key, api_secret_key, booking_channel)
                VALUES ('96929', '2c413c402bd9402092b4a3f5157c899e', 'dummy_secret', 'www.florencewithlocals.com')";
        if ($conn->query($sql)) {
            echo "✓ Added default Bokun configuration\n";
        }
    } else {
        echo "✗ Error creating bokun_config table: " . $conn->error . "\n";
    }
}

echo "\nFinal verification:\n";
echo "==================\n";

$bokunColumns = ['last_sync', 'bokun_booking_id', 'bokun_experience_id', 'bokun_confirmation_code', 'bokun_data'];
foreach ($bokunColumns as $column) {
    $result = $conn->query("SHOW COLUMNS FROM tours LIKE '$column'");
    if ($result->num_rows > 0) {
        echo "✓ $column - EXISTS\n";
    } else {
        echo "✗ $column - MISSING\n";
    }
}

$conn->close();
echo "\n✅ Bokun integration columns should now be fixed!\n";
?>