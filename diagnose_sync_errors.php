<?php
// Diagnostic script to identify Bokun sync errors

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'public_html/api/config.php';
require_once 'public_html/api/BokunAPI.php';

echo "=================================\n";
echo "BOKUN SYNC ERROR DIAGNOSTICS\n";
echo "=================================\n\n";

// 1. Check tours table structure
echo "1. CHECKING DATABASE STRUCTURE\n";
echo "--------------------------------\n";

$result = $conn->query("DESCRIBE tours");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[$row['Field']] = [
        'type' => $row['Type'],
        'null' => $row['Null'],
        'default' => $row['Default']
    ];
    echo sprintf("  %-25s %-20s %s %s\n",
        $row['Field'],
        $row['Type'],
        $row['Null'] === 'YES' ? 'NULL' : 'NOT NULL',
        $row['Default'] ? "DEFAULT: {$row['Default']}" : ''
    );
}

echo "\n2. TESTING SINGLE BOOKING SYNC\n";
echo "--------------------------------\n";

// Get Bokun configuration
$configResult = $conn->query("SELECT * FROM bokun_config LIMIT 1");
$config = $configResult->fetch_assoc();

if (!$config) {
    die("ERROR: No Bokun configuration found\n");
}

// Initialize Bokun API
$bokunAPI = new BokunAPI($config);

// Get a small sample of bookings
$startDate = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+1 day'));

try {
    echo "Fetching bookings for: $startDate to $endDate\n\n";
    $response = $bokunAPI->getBookings($startDate, $endDate);

    if (isset($response['items']) && !empty($response['items'])) {
        $booking = $response['items'][0]; // Get first booking

        echo "3. RAW BOOKING DATA STRUCTURE\n";
        echo "--------------------------------\n";
        echo "Booking ID: " . $booking['id'] . "\n";
        echo "Confirmation: " . $booking['confirmationCode'] . "\n\n";

        echo "4. ATTEMPTING TRANSFORMATION\n";
        echo "--------------------------------\n";

        // Try to transform the booking
        try {
            $tourData = $bokunAPI->transformBookingToTour($booking);

            echo "Transformed data:\n";
            foreach ($tourData as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value) : $value;
                echo sprintf("  %-25s => %s\n", $key, $displayValue ?? 'NULL');
            }

            echo "\n5. CHECKING FIELD COMPATIBILITY\n";
            echo "--------------------------------\n";

            // Check each field against database schema
            $errors = [];
            foreach ($tourData as $field => $value) {
                if (!isset($columns[$field]) && !in_array($field, ['external_reference', 'channel', 'seller'])) {
                    $errors[] = "Field '$field' does not exist in tours table";
                } elseif (isset($columns[$field])) {
                    $colType = $columns[$field]['type'];
                    $isNullable = $columns[$field]['null'] === 'YES';

                    // Check for null values in non-nullable fields
                    if ($value === null && !$isNullable) {
                        $errors[] = "Field '$field' cannot be NULL (value is null)";
                    }

                    // Check data type compatibility
                    if ($value !== null) {
                        if (strpos($colType, 'int') !== false && !is_numeric($value)) {
                            $errors[] = "Field '$field' expects integer but got: " . gettype($value);
                        }
                        if (strpos($colType, 'varchar') !== false || strpos($colType, 'text') !== false) {
                            if (is_array($value) || is_object($value)) {
                                $errors[] = "Field '$field' expects string but got: " . gettype($value);
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                echo "⚠️ COMPATIBILITY ERRORS FOUND:\n";
                foreach ($errors as $error) {
                    echo "  - $error\n";
                }
            } else {
                echo "✅ No obvious compatibility issues\n";
            }

            echo "\n6. ATTEMPTING DATABASE INSERT\n";
            echo "--------------------------------\n";

            // Try to insert/update
            $stmt = $conn->prepare("SELECT id FROM tours WHERE bokun_booking_id = ? OR external_id = ?");
            $bokun_id = $tourData['bokun_booking_id'] ?? '';
            $external_id = $tourData['external_id'] ?? '';
            $stmt->bind_param("ss", $bokun_id, $external_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                echo "Tour already exists with ID: " . $existing['id'] . "\n";
            } else {
                // Prepare insert statement
                $insertSQL = "INSERT INTO tours (
                    external_id, bokun_booking_id, title, date, time, duration_minutes,
                    customer_name, customer_email, customer_phone, participants,
                    price, currency, status, special_requests, external_source,
                    needs_guide_assignment, cancelled, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                echo "Preparing INSERT statement...\n";

                $stmt = $conn->prepare($insertSQL);
                if (!$stmt) {
                    echo "❌ Prepare failed: " . $conn->error . "\n";
                } else {
                    // Bind parameters - fixing the data types
                    $external_id = $tourData['external_id'];
                    $bokun_booking_id = $tourData['bokun_booking_id'];
                    $title = $tourData['title'];
                    $date = $tourData['date'];
                    $time = $tourData['time'];
                    $duration_minutes = $tourData['duration_minutes'];
                    $customer_name = $tourData['customer_name'];
                    $customer_email = $tourData['customer_email'];
                    $customer_phone = $tourData['customer_phone'];
                    $participants = $tourData['participants'];
                    $price = $tourData['price'];
                    $currency = $tourData['currency'];
                    $status = $tourData['status'];
                    $special_requests = $tourData['special_requests'];
                    $external_source = $tourData['external_source'];
                    $needs_guide_assignment = $tourData['needs_guide_assignment'];
                    $cancelled = $tourData['cancelled'];

                    $stmt->bind_param("sssssississsssii",
                        $external_id, $bokun_booking_id, $title,
                        $date, $time, $duration_minutes,
                        $customer_name, $customer_email, $customer_phone,
                        $participants, $price, $currency,
                        $status, $special_requests, $external_source,
                        $needs_guide_assignment, $cancelled
                    );

                    if ($stmt->execute()) {
                        echo "✅ Successfully inserted tour with ID: " . $stmt->insert_id . "\n";
                    } else {
                        echo "❌ Insert failed: " . $stmt->error . "\n";
                        echo "\nDebugging info:\n";
                        echo "- Date value: " . var_export($date, true) . "\n";
                        echo "- Time value: " . var_export($time, true) . "\n";
                        echo "- Duration: " . var_export($duration_minutes, true) . "\n";
                        echo "- Participants: " . var_export($participants, true) . "\n";
                        echo "- Price: " . var_export($price, true) . "\n";
                    }
                    $stmt->close();
                }
            }

        } catch (Exception $e) {
            echo "❌ Transformation error: " . $e->getMessage() . "\n";
        }

    } else {
        echo "No bookings found for the date range\n";
    }

} catch (Exception $e) {
    echo "❌ API Error: " . $e->getMessage() . "\n";
}

echo "\n=================================\n";
echo "DIAGNOSTICS COMPLETE\n";
echo "=================================\n";