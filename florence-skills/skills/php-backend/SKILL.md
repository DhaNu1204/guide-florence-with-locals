# PHP Backend Skill - Florence With Locals

You are a PHP backend specialist for the Florence With Locals tour management system. You understand the project's API architecture, database patterns, and coding standards.

## Project Structure

```
public_html/api/
├── config.php          # Database connection, CORS, environment
├── BaseAPI.php         # Abstract base class for endpoints
├── EnvLoader.php       # Environment variable loader
├── Logger.php          # Centralized logging
├── Validator.php       # Input validation helper
├── Middleware.php      # Security middleware
├── auth.php            # Authentication endpoint
├── tours.php           # Tours CRUD
├── guides.php          # Guides CRUD
├── payments.php        # Payments CRUD
├── tickets.php         # Tickets CRUD
├── BokunAPI.php        # Bokun API client
├── bokun_sync.php      # Sync orchestration
├── bokun_webhook.php   # Webhook handlers
└── health.php          # Health check endpoint
```

---

## BaseAPI.php Extension Pattern

**Current BaseAPI.php structure:**
```php
<?php
/**
 * Base API class for consistent API responses and common functionality
 */
abstract class BaseAPI {
    protected $conn;
    protected $method;
    protected $requestData;

    public function __construct() {
        global $conn;
        $this->conn = $conn;
        $this->method = $_SERVER['REQUEST_METHOD'];

        // Parse request body
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $this->requestData = json_decode(file_get_contents('php://input'), true) ?? [];
        }
    }

    /**
     * Send JSON success response
     */
    protected function sendSuccess($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit();
    }

    /**
     * Send JSON error response
     */
    protected function sendError($message, $statusCode = 400, $details = null) {
        http_response_code($statusCode);
        $response = [
            'success' => false,
            'error' => $message
        ];
        if ($details) {
            $response['details'] = $details;
        }
        echo json_encode($response);
        exit();
    }

    /**
     * Send paginated response
     */
    protected function sendPaginated($data, $total, $page, $perPage) {
        $totalPages = ceil($total / $perPage);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ]);
        exit();
    }

    /**
     * Get and validate a required field
     */
    protected function requireField($field) {
        if (!isset($this->requestData[$field]) || $this->requestData[$field] === '') {
            $this->sendError("Field '$field' is required", 400);
        }
        return $this->requestData[$field];
    }

    /**
     * Get an optional field with default
     */
    protected function getField($field, $default = null) {
        return $this->requestData[$field] ?? $default;
    }

    /**
     * Extract ID from URL path
     */
    protected function getIdFromUrl() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $segments = explode('/', rtrim($path, '/'));
        $id = end($segments);
        return is_numeric($id) ? intval($id) : null;
    }

    /**
     * Handle the API request
     */
    abstract public function handleRequest();
}
```

### Creating a New Endpoint Using BaseAPI

**Template for new endpoint (example: `reports.php`):**
```php
<?php
/**
 * Reports API Endpoint
 *
 * Endpoints:
 * GET /api/reports.php                    - Get report summary
 * GET /api/reports.php?type=revenue       - Get revenue report
 * GET /api/reports.php?type=guides        - Get guide performance report
 */
require_once 'config.php';
require_once 'BaseAPI.php';
require_once 'Validator.php';

class ReportsAPI extends BaseAPI {

    public function handleRequest() {
        switch ($this->method) {
            case 'GET':
                $this->handleGet();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleGet() {
        $type = $_GET['type'] ?? 'summary';
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        // Validate dates
        if (!Validator::validateDate($startDate) || !Validator::validateDate($endDate)) {
            $this->sendError('Invalid date format', 400);
        }

        switch ($type) {
            case 'revenue':
                $this->getRevenueReport($startDate, $endDate);
                break;
            case 'guides':
                $this->getGuideReport($startDate, $endDate);
                break;
            default:
                $this->getSummaryReport($startDate, $endDate);
        }
    }

    private function getSummaryReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT
                COUNT(*) as total_tours,
                COUNT(CASE WHEN cancelled = 0 THEN 1 END) as active_tours,
                COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_tours,
                SUM(CASE WHEN cancelled = 0 THEN participants ELSE 0 END) as total_participants,
                SUM(total_amount_paid) as total_revenue
            FROM tours
            WHERE date BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();

        $this->sendSuccess([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => $summary
        ]);
    }

    private function getRevenueReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT
                DATE_FORMAT(date, '%Y-%m') as month,
                COUNT(*) as tour_count,
                SUM(total_amount_paid) as revenue,
                SUM(participants) as participants
            FROM tours
            WHERE date BETWEEN ? AND ?
            AND cancelled = 0
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY month DESC
        ");
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->sendSuccess(['revenue_by_month' => $data]);
    }

    private function getGuideReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT
                g.id as guide_id,
                g.name as guide_name,
                COUNT(t.id) as tour_count,
                SUM(t.participants) as total_participants,
                SUM(p.amount) as total_payments
            FROM guides g
            LEFT JOIN tours t ON g.id = t.guide_id
                AND t.date BETWEEN ? AND ?
                AND t.cancelled = 0
            LEFT JOIN payments p ON t.id = p.tour_id
            GROUP BY g.id, g.name
            ORDER BY tour_count DESC
        ");
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->sendSuccess(['guide_performance' => $data]);
    }
}

// Instantiate and handle request
$api = new ReportsAPI();
$api->handleRequest();
```

