<?php
// Simple script to create users for testing
$db_host = 'localhost';
$db_user = 'u803853690_guideDhanu';
$db_pass = 'GTIUaaN@88*522**267';
$db_name = 'u803853690_guide';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "Connected successfully\n";
    
    // Create users table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($sql) === TRUE) {
        echo "Users table created successfully\n";
    }
    
    // Create sessions table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) === TRUE) {
        echo "Sessions table created successfully\n";
    }
    
    // Hash passwords properly
    $adminPassword = password_hash('Kandy@123', PASSWORD_DEFAULT);
    $viewerPassword = password_hash('Sudesh@93', PASSWORD_DEFAULT);
    
    echo "Admin password hash: $adminPassword\n";
    echo "Viewer password hash: $viewerPassword\n";
    
    // Insert admin user
    $stmt = $conn->prepare("INSERT IGNORE INTO users (email, password, role) VALUES (?, ?, 'admin')");
    $stmt->bind_param("ss", $adminEmail, $adminPassword);
    $adminEmail = 'dhanu';
    $stmt->execute();
    echo "Admin user created/updated\n";
    
    // Insert viewer user  
    $stmt = $conn->prepare("INSERT IGNORE INTO users (email, password, role) VALUES (?, ?, 'viewer')");
    $stmt->bind_param("ss", $viewerEmail, $viewerPassword);
    $viewerEmail = 'Sudeshshiwanka25@gmail.com';
    $stmt->execute();
    echo "Viewer user created/updated\n";
    
    // Check created users
    $result = $conn->query("SELECT id, email, role FROM users");
    echo "\nCreated users:\n";
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Email: {$row['email']}, Role: {$row['role']}\n";
    }
    
    $conn->close();
    echo "\nSetup complete!\n";
    echo "Login credentials:\n";
    echo "Admin - Username: dhanu, Password: Kandy@123\n";
    echo "Viewer - Username: Sudeshshiwanka25@gmail.com, Password: Sudesh@93\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>