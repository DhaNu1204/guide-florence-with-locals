<?php
$servername = 'localhost';
$username = 'root';
$password = 'RL94_#BbiLhuy789xF';
$dbname = 'florence_guides';

$conn = new mysqli($servername, $username, $password, $dbname);

// Find the GetYourGuide booking
$sql = "SELECT bokun_data FROM tours WHERE booking_channel LIKE '%GetYourGuide%' AND time = '15:30' AND date = '2025-10-15' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $bokunData = json_decode($row['bokun_data'], true);

    echo "COMPLETE BOKUN DATA FOR GETYOURGUIDE BOOKING:\n";
    echo "==============================================\n\n";
    echo json_encode($bokunData, JSON_PRETTY_PRINT);
}

$conn->close();
