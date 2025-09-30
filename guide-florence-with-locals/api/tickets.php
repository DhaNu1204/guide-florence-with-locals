<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
$host = 'localhost';
$db_name = 'u803853690_guide_florence';
$username = 'u803853690_guidefl';
$password = 'FlorenceGuide2024!';

// Create connection
$conn = new mysqli($host, $username, $password, $db_name);

// Check connection
if ($conn->connect_error) {
    echo json_encode(array(
        "success" => false,
        "message" => "Connection failed: " . $conn->connect_error
    ));
    exit();
}

// Set charset
$conn->set_charset("utf8mb4");

// Create tickets table if it doesn't exist
function createTicketsTableIfNotExists($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS tickets (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        location VARCHAR(255) NOT NULL,
        code VARCHAR(255) NOT NULL,
        date DATE NOT NULL,
        time TIME DEFAULT NULL,
        quantity INT(11) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if (!$conn->query($sql)) {
        throw new Exception("Error creating table: " . $conn->error);
    }
    
    // Check if time column exists, if not add it
    $checkColumn = "SHOW COLUMNS FROM tickets LIKE 'time'";
    $result = $conn->query($checkColumn);
    if ($result->num_rows == 0) {
        $addColumn = "ALTER TABLE tickets ADD COLUMN time TIME DEFAULT NULL AFTER date";
        if (!$conn->query($addColumn)) {
            // Column might already exist or error
            error_log("Could not add time column: " . $conn->error);
        }
    }
}

// Create table if it doesn't exist
try {
    createTicketsTableIfNotExists($conn);
} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Database initialization error: " . $e->getMessage()
    ));
    exit();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        // Get all tickets
        $sql = "SELECT * FROM tickets ORDER BY date DESC, time ASC";
        $result = $conn->query($sql);
        
        if ($result) {
            $tickets = array();
            while($row = $result->fetch_assoc()) {
                // Convert date to YYYY-MM-DD format
                $row['date'] = date('Y-m-d', strtotime($row['date']));
                // Convert time to HH:MM format if not null
                if ($row['time']) {
                    $row['time'] = date('H:i', strtotime($row['time']));
                }
                $tickets[] = $row;
            }
            
            echo json_encode(array(
                "success" => true,
                "tickets" => $tickets
            ));
        } else {
            echo json_encode(array(
                "success" => false,
                "message" => "Error fetching tickets: " . $conn->error
            ));
        }
        break;
        
    case 'POST':
        // Add new ticket
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validate required fields
        if (!isset($data['location']) || !isset($data['code']) || 
            !isset($data['date']) || !isset($data['quantity'])) {
            echo json_encode(array(
                "success" => false,
                "message" => "Missing required fields"
            ));
            exit();
        }
        
        $location = $conn->real_escape_string($data['location']);
        $code = $conn->real_escape_string($data['code']);
        $date = $conn->real_escape_string($data['date']);
        $time = isset($data['time']) ? $conn->real_escape_string($data['time']) : null;
        $quantity = intval($data['quantity']);
        
        if ($time) {
            $sql = "INSERT INTO tickets (location, code, date, time, quantity) 
                    VALUES ('$location', '$code', '$date', '$time', $quantity)";
        } else {
            $sql = "INSERT INTO tickets (location, code, date, quantity) 
                    VALUES ('$location', '$code', '$date', $quantity)";
        }
        
        if ($conn->query($sql)) {
            $insertId = $conn->insert_id;
            
            // Fetch the newly created ticket
            $selectSql = "SELECT * FROM tickets WHERE id = $insertId";
            $result = $conn->query($selectSql);
            $newTicket = $result->fetch_assoc();
            
            // Format the response
            $newTicket['date'] = date('Y-m-d', strtotime($newTicket['date']));
            if ($newTicket['time']) {
                $newTicket['time'] = date('H:i', strtotime($newTicket['time']));
            }
            
            echo json_encode(array(
                "success" => true,
                "message" => "Ticket added successfully",
                "ticket" => $newTicket
            ));
        } else {
            echo json_encode(array(
                "success" => false,
                "message" => "Error adding ticket: " . $conn->error
            ));
        }
        break;
        
    case 'PUT':
        // Update ticket
        // Extract ID from URL
        $uri = $_SERVER['REQUEST_URI'];
        $uri_parts = explode('/', $uri);
        $id = end($uri_parts);
        
        if (!is_numeric($id)) {
            echo json_encode(array(
                "success" => false,
                "message" => "Invalid ticket ID"
            ));
            exit();
        }
        
        $id = intval($id);
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validate required fields
        if (!isset($data['location']) || !isset($data['code']) || 
            !isset($data['date']) || !isset($data['quantity'])) {
            echo json_encode(array(
                "success" => false,
                "message" => "Missing required fields"
            ));
            exit();
        }
        
        $location = $conn->real_escape_string($data['location']);
        $code = $conn->real_escape_string($data['code']);
        $date = $conn->real_escape_string($data['date']);
        $time = isset($data['time']) ? $conn->real_escape_string($data['time']) : null;
        $quantity = intval($data['quantity']);
        
        if ($time) {
            $sql = "UPDATE tickets SET 
                    location = '$location',
                    code = '$code',
                    date = '$date',
                    time = '$time',
                    quantity = $quantity
                    WHERE id = $id";
        } else {
            $sql = "UPDATE tickets SET 
                    location = '$location',
                    code = '$code',
                    date = '$date',
                    time = NULL,
                    quantity = $quantity
                    WHERE id = $id";
        }
        
        if ($conn->query($sql)) {
            if ($conn->affected_rows > 0) {
                // Fetch the updated ticket
                $selectSql = "SELECT * FROM tickets WHERE id = $id";
                $result = $conn->query($selectSql);
                $updatedTicket = $result->fetch_assoc();
                
                // Format the response
                $updatedTicket['date'] = date('Y-m-d', strtotime($updatedTicket['date']));
                if ($updatedTicket['time']) {
                    $updatedTicket['time'] = date('H:i', strtotime($updatedTicket['time']));
                }
                
                echo json_encode(array(
                    "success" => true,
                    "message" => "Ticket updated successfully",
                    "ticket" => $updatedTicket
                ));
            } else {
                echo json_encode(array(
                    "success" => false,
                    "message" => "Ticket not found or no changes made"
                ));
            }
        } else {
            echo json_encode(array(
                "success" => false,
                "message" => "Error updating ticket: " . $conn->error
            ));
        }
        break;
        
    case 'DELETE':
        // Delete ticket
        // Extract ID from URL
        $uri = $_SERVER['REQUEST_URI'];
        $uri_parts = explode('/', $uri);
        $id = end($uri_parts);
        
        if (!is_numeric($id)) {
            echo json_encode(array(
                "success" => false,
                "message" => "Invalid ticket ID"
            ));
            exit();
        }
        
        $id = intval($id);
        $sql = "DELETE FROM tickets WHERE id = $id";
        
        if ($conn->query($sql)) {
            if ($conn->affected_rows > 0) {
                echo json_encode(array(
                    "success" => true,
                    "message" => "Ticket deleted successfully"
                ));
            } else {
                echo json_encode(array(
                    "success" => false,
                    "message" => "Ticket not found"
                ));
            }
        } else {
            echo json_encode(array(
                "success" => false,
                "message" => "Error deleting ticket: " . $conn->error
            ));
        }
        break;
        
    default:
        echo json_encode(array(
            "success" => false,
            "message" => "Method not allowed"
        ));
        break;
}

$conn->close();
?> 