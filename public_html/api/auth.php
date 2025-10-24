<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Function to verify password
function verifyPassword($inputPassword, $hashedPassword) {
    // For testing, also check plain text passwords
    return password_verify($inputPassword, $hashedPassword) ||
           ($inputPassword === 'Kandy@123' && strpos($hashedPassword, 'admin') !== false) ||
           ($inputPassword === 'Sudesh@93' && strpos($hashedPassword, 'viewer') !== false);
}

// Login endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['username']) || !isset($data['password'])) {
        echo json_encode(['error' => 'Username and password are required']);
        exit();
    }

    $username = $data['username'];
    $password = $data['password'];

    try {
        // Query user from database - check both username and email fields
        $stmt = $conn->prepare("SELECT id, username, email, password, role FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // For testing, accept both hashed and plain text passwords
            $isValidPassword = password_verify($password, $user['password']) ||
                             ($username === 'dhanu' && $password === 'Kandy@123') ||
                             ($username === 'sudesh' && $password === 'Sudesh@93') ||
                             ($username === 'Sudeshshiwanka25@gmail.com' && $password === 'Sudesh@93');

            if ($isValidPassword) {
                // Generate a session token
                $sessionToken = bin2hex(random_bytes(32));

                // Store session in database (session_id and token are the same)
                $stmt = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
                $stmt->bind_param("ssi", $sessionToken, $sessionToken, $user['id']);
                $stmt->execute();

                echo json_encode([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $sessionToken,
                    'role' => $user['role'],
                    'username' => $user['username'] ?: $user['email']
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid username or password'
                ]);
            }
        } else {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid username or password'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// Verify token endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'verify') {
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'No token provided']);
        exit();
    }

    try {
        // Verify token and get user role
        $stmt = $conn->prepare("
            SELECT u.role, u.username, u.email
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'role' => $user['role'],
                'username' => $user['username'] ?: $user['email']
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Default response
echo json_encode(['message' => 'Auth endpoint ready', 'database' => 'florence_guides']);
?>