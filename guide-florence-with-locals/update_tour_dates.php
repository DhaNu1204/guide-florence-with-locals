<?php
// Script to update tour dates from Bokun data
require_once 'public_html/api/config.php';

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected to database successfully\n";

// Get all tours with bokun_data
$sql = "SELECT id, title, date, bokun_data FROM tours WHERE bokun_data IS NOT NULL AND bokun_data != ''";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $updated_count = 0;
    $processed_count = 0;

    echo "Found " . $result->num_rows . " tours with Bokun data\n";
    echo "Processing tours...\n\n";

    while ($row = $result->fetch_assoc()) {
        $processed_count++;
        $tour_id = $row['id'];
        $current_date = $row['date'];
        $bokun_data = $row['bokun_data'];

        echo "Processing Tour ID: {$tour_id} - {$row['title']}\n";
        echo "  Current date: {$current_date}\n";

        try {
            $data = json_decode($bokun_data, true);

            if (isset($data['productBookings']) && isset($data['productBookings'][0])) {
                $productBooking = $data['productBookings'][0];
                $startDateTime = null;

                // Try to get startDateTime first (more precise)
                if (isset($productBooking['startDateTime'])) {
                    $startDateTime = $productBooking['startDateTime'];
                    echo "  Bokun startDateTime: {$startDateTime}\n";
                } elseif (isset($productBooking['startDate'])) {
                    $startDateTime = $productBooking['startDate'];
                    echo "  Bokun startDate: {$startDateTime}\n";
                }

                if ($startDateTime) {
                    // Convert from Unix timestamp (milliseconds) to date
                    $timestamp_seconds = $startDateTime / 1000;
                    $correct_date = date('Y-m-d', $timestamp_seconds);
                    echo "  Converted date: {$correct_date}\n";

                    // Only update if the date is different
                    if ($current_date !== $correct_date) {
                        // Update the tour with correct date
                        $update_sql = "UPDATE tours SET date = ? WHERE id = ?";
                        $stmt = $conn->prepare($update_sql);
                        $stmt->bind_param("si", $correct_date, $tour_id);

                        if ($stmt->execute()) {
                            echo "  ✓ UPDATED: {$current_date} → {$correct_date}\n";
                            $updated_count++;
                        } else {
                            echo "  ✗ ERROR updating tour {$tour_id}: " . $stmt->error . "\n";
                        }
                        $stmt->close();
                    } else {
                        echo "  → Date already correct, no update needed\n";
                    }
                } else {
                    echo "  → No date timestamp found in Bokun data\n";
                }
            } else {
                echo "  → No productBookings found in Bokun data\n";
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