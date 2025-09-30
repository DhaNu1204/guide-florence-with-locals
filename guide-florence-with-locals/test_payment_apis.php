<?php
/**
 * Test script for Payment System APIs
 * Tests all payment-related endpoints
 */

echo "Payment System API Testing\n";
echo "=========================\n\n";

$base_url = 'http://localhost:8080/api';
$test_results = [];

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

// Test 1: Check tours API for payment fields
echo "Test 1: Tours API (checking payment fields)\n";
$result = testApi('/tours.php');
if ($result['http_code'] === 200) {
    $tours = json_decode($result['response'], true);
    if (is_array($tours) && count($tours) > 0) {
        echo "✓ Tours API working, found " . count($tours) . " tours\n";
        $tour = $tours[0];
        $payment_fields = ['payment_status', 'total_amount_paid', 'expected_amount'];
        foreach ($payment_fields as $field) {
            if (array_key_exists($field, $tour)) {
                echo "✓ Payment field '$field' present\n";
            } else {
                echo "✗ Payment field '$field' missing\n";
            }
        }
    } else {
        echo "✗ No tours found\n";
    }
} else {
    echo "✗ Tours API failed: HTTP " . $result['http_code'] . "\n";
}
echo "\n";

// Test 2: Guide Payments API - Overview
echo "Test 2: Guide Payments API - Overview\n";
$result = testApi('/guide-payments.php?action=overview');
if ($result['http_code'] === 200 && $result['data']['success']) {
    echo "✓ Guide payments overview API working\n";
    $data = $result['data']['data'];
    if (isset($data['overall'])) {
        echo "✓ Overall statistics present\n";
        echo "  Total transactions: " . ($data['overall']['total_transactions'] ?? 0) . "\n";
        echo "  Total amount: €" . ($data['overall']['total_amount'] ?? 0) . "\n";
    }
} else {
    echo "✗ Guide payments overview failed: HTTP " . $result['http_code'] . "\n";
}
echo "\n";

// Test 3: Guide Payments API - All guides
echo "Test 3: Guide Payments API - All Guides\n";
$result = testApi('/guide-payments.php');
if ($result['http_code'] === 200 && $result['data']['success']) {
    echo "✓ Guide payments summary API working\n";
    $guides = $result['data']['data'];
    echo "  Found " . count($guides) . " guides\n";
    foreach ($guides as $guide) {
        echo "  - " . $guide['guide_name'] . ": " . $guide['total_tours'] . " tours, €" . $guide['total_payments_received'] . " received\n";
    }
} else {
    echo "✗ Guide payments summary failed: HTTP " . $result['http_code'] . "\n";
}
echo "\n";

// Test 4: Payment Reports API - Summary
echo "Test 4: Payment Reports API - Summary\n";
$result = testApi('/payment-reports.php?type=summary&start_date=2025-01-01&end_date=2025-12-31');
if ($result['http_code'] === 200 && $result['data']['success']) {
    echo "✓ Payment reports summary API working\n";
    $report = $result['data']['data'];
    echo "  Report type: " . $report['report_type'] . "\n";
    if (isset($report['overall_statistics'])) {
        echo "  Total transactions: " . $report['overall_statistics']['total_transactions'] . "\n";
        echo "  Total amount: €" . $report['overall_statistics']['total_amount'] . "\n";
    }
} else {
    echo "✗ Payment reports failed: HTTP " . $result['http_code'] . "\n";
}
echo "\n";

// Test 5: Create a test payment transaction
echo "Test 5: Create Test Payment Transaction\n";
$tours_result = testApi('/tours.php');
if ($tours_result['http_code'] === 200) {
    $tours = json_decode($tours_result['response'], true);
    if (is_array($tours) && count($tours) > 0) {
        $test_tour = $tours[0];
        $payment_data = [
            'tour_id' => $test_tour['id'],
            'guide_id' => $test_tour['guide_id'],
            'amount' => 150.00,
            'payment_method' => 'cash',
            'payment_date' => date('Y-m-d'),
            'payment_time' => '18:00:00',
            'notes' => 'Test payment transaction'
        ];

        $result = testApi('/payments.php', 'POST', $payment_data);
        if ($result['http_code'] === 201 && $result['data']['success']) {
            echo "✓ Payment creation API working\n";
            echo "  Created payment ID: " . $result['data']['data']['payment_id'] . "\n";
            echo "  Tour payment status: " . $result['data']['data']['tour_payment_status'] . "\n";

            // Clean up - delete the test payment
            $payment_id = $result['data']['data']['payment_id'];
            $delete_result = testApi("/payments.php?id=$payment_id", 'DELETE');
            if ($delete_result['http_code'] === 200) {
                echo "✓ Test payment cleaned up\n";
            }
        } else {
            echo "✗ Payment creation failed: HTTP " . $result['http_code'] . "\n";
            if (isset($result['data']['error'])) {
                echo "  Error: " . $result['data']['error'] . "\n";
            }
        }
    } else {
        echo "✗ No tours available for payment test\n";
    }
} else {
    echo "✗ Cannot load tours for payment test\n";
}
echo "\n";

// Test 6: Payment Reports - Export functionality
echo "Test 6: Payment Reports - Export Test\n";
$result = testApi('/payment-reports.php?type=export&format=txt&start_date=2025-01-01&end_date=2025-12-31');
if ($result['http_code'] === 200) {
    if (strpos($result['response'], 'FLORENCE TOURS - PAYMENT REPORT') !== false) {
        echo "✓ Payment export (text format) working\n";
        echo "  Export contains proper header\n";
    } else {
        echo "✗ Payment export format incorrect\n";
    }
} else {
    echo "✗ Payment export failed: HTTP " . $result['http_code'] . "\n";
}
echo "\n";

echo "Payment System API Testing Complete!\n";
echo "====================================\n";
?>