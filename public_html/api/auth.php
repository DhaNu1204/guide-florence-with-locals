<?php
/**
 * Authentication API
 * Handles login, logout, and token verification
 *
 * SECURITY: CORS headers handled by config.php
 */
require_once 'config.php';

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Function to verify password - SECURITY: Only use proper password hashing
function verifyPassword($inputPassword, $hashedPassword) {
    // Use only proper password verification
    // NOTE: All user passwords should be stored using password_hash()
    return password_verify($inputPassword, $hashedPassword);
}

// Rate limiting check to prevent brute force attacks
function checkRateLimit($conn, $identifier) {
    $maxAttempts = 5;
    $windowSeconds = 300; // 5 minutes

    // Check if login_attempts table exists (graceful degradation)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        // Table doesn't exist - skip rate limiting but allow login
        return true;
    }

    try {
        // Clean old attempts
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        if ($stmt) {
            $stmt->bind_param("i", $windowSeconds);
            $stmt->execute();
        }

        // Check current attempts
        $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE identifier = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        if ($stmt) {
            $stmt->bind_param("si", $identifier, $windowSeconds);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if ($row['attempts'] >= $maxAttempts) {
                return false; // Rate limited
            }
        }
    } catch (Exception $e) {
        // On any error, allow the request but log it
        error_log("Rate limiting error: " . $e->getMessage());
    }

    return true; // Allow request
}

// Record failed login attempt
function recordFailedAttempt($conn, $identifier) {
    // Check if table exists first (graceful degradation)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return; // Skip if table doesn't exist
    }

    try {
        $stmt = $conn->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW())");
        if ($stmt) {
            $stmt->bind_param("s", $identifier);
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log("Failed to record login attempt: " . $e->getMessage());
    }
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
        // Rate limiting check
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!checkRateLimit($conn, $clientIP)) {
            // Log rate limit events to Sentry for security monitoring
            if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
                sentry_capture_message("Rate limit exceeded for login attempts", 'warning', [
                    'context' => 'auth_rate_limit',
                    'client_ip' => $clientIP,
                    'username_attempted' => $username
                ]);
            }

            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Too many login attempts. Please try again in 5 minutes.'
            ]);
            exit();
        }

        // Query user from database - check both username and email fields
        $stmt = $conn->prepare("SELECT id, username, email, password, role FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // SECURITY: Use ONLY proper bcrypt password verification
            // All passwords MUST be hashed with password_hash()
            $isValidPassword = verifyPassword($password, $user['password']);

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
                // Record failed attempt for rate limiting
                recordFailedAttempt($conn, $clientIP);

                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid username or password'
                ]);
            }
        } else {
            // Record failed attempt for rate limiting
            recordFailedAttempt($conn, $clientIP);

            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid username or password'
            ]);
        }
    } catch (Exception $e) {
        // Send to Sentry if available
        if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
            sentry_capture_exception($e, [
                'context' => 'auth_login',
                'username' => $username,
                'client_ip' => $clientIP
            ]);
        }

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
        // Send to Sentry if available
        if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
            sentry_capture_exception($e, [
                'context' => 'auth_token_verify'
            ]);
        }

        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Default response
echo json_encode(['message' => 'Auth endpoint ready', 'database' => 'florence_guides']);
?>