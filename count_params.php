<?php
// Count the exact parameters in the INSERT statement

$sql = "INSERT INTO tours (
    external_id, bokun_booking_id, bokun_confirmation_code, title, date, time, duration,
    customer_name, customer_email, customer_phone, participants,
    booking_channel, total_amount_paid, expected_amount, payment_status, paid,
    external_source, needs_guide_assignment, guide_id, cancelled,
    bokun_data, last_synced, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

// Count question marks
$paramCount = substr_count($sql, '?');
echo "Number of ? parameters: $paramCount\n\n";

// List the fields (excluding created_at and updated_at which use NOW())
$fields = [
    'external_id',           // 1  - s
    'bokun_booking_id',      // 2  - s
    'bokun_confirmation_code', // 3  - s
    'title',                 // 4  - s
    'date',                  // 5  - s
    'time',                  // 6  - s
    'duration',              // 7  - s
    'customer_name',         // 8  - s
    'customer_email',        // 9  - s
    'customer_phone',        // 10 - s
    'participants',          // 11 - i
    'booking_channel',       // 12 - s
    'total_amount_paid',     // 13 - d
    'expected_amount',       // 14 - d
    'payment_status',        // 15 - s
    'paid',                  // 16 - i
    'external_source',       // 17 - s
    'needs_guide_assignment', // 18 - i
    'guide_id',              // 19 - i
    'cancelled',             // 20 - i
    'bokun_data',            // 21 - s
    'last_synced'            // 22 - s
];

echo "Field count: " . count($fields) . "\n";
echo "Fields:\n";
foreach ($fields as $i => $field) {
    echo sprintf("%2d. %s\n", $i+1, $field);
}

echo "\nCorrect type string (22 chars): ";
$types = [
    's', 's', 's', 's', 's', 's', 's',  // 1-7: strings
    's', 's', 's',                      // 8-10: strings
    'i',                                // 11: participants (int)
    's',                                // 12: booking_channel (string)
    'd', 'd',                           // 13-14: amounts (decimal)
    's',                                // 15: payment_status (string)
    'i',                                // 16: paid (int)
    's',                                // 17: external_source (string)
    'i', 'i', 'i',                      // 18-20: ints
    's', 's'                            // 21-22: strings
];

echo implode('', $types) . "\n";
echo "Type string length: " . strlen(implode('', $types)) . "\n";
?>