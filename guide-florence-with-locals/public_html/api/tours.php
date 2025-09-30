<?php
// Enable error reporting and logging for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
error_log("tours.php called: " . 
    $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once 'config.php';

// Create database connection using credentials from config.php
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit();
}

// First, check if the cancelled column exists and add it if it doesn't
$checkColumnQuery = "SHOW COLUMNS FROM tours LIKE 'cancelled'";
$columnResult = $conn->query($checkColumnQuery);

// If the cancelled column doesn't exist, create it
if ($columnResult->num_rows === 0) {
    $addColumnQuery = "ALTER TABLE tours ADD COLUMN cancelled TINYINT(1) DEFAULT 0";
    if (!$conn->query($addColumnQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(["error" => "Failed to add cancelled column: " . $conn->error]);
        exit();
    }
}

// Check if the booking_channel column exists and add it if it doesn't
$checkBookingChannelQuery = "SHOW COLUMNS FROM tours LIKE 'booking_channel'";
$bookingChannelResult = $conn->query($checkBookingChannelQuery);

// If the booking_channel column doesn't exist, create it
if ($bookingChannelResult->num_rows === 0) {
    $addBookingChannelQuery = "ALTER TABLE tours ADD COLUMN booking_channel VARCHAR(255) DEFAULT NULL";
    if (!$conn->query($addBookingChannelQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(["error" => "Failed to add booking_channel column: " . $conn->error]);
        exit();
    }
}

// Check if the notes column exists and add it if it doesn't
$checkNotesQuery = "SHOW COLUMNS FROM tours LIKE 'notes'";
$notesResult = $conn->query($checkNotesQuery);

// If the notes column doesn't exist, create it
if ($notesResult->num_rows === 0) {
    $addNotesQuery = "ALTER TABLE tours ADD COLUMN notes TEXT DEFAULT NULL";
    if (!$conn->query($addNotesQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(["error" => "Failed to add notes column: " . $conn->error]);
        exit();
    }
}

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];

// Get tour ID from the URL if provided
$tourId = null;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathSegments = explode('/', $path);
$lastSegment = end($pathSegments);

// If the last segment is numeric, it's probably a tour ID
if (is_numeric($lastSegment)) {
    $tourId = $lastSegment;
}

// Handle requests based on method
switch ($method) {
    case 'GET':
        // Get all tours with guide names and payment information
        $sql = "SELECT t.*, g.name as guide_name
                FROM tours t
                LEFT JOIN guides g ON t.guide_id = g.id
                ORDER BY t.date ASC, t.time ASC";

        $result = $conn->query($sql);

        if ($result) {
            $tours = [];
            while ($row = $result->fetch_assoc()) {
                // Ensure ID is an integer
                $row['id'] = intval($row['id']);

                // Convert paid to boolean for frontend (backward compatibility)
                $row['paid'] = isset($row['paid']) && $row['paid'] == 1 ? true : false;

                // Convert cancelled to boolean for frontend
                $row['cancelled'] = isset($row['cancelled']) && $row['cancelled'] == 1 ? true : false;

                // Format payment-related fields
                if (isset($row['total_amount_paid'])) {
                    $row['total_amount_paid'] = floatval($row['total_amount_paid']);
                }
                if (isset($row['expected_amount'])) {
                    $row['expected_amount'] = $row['expected_amount'] ? floatval($row['expected_amount']) : null;
                }

                // Ensure payment_status has a default value
                if (!isset($row['payment_status'])) {
                    $row['payment_status'] = 'unpaid';
                }

                $tours[] = $row;
            }
            echo json_encode($tours);
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            echo json_encode(["error" => "Failed to get tours: " . $conn->error]);
        }
        break;
        
    case 'POST':
        // Create a new tour
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Check for required fields
        if (!isset($data['title']) || !isset($data['date']) || !isset($data['time']) || !isset($data['guideId'])) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Missing required fields"]);
            break;
        }
        
        // Set paid status if provided, default to 0 (unpaid)
        $paid = isset($data['paid']) && $data['paid'] ? 1 : 0;
        
        // Set cancelled status if provided, default to 0 (not cancelled)
        $cancelled = isset($data['cancelled']) && $data['cancelled'] ? 1 : 0;
        
        // Set booking channel if provided
        $bookingChannel = isset($data['bookingChannel']) ? $data['bookingChannel'] : null;

        // Set payment-related fields
        $expectedAmount = isset($data['expectedAmount']) ? $data['expectedAmount'] : null;
        $paymentNotes = isset($data['paymentNotes']) ? $data['paymentNotes'] : null;
        $paymentStatus = isset($data['paymentStatus']) ? $data['paymentStatus'] : 'unpaid';

        // Validate payment status
        $validPaymentStatuses = ['unpaid', 'partial', 'paid', 'overpaid'];
        if (!in_array($paymentStatus, $validPaymentStatuses)) {
            $paymentStatus = 'unpaid';
        }

        // Get guide name for the response
        $guideStmt = $conn->prepare("SELECT name FROM guides WHERE id = ?");
        $guideStmt->bind_param("i", $data['guideId']);
        $guideStmt->execute();
        $guideResult = $guideStmt->get_result();
        $guideName = '';

        if ($guideRow = $guideResult->fetch_assoc()) {
            $guideName = $guideRow['name'];
        }

        // Insert the tour with enhanced payment fields
        $stmt = $conn->prepare("INSERT INTO tours (title, duration, description, date, time, guide_id, booking_channel, paid, cancelled, payment_status, expected_amount, payment_notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("sssssisiisds",
            $data['title'],
            $data['duration'],
            $data['description'],
            $data['date'],
            $data['time'],
            $data['guideId'],
            $bookingChannel,
            $paid,
            $cancelled,
            $paymentStatus,
            $expectedAmount,
            $paymentNotes
        );
        
        if ($stmt->execute()) {
            $newTourId = $stmt->insert_id;
            
            // Return the created tour
            $newTour = [
                'id' => $newTourId,
                'title' => $data['title'],
                'duration' => $data['duration'],
                'description' => $data['description'],
                'date' => $data['date'],
                'time' => $data['time'],
                'guide_id' => $data['guideId'],
                'guide_name' => $guideName,
                'booking_channel' => $bookingChannel,
                'paid' => (bool)$paid,
                'cancelled' => (bool)$cancelled,
                'payment_status' => $paymentStatus,
                'total_amount_paid' => 0.00,
                'expected_amount' => $expectedAmount ? floatval($expectedAmount) : null,
                'payment_notes' => $paymentNotes,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode($newTour);
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            echo json_encode(["error" => "Failed to create tour: " . $stmt->error]);
        }
        break;
        
    case 'PUT':
        // Update a tour
        if (!$tourId) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Tour ID is required for update"]);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Build SET clause for the SQL statement based on provided fields
        $setFields = [];
        $bindTypes = "";
        $bindValues = [];
        
        if (isset($data['title'])) {
            $setFields[] = "title = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['title'];
        }
        
        if (isset($data['duration'])) {
            $setFields[] = "duration = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['duration'];
        }
        
        if (isset($data['description'])) {
            $setFields[] = "description = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['description'];
        }
        
        if (isset($data['date'])) {
            $setFields[] = "date = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['date'];
        }
        
        if (isset($data['time'])) {
            $setFields[] = "time = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['time'];
        }
        
        if (isset($data['guideId'])) {
            $setFields[] = "guide_id = ?";
            // Convert empty string to NULL for database
            if ($data['guideId'] === '' || $data['guideId'] === null) {
                $bindTypes .= "s";
                $bindValues[] = null;
            } else {
                $bindTypes .= "i";
                $bindValues[] = $data['guideId'];
            }
        }
        
        if (isset($data['booking_channel'])) {
            $setFields[] = "booking_channel = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['booking_channel'];
        }
        
        if (isset($data['paid'])) {
            $setFields[] = "paid = ?";
            $bindTypes .= "i";
            $bindValues[] = $data['paid'] ? 1 : 0;
        }
        
        if (isset($data['cancelled'])) {
            $setFields[] = "cancelled = ?";
            $bindTypes .= "i";
            $bindValues[] = $data['cancelled'] ? 1 : 0;
        }

        // Enhanced payment fields
        if (isset($data['payment_status'])) {
            $validPaymentStatuses = ['unpaid', 'partial', 'paid', 'overpaid'];
            if (in_array($data['payment_status'], $validPaymentStatuses)) {
                $setFields[] = "payment_status = ?";
                $bindTypes .= "s";
                $bindValues[] = $data['payment_status'];
            }
        }

        if (isset($data['expected_amount'])) {
            $setFields[] = "expected_amount = ?";
            $bindTypes .= "d";
            $bindValues[] = $data['expected_amount'] ? floatval($data['expected_amount']) : null;
        }

        if (isset($data['payment_notes'])) {
            $setFields[] = "payment_notes = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['payment_notes'];
        }

        // Always update the updated_at timestamp
        $setFields[] = "updated_at = NOW()";
        
        // If no fields to update, return error
        if (count($setFields) === 1) { // Only updated_at
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "No fields to update"]);
            break;
        }
        
        // Build the SQL statement
        $sql = "UPDATE tours SET " . implode(", ", $setFields) . " WHERE id = ?";
        $bindTypes .= "i";
        $bindValues[] = $tourId;
        
        $stmt = $conn->prepare($sql);
        
        // Bind parameters dynamically
        $bindParams = array_merge([$bindTypes], $bindValues);
        $stmt->bind_param(...$bindParams);
        
        if ($stmt->execute()) {
            // Check if the tour was found and updated
            if ($stmt->affected_rows > 0) {
                // Fetch the updated tour
                $selectStmt = $conn->prepare("SELECT t.*, g.name as guide_name FROM tours t LEFT JOIN guides g ON t.guide_id = g.id WHERE t.id = ?");
                $selectStmt->bind_param("i", $tourId);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                
                if ($tour = $result->fetch_assoc()) {
                    // Convert paid to boolean for frontend (backward compatibility)
                    if (isset($tour['paid'])) {
                        $tour['paid'] = (bool)$tour['paid'];
                    } else {
                        $tour['paid'] = false;
                    }

                    // Convert cancelled to boolean for frontend
                    if (isset($tour['cancelled'])) {
                        $tour['cancelled'] = (bool)$tour['cancelled'];
                    } else {
                        $tour['cancelled'] = false;
                    }

                    // Format payment-related fields
                    if (isset($tour['total_amount_paid'])) {
                        $tour['total_amount_paid'] = floatval($tour['total_amount_paid']);
                    }
                    if (isset($tour['expected_amount'])) {
                        $tour['expected_amount'] = $tour['expected_amount'] ? floatval($tour['expected_amount']) : null;
                    }

                    // Ensure payment_status has a default value
                    if (!isset($tour['payment_status'])) {
                        $tour['payment_status'] = 'unpaid';
                    }

                    echo json_encode($tour);
                } else {
                    header("HTTP/1.1 404 Not Found");
                    echo json_encode(["error" => "Tour with ID $tourId not found"]);
                }
            } else {
                header("HTTP/1.1 404 Not Found");
                echo json_encode(["error" => "Tour with ID $tourId not found"]);
            }
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            echo json_encode(["error" => "Failed to update tour: " . $stmt->error]);
        }
        break;
        
    case 'DELETE':
        // Delete a tour
        if (!$tourId) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Tour ID is required for deletion"]);
            break;
        }
        
        $stmt = $conn->prepare("DELETE FROM tours WHERE id = ?");
        $stmt->bind_param("i", $tourId);
        
        if ($stmt->execute()) {
            // Check if the tour was found and deleted
            if ($stmt->affected_rows > 0) {
                echo json_encode(["success" => true, "message" => "Tour deleted successfully"]);
            } else {
                header("HTTP/1.1 404 Not Found");
                echo json_encode(["error" => "Tour with ID $tourId not found"]);
            }
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            echo json_encode(["error" => "Failed to delete tour: " . $stmt->error]);
        }
        break;
        
    default:
        header("HTTP/1.1 405 Method Not Allowed");
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

// Close the database connection
$conn->close();
?>