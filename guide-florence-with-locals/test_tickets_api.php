<?php
// Simple test script to check if tickets API is working
echo "<h1>Tickets API Test</h1>";

// Test database connection
echo "<h2>1. Testing Database Connection</h2>";
$host = 'localhost';
$db_name = 'u803853690_guide_florence';
$username = 'u803853690_guidefl';
$password = 'FlorenceGuide2024!';

$conn = new mysqli($host, $username, $password, $db_name);

if ($conn->connect_error) {
    echo "<span style='color: red;'>❌ Database connection failed: " . $conn->connect_error . "</span><br>";
} else {
    echo "<span style='color: green;'>✅ Database connection successful!</span><br>";
    
    // Test if tickets table exists
    echo "<h2>2. Checking Tickets Table</h2>";
    $result = $conn->query("SHOW TABLES LIKE 'tickets'");
    if ($result->num_rows > 0) {
        echo "<span style='color: green;'>✅ Tickets table exists!</span><br>";
        
        // Check table structure
        echo "<h3>Table Structure:</h3>";
        $result = $conn->query("DESCRIBE tickets");
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Count tickets
        echo "<h2>3. Ticket Count</h2>";
        $result = $conn->query("SELECT COUNT(*) as total FROM tickets");
        $row = $result->fetch_assoc();
        echo "<p><strong>Total tickets in database:</strong> {$row['total']}</p>";
        
        if ($row['total'] > 0) {
            // Show sample tickets
            echo "<h2>4. Sample Tickets (First 5)</h2>";
            $result = $conn->query("SELECT * FROM tickets ORDER BY date DESC, time ASC LIMIT 5");
            
            if ($result->num_rows > 0) {
                echo "<table border='1' style='border-collapse: collapse;'>";
                echo "<tr><th>ID</th><th>Location</th><th>Code</th><th>Date</th><th>Time</th><th>Quantity</th></tr>";
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>{$row['id']}</td>";
                    echo "<td>{$row['location']}</td>";
                    echo "<td>{$row['code']}</td>";
                    echo "<td>{$row['date']}</td>";
                    echo "<td>{$row['time']}</td>";
                    echo "<td>{$row['quantity']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        }
        
    } else {
        echo "<span style='color: red;'>❌ Tickets table does not exist!</span><br>";
    }
}

echo "<h2>5. API Endpoint Test</h2>";
$api_url = "https://guide.nextaudioguides.com/api/tickets";
echo "<p>Testing API endpoint: <a href='$api_url' target='_blank'>$api_url</a></p>";

// Test the API call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>API Response:</h3>";
echo "<p><strong>HTTP Code:</strong> $http_code</p>";
echo "<p><strong>Response:</strong></p>";
echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>";
echo htmlspecialchars($response);
echo "</pre>";

if ($http_code === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success'] && isset($data['tickets'])) {
        echo "<span style='color: green;'>✅ API is working correctly!</span><br>";
        echo "<p><strong>Tickets returned by API:</strong> " . count($data['tickets']) . "</p>";
    } else {
        echo "<span style='color: orange;'>⚠️ API responded but format might be incorrect</span><br>";
    }
} else {
    echo "<span style='color: red;'>❌ API call failed</span><br>";
}

$conn->close();

echo "<hr>";
echo "<p><strong>Instructions:</strong></p>";
echo "<ol>";
echo "<li>If all tests pass, your tickets should now be visible at <a href='https://guide.nextaudioguides.com/tickets' target='_blank'>https://guide.nextaudioguides.com/tickets</a></li>";
echo "<li>If there are errors, check the database connection and API endpoint</li>";
echo "<li>Delete this test file after use for security</li>";
echo "</ol>";
?> 