<?php
// Test the fixed sync functionality

require_once 'public_html/api/config.php';

// Test a small sync
$result = $conn->query("
    SELECT COUNT(*) as before_count
    FROM tours
    WHERE external_source = 'bokun'
");
$beforeCount = $result->fetch_assoc()['before_count'];

echo "Tours from Bokun before sync: $beforeCount\n";

// Call the sync API
$syncUrl = 'http://localhost:8080/api/bokun_sync.php?action=sync&start_date=2025-09-28&end_date=2025-09-29';

$ch = curl_init($syncUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);

echo "\nSync Response:\n";
echo $response . "\n\n";

// Check after count
$result = $conn->query("
    SELECT COUNT(*) as after_count
    FROM tours
    WHERE external_source = 'bokun'
");
$afterCount = $result->fetch_assoc()['after_count'];

echo "Tours from Bokun after sync: $afterCount\n";
echo "New tours synced: " . ($afterCount - $beforeCount) . "\n";

// Show latest synced tour
$result = $conn->query("
    SELECT id, title, external_id, customer_name, date, time, booking_channel
    FROM tours
    WHERE external_source = 'bokun'
    ORDER BY last_synced DESC
    LIMIT 1
");

if ($row = $result->fetch_assoc()) {
    echo "\nLatest synced tour:\n";
    echo "- ID: {$row['id']}\n";
    echo "- Title: {$row['title']}\n";
    echo "- External ID: {$row['external_id']}\n";
    echo "- Customer: {$row['customer_name']}\n";
    echo "- Date: {$row['date']} {$row['time']}\n";
    echo "- Channel: {$row['booking_channel']}\n";
}
?>