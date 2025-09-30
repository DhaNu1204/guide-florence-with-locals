<?php
/**
 * Script to execute the payment system migration
 */

// Disable REQUEST_METHOD check for CLI execution
$_SERVER['REQUEST_METHOD'] = 'GET';

// Include database configuration
require_once 'public_html/api/config.php';

echo "Starting Payment System Migration...\n";

// Read the migration SQL file
$migrationFile = 'payment_system_migration_simple.sql';
if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);

// Split SQL into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$successCount = 0;
$errorCount = 0;

foreach ($statements as $statement) {
    // Skip empty statements and comments
    if (empty($statement) || substr(trim($statement), 0, 2) === '--' || substr(trim($statement), 0, 2) === '/*') {
        continue;
    }

    // Skip DELIMITER statements (they're MySQL client specific)
    if (stripos(trim($statement), 'DELIMITER') === 0) {
        continue;
    }

    // Execute the statement
    if ($conn->query($statement)) {
        $successCount++;
        echo "✓ Executed statement successfully\n";
    } else {
        $errorCount++;
        echo "✗ Error executing statement: " . $conn->error . "\n";
        echo "Statement: " . substr($statement, 0, 100) . "...\n";
    }
}

echo "\nMigration Summary:\n";
echo "Successful statements: $successCount\n";
echo "Failed statements: $errorCount\n";

if ($errorCount === 0) {
    echo "\n✓ Migration completed successfully!\n";
} else {
    echo "\n⚠ Migration completed with some errors. Please review the errors above.\n";
}

$conn->close();
?>