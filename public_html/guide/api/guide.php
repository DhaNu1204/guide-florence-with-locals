<?php
require_once 'config.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log request for debugging
$method = $_SERVER['REQUEST_METHOD'];
$raw_data = file_get_contents('php://input');
error_log("API Request to guides.php: Method: $method, Data: $raw_data");

// Get all guides
if ($method === 'GET') {
    // Add ORDER BY to ensure consistent sorting
    $sql = "SELECT * FROM guides ORDER BY id";
    $result = $conn->query($sql);
    
    if ($result) {
        $guides = [];
        while ($row = $result->fetch_assoc()) {
            $guides[] = $row;
        }
        echo json_encode($guides);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch guides: ' . $conn->error]);
    }
}

// Add a new guide
else if ($method === 'POST') {
    // Get POST data
    $data = json_decode($raw_data, true);
    
    error_log("Decoded JSON data for guide: " . print_r($data, true));
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data']);
        exit();
    }
    
    // Extract guide data with defaults
    $name = $conn->real_escape_string($data['name'] ?? '');
    $phone = $conn->real_escape_string($data['phone'] ?? '');
    $email = $conn->real_escape_string($data['email'] ?? '');
    $languages = $conn->real_escape_string($data['languages'] ?? '');
    $bio = $conn->real_escape_string($data['bio'] ?? '');
    $photo_url = $conn->real_escape_string($data['photo_url'] ?? '');
    
    // Validate required fields
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name is required']);
        exit();
    }
    
    // Check if we're updating an existing guide
    if (isset($data['id']) && !empty($data['id'])) {
        $id = intval($data['id']);
        
        // Update existing guide
        $sql = "UPDATE guides SET name = '$name', phone = '$phone'";
        
        // Conditionally add other fields if they exist
        if (!empty($email)) $sql .= ", email = '$email'";
        if (!empty($languages)) $sql .= ", languages = '$languages'";
        if (!empty($bio)) $sql .= ", bio = '$bio'";
        if (!empty($photo_url)) $sql .= ", photo_url = '$photo_url'";
        
        $sql .= " WHERE id = $id";
        
        if ($conn->query($sql)) {
            // Return the updated guide
            $sql = "SELECT * FROM guides WHERE id = $id";
            $result = $conn->query($sql);
            $guide = $result->fetch_assoc();
            
            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update guide: ' . $conn->error, 'sql' => $sql]);
        }
    } else {
        // Insert new guide
        $sql = "INSERT INTO guides (name, phone";
        $values = "('$name', '$phone'";
        
        // Conditionally add other fields if they exist
        if (!empty($email)) { $sql .= ", email"; $values .= ", '$email'"; }
        if (!empty($languages)) { $sql .= ", languages"; $values .= ", '$languages'"; }
        if (!empty($bio)) { $sql .= ", bio"; $values .= ", '$bio'"; }
        if (!empty($photo_url)) { $sql .= ", photo_url"; $values .= ", '$photo_url'"; }
        
        $sql .= ") VALUES " . $values . ")";
        
        if ($conn->query($sql)) {
            $guide_id = $conn->insert_id;
            
            // Return the created guide
            $sql = "SELECT * FROM guides WHERE id = $guide_id";
            $result = $conn->query($sql);
            $guide = $result->fetch_assoc();
            
            http_response_code(201); // Created
            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add guide: ' . $conn->error, 'sql' => $sql]);
        }
    }
}

// Delete a guide
else if ($method === 'DELETE') {
    // Extract ID from URL
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathSegments = explode('/', rtrim($path, '/'));
    $id = intval(end($pathSegments));
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid guide ID']);
        exit();
    }
    
    error_log("Deleting guide with ID: $id");
    
    // Check if guide is associated with any tours
    $sql = "SELECT COUNT(*) as count FROM tours WHERE guide_id = $id";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete guide with associated tours']);
        exit();
    }
    
    // Delete the guide
    $sql = "DELETE FROM guides WHERE id = $id";
    
    if ($conn->query($sql)) {
        if ($conn->affected_rows > 0) {
            echo json_encode(['message' => 'Guide deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Guide not found']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete guide: ' . $conn->error]);
    }
}

// Method not allowed
else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed']);
}

// Close connection
$conn->close();
?> 