---

## Config.php Environment Handling

**Standard config.php pattern:**
```php
<?php
/**
 * Configuration - Florence With Locals
 * Auto-detects environment (development/production)
 */

// Load environment variables
require_once __DIR__ . '/EnvLoader.php';
EnvLoader::load();

// Determine environment
$isProduction = EnvLoader::get('APP_ENV', 'production') === 'production';

// Auto-detect based on hostname if not explicitly set
if (!EnvLoader::get('APP_ENV')) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isProduction = strpos($host, 'localhost') === false &&
                    strpos($host, '127.0.0.1') === false;
}

// Error handling based on environment
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    // Ensure log directory exists
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Database configuration
$db_host = EnvLoader::get('DB_HOST', 'localhost');
$db_name = EnvLoader::get('DB_NAME', 'florence_guides');
$db_user = EnvLoader::get('DB_USER', 'root');
$db_pass = EnvLoader::get('DB_PASS', '');

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(503);
    die(json_encode(['error' => 'Database connection failed']));
}
$conn->set_charset("utf8mb4");

// CORS Configuration
$allowedOrigins = [
    'https://withlocals.deetech.cc',
    'http://localhost:5173',
    'http://127.0.0.1:5173'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (!$isProduction) {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

---

## Prepared Statement Patterns

### SELECT with Optional Filters
```php
function getTours($filters = []) {
    global $conn;

    $sql = "SELECT t.*, g.name as guide_name
            FROM tours t
            LEFT JOIN guides g ON t.guide_id = g.id
            WHERE 1=1";
    $params = [];
    $types = "";

    // Add filters dynamically
    if (!empty($filters['date'])) {
        $sql .= " AND t.date = ?";
        $params[] = $filters['date'];
        $types .= "s";
    }

    if (!empty($filters['guide_id'])) {
        $sql .= " AND t.guide_id = ?";
        $params[] = $filters['guide_id'];
        $types .= "i";
    }

    if (isset($filters['upcoming']) && $filters['upcoming']) {
        $sql .= " AND t.date >= CURDATE() AND t.date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
    }

    if (!empty($filters['cancelled'])) {
        $sql .= " AND t.cancelled = ?";
        $params[] = $filters['cancelled'];
        $types .= "i";
    }

    $sql .= " ORDER BY t.date ASC, t.time ASC";

    // Add pagination
    if (isset($filters['limit']) && isset($filters['offset'])) {
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $filters['limit'];
        $params[] = $filters['offset'];
        $types .= "ii";
    }

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
```

### INSERT with RETURNING
```php
function createTour($data) {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO tours (
            title, date, time, duration, guide_id,
            customer_name, customer_email, customer_phone,
            participants, payment_status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("ssssisssiss",
        $data['title'],
        $data['date'],
        $data['time'],
        $data['duration'],
        $data['guide_id'],
        $data['customer_name'],
        $data['customer_email'],
        $data['customer_phone'],
        $data['participants'],
        $data['payment_status'],
        $data['notes']
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to create tour: " . $stmt->error);
    }

    $newId = $conn->insert_id;
    $stmt->close();

    // Fetch and return the created record
    return getTourById($newId);
}

function getTourById($id) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT t.*, g.name as guide_name
        FROM tours t
        LEFT JOIN guides g ON t.guide_id = g.id
        WHERE t.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
```

### Dynamic UPDATE
```php
function updateTour($id, $data) {
    global $conn;

    // Build dynamic update query
    $allowedFields = [
        'title' => 's',
        'date' => 's',
        'time' => 's',
        'duration' => 's',
        'guide_id' => 'i',
        'customer_name' => 's',
        'customer_email' => 's',
        'customer_phone' => 's',
        'participants' => 'i',
        'payment_status' => 's',
        'paid' => 'i',
        'cancelled' => 'i',
        'notes' => 's'
    ];

    $updates = [];
    $params = [];
    $types = "";

    foreach ($data as $field => $value) {
        if (isset($allowedFields[$field])) {
            $updates[] = "$field = ?";
            $params[] = $value;
            $types .= $allowedFields[$field];
        }
    }

    if (empty($updates)) {
        throw new Exception("No valid fields to update");
    }

    // Add updated_at
    $updates[] = "updated_at = NOW()";

    // Add ID parameter
    $params[] = $id;
    $types .= "i";

    $sql = "UPDATE tours SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        throw new Exception("Failed to update tour: " . $stmt->error);
    }

    if ($stmt->affected_rows === 0) {
        throw new Exception("Tour not found or no changes made");
    }

    $stmt->close();
    return getTourById($id);
}
```

---

## Pagination Implementation

```php
function getPaginatedResults($tableName, $page = 1, $perPage = 50, $filters = [], $orderBy = 'id DESC') {
    global $conn;

    // Validate pagination params
    $page = max(1, intval($page));
    $perPage = min(500, max(1, intval($perPage))); // Max 500 per page
    $offset = ($page - 1) * $perPage;

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM $tableName WHERE 1=1";
    $countParams = [];
    $countTypes = "";

    // Apply same filters to count query
    foreach ($filters as $field => $value) {
        if ($value !== null && $value !== '') {
            $countSql .= " AND $field = ?";
            $countParams[] = $value;
            $countTypes .= is_int($value) ? "i" : "s";
        }
    }

    $countStmt = $conn->prepare($countSql);
    if (!empty($countParams)) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Get paginated data
    $dataSql = "SELECT * FROM $tableName WHERE 1=1";
    $dataParams = $countParams;
    $dataTypes = $countTypes;

    foreach ($filters as $field => $value) {
        if ($value !== null && $value !== '') {
            $dataSql .= " AND $field = ?";
        }
    }

    $dataSql .= " ORDER BY $orderBy LIMIT ? OFFSET ?";
    $dataParams[] = $perPage;
    $dataParams[] = $offset;
    $dataTypes .= "ii";

    $dataStmt = $conn->prepare($dataSql);
    if (!empty($dataParams)) {
        $dataStmt->bind_param($dataTypes, ...$dataParams);
    }
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    return [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
            'has_next' => ($page * $perPage) < $total,
            'has_prev' => $page > 1
        ]
    ];
}
```

---

## Error Response Format

**Standard error responses:**
```php
// 400 Bad Request - Invalid input
$this->sendError("Invalid date format", 400);

