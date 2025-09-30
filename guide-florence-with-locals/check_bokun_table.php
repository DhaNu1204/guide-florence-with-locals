<?php
// Check if bokun_config table exists and create if needed
require_once 'config.php';

echo "Testing database connection and bokun_config table...\n";

// Test connection
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "Database connection successful\n";

// Check if bokun_config table exists
$result = $conn->query("SHOW TABLES LIKE 'bokun_config'");
if ($result->num_rows == 0) {
    echo "Error: bokun_config table does not exist!\n";

    // Show all tables
    echo "Available tables:\n";
    $tables = $conn->query("SHOW TABLES");
    while ($row = $tables->fetch_array()) {
        echo "- " . $row[0] . "\n";
    }

    // Create the table
    echo "Creating bokun_config table...\n";
    $createTable = "
        CREATE TABLE bokun_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            access_key VARCHAR(255) NOT NULL,
            secret_key VARCHAR(255) NOT NULL,
            vendor_id VARCHAR(50) NOT NULL,
            sync_enabled TINYINT(1) DEFAULT 1,
            auto_assign_guides TINYINT(1) DEFAULT 0,
            last_sync_date DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";

    if ($conn->query($createTable)) {
        echo "bokun_config table created successfully!\n";
    } else {
        echo "Error creating table: " . $conn->error . "\n";
    }
} else {
    echo "bokun_config table exists\n";

    // Show table structure
    echo "Table structure:\n";
    $structure = $conn->query("DESCRIBE bokun_config");
    while ($row = $structure->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }

    // Show existing data
    echo "Existing data:\n";
    $data = $conn->query("SELECT * FROM bokun_config");
    if ($data->num_rows > 0) {
        while ($row = $data->fetch_assoc()) {
            foreach ($row as $key => $value) {
                if (in_array($key, ['access_key', 'secret_key'])) {
                    echo "- " . $key . ": " . (empty($value) ? "(empty)" : "(hidden - " . strlen($value) . " chars)") . "\n";
                } else {
                    echo "- " . $key . ": " . $value . "\n";
                }
            }
        }
    } else {
        echo "No data in bokun_config table\n";
    }
}

$conn->close();
?>