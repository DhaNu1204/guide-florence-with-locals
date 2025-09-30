<?php
// Import tickets script (FIXED VERSION)
// This version adds the time column if missing, then imports data

// Database configuration
$host = 'localhost';
$db_name = 'u803853690_guide_florence';
$username = 'u803853690_guidefl';
$password = 'FlorenceGuide2024!';

// Create connection
$conn = new mysqli($host, $username, $password, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

echo "<h1>Ticket Import Script (Fixed Version)</h1>";

// Step 1: Check if time column exists, if not add it
echo "<h2>Step 1: Checking/Adding Time Column</h2>";
$checkColumn = "SHOW COLUMNS FROM tickets LIKE 'time'";
$result = $conn->query($checkColumn);

if ($result->num_rows == 0) {
    echo "<p>Time column not found. Adding it...</p>";
    $addColumn = "ALTER TABLE tickets ADD COLUMN time TIME DEFAULT NULL AFTER date";
    if ($conn->query($addColumn)) {
        echo "<span style='color: green;'>✓</span> Time column added successfully!<br>";
    } else {
        echo "<span style='color: red;'>✗</span> Error adding time column: " . $conn->error . "<br>";
        exit();
    }
} else {
    echo "<span style='color: green;'>✓</span> Time column already exists.<br>";
}

// Step 2: Import ticket data
echo "<h2>Step 2: Importing Ticket Data</h2>";

// Ticket data array
$tickets = [
    ['Accademia', '18124827', '2025-06-12', '14:45', 10],
    ['Accademia', '18124833', '2025-06-12', '16:30', 10],
    ['Accademia', '18124835', '2025-06-12', '17:00', 10],
    ['Accademia', '18124840', '2025-06-13', '14:45', 10],
    ['Accademia', '18124844', '2025-06-13', '16:30', 10],
    ['Accademia', '18124847', '2025-06-13', '17:00', 10],
    ['Accademia', '18124851', '2025-06-14', '14:45', 10],
    ['Accademia', '18124853', '2025-06-14', '16:30', 10],
    ['Accademia', '18124859', '2025-06-14', '17:15', 10],
    ['Accademia', '18124866', '2025-06-15', '14:45', 10],
    ['Accademia', '18124871', '2025-06-15', '16:30', 10],
    ['Accademia', '18124876', '2025-06-15', '17:00', 10],
    ['Accademia', '18124882', '2025-06-17', '14:45', 10],
    ['Accademia', '18124888', '2025-06-17', '16:30', 10],
    ['Accademia', '18124893', '2025-06-17', '17:00', 10],
    ['Accademia', '18124900', '2025-06-18', '14:45', 10],
    ['Accademia', '18124909', '2025-06-18', '16:30', 10],
    ['Accademia', '18124915', '2025-06-18', '17:00', 10],
    ['Accademia', '18124918', '2025-06-19', '14:45', 10],
    ['Accademia', '18124927', '2025-06-19', '16:30', 10],
    ['Accademia', '18124930', '2025-06-19', '17:00', 10],
    ['Accademia', '18124934', '2025-06-20', '14:45', 10],
    ['Accademia', '18124936', '2025-06-20', '16:30', 10],
    ['Accademia', '18124939', '2025-06-20', '17:00', 10],
    ['Accademia', '18124945', '2025-06-21', '14:45', 10],
    ['Accademia', '18124948', '2025-06-21', '16:30', 10],
    ['Accademia', '18124951', '2025-06-21', '17:00', 10],
    ['Accademia', '18125013', '2025-06-22', '14:45', 10],
    ['Accademia', '18125017', '2025-06-22', '16:30', 10],
    ['Accademia', '18125019', '2025-06-22', '17:00', 10],
    ['Accademia', '18125025', '2025-06-24', '14:45', 10],
    ['Accademia', '18125031', '2025-06-24', '16:30', 10],
    ['Accademia', '18125035', '2025-06-24', '17:00', 10],
    ['Accademia', '18125045', '2025-06-25', '14:45', 10],
    ['Accademia', '18125047', '2025-06-25', '16:30', 10],
    ['Accademia', '18125051', '2025-06-25', '17:00', 10],
    ['Accademia', '18125055', '2025-06-26', '14:45', 10],
    ['Accademia', '18125058', '2025-06-26', '16:30', 10],
    ['Accademia', '18125060', '2025-06-26', '17:00', 10],
    ['Accademia', '18125063', '2025-06-27', '14:45', 10],
    ['Accademia', '18125066', '2025-06-27', '16:30', 10],
    ['Accademia', '18125069', '2025-06-27', '17:00', 10],
    ['Accademia', '18125072', '2025-06-28', '15:15', 10],
    ['Accademia', '18125073', '2025-06-28', '16:30', 10],
    ['Accademia', '18125074', '2025-06-28', '17:00', 10],
    ['Accademia', '18125078', '2025-06-29', '14:45', 10],
    ['Accademia', '18125080', '2025-06-29', '16:30', 10],
    ['Accademia', '18125084', '2025-06-29', '17:00', 10]
];

echo "<p>Importing " . count($tickets) . " tickets...</p>";

$successful = 0;
$failed = 0;
$errors = [];

foreach ($tickets as $ticket) {
    $location = $ticket[0];
    $code = $ticket[1];
    $date = $ticket[2];
    $time = $ticket[3];
    $quantity = $ticket[4];
    
    // Check if ticket already exists (avoid duplicates)
    $checkSql = "SELECT id FROM tickets WHERE code = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $code);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<span style='color: orange;'>⚠</span> Skipped (already exists): $code<br>";
        $checkStmt->close();
        continue;
    }
    $checkStmt->close();
    
    // Insert new ticket
    $sql = "INSERT INTO tickets (location, code, date, time, quantity) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ssssi", $location, $code, $date, $time, $quantity);
        
        if ($stmt->execute()) {
            $successful++;
            echo "<span style='color: green;'>✓</span> Added: $code ($date $time)<br>";
        } else {
            $failed++;
            $error = "Error adding $code: " . $stmt->error;
            $errors[] = $error;
            echo "<span style='color: red;'>✗</span> $error<br>";
        }
        
        $stmt->close();
    } else {
        $failed++;
        $error = "Error preparing statement for $code: " . $conn->error;
        $errors[] = $error;
        echo "<span style='color: red;'>✗</span> $error<br>";
    }
}

echo "<hr>";
echo "<h2>Import Summary</h2>";
echo "<p><strong>Successful:</strong> $successful tickets</p>";
echo "<p><strong>Failed:</strong> $failed tickets</p>";

if (count($errors) > 0) {
    echo "<h3>Errors:</h3>";
    foreach ($errors as $error) {
        echo "<p style='color: red;'>$error</p>";
    }
}

// Check total tickets in database
$result = $conn->query("SELECT COUNT(*) as total FROM tickets");
$row = $result->fetch_assoc();
echo "<p><strong>Total tickets in database:</strong> " . $row['total'] . "</p>";

// Show table structure
echo "<h3>Updated Table Structure:</h3>";
$result = $conn->query("DESCRIBE tickets");
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . $row['Default'] . "</td>";
    echo "<td>" . $row['Extra'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<p style='color: red;'><strong>IMPORTANT:</strong> Please delete this file after running for security!</p>";

$conn->close();
?> 