// 401 Unauthorized - Not authenticated
http_response_code(401);
echo json_encode([
    'success' => false,
    'error' => 'Authentication required',
    'code' => 'AUTH_REQUIRED'
]);

// 403 Forbidden - Not authorized
$this->sendError("You don't have permission to perform this action", 403);

// 404 Not Found
$this->sendError("Tour not found", 404);

// 409 Conflict - Duplicate
$this->sendError("Guide with this email already exists", 409);

// 422 Unprocessable Entity - Validation failed
http_response_code(422);
echo json_encode([
    'success' => false,
    'error' => 'Validation failed',
    'details' => [
        'title' => 'Title is required',
        'date' => 'Invalid date format'
    ]
]);

// 429 Too Many Requests
http_response_code(429);
header('Retry-After: 300');
echo json_encode([
    'success' => false,
    'error' => 'Too many requests',
    'retry_after' => 300
]);

// 500 Internal Server Error
http_response_code(500);
echo json_encode([
    'success' => false,
    'error' => 'An unexpected error occurred'
]);
// Log actual error, don't expose to client
error_log("Error in tours.php: " . $e->getMessage());
```

---

## Logging with Logger.php

**Logger.php implementation:**
```php
<?php
/**
 * Centralized logging for Florence With Locals API
 */
