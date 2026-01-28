<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the config file for database credentials
require_once __DIR__ . '/config.php';

// Apply rate limiting based on HTTP method
autoRateLimit('tickets');

// The headers are already set in config.php, but we need JSON specifically
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create tickets table if it doesn't exist
function createTicketsTableIfNotExists($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS tickets (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        location VARCHAR(255) NOT NULL DEFAULT '',
        museum VARCHAR(255) NOT NULL DEFAULT '',
        ticket_type VARCHAR(255) NOT NULL DEFAULT '',
        date DATE NOT NULL,
        time TIME DEFAULT NULL,
        quantity INT(11) NOT NULL DEFAULT 0,
        price DECIMAL(10,2) DEFAULT 0.00,
        notes TEXT DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";

    if (!$conn->query($sql)) {
        throw new Exception("Error creating table: " . $conn->error);
    }

    // Ensure all required columns exist (for existing tables)
    $requiredColumns = [
        'location' => "ALTER TABLE tickets ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT '' AFTER id",
        'museum' => "ALTER TABLE tickets ADD COLUMN museum VARCHAR(255) NOT NULL DEFAULT '' AFTER location",
        'ticket_type' => "ALTER TABLE tickets ADD COLUMN ticket_type VARCHAR(255) NOT NULL DEFAULT '' AFTER museum",
        'time' => "ALTER TABLE tickets ADD COLUMN time TIME DEFAULT NULL AFTER date",
        'price' => "ALTER TABLE tickets ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00 AFTER quantity",
        'notes' => "ALTER TABLE tickets ADD COLUMN notes TEXT DEFAULT NULL AFTER price",
        'status' => "ALTER TABLE tickets ADD COLUMN status VARCHAR(50) DEFAULT 'available' AFTER notes"
    ];

    foreach ($requiredColumns as $column => $alterSql) {
        $checkColumn = "SHOW COLUMNS FROM tickets LIKE '$column'";
        $result = $conn->query($checkColumn);
        if ($result && $result->num_rows == 0) {
            if (!$conn->query($alterSql)) {
                error_log("Could not add $column column: " . $conn->error);
            }
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

        // Validate required fields - support both old (code) and new (museum/ticket_type) schema
        $hasOldSchema = isset($data['code']);
        $hasNewSchema = isset($data['museum']) || isset($data['ticket_type']);

        if (!isset($data['location']) || !isset($data['date']) || !isset($data['quantity'])) {
            echo json_encode(array(
                "success" => false,
                "message" => "Missing required fields (location, date, quantity)"
            ));
            exit();
        }

        $location = $data['location'];
        // Support backward compatibility: if 'code' is sent, use it as museum
        $museum = $data['museum'] ?? $data['code'] ?? '';
        $ticket_type = $data['ticket_type'] ?? '';
        $date = $data['date'];
        $time = isset($data['time']) && !empty($data['time']) ? $data['time'] : null;
        $quantity = intval($data['quantity']);
        $price = isset($data['price']) ? floatval($data['price']) : 0.00;
        $notes = $data['notes'] ?? null;
        $status = $data['status'] ?? 'available';

        // Use prepared statement for INSERT
        $stmt = $conn->prepare("INSERT INTO tickets (location, museum, ticket_type, date, time, quantity, price, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssidss", $location, $museum, $ticket_type, $date, $time, $quantity, $price, $notes, $status);

        if ($stmt->execute()) {
            $insertId = $conn->insert_id;
            $stmt->close();

            // Fetch the newly created ticket using prepared statement
            $selectStmt = $conn->prepare("SELECT * FROM tickets WHERE id = ?");
            $selectStmt->bind_param("i", $insertId);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $newTicket = $result->fetch_assoc();
            $selectStmt->close();

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
                "message" => "Error adding ticket: " . $stmt->error
            ));
            $stmt->close();
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
        if (!isset($data['location']) || !isset($data['date']) || !isset($data['quantity'])) {
            echo json_encode(array(
                "success" => false,
                "message" => "Missing required fields (location, date, quantity)"
            ));
            exit();
        }

        $location = $data['location'];
        // Support backward compatibility: if 'code' is sent, use it as museum
        $museum = $data['museum'] ?? $data['code'] ?? '';
        $ticket_type = $data['ticket_type'] ?? '';
        $date = $data['date'];
        $time = isset($data['time']) && !empty($data['time']) ? $data['time'] : null;
        $quantity = intval($data['quantity']);
        $price = isset($data['price']) ? floatval($data['price']) : 0.00;
        $notes = $data['notes'] ?? null;
        $status = $data['status'] ?? 'available';

        // Use prepared statement for UPDATE
        $stmt = $conn->prepare("UPDATE tickets SET location = ?, museum = ?, ticket_type = ?, date = ?, time = ?, quantity = ?, price = ?, notes = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssssidssi", $location, $museum, $ticket_type, $date, $time, $quantity, $price, $notes, $status, $id);

        if ($stmt->execute()) {
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            if ($affectedRows > 0 || $conn->errno === 0) {
                // Fetch the updated ticket using prepared statement
                $selectStmt = $conn->prepare("SELECT * FROM tickets WHERE id = ?");
                $selectStmt->bind_param("i", $id);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $updatedTicket = $result->fetch_assoc();
                $selectStmt->close();

                if ($updatedTicket) {
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
                        "message" => "Ticket not found"
                    ));
                }
            } else {
                echo json_encode(array(
                    "success" => false,
                    "message" => "Ticket not found or no changes made"
                ));
            }
        } else {
            echo json_encode(array(
                "success" => false,
                "message" => "Error updating ticket: " . $stmt->error
            ));
            $stmt->close();
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

        // Use prepared statement for DELETE
        $stmt = $conn->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            if ($affectedRows > 0) {
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
                "message" => "Error deleting ticket: " . $stmt->error
            ));
            $stmt->close();
        }
        break;
        
    default:
        echo json_encode(array(
            "success" => false,
            "message" => "Method not allowed"
        ));
        break;
}

// Connection close is handled in config.php
?> 