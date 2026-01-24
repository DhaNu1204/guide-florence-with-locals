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

// Add a new guide - SECURITY: Using prepared statements to prevent SQL injection
else if ($method === 'POST') {
    // Get POST data
    $data = json_decode($raw_data, true);

    error_log("Decoded JSON data for guide: " . print_r($data, true));

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data']);
        exit();
    }

    // Extract and sanitize guide data
    $name = trim($data['name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $languages = trim($data['languages'] ?? '');
    $bio = trim($data['bio'] ?? '');
    $photo_url = trim($data['photo_url'] ?? '');

    // Input validation
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name is required']);
        exit();
    }

    if (strlen($name) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'Name too long (max 255 characters)']);
        exit();
    }

    // Validate email format if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email format']);
        exit();
    }

    // Check if we're updating an existing guide
    if (isset($data['id']) && !empty($data['id'])) {
        $id = intval($data['id']);

        // SECURITY: Update using prepared statement
        $stmt = $conn->prepare("UPDATE guides SET name = ?, phone = ?, email = ?, languages = ?, bio = ?, photo_url = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $name, $phone, $email, $languages, $bio, $photo_url, $id);

        if ($stmt->execute()) {
            // Return the updated guide using prepared statement
            $stmt = $conn->prepare("SELECT * FROM guides WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $guide = $result->fetch_assoc();

            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update guide']);
            error_log("Guide update error: " . $stmt->error);
        }
    } else {
        // SECURITY: Insert using prepared statement
        $stmt = $conn->prepare("INSERT INTO guides (name, phone, email, languages, bio, photo_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name, $phone, $email, $languages, $bio, $photo_url);

        if ($stmt->execute()) {
            $guide_id = $conn->insert_id;

            // Return the created guide using prepared statement
            $stmt = $conn->prepare("SELECT * FROM guides WHERE id = ?");
            $stmt->bind_param("i", $guide_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $guide = $result->fetch_assoc();

            http_response_code(201); // Created
            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add guide']);
            error_log("Guide insert error: " . $stmt->error);
        }
    }
}

// Delete a guide - SECURITY: Using prepared statements
else if ($method === 'DELETE') {
    // Extract ID from URL
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathSegments = explode('/', rtrim($path, '/'));
    $id = intval(end($pathSegments));

    if (!$id || $id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid guide ID']);
        exit();
    }

    error_log("Deleting guide with ID: $id");

    // SECURITY: Check if guide is associated with any tours using prepared statement
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tours WHERE guide_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row['count'] > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete guide with associated tours']);
        exit();
    }

    // SECURITY: Delete the guide using prepared statement
    $stmt = $conn->prepare("DELETE FROM guides WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['message' => 'Guide deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Guide not found']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete guide']);
        error_log("Guide delete error: " . $stmt->error);
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