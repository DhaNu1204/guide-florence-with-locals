<?php
echo "=== PHP Debug Test ===<br><br>";

echo "1. PHP is working: ✅<br>";
echo "PHP Version: " . phpversion() . "<br><br>";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo "2. Error reporting enabled: ✅<br><br>";

echo "3. Testing Database Connection:<br>";
$host = 'localhost';
$db_name = 'u803853690_guide_florence';
$username = 'u803853690_guidefl';
$password = 'FlorenceGuide2024!';

echo "Host: $host<br>";
echo "Database: $db_name<br>";
echo "Username: $username<br><br>";

try {
    $conn = new mysqli($host, $username, $password, $db_name);
    
    if ($conn->connect_error) {
        echo "❌ Connection failed: " . $conn->connect_error . "<br>";
    } else {
        echo "✅ Database connection successful!<br><br>";
        
        echo "4. Checking tickets table:<br>";
        $result = $conn->query("SHOW TABLES LIKE 'tickets'");
        if ($result && $result->num_rows > 0) {
            echo "✅ Tickets table exists<br>";
            
            $result = $conn->query("SELECT COUNT(*) as total FROM tickets");
            if ($result) {
                $row = $result->fetch_assoc();
                echo "✅ Ticket count: " . $row['total'] . "<br><br>";
                
                if ($row['total'] > 0) {
                    echo "5. Sample ticket data:<br>";
                    $result = $conn->query("SELECT * FROM tickets LIMIT 1");
                    if ($result && $result->num_rows > 0) {
                        $ticket = $result->fetch_assoc();
                        echo "<pre>";
                        print_r($ticket);
                        echo "</pre>";
                    }
                }
            } else {
                echo "❌ Error counting tickets: " . $conn->error . "<br>";
            }
        } else {
            echo "❌ Tickets table does not exist<br>";
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}

echo "<br>=== End Debug Test ===<br>";
?> 