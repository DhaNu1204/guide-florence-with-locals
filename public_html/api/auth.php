<?php
/**
 * Authentication API
 * Handles login, logout, and token verification
 *
 * SECURITY: CORS headers handled by config.php
 */
require_once 'config.php';
require_once __DIR__ . '/Middleware.php'; // step 1.5: hashed session tokens

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Probabilistic cleanup of expired sessions (runs ~5% of requests)
function cleanupExpiredSessions($conn) {
    if (mt_rand(1, 20) !== 1) return; // 5% chance
    try {
        $stmt = $conn->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
        if ($stmt) {
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            if ($deleted > 0) {
                error_log("Session cleanup: removed $deleted expired sessions");
            }
        }
    } catch (Exception $e) {
        error_log("Session cleanup error: " . $e->getMessage());
    }
}

// Function to verify password - SECURITY: Only use proper password hashing
function verifyPassword($inputPassword, $hashedPassword) {
    // Use only proper password verification
    // NOTE: All user passwords should be stored using password_hash()
    return password_verify($inputPassword, $hashedPassword);
}

// ---------------------------------------------------------------------------
// Step 1.3: failed-login limiter. Self-provisioned table (like rate_limits), two
// counters: per client IP (5 failed logins per minute) and per username (10 per
// 15 minutes, so a distributed attack on one account is slowed too). A successful
// login clears the username counter. Both limits answer 429 + Retry-After with the
// same body, which never says whether the username exists.
// ---------------------------------------------------------------------------
const LOGIN_IP_MAX_ATTEMPTS = 5;
const LOGIN_IP_WINDOW_SECONDS = 60;
const LOGIN_USER_MAX_ATTEMPTS = 10;
const LOGIN_USER_WINDOW_SECONDS = 900;

function ensureLoginAttemptsTable($conn) {
    static $done = false;
    if ($done) return;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(190) NOT NULL,
                attempt_time DATETIME NOT NULL,
                INDEX idx_identifier_time (identifier, attempt_time),
                INDEX idx_attempt_time (attempt_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    } catch (Throwable $e) {
        error_log('login_attempts self-provision failed: ' . $e->getMessage());
    }
}

function loginUserIdentifier($username) {
    return 'user:' . mb_substr(mb_strtolower(trim((string) $username)), 0, 180);
}

function loginIpIdentifier($ip) {
    return 'ip:' . $ip;
}

/**
 * @return int 0 when allowed, otherwise the number of seconds until the window frees up
 */
function loginRetryAfter($conn, $identifier, $maxAttempts, $windowSeconds) {
    ensureLoginAttemptsTable($conn);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS attempts,
               TIMESTAMPDIFF(SECOND, MIN(attempt_time), NOW()) AS oldest_age
        FROM login_attempts
        WHERE identifier = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->bind_param('si', $identifier, $windowSeconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int) $row['attempts'] < $maxAttempts) {
        return 0;
    }
    return max(1, $windowSeconds - (int) $row['oldest_age']);
}

// Record failed login attempt (both counters)
function recordFailedAttempt($conn, $clientIP, $username) {
    ensureLoginAttemptsTable($conn);
    try {
        $stmt = $conn->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW()), (?, NOW())");
        $ipId = loginIpIdentifier($clientIP);
        $userId = loginUserIdentifier($username);
        $stmt->bind_param('ss', $ipId, $userId);
        $stmt->execute();
        $stmt->close();
        // Keep the table small: drop rows older than the longest window
        if (mt_rand(1, 20) === 1) {
            $conn->query("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL " . (int) LOGIN_USER_WINDOW_SECONDS . " SECOND)");
        }
    } catch (Throwable $e) {
        error_log('Failed to record login attempt: ' . $e->getMessage());
    }
}

// A successful login clears the username counter
function clearUserAttempts($conn, $username) {
    try {
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $userId = loginUserIdentifier($username);
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Failed to clear login attempts: ' . $e->getMessage());
    }
}

function sendLoginTooManyAttempts($retryAfter) {
    http_response_code(429);
    header('Retry-After: ' . (int) $retryAfter);
    echo json_encode([
        'success' => false,
        'message' => 'Too many login attempts. Please try again later.',
        'retry_after' => (int) $retryAfter
    ]);
    exit();
}

// Step 1.5: server-side logout. POST auth.php?action=logout with a valid Bearer token
// deletes that session row; an already-invalid token gets the usual 401.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'logout') {
    applyRateLimit('auth');
    require_once __DIR__ . '/Middleware.php';
    Middleware::requireAuth($conn);
    $headers = getallheaders();
    $bearer = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';
    Middleware::deleteSession($conn, $bearer);
    echo json_encode(['success' => true]);
    exit();
}

// Login endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Apply centralized rate limiting (stricter: 5 requests per minute)
    applyRateLimit('login');

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['username']) || !isset($data['password'])) {
        echo json_encode(['error' => 'Username and password are required']);
        exit();
    }

    $username = $data['username'];
    $password = $data['password'];

    try {
        // Rate limiting check (step 1.3: IP counter AND username counter)
        $clientIP = RateLimiter::getClientIp();
        ensureLoginAttemptsTable($conn);
        $retryAfter = max(
            loginRetryAfter($conn, loginIpIdentifier($clientIP), LOGIN_IP_MAX_ATTEMPTS, LOGIN_IP_WINDOW_SECONDS),
            loginRetryAfter($conn, loginUserIdentifier($username), LOGIN_USER_MAX_ATTEMPTS, LOGIN_USER_WINDOW_SECONDS)
        );
        if ($retryAfter > 0) {
            // Log rate limit events to Sentry for security monitoring
            if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
                sentry_capture_message("Rate limit exceeded for login attempts", 'warning', [
                    'context' => 'auth_rate_limit',
                    'client_ip' => $clientIP,
                    'username_attempted' => $username
                ]);
            }

            sendLoginTooManyAttempts($retryAfter);
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

                // Step 1.5: only sha256(token) is stored; session_id carries the 'sha256:' marker
                $tokenHash = Middleware::hashToken($sessionToken);
                $sessionId = Middleware::SESSION_ID_PREFIX . $tokenHash;
                $stmt = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
                $stmt->bind_param("ssi", $sessionId, $tokenHash, $user['id']);
                $stmt->execute();

                // Step 1.3: a successful login clears the username counter
                clearUserAttempts($conn, $username);

                // Cleanup expired sessions probabilistically
                cleanupExpiredSessions($conn);

                echo json_encode([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $sessionToken,
                    'role' => $user['role'],
                    'username' => $user['username'] ?: $user['email']
                ]);
            } else {
                // Record failed attempt for rate limiting
                recordFailedAttempt($conn, $clientIP, $username);

                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid username or password'
                ]);
            }
        } else {
            // Record failed attempt for rate limiting
            recordFailedAttempt($conn, $clientIP, $username);

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
            'message' => 'An internal error occurred'
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
        // Step 1.5: shared lookup (hashed token, raw fallback with in-place rewrite)
        require_once __DIR__ . '/Middleware.php';
        $user = Middleware::findSessionUser($conn, $token);
        if ($user) {
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
        error_log("Auth verify error: " . $e->getMessage());
        echo json_encode(['error' => 'An internal error occurred']);
    }
    exit();
}

// Default response
echo json_encode(['message' => 'Auth endpoint ready']);
?>