<?php
/**
 * Middleware.php - Shared middleware functions for API endpoints
 *
 * This file provides reusable middleware functions for:
 * - CORS handling
 * - Authentication verification
 * - Rate limiting
 * - Request logging
 *
 * Usage: require_once 'Middleware.php'; at the top of API files
 */

class Middleware {

    /**
     * Handle CORS headers and preflight requests
     *
     * @param array $allowedOrigins Optional list of allowed origins
     * @return void
     */
    public static function handleCORS($allowedOrigins = null) {
        // Default allowed origins
        if ($allowedOrigins === null) {
            $allowedOrigins = [
                'https://withlocals.deetech.cc',
                'http://withlocals.deetech.cc',
                'http://localhost:5173',
                'http://localhost:5174',
                'http://localhost:5175',
                'http://127.0.0.1:5173'
            ];
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        // Check if origin is allowed
        if (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            // Default to first allowed origin for production
            header("Access-Control-Allow-Origin: " . $allowedOrigins[0]);
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: true");
        header("Content-Type: application/json; charset=UTF-8");

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    /**
     * Verify authentication token
     *
     * @param mysqli $conn Database connection
     * @return array|false User data if authenticated, false otherwise
     */
    public static function verifyAuth($conn) {
        $headers = getallheaders();
        $token = isset($headers['Authorization'])
            ? str_replace('Bearer ', '', $headers['Authorization'])
            : null;

        if (!$token) {
            return false;
        }

        return self::findSessionUser($conn, $token);
    }

    /**
     * Step 1.5: sessions.token holds sha256(token). The presented token is hashed
     * before the lookup. Transition: a row that still holds the raw value is found
     * by its raw value and rewritten to the hash in place, so nobody is logged out.
     * Hashed rows are marked by session_id = 'sha256:<hash>' (raw tokens are also
     * 64 hex characters, so length cannot tell the two apart).
     *
     * @param mysqli $conn
     * @param string $token Raw Bearer token as presented by the client
     * @return array|false User data (id, role, username, email) or false
     */
    public static function findSessionUser($conn, $token) {
        $token = (string) $token;
        if ($token === '') {
            return false;
        }
        $hash = self::hashToken($token);

        $stmt = $conn->prepare("
            SELECT u.id, u.role, u.username, u.email
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->bind_param("s", $hash);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user) {
            return $user;
        }

        // Transition path: row still stores the raw token -> rewrite it to the hash
        $stmt = $conn->prepare("
            SELECT u.id, u.role, u.username, u.email
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            return false;
        }
        try {
            $marker = self::SESSION_ID_PREFIX . $hash;
            $upd = $conn->prepare("UPDATE sessions SET token = ?, session_id = ? WHERE token = ?");
            $upd->bind_param("sss", $hash, $marker, $token);
            $upd->execute();
            $upd->close();
        } catch (Throwable $e) {
            error_log('sessions: in-place hash rewrite failed: ' . $e->getMessage());
        }
        return $user;
    }

    const SESSION_ID_PREFIX = 'sha256:';

    public static function hashToken($token) {
        return hash('sha256', (string) $token);
    }

    /**
     * Step 1.5: server-side logout. Deletes the session row of the presented token
     * (hashed or, during the transition, raw). Returns the number of rows removed.
     */
    public static function deleteSession($conn, $token) {
        $token = (string) $token;
        if ($token === '') {
            return 0;
        }
        $hash = self::hashToken($token);
        $stmt = $conn->prepare("DELETE FROM sessions WHERE token = ? OR token = ?");
        $stmt->bind_param("ss", $hash, $token);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return $n;
    }

    /**
     * Require authentication - returns 401 if not authenticated
     *
     * @param mysqli $conn Database connection
     * @return array User data
     */
    public static function requireAuth($conn) {
        $user = self::verifyAuth($conn);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit();
        }

        return $user;
    }

    /**
     * Require specific role
     *
     * @param mysqli $conn Database connection
     * @param string $requiredRole Required role (e.g., 'admin')
     * @return array User data
     */
    public static function requireRole($conn, $requiredRole) {
        $user = self::requireAuth($conn);

        if ($user['role'] !== $requiredRole && $user['role'] !== 'admin') {
            self::forbidden();
        }

        return $user;
    }

    /**
     * Step 1.1: authenticate, and additionally require the admin role for any
     * write method (POST, PUT, PATCH, DELETE). GET/HEAD stay available to viewers.
     *
     * @param mysqli $conn Database connection
     * @return array User data
     */
    public static function requireAdminForWrites($conn) {
        $user = self::requireAuth($conn);

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $user['role'] !== 'admin') {
            self::forbidden();
        }

        return $user;
    }

    /**
     * Step 6.10: the accounts allowed to see financial data (Daily P&L: revenue,
     * costs, profit, the rates behind them). This is deliberately NARROWER than the
     * admin role - the system has more than one admin, and only the owner sees money.
     *
     * The list is `PNL_OWNER_USERS` in the server .env (comma separated usernames or
     * emails, case-insensitive). It falls back to the built-in default so the gate is
     * correct the moment the code is deployed and never needs an env edit to work.
     */
    const DEFAULT_PNL_OWNER_USERS = 'dhanu';

    public static function pnlOwnerUsers() {
        $raw = '';
        if (class_exists('EnvLoader')) {
            $raw = (string) EnvLoader::get('PNL_OWNER_USERS', '');
        }
        if (trim($raw) === '') {
            $raw = self::DEFAULT_PNL_OWNER_USERS;
        }
        $names = [];
        foreach (explode(',', $raw) as $n) {
            $n = strtolower(trim($n));
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return $names;
    }

    /**
     * Step 6.10: is this authenticated user allowed to see money? Admin role AND on
     * the owner list - so a second admin keeps every other admin power and still gets
     * a 403 here.
     *
     * @param array $user Row returned by verifyAuth()/requireAuth()
     * @return bool
     */
    public static function isPnlOwner($user) {
        if (!is_array($user) || (isset($user['role']) ? $user['role'] : '') !== 'admin') {
            return false;
        }
        $names = self::pnlOwnerUsers();
        foreach (['username', 'email'] as $field) {
            $v = strtolower(trim((string) (isset($user[$field]) ? $user[$field] : '')));
            if ($v !== '' && in_array($v, $names, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Step 6.10: authenticate, then require the owner. Anyone else - viewer or a
     * non-owner admin - gets 403 with a message the UI can show as it is.
     * An unauthenticated request still gets 401 from requireAuth().
     *
     * @param mysqli $conn Database connection
     * @return array User data
     */
    public static function requirePnlOwner($conn) {
        $user = self::requireAuth($conn);

        if (!self::isPnlOwner($user)) {
            self::forbidden('You do not have access to this page.');
        }

        return $user;
    }

    /**
     * Uniform 403: real status, JSON body, no role names or user details.
     */
    public static function forbidden($message = 'forbidden') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $message]);
        exit();
    }

    /**
     * Generate unique request ID for logging/debugging
     *
     * @return string UUID-like request identifier
     */
    public static function generateRequestId() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Log API request for debugging
     *
     * @param string $endpoint Endpoint name
     * @param string $method HTTP method
     * @param mixed $data Request data (optional)
     * @return void
     */
    public static function logRequest($endpoint, $method, $data = null) {
        $requestId = self::generateRequestId();
        $logMessage = "[{$requestId}] {$method} {$endpoint}";

        if ($data !== null && defined('DEBUG') && DEBUG === true) {
            $logMessage .= " Data: " . json_encode($data);
        }

        error_log($logMessage);
        return $requestId;
    }
}

/**
 * Response helper class for consistent API responses
 */
class Response {

    /**
     * Send success response
     *
     * @param mixed $data Response data
     * @param string $message Optional success message
     * @param int $statusCode HTTP status code (default 200)
     * @return void
     */
    public static function success($data = null, $message = null, $statusCode = 200) {
        http_response_code($statusCode);
        $response = ['success' => true];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        echo json_encode($response);
        exit();
    }

    /**
     * Send created response (201)
     *
     * @param mixed $data Created resource data
     * @return void
     */
    public static function created($data) {
        self::success($data, 'Resource created successfully', 201);
    }

    /**
     * Send error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default 400)
     * @param array $errors Optional validation errors array
     * @return void
     */
    public static function error($message, $statusCode = 400, $errors = null) {
        http_response_code($statusCode);
        $response = [
            'success' => false,
            'error' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        echo json_encode($response);
        exit();
    }

    /**
     * Send validation error response (422)
     *
     * @param array $errors Validation errors
     * @return void
     */
    public static function validationError($errors) {
        self::error('Validation failed', 422, $errors);
    }

    /**
     * Send not found response (404)
     *
     * @param string $resource Resource name
     * @return void
     */
    public static function notFound($resource = 'Resource') {
        self::error("{$resource} not found", 404);
    }

    /**
     * Send unauthorized response (401)
     *
     * @param string $message Optional custom message
     * @return void
     */
    public static function unauthorized($message = 'Authentication required') {
        self::error($message, 401);
    }

    /**
     * Send forbidden response (403)
     *
     * @param string $message Optional custom message
     * @return void
     */
    public static function forbidden($message = 'Access denied') {
        self::error($message, 403);
    }

    /**
     * Send server error response (500)
     *
     * @param string $message Optional custom message (generic in production)
     * @return void
     */
    public static function serverError($message = null) {
        // In production, don't expose error details
        $displayMessage = (defined('DEBUG') && DEBUG === true && $message)
            ? $message
            : 'Internal server error';

        self::error($displayMessage, 500);
    }
}

/**
 * Input validation helper class
 */
class Validator {

    private $errors = [];
    private $data = [];

    public function __construct($data) {
        $this->data = $data;
    }

    /**
     * Validate required field
     */
    public function required($field, $message = null) {
        if (!isset($this->data[$field]) || trim($this->data[$field]) === '') {
            $this->errors[$field] = $message ?? "{$field} is required";
        }
        return $this;
    }

    /**
     * Validate email format
     */
    public function email($field, $message = null) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field] = $message ?? "Invalid email format";
            }
        }
        return $this;
    }

