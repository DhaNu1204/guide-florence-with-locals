<?php
require_once 'public_html/api/config.php';

echo "Updating dhanu user with proper email...\n\n";

// Update dhanu user with the correct email
$stmt = $conn->prepare("UPDATE users SET email = ? WHERE username = ?");
$email = 'g.dhanushka123@gmail.com';
$username = 'dhanu';
$stmt->bind_param("ss", $email, $username);

if ($stmt->execute()) {
    echo "✓ Updated dhanu user with email: g.dhanushka123@gmail.com\n";
} else {
    echo "✗ Failed to update dhanu email: " . $conn->error . "\n";
}

echo "\nUpdated user information:\n";
echo "------------------------\n";
$result = $conn->query("SELECT id, username, email, role FROM users WHERE username = 'dhanu'");
if ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . "\n";
    echo "Username: " . $row['username'] . "\n";
    echo "Email: " . $row['email'] . "\n";
    echo "Role: " . $row['role'] . "\n";
}

echo "\nLogin credentials:\n";
echo "------------------------\n";
echo "Username: dhanu\n";
echo "Email: g.dhanushka123@gmail.com\n";
echo "Password: Kandy@123\n";
echo "\nYou can login with either:\n";
echo "- Username: dhanu / Kandy@123\n";
echo "- Email: g.dhanushka123@gmail.com / Kandy@123\n";

$conn->close();
?>