<?php
/**
 * Debug payment creation issue
 */

$base_url = 'http://localhost:8080/api';

function testApi($endpoint, $method = 'GET', $data = null) {
    global $base_url;

    $url = $base_url . $endpoint;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'response' => $response,
        'data' => json_decode($response, true)
    ];
}

echo "Debug Payment Creation\n";
echo "=====================\n\n";

// Get a tour for testing
$tours_result = testApi('/tours.php');
if ($tours_result['http_code'] === 200) {
    $tours = json_decode($tours_result['response'], true);
    if (is_array($tours) && count($tours) > 0) {
        $test_tour = $tours[0];
        echo "Using tour: " . $test_tour['title'] . " (ID: " . $test_tour['id'] . ", Guide ID: " . $test_tour['guide_id'] . ")\n\n";

        $payment_data = [
            'tour_id' => $test_tour['id'],
            'guide_id' => $test_tour['guide_id'],
            'amount' => 150.00,
            'payment_method' => 'cash',
            'payment_date' => date('Y-m-d'),
            'payment_time' => '18:00:00',
            'notes' => 'Test payment transaction'
        ];

        echo "Payment data:\n";
        print_r($payment_data);
        echo "\n";

        $result = testApi('/payments.php', 'POST', $payment_data);
        echo "HTTP Code: " . $result['http_code'] . "\n";
        echo "Response: " . $result['response'] . "\n";
        echo "Parsed data:\n";
        print_r($result['data']);
    }
}
?>