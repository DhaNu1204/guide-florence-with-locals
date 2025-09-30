<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Test database connection
$db_host = 'localhost';
$db_user = 'u803853690_guideDhanu';
$db_pass = 'GTIUaaN@88*522**267';
$db_name = 'u803853690_guide';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed',
            'error' => $conn->connect_error,
            'suggestion' => 'Please check your database credentials in config.php'
        ]);
    } else {
        // Test if Bokun tables exist
        $tables = [];
        $bokun_tables = ['bokun_config', 'bokun_webhook_logs', 'bokun_tour_mapping', 'guide_availability'];
        
        foreach ($bokun_tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $tables[$table] = $result && $result->num_rows > 0 ? 'exists' : 'missing';
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Database connected successfully',
            'database' => $db_name,
            'bokun_tables' => $tables,
            'note' => 'If tables are missing, run the bokun_integration.sql migration'
        ]);
    }
    
    $conn->close();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Connection error',
        'error' => $e->getMessage()
    ]);
}
?>