<?php
// Sync future bookings from Bokun for the next 3 months
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'api/config.php';

echo "=== BOKUN FUTURE BOOKINGS SYNC ===\n";
echo "Current date: " . date('Y-m-d') . "\n";

// Check current tour dates
echo "\nCurrent tour dates in database:\n";
$result = $conn->query("SELECT date, COUNT(*) as count FROM tours GROUP BY date ORDER BY date");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['date'] . ": " . $row['count'] . " tours\n";
    }
}

// Now sync future bookings - start from today and go 3 months ahead
$startDate = date('Y-m-d'); // Today
$endDate = date('Y-m-d', strtotime('+3 months')); // 3 months from now

echo "\n=== SYNCING BOKUN BOOKINGS ===\n";
echo "Date range: $startDate to $endDate\n";

// Call the Bokun sync API endpoint
$apiUrl = "https://withlocals.deetech.cc/api/bokun_sync.php?action=sync&start_date=$startDate&end_date=$endDate";
echo "API URL: $apiUrl\n";

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 30,
        'header' => "User-Agent: BokuSync/1.0\r\n"
    ]
]);

echo "\nCalling Bokun sync API...\n";
$response = @file_get_contents($apiUrl, false, $context);

if ($response === false) {
    echo "❌ Failed to call Bokun sync API\n";
    echo "Error: " . error_get_last()['message'] . "\n";
} else {
    echo "✅ Bokun sync API response:\n";
    echo $response . "\n";
}

// Check updated tour counts
echo "\n=== POST-SYNC STATUS ===\n";
$result = $conn->query("SELECT COUNT(*) as total FROM tours");
$total = $result->fetch_assoc()['total'];
echo "Total tours after sync: $total\n";

$result = $conn->query("SELECT COUNT(*) as total FROM tours WHERE date >= CURDATE()");
$upcoming = $result->fetch_assoc()['total'];
echo "Upcoming tours (future dates): $upcoming\n";

$result = $conn->query("SELECT COUNT(*) as total FROM tours WHERE date >= CURDATE() AND (guide_id IS NULL OR guide_id = '' OR guide_id = 'null')");
$unassigned = $result->fetch_assoc()['total'];
echo "Unassigned tours (future dates): $unassigned\n";

echo "\nUpdated tour dates:\n";
$result = $conn->query("SELECT date, COUNT(*) as count FROM tours GROUP BY date ORDER BY date");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $status = ($row['date'] >= date('Y-m-d')) ? '[FUTURE]' : '[PAST]';
        echo "- " . $row['date'] . ": " . $row['count'] . " tours $status\n";
    }
}

$conn->close();
echo "\n=== SYNC COMPLETE ===\n";
?>