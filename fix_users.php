<?php
require_once 'public_html/api/config.php';

echo "Fixing user accounts...\n\n";

// Update dhanu user with proper password hash
$adminPassword = password_hash('Kandy@123', PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = 'dhanu'");
$stmt->bind_param("s", $adminPassword);
if ($stmt->execute()) {
    echo "✓ Updated dhanu password\n";
} else {
    echo "✗ Failed to update dhanu: " . $conn->error . "\n";
}

// Check if viewer user exists
$result = $conn->query("SELECT * FROM users WHERE email = 'Sudeshshiwanka25@gmail.com'");
if ($result->num_rows == 0) {
    // Create viewer user
    $viewerPassword = password_hash('Sudesh@93', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'viewer')");
    $stmt->bind_param("ss", $email, $viewerPassword);
    $email = 'Sudeshshiwanka25@gmail.com';
    if ($stmt->execute()) {
        echo "✓ Created viewer user\n";
    } else {
        echo "✗ Failed to create viewer: " . $conn->error . "\n";
    }
} else {
    // Update existing viewer user
    $viewerPassword = password_hash('Sudesh@93', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = 'Sudeshshiwanka25@gmail.com'");
    $stmt->bind_param("s", $viewerPassword);
    if ($stmt->execute()) {
        echo "✓ Updated viewer password\n";
    } else {
        echo "✗ Failed to update viewer: " . $conn->error . "\n";
    }
}

echo "\nCurrent users:\n";
echo "------------------------\n";
$result = $conn->query("SELECT id, email, role FROM users");
while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . " | Email: " . $row['email'] . " | Role: " . $row['role'] . "\n";
}

echo "\nLogin credentials:\n";
echo "------------------------\n";
echo "Admin: dhanu / Kandy@123\n";
echo "Viewer: Sudeshshiwanka25@gmail.com / Sudesh@93\n";

$conn->close();
?>