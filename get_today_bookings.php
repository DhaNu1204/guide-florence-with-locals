<?php
// Get today's bookings
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try local database first
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'RL94_#BbiLhuy789xF';
$db_name = 'florence_guides';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo "Local database connection failed, trying production...\n";
    // Try production database
    $db_host = 'localhost';
    $db_user = 'u803853690_withlocals';
    $db_pass = 'YY!C~W2frt*5';
    $db_name = 'u803853690_withlocals';

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        die("Production database connection also failed: " . $conn->connect_error . "\n");
    }
    echo "Connected to production database\n\n";
} else {
    echo "Connected to local database\n\n";
}

$conn->set_charset("utf8");

// Get today's date (2025-10-24)
$today = '2025-10-24';

// Query for today's bookings
$sql = "SELECT
    t.*,
    g.name as guide_name,
    g.email as guide_email,
    g.languages as guide_languages
FROM tours t
LEFT JOIN guides g ON t.guide_id = g.id
WHERE DATE(t.date) = ?
ORDER BY t.time ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

echo "=== BOOKINGS FOR TODAY: " . $today . " ===\n\n";

if ($result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $count++;
        echo "BOOKING #{$count}\n";
        echo str_repeat("=", 60) . "\n";
        echo "Tour ID: " . $row['id'] . "\n";
        echo "Customer Name: " . $row['customer_name'] . "\n";
        echo "Date: " . $row['date'] . "\n";
        echo "Time: " . $row['time'] . "\n";
        echo "Number of People: " . $row['num_people'] . "\n";
        echo "Tour Type: " . ($row['tour_type'] ?? 'N/A') . "\n";
        echo "Status: " . ($row['status'] ?? 'confirmed') . "\n";
        echo "\nGuide Information:\n";
        echo "  Guide: " . ($row['guide_name'] ?? 'Not assigned') . "\n";
        echo "  Email: " . ($row['guide_email'] ?? 'N/A') . "\n";
        echo "  Languages: " . ($row['guide_languages'] ?? 'N/A') . "\n";
        echo "\nBooking Details:\n";
        echo "  Channel: " . ($row['booking_channel'] ?? 'N/A') . "\n";
        echo "  Payment Status: " . ($row['is_paid'] ? 'PAID' : 'NOT PAID') . "\n";
        echo "  Amount: €" . ($row['amount'] ?? '0') . "\n";
        echo "  Description: " . ($row['description'] ?? 'N/A') . "\n";
        echo "  Notes: " . ($row['notes'] ?? 'N/A') . "\n";
        echo "\nBookun Details:\n";
        echo "  Bokun ID: " . ($row['bokun_confirmation_code'] ?? 'N/A') . "\n";
        echo "  Reference: " . ($row['bokun_reference_number'] ?? 'N/A') . "\n";
        echo "\n" . str_repeat("=", 60) . "\n\n";
    }
    echo "TOTAL BOOKINGS TODAY: {$count}\n";
} else {
    echo "No bookings found for today.\n";

    // Check what dates we have bookings for
    echo "\nChecking for bookings in nearby dates...\n";
    $sql_nearby = "SELECT DATE(date) as booking_date, COUNT(*) as count
                   FROM tours
                   WHERE DATE(date) BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
                   GROUP BY DATE(date)
                   ORDER BY booking_date";
    $stmt_nearby = $conn->prepare($sql_nearby);
    $stmt_nearby->bind_param("ss", $today, $today);
    $stmt_nearby->execute();
    $result_nearby = $stmt_nearby->get_result();

    echo "\nBookings within ±7 days:\n";
    while ($row = $result_nearby->fetch_assoc()) {
        echo "  " . $row['booking_date'] . ": " . $row['count'] . " booking(s)\n";
    }
}

$conn->close();
?>
