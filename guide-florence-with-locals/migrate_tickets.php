<?php
echo "<h1>Ticket Migration Script</h1>";
echo "<p>Moving tickets from u803853690_guide_florence to u803853690_guide</p>";

// Working database credentials
$host = 'localhost';
$target_db = 'u803853690_guide';
$username = 'u803853690_guideDhanu';
$password = 'GTIUaaN@88*522**267';

// Source database (where tickets were imported)
$source_db = 'u803853690_guide_florence';
$source_username = 'u803853690_guidefl';
$source_password = 'FlorenceGuide2024!';

echo "<h2>Step 1: Connect to target database (u803853690_guide)</h2>";

// Connect to target database
$target_conn = new mysqli($host, $username, $password, $target_db);
if ($target_conn->connect_error) {
    echo "❌ Target database connection failed: " . $target_conn->connect_error . "<br>";
    exit();
} else {
    echo "✅ Connected to target database (u803853690_guide)<br>";
}

echo "<h2>Step 2: Create tickets table in target database</h2>";

// Create tickets table in target database
$createTableSQL = "CREATE TABLE IF NOT EXISTS tickets (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    time TIME DEFAULT NULL,
    quantity INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($target_conn->query($createTableSQL)) {
    echo "✅ Tickets table created/verified in target database<br>";
} else {
    echo "❌ Error creating tickets table: " . $target_conn->error . "<br>";
    exit();
}

echo "<h2>Step 3: Try to connect to source database (u803853690_guide_florence)</h2>";

// Try to connect to source database
$source_conn = new mysqli($host, $source_username, $source_password, $source_db);
if ($source_conn->connect_error) {
    echo "❌ Source database connection failed: " . $source_conn->connect_error . "<br>";
    echo "<p><strong>Alternative:</strong> Since we can't connect to the source database, let's manually insert the tickets.</p>";
    
    // Manual insert of the 47 tickets
    echo "<h2>Step 4: Manually inserting tickets</h2>";
    
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
    
    $successful = 0;
    $failed = 0;
    
    foreach ($tickets as $ticket) {
        $location = $ticket[0];
        $code = $ticket[1];
        $date = $ticket[2];
        $time = $ticket[3];
        $quantity = $ticket[4];
        
        // Check if ticket already exists
        $checkSQL = "SELECT id FROM tickets WHERE code = ?";
        $checkStmt = $target_conn->prepare($checkSQL);
        $checkStmt->bind_param("s", $code);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<span style='color: orange;'>⚠</span> Skipped (exists): $code<br>";
            $checkStmt->close();
            continue;
        }
        $checkStmt->close();
        
        // Insert ticket
        $insertSQL = "INSERT INTO tickets (location, code, date, time, quantity) VALUES (?, ?, ?, ?, ?)";
        $insertStmt = $target_conn->prepare($insertSQL);
        $insertStmt->bind_param("ssssi", $location, $code, $date, $time, $quantity);
        
        if ($insertStmt->execute()) {
            $successful++;
            echo "<span style='color: green;'>✓</span> Added: $code ($date $time)<br>";
        } else {
            $failed++;
            echo "<span style='color: red;'>✗</span> Failed: $code - " . $insertStmt->error . "<br>";
        }
        $insertStmt->close();
    }
    
    echo "<h2>Migration Summary</h2>";
    echo "<p><strong>Successful:</strong> $successful tickets</p>";
    echo "<p><strong>Failed:</strong> $failed tickets</p>";
    
} else {
    echo "✅ Connected to source database<br>";
    
    // Get tickets from source database
    $result = $source_conn->query("SELECT * FROM tickets");
    if ($result && $result->num_rows > 0) {
        echo "<p>Found " . $result->num_rows . " tickets in source database</p>";
        
        $successful = 0;
        $failed = 0;
        
        while ($ticket = $result->fetch_assoc()) {
            // Insert into target database
            $insertSQL = "INSERT INTO tickets (location, code, date, time, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insertStmt = $target_conn->prepare($insertSQL);
            $insertStmt->bind_param("ssssiss", 
                $ticket['location'], 
                $ticket['code'], 
                $ticket['date'], 
                $ticket['time'], 
                $ticket['quantity'],
                $ticket['created_at'],
                $ticket['updated_at']
            );
            
            if ($insertStmt->execute()) {
                $successful++;
                echo "<span style='color: green;'>✓</span> Migrated: {$ticket['code']}<br>";
            } else {
                $failed++;
                echo "<span style='color: red;'>✗</span> Failed: {$ticket['code']} - " . $insertStmt->error . "<br>";
            }
            $insertStmt->close();
        }
        
        echo "<h2>Migration Summary</h2>";
        echo "<p><strong>Successful:</strong> $successful tickets</p>";
        echo "<p><strong>Failed:</strong> $failed tickets</p>";
        
    } else {
        echo "<p>No tickets found in source database</p>";
    }
    
    $source_conn->close();
}

// Final check
$result = $target_conn->query("SELECT COUNT(*) as total FROM tickets");
$row = $result->fetch_assoc();
echo "<p><strong>Total tickets in target database:</strong> " . $row['total'] . "</p>";

$target_conn->close();

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Test the debug script: <a href='debug_tickets.php'>debug_tickets.php</a></li>";
echo "<li>Test the API: <a href='api/tickets'>api/tickets</a></li>";
echo "<li>Check your tickets page: <a href='tickets'>tickets</a></li>";
echo "<li>Delete this migration script for security</li>";
echo "</ol>";
?> 