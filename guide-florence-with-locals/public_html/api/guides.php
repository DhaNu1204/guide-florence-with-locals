<?php
require_once 'config.php';
require_once 'Middleware.php';

// Require authentication for all guide operations
Middleware::requireAuth($conn);

// Apply rate limiting based on HTTP method
autoRateLimit('guides');

// Log request for debugging
$method = $_SERVER['REQUEST_METHOD'];
$raw_data = file_get_contents('php://input');
error_log("API Request to guides.php: Method: $method, Data: $raw_data");

// Get guide ID from the URL path if provided
$guideId = null;
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
if (!empty($pathInfo)) {
    // PATH_INFO will be like "/123"
    $pathSegments = explode('/', trim($pathInfo, '/'));
    if (!empty($pathSegments[0]) && is_numeric($pathSegments[0])) {
        $guideId = intval($pathSegments[0]);
    }
}

// Get all guides with pagination
if ($method === 'GET') {
    // Get pagination parameters from query string
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
    $offset = ($page - 1) * $perPage;

    // Get total count for pagination metadata
    $countSql = "SELECT COUNT(*) as total FROM guides";
    $countResult = $conn->query($countSql);
    $totalRecords = 0;

    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = intval($countRow['total']);
    }

    // Get guides with pagination using prepared statement
    $sql = "SELECT * FROM guides ORDER BY id LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $guides = [];
        while ($row = $result->fetch_assoc()) {
            // Convert languages string to array for frontend consistency
            if (!empty($row['languages'])) {
                $row['languages'] = array_map('trim', explode(',', $row['languages']));
            } else {
                $row['languages'] = [];
            }
            $guides[] = $row;
        }

        // Calculate pagination metadata
        $totalPages = $totalRecords > 0 ? ceil($totalRecords / $perPage) : 0;

        // Return paginated response with metadata
        echo json_encode([
            'data' => $guides,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ]);
    } else {
        http_response_code(500);
        error_log("Failed to fetch guides: " . $conn->error);
        echo json_encode(['error' => 'Failed to fetch guides']);
    }
}

// Add a new guide - SECURITY: Using prepared statements to prevent SQL injection
// POST should only handle new guide creation (updates are handled by PUT)
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

    // Handle languages - can be array or comma-separated string
    $languagesInput = $data['languages'] ?? '';
    if (is_array($languagesInput)) {
        // Convert array to comma-separated string for storage
        $languages = implode(',', array_map('trim', $languagesInput));
    } else {
        $languages = trim($languagesInput);
    }

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

    // SECURITY: Insert using prepared statement with duplicate email handling
    try {
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

            // Convert languages string to array for frontend consistency
            if (!empty($guide['languages'])) {
                $guide['languages'] = array_map('trim', explode(',', $guide['languages']));
            } else {
                $guide['languages'] = [];
            }

            http_response_code(201); // Created
            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add guide']);
            error_log("Guide insert error: " . $stmt->error);
        }
    } catch (mysqli_sql_exception $e) {
        // Handle duplicate email error (MySQL error code 1062)
        if ($e->getCode() == 1062 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            http_response_code(409); // Conflict
            echo json_encode(['error' => 'A guide with this email already exists']);
        } else {
            http_response_code(500);
            error_log("Guide insert error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to add guide']);
        }
    }
}

// Update an existing guide - SECURITY: Using prepared statements
else if ($method === 'PUT') {
    // Validate guide ID from URL path
    if (!$guideId || $guideId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid guide ID is required in URL path (e.g., /api/guides.php/123)']);
        exit();
    }

    // Get PUT data
    $data = json_decode($raw_data, true);

    error_log("Decoded JSON data for guide update: " . print_r($data, true));

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data']);
        exit();
    }

    // Check if guide exists
    $checkStmt = $conn->prepare("SELECT id FROM guides WHERE id = ?");
    $checkStmt->bind_param("i", $guideId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Guide not found']);
        exit();
    }

    // Extract and sanitize guide data
    $name = trim($data['name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');

    // Handle languages - can be array or comma-separated string
    $languagesInput = $data['languages'] ?? '';
    if (is_array($languagesInput)) {
        // Convert array to comma-separated string for storage
        $languages = implode(',', array_map('trim', $languagesInput));
    } else {
        $languages = trim($languagesInput);
    }

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

    // SECURITY: Update using prepared statement with duplicate email handling
    try {
        $stmt = $conn->prepare("UPDATE guides SET name = ?, phone = ?, email = ?, languages = ?, bio = ?, photo_url = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $name, $phone, $email, $languages, $bio, $photo_url, $guideId);

        if ($stmt->execute()) {
            // Return the updated guide using prepared statement
            $stmt = $conn->prepare("SELECT * FROM guides WHERE id = ?");
            $stmt->bind_param("i", $guideId);
            $stmt->execute();
            $result = $stmt->get_result();
            $guide = $result->fetch_assoc();

            // Convert languages string to array for frontend consistency
            if (!empty($guide['languages'])) {
                $guide['languages'] = array_map('trim', explode(',', $guide['languages']));
            } else {
                $guide['languages'] = [];
            }

            echo json_encode($guide);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update guide']);
            error_log("Guide update error: " . $stmt->error);
        }
    } catch (mysqli_sql_exception $e) {
        // Handle duplicate email error (MySQL error code 1062)
        if ($e->getCode() == 1062 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            http_response_code(409); // Conflict
            echo json_encode(['error' => 'Another guide already has this email']);
        } else {
            http_response_code(500);
            error_log("Guide update error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to update guide']);
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