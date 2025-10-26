<?php
/**
 * Fix Tour Dates - Correct dates that were incorrectly set to booking creation date
 *
 * This script re-parses all tours with bokun_data to extract the correct tour date
 * from startDateTime/startDate instead of creationDate.
 *
 * Usage: php fix_tour_dates.php
 */

require_once 'config.php';

echo "Starting tour date correction process...\n";
echo "=========================================\n\n";

// Get all tours with bokun_data
$result = $conn->query("
    SELECT id, external_id, bokun_booking_id, date, time, bokun_data, customer_name
    FROM tours
    WHERE bokun_data IS NOT NULL AND bokun_data != ''
    ORDER BY id
");

if (!$result) {
    die("Error querying tours: " . $conn->error . "\n");
}

$totalTours = $result->num_rows;
$updatedCount = 0;
$errorCount = 0;
$skippedCount = 0;

echo "Found {$totalTours} tours with Bokun data to check\n\n";

while ($tour = $result->fetch_assoc()) {
    $booking = json_decode($tour['bokun_data'], true);

    if (!$booking) {
        echo "ERROR: Tour ID {$tour['id']} - Could not parse bokun_data\n";
        $errorCount++;
        continue;
    }

    // Extract productBooking
    $productBooking = isset($booking['productBookings']) && !empty($booking['productBookings'])
        ? $booking['productBookings'][0]
        : [];

    // Extract correct date and time
    $correctDate = null;
    $correctTime = '09:00'; // Default time

    // Try different fields to get the tour date
    if (isset($productBooking['startDateTime'])) {
        $timestamp = is_numeric($productBooking['startDateTime'])
            ? $productBooking['startDateTime'] / 1000
            : strtotime($productBooking['startDateTime']);
        $correctDate = date('Y-m-d', $timestamp);
        $correctTime = date('H:i', $timestamp);
    } elseif (isset($productBooking['startTime'])) {
        $timestamp = is_numeric($productBooking['startTime'])
            ? $productBooking['startTime'] / 1000
            : strtotime($productBooking['startTime']);
        $correctDate = date('Y-m-d', $timestamp);
        $correctTime = date('H:i', $timestamp);
    } elseif (isset($productBooking['startDate'])) {
        $timestamp = is_numeric($productBooking['startDate'])
            ? $productBooking['startDate'] / 1000
            : strtotime($productBooking['startDate']);
        $correctDate = date('Y-m-d', $timestamp);
        // Try to get time from fields.startTimeStr
        if (isset($productBooking['fields']['startTimeStr'])) {
            $correctTime = $productBooking['fields']['startTimeStr'];
        }
    } elseif (isset($booking['startTime'])) {
        $timestamp = is_numeric($booking['startTime'])
            ? $booking['startTime'] / 1000
            : strtotime($booking['startTime']);
        $correctDate = date('Y-m-d', $timestamp);
        $correctTime = date('H:i', $timestamp);
    }

    if (!$correctDate) {
        echo "WARNING: Tour ID {$tour['id']} ({$tour['external_id']}) - No tour date found in bokun_data\n";
        $errorCount++;
        continue;
    }

    // Check if date needs updating
    if ($tour['date'] === $correctDate && $tour['time'] === $correctTime) {
        $skippedCount++;
        continue; // Already correct
    }

    // Update the tour
    $stmt = $conn->prepare("UPDATE tours SET date = ?, time = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $correctDate, $correctTime, $tour['id']);

    if ($stmt->execute()) {
        echo "✓ Updated Tour ID {$tour['id']} ({$tour['customer_name']})\n";
        echo "  Old: {$tour['date']} {$tour['time']}\n";
        echo "  New: {$correctDate} {$correctTime}\n\n";
        $updatedCount++;
    } else {
        echo "ERROR: Could not update Tour ID {$tour['id']}: " . $stmt->error . "\n";
        $errorCount++;
    }

    $stmt->close();
}

echo "\n=========================================\n";
echo "Tour date correction completed!\n";
echo "Total tours checked: {$totalTours}\n";
echo "Tours updated: {$updatedCount}\n";
echo "Already correct (skipped): {$skippedCount}\n";
echo "Errors: {$errorCount}\n";
echo "=========================================\n";

$conn->close();
?>
