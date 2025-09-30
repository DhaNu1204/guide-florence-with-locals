<?php
// Script to update tour times from Bokun data
require_once 'public_html/api/config.php';

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected to database successfully\n";

// Get all tours with bokun_data
$sql = "SELECT id, title, date, time, bokun_data FROM tours WHERE bokun_data IS NOT NULL AND bokun_data != ''";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $updated_count = 0;
    $processed_count = 0;

    echo "Found " . $result->num_rows . " tours with Bokun data\n";
    echo "Processing tours...\n\n";

    while ($row = $result->fetch_assoc()) {
        $processed_count++;
        $tour_id = $row['id'];
        $current_time = $row['time'];
        $bokun_data = $row['bokun_data'];

        echo "Processing Tour ID: {$tour_id} - {$row['title']}\n";
        echo "  Current time: {$current_time}\n";

        try {
            $data = json_decode($bokun_data, true);

            if (isset($data['productBookings']) &&
                isset($data['productBookings'][0]) &&
                isset($data['productBookings'][0]['fields']) &&
                isset($data['productBookings'][0]['fields']['startTimeStr'])) {

                $correct_time = $data['productBookings'][0]['fields']['startTimeStr'];
                echo "  Bokun time: {$correct_time}\n";

                // Only update if the time is different
                if ($current_time !== $correct_time) {
                    // Update the tour with correct time
                    $update_sql = "UPDATE tours SET time = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param("si", $correct_time, $tour_id);

                    if ($stmt->execute()) {
                        echo "  ✓ UPDATED: {$current_time} → {$correct_time}\n";
                        $updated_count++;
                    } else {
                        echo "  ✗ ERROR updating tour {$tour_id}: " . $stmt->error . "\n";
                    }
                    $stmt->close();
                } else {
                    echo "  → Time already correct, no update needed\n";
                }
            } else {
                echo "  → No startTimeStr found in Bokun data\n";
            }
        } catch (Exception $e) {
            echo "  ✗ Error parsing Bokun data: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    echo "=== SUMMARY ===\n";
    echo "Total tours processed: {$processed_count}\n";
    echo "Tours updated: {$updated_count}\n";
    echo "Tours skipped (already correct): " . ($processed_count - $updated_count) . "\n";

} else {
    echo "No tours found with Bokun data\n";
}

$conn->close();
echo "\nDatabase connection closed.\n";
?>