    /**
     * Validate maximum length
     */
    public function maxLength($field, $max, $message = null) {
        if (isset($this->data[$field]) && strlen($this->data[$field]) > $max) {
            $this->errors[$field] = $message ?? "{$field} must be less than {$max} characters";
        }
        return $this;
    }

    /**
     * Validate minimum length
     */
    public function minLength($field, $min, $message = null) {
        if (isset($this->data[$field]) && strlen($this->data[$field]) < $min) {
            $this->errors[$field] = $message ?? "{$field} must be at least {$min} characters";
        }
        return $this;
    }

    /**
     * Validate numeric value
     */
    public function numeric($field, $message = null) {
        if (isset($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->errors[$field] = $message ?? "{$field} must be a number";
        }
        return $this;
    }

    /**
     * Validate positive number
     */
    public function positive($field, $message = null) {
        if (isset($this->data[$field]) && floatval($this->data[$field]) <= 0) {
            $this->errors[$field] = $message ?? "{$field} must be greater than 0";
        }
        return $this;
    }

    /**
     * Validate date format (YYYY-MM-DD)
     */
    public function date($field, $message = null) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            $d = DateTime::createFromFormat('Y-m-d', $this->data[$field]);
            if (!$d || $d->format('Y-m-d') !== $this->data[$field]) {
                $this->errors[$field] = $message ?? "Invalid date format (expected YYYY-MM-DD)";
            }
        }
        return $this;
    }

    /**
     * Validate time format (HH:MM or HH:MM:SS)
     */
    public function time($field, $message = null) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $this->data[$field])) {
                $this->errors[$field] = $message ?? "Invalid time format";
            }
        }
        return $this;
    }

    /**
     * Validate value is in allowed list
     */
    public function in($field, $allowedValues, $message = null) {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $allowedValues)) {
            $this->errors[$field] = $message ?? "{$field} must be one of: " . implode(', ', $allowedValues);
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function isValid() {
        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Fail with validation errors if any
     */
    public function validate() {
        if (!$this->isValid()) {
            Response::validationError($this->errors);
        }
        return true;
    }
}
?>
