<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Test password verification
$plainPassword1 = "Kandy@123";
$plainPassword2 = "Sudesh@93";
$hashedFromDB = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$result = [
    'admin_password_match' => password_verify($plainPassword1, $hashedFromDB),
    'viewer_password_match' => password_verify($plainPassword2, $hashedFromDB),
    'hash_test' => $hashedFromDB,
    'login_credentials' => [
        'admin' => [
            'username' => 'dhanu',
            'password' => 'Kandy@123',
            'role' => 'admin'
        ],
        'viewer' => [
            'username' => 'Sudeshshiwanka25@gmail.com', 
            'password' => 'Sudesh@93',
            'role' => 'viewer'
        ]
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT);
?>