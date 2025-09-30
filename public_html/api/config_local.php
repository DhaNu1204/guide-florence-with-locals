<?php
// Local development configuration using SQLite
$db_path = __DIR__ . '/../../local_test.db';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create SQLite database connection
try {
    $conn = new PDO("sqlite:" . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they don't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'viewer',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->exec("CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token VARCHAR(255) UNIQUE NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    $conn->exec("CREATE TABLE IF NOT EXISTS guides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(20),
        languages VARCHAR(255),
        bio TEXT,
        photo_url VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->exec("CREATE TABLE IF NOT EXISTS tours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        duration VARCHAR(50),
        description TEXT,
        date DATE NOT NULL,
        time VARCHAR(10) NOT NULL,
        guide_id INTEGER,
        booking_channel VARCHAR(100) DEFAULT 'Website',
        paid INTEGER DEFAULT 0,
        cancelled INTEGER DEFAULT 0,
        external_id VARCHAR(255),
        external_source VARCHAR(50),
        bokun_booking_id VARCHAR(255),
        bokun_experience_id VARCHAR(255),
        bokun_confirmation_code VARCHAR(100),
        participants INTEGER,
        customer_name VARCHAR(255),
        customer_email VARCHAR(255),
        customer_phone VARCHAR(50),
        needs_guide_assignment INTEGER DEFAULT 0,
        bokun_data TEXT,
        last_synced DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guide_id) REFERENCES guides(id)
    )");
    
    // Insert default users if they don't exist
    $adminHash = password_hash('Kandy@123', PASSWORD_DEFAULT);
    $viewerHash = password_hash('Sudesh@93', PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT OR IGNORE INTO users (email, password, role) VALUES (?, ?, ?)");
    $stmt->execute(['dhanu', $adminHash, 'admin']);
    $stmt->execute(['Sudeshshiwanka25@gmail.com', $viewerHash, 'viewer']);
    
    // Insert some default guides if they don't exist
    $stmt = $conn->prepare("INSERT OR IGNORE INTO guides (name, email, phone, languages) VALUES (?, ?, ?, ?)");
    $stmt->execute(['Lucia Bianchi', 'lucia@example.com', '+39 123 456 7890', 'Italian, English, French']);
    $stmt->execute(['Marco Rossi', 'marco@example.com', '+39 123 456 7891', 'Italian, English, Spanish']);
    $stmt->execute(['Sofia Esposito', 'sofia@example.com', '+39 123 456 7892', 'Italian, English, German']);
    
} catch(PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Function to adapt SQLite queries from MySQL
function adaptQuery($query) {
    // Convert MySQL AUTO_INCREMENT to SQLite AUTOINCREMENT
    $query = str_replace('AUTO_INCREMENT', 'AUTOINCREMENT', $query);
    // Convert TINYINT to INTEGER
    $query = str_replace('TINYINT', 'INTEGER', $query);
    return $query;
}
?>