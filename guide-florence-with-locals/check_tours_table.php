<?php
// Check tours table structure
require_once 'config.php';

echo "Checking tours table structure...\n";

// Test connection
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "Database connection successful\n";

// Check if tours table exists
$result = $conn->query("SHOW TABLES LIKE 'tours'");
if ($result->num_rows == 0) {
    echo "Error: tours table does not exist!\n";
    exit(1);
}

echo "tours table exists\n";

// Show table structure
echo "Table structure:\n";
$structure = $conn->query("DESCRIBE tours");
while ($row = $structure->fetch_assoc()) {
    echo "- " . $row['Field'] . " (" . $row['Type'] . ") " .
         ($row['Null'] == 'YES' ? 'NULL' : 'NOT NULL') .
         ($row['Default'] !== null ? ' DEFAULT ' . $row['Default'] : '') . "\n";
}

// Count existing tours
$count = $conn->query("SELECT COUNT(*) as total FROM tours");
$total = $count->fetch_assoc()['total'];
echo "\nExisting tours count: " . $total . "\n";

// Show sample data if any exists
if ($total > 0) {
    echo "\nSample tour data:\n";
    $sample = $conn->query("SELECT * FROM tours LIMIT 2");
    while ($row = $sample->fetch_assoc()) {
        foreach ($row as $key => $value) {
            echo "- " . $key . ": " . $value . "\n";
        }
        echo "---\n";
    }
}

$conn->close();
?>