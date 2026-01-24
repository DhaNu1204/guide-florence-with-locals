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

        $stmt = $conn->prepare("
            SELECT u.id, u.role, u.username, u.email
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }

        return false;
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
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            exit();
        }

        return $user;
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
