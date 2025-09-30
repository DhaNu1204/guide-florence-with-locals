<?php
// Debug script to check production database and tour data
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the production config
require_once 'public_html/api/config.php';

echo "=== PRODUCTION DATABASE DEBUG ===\n";

// Test database connection
if ($conn->connect_error) {
    echo "Database connection failed: " . $conn->connect_error . "\n";
    exit(1);
}

echo "✅ Database connection successful\n\n";

// Check tours table structure
echo "=== TOURS TABLE STRUCTURE ===\n";
$result = $conn->query("DESCRIBE tours");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error describing tours table: " . $conn->error . "\n";
}

echo "\n=== TOURS COUNT ===\n";
$result = $conn->query("SELECT COUNT(*) as total FROM tours");
if ($result) {
    $count = $result->fetch_assoc();
    echo "Total tours: " . $count['total'] . "\n";
} else {
    echo "Error counting tours: " . $conn->error . "\n";
}

echo "\n=== BOKUN TOURS COUNT ===\n";
$result = $conn->query("SELECT COUNT(*) as total FROM tours WHERE external_source = 'bokun'");
if ($result) {
    $count = $result->fetch_assoc();
    echo "Bokun tours: " . $count['total'] . "\n";
} else {
    echo "Error counting Bokun tours: " . $conn->error . "\n";
}

echo "\n=== UPCOMING TOURS (future dates) ===\n";
$result = $conn->query("SELECT COUNT(*) as total FROM tours WHERE date >= CURDATE() AND cancelled = 0");
if ($result) {
    $count = $result->fetch_assoc();
    echo "Upcoming tours: " . $count['total'] . "\n";
} else {
    echo "Error counting upcoming tours: " . $conn->error . "\n";
}

echo "\n=== UNASSIGNED TOURS (future dates, no guide) ===\n";
$result = $conn->query("SELECT COUNT(*) as total FROM tours WHERE date >= CURDATE() AND cancelled = 0 AND (guide_id IS NULL OR guide_id = '' OR guide_id = 'null')");
if ($result) {
    $count = $result->fetch_assoc();
    echo "Unassigned tours: " . $count['total'] . "\n";
} else {
    echo "Error counting unassigned tours: " . $conn->error . "\n";
}

echo "\n=== SAMPLE UPCOMING TOURS ===\n";
$result = $conn->query("SELECT id, title, date, time, guide_id, guide_name, external_source FROM tours WHERE date >= CURDATE() AND cancelled = 0 ORDER BY date LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . ", Title: " . substr($row['title'], 0, 50) . ", Date: " . $row['date'] . ", Time: " . $row['time'] . ", Guide: " . ($row['guide_name'] ?: 'Unassigned') . ", Source: " . $row['external_source'] . "\n";
    }
} else {
    echo "Error fetching sample tours: " . $conn->error . "\n";
}

$conn->close();
echo "\n=== DEBUG COMPLETE ===\n";
?>