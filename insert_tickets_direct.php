<?php
echo "<h1>Direct Ticket Insert</h1>";

// Include the existing config to use the same credentials
require_once 'api/config.php';

echo "<p>Using database: $db_name</p>";
echo "<p>Using username: $db_user</p>";

// Check if tickets table exists, if not create it
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

if ($conn->query($createTableSQL)) {
    echo "✅ Tickets table ready<br><br>";
} else {
    echo "❌ Error with table: " . $conn->error . "<br>";
    exit();
}

// Check current ticket count
$result = $conn->query("SELECT COUNT(*) as total FROM tickets");
$row = $result->fetch_assoc();
echo "<p>Current tickets in database: " . $row['total'] . "</p>";

if ($row['total'] > 0) {
    echo "<p>⚠️ Tickets already exist. Showing first 5:</p>";
    $result = $conn->query("SELECT * FROM tickets LIMIT 5");
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Location</th><th>Code</th><th>Date</th><th>Time</th><th>Quantity</th></tr>";
    while ($ticket = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$ticket['id']}</td>";
        echo "<td>{$ticket['location']}</td>";
        echo "<td>{$ticket['code']}</td>";
        echo "<td>{$ticket['date']}</td>";
        echo "<td>{$ticket['time']}</td>";
        echo "<td>{$ticket['quantity']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<br><a href='api/tickets'>Check API Response</a> | ";
    echo "<a href='tickets'>View Tickets Page</a>";
} else {
    echo "<h2>Inserting Tickets...</h2>";
    
    // All 47 tickets data
    $tickets_sql = "INSERT INTO tickets (location, code, date, time, quantity) VALUES
    ('Accademia', '18124827', '2025-06-12', '14:45:00', 10),
    ('Accademia', '18124833', '2025-06-12', '16:30:00', 10),
    ('Accademia', '18124835', '2025-06-12', '17:00:00', 10),
    ('Accademia', '18124840', '2025-06-13', '14:45:00', 10),
    ('Accademia', '18124844', '2025-06-13', '16:30:00', 10),
    ('Accademia', '18124847', '2025-06-13', '17:00:00', 10),
    ('Accademia', '18124851', '2025-06-14', '14:45:00', 10),
    ('Accademia', '18124853', '2025-06-14', '16:30:00', 10),
    ('Accademia', '18124859', '2025-06-14', '17:15:00', 10),
    ('Accademia', '18124866', '2025-06-15', '14:45:00', 10),
    ('Accademia', '18124871', '2025-06-15', '16:30:00', 10),
    ('Accademia', '18124876', '2025-06-15', '17:00:00', 10),
    ('Accademia', '18124882', '2025-06-17', '14:45:00', 10),
    ('Accademia', '18124888', '2025-06-17', '16:30:00', 10),
    ('Accademia', '18124893', '2025-06-17', '17:00:00', 10),
    ('Accademia', '18124900', '2025-06-18', '14:45:00', 10),
    ('Accademia', '18124909', '2025-06-18', '16:30:00', 10),
    ('Accademia', '18124915', '2025-06-18', '17:00:00', 10),
    ('Accademia', '18124918', '2025-06-19', '14:45:00', 10),
    ('Accademia', '18124927', '2025-06-19', '16:30:00', 10),
    ('Accademia', '18124930', '2025-06-19', '17:00:00', 10),
    ('Accademia', '18124934', '2025-06-20', '14:45:00', 10),
    ('Accademia', '18124936', '2025-06-20', '16:30:00', 10),
    ('Accademia', '18124939', '2025-06-20', '17:00:00', 10),
    ('Accademia', '18124945', '2025-06-21', '14:45:00', 10),
    ('Accademia', '18124948', '2025-06-21', '16:30:00', 10),
    ('Accademia', '18124951', '2025-06-21', '17:00:00', 10),
    ('Accademia', '18125013', '2025-06-22', '14:45:00', 10),
    ('Accademia', '18125017', '2025-06-22', '16:30:00', 10),
    ('Accademia', '18125019', '2025-06-22', '17:00:00', 10),
    ('Accademia', '18125025', '2025-06-24', '14:45:00', 10),
    ('Accademia', '18125031', '2025-06-24', '16:30:00', 10),
    ('Accademia', '18125035', '2025-06-24', '17:00:00', 10),
    ('Accademia', '18125045', '2025-06-25', '14:45:00', 10),
    ('Accademia', '18125047', '2025-06-25', '16:30:00', 10),
    ('Accademia', '18125051', '2025-06-25', '17:00:00', 10),
    ('Accademia', '18125055', '2025-06-26', '14:45:00', 10),
    ('Accademia', '18125058', '2025-06-26', '16:30:00', 10),
    ('Accademia', '18125060', '2025-06-26', '17:00:00', 10),
    ('Accademia', '18125063', '2025-06-27', '14:45:00', 10),
    ('Accademia', '18125066', '2025-06-27', '16:30:00', 10),
    ('Accademia', '18125069', '2025-06-27', '17:00:00', 10),
    ('Accademia', '18125072', '2025-06-28', '15:15:00', 10),
    ('Accademia', '18125073', '2025-06-28', '16:30:00', 10),
    ('Accademia', '18125074', '2025-06-28', '17:00:00', 10),
    ('Accademia', '18125078', '2025-06-29', '14:45:00', 10),
    ('Accademia', '18125080', '2025-06-29', '16:30:00', 10),
    ('Accademia', '18125084', '2025-06-29', '17:00:00', 10)";
    
    if ($conn->query($tickets_sql)) {
        echo "✅ Successfully inserted " . $conn->affected_rows . " tickets!<br><br>";
        
        // Show the inserted tickets
        $result = $conn->query("SELECT * FROM tickets LIMIT 5");
        echo "<h3>First 5 tickets inserted:</h3>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Location</th><th>Code</th><th>Date</th><th>Time</th><th>Quantity</th></tr>";
        while ($ticket = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$ticket['id']}</td>";
            echo "<td>{$ticket['location']}</td>";
            echo "<td>{$ticket['code']}</td>";
            echo "<td>{$ticket['date']}</td>";
            echo "<td>{$ticket['time']}</td>";
            echo "<td>{$ticket['quantity']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<br><h3>Next Steps:</h3>";
        echo "<a href='api/tickets' class='button'>Check API Response</a> | ";
        echo "<a href='tickets' class='button'>View Tickets Page</a>";
        
    } else {
        echo "❌ Error inserting tickets: " . $conn->error . "<br>";
    }
}

$conn->close();
?> 