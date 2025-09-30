<?php
require_once 'config.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check database connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

$response = [
    'status' => 'success',
    'database' => $db_name,
    'connection' => 'OK',
    'tables' => []
];

// Check all tables
$tables = ['users', 'guides', 'tours', 'tickets', 'bokun_config', 'sessions'];

foreach ($tables as $table) {
    $checkQuery = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($checkQuery);
    
    if ($result && $result->num_rows > 0) {
        // Table exists, get structure
        $structureQuery = "DESCRIBE $table";
        $structureResult = $conn->query($structureQuery);
        
        $columns = [];
        while ($row = $structureResult->fetch_assoc()) {
            $columns[] = [
                'field' => $row['Field'],
                'type' => $row['Type'],
                'null' => $row['Null'],
                'key' => $row['Key'],
                'default' => $row['Default']
            ];
        }
        
        // Get row count
        $countQuery = "SELECT COUNT(*) as count FROM $table";
        $countResult = $conn->query($countQuery);
        $count = $countResult ? $countResult->fetch_assoc()['count'] : 0;
        
        $response['tables'][$table] = [
            'exists' => true,
            'columns' => $columns,
            'row_count' => $count
        ];
    } else {
        $response['tables'][$table] = [
            'exists' => false,
            'columns' => [],
            'row_count' => 0
        ];
    }
}

// Check specific important columns for guides table
if (isset($response['tables']['guides']['exists']) && $response['tables']['guides']['exists']) {
    $guidesColumns = array_column($response['tables']['guides']['columns'], 'field');
    $response['guides_has_email'] = in_array('email', $guidesColumns);
    $response['guides_has_languages'] = in_array('languages', $guidesColumns);
}

// Check specific important columns for tours table  
if (isset($response['tables']['tours']['exists']) && $response['tables']['tours']['exists']) {
    $toursColumns = array_column($response['tables']['tours']['columns'], 'field');
    $response['tours_has_cancelled'] = in_array('cancelled', $toursColumns);
    $response['tours_has_booking_channel'] = in_array('booking_channel', $toursColumns);
}

echo json_encode($response, JSON_PRETTY_PRINT);

$conn->close();
?>