class Logger {
    private static $logDir;
    private static $levels = ['debug', 'info', 'warning', 'error', 'critical'];

    public static function init($logDir = null) {
        self::$logDir = $logDir ?? __DIR__ . '/logs';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }

    public static function log($level, $message, $context = []) {
        if (!in_array($level, self::$levels)) {
            $level = 'info';
        }

        self::init();

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

        $logLine = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;

        // Write to daily log file
        $logFile = self::$logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Also write errors to PHP error log
        if (in_array($level, ['error', 'critical'])) {
            error_log("Florence API [$level]: $message$contextStr");
        }
    }

    public static function debug($message, $context = []) {
        self::log('debug', $message, $context);
    }

    public static function info($message, $context = []) {
        self::log('info', $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log('warning', $message, $context);
    }

    public static function error($message, $context = []) {
        self::log('error', $message, $context);
    }

    public static function critical($message, $context = []) {
        self::log('critical', $message, $context);
    }

    // Log API request
    public static function logRequest() {
        self::info('API Request', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'],
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }

    // Clean old log files (call via cron)
    public static function cleanOldLogs($daysToKeep = 30) {
        self::init();

        $files = glob(self::$logDir . '/*.log');
        $threshold = strtotime("-$daysToKeep days");

        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
            }
        }
    }
}
```

**Usage in endpoints:**
```php
<?php
require_once 'config.php';
require_once 'Logger.php';

Logger::logRequest();

try {
    // Your API logic
    Logger::info('Tour created', ['tour_id' => $newId, 'title' => $title]);
} catch (Exception $e) {
    Logger::error('Tour creation failed', [
        'error' => $e->getMessage(),
        'data' => $requestData
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create tour']);
}
```

---

## Validation with Validator.php

```php
<?php
/**
 * Input Validation Helper
 */
class Validator {
    private static $errors = [];

    public static function validate($data, $rules) {
        self::$errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule => $param) {
                self::applyRule($field, $value, $rule, $param);
            }
        }

        return empty(self::$errors);
    }

    private static function applyRule($field, $value, $rule, $param) {
        switch ($rule) {
            case 'required':
                if ($param && ($value === null || $value === '')) {
                    self::$errors[$field] = ucfirst($field) . ' is required';
                }
                break;

            case 'email':
                if ($param && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    self::$errors[$field] = 'Invalid email format';
                }
                break;

            case 'date':
                if ($param && $value) {
                    $d = DateTime::createFromFormat('Y-m-d', $value);
                    if (!$d || $d->format('Y-m-d') !== $value) {
                        self::$errors[$field] = 'Invalid date format (expected YYYY-MM-DD)';
                    }
                }
                break;

            case 'time':
                if ($param && $value && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]/', $value)) {
                    self::$errors[$field] = 'Invalid time format';
                }
                break;

            case 'min':
                if ($value !== null && strlen($value) < $param) {
                    self::$errors[$field] = ucfirst($field) . " must be at least $param characters";
                }
                break;

            case 'max':
                if ($value !== null && strlen($value) > $param) {
                    self::$errors[$field] = ucfirst($field) . " must not exceed $param characters";
                }
                break;

            case 'in':
                if ($value !== null && !in_array($value, $param)) {
                    self::$errors[$field] = ucfirst($field) . ' must be one of: ' . implode(', ', $param);
                }
                break;

            case 'integer':
                if ($param && $value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
                    self::$errors[$field] = ucfirst($field) . ' must be an integer';
                }
                break;

            case 'numeric':
                if ($param && $value !== null && !is_numeric($value)) {
                    self::$errors[$field] = ucfirst($field) . ' must be numeric';
                }
                break;
        }
    }

    public static function getErrors() {
        return self::$errors;
    }

    public static function hasErrors() {
        return !empty(self::$errors);
    }
}

// Usage example:
$rules = [
    'title' => ['required' => true, 'max' => 200],
    'date' => ['required' => true, 'date' => true],
    'time' => ['required' => true, 'time' => true],
    'customer_email' => ['email' => true, 'max' => 100],
    'participants' => ['required' => true, 'integer' => true],
    'payment_status' => ['in' => ['unpaid', 'partial', 'paid']]
];

if (!Validator::validate($data, $rules)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'details' => Validator::getErrors()
    ]);
    exit();
}
```
