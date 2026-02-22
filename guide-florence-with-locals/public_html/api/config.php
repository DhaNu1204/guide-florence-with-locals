<?php
// Set default timezone to Italy (Europe/Rome)
date_default_timezone_set('Europe/Rome');

/**
 * Polyfill for getallheaders()
 * Required for shared hosting environments (e.g., Hostinger) where
 * the function is not available with PHP-FPM/FastCGI.
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}

/**
 * GZIP Compression (PHP Fallback)
 *
 * Enables gzip compression for API responses when:
 * 1. Client accepts gzip encoding
 * 2. zlib extension is available
 * 3. Output buffering is not already active
 *
 * This is a fallback in case mod_deflate is not available on the server.
 * Reduces JSON response sizes by 70-80%.
 */
function initGzipCompression() {
    // Skip if CLI mode
    if (php_sapi_name() === 'cli') {
        return false;
    }

    // Check if client accepts gzip
    $acceptEncoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
    if (strpos($acceptEncoding, 'gzip') === false) {
        return false;
    }

    // Check if zlib is available
    if (!function_exists('ob_gzhandler')) {
        return false;
    }

    // Check if output buffering is not already active with a callback
    if (ob_get_level() > 0 && ob_get_length() > 0) {
        return false;
    }

    // Start gzip output buffering
    // Note: ob_gzhandler automatically sets Content-Encoding header
    if (ob_start('ob_gzhandler')) {
        return true;
    }

    return false;
}

// Initialize gzip compression
$gzipEnabled = initGzipCompression();

/**
 * Smart Configuration File
 * Automatically detects environment and loads appropriate settings
 * No manual changes needed between development and production
 *
 * SECURITY: Uses EnvLoader for sensitive credentials
 */

// Load environment variables
require_once __DIR__ . '/EnvLoader.php';
require_once __DIR__ . '/SentryLogger.php';
EnvLoader::load();

// Detect environment based on server characteristics
function detectEnvironment() {
    // Check multiple indicators to determine environment
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $serverAddr = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';

    // Production indicators
    if (
        strpos($host, 'withlocals.deetech.cc') !== false ||
        strpos($host, 'deetech.cc') !== false ||
        strpos($documentRoot, 'u803853690') !== false ||
        strpos($documentRoot, 'hostinger') !== false ||
        file_exists('/home/u803853690/')
    ) {
        return 'production';
    }

    // Development indicators
    if (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($serverAddr, '127.0.0.1') !== false ||
        strpos($serverAddr, '::1') !== false ||
        strpos($documentRoot, 'xampp') !== false ||
        strpos($documentRoot, 'wamp') !== false ||
        strpos($documentRoot, 'florence-with-locals') !== false
    ) {
        return 'development';
    }

    // Default to development for safety
    return 'development';
}

// Detect current environment
$environment = detectEnvironment();

// Load environment-specific configuration
if ($environment === 'production') {
    // =====================================
    // PRODUCTION CONFIGURATION
    // =====================================
    // SECURITY: Credentials loaded from environment variables
    $db_host = EnvLoader::get('DB_HOST', 'localhost');
    $db_user = EnvLoader::get('DB_USER', 'u803853690_withlocals');
    $db_pass = EnvLoader::get('DB_PASS', '');  // REQUIRED: Set in .env file
    $db_name = EnvLoader::get('DB_NAME', 'u803853690_withlocals');

    // Production CORS settings
    $allowed_origins = [
        'https://withlocals.deetech.cc',
        'http://withlocals.deetech.cc'
    ];

    // Production environment flags
    define('ENVIRONMENT', 'production');
    define('DEBUG', false);
    define('BASE_URL', 'https://withlocals.deetech.cc');
    define('API_URL', 'https://withlocals.deetech.cc/api');

    // Disable error display in production
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);

    // Initialize Sentry error tracking in production
    $sentryDsn = EnvLoader::get('SENTRY_DSN', '');
    if (!empty($sentryDsn)) {
        SentryLogger::init($sentryDsn, [
            'environment' => EnvLoader::get('SENTRY_ENVIRONMENT', 'production'),
            'release' => EnvLoader::get('SENTRY_RELEASE', null)
        ])->setupHandlers();

        // Add server context as tags
        SentryLogger::getInstance()->setTags([
            'php_version' => PHP_VERSION,
            'server' => 'hostinger'
        ]);
    }

} else {
    // =====================================
    // DEVELOPMENT CONFIGURATION
    // =====================================

    // First, check if there's a .env.local file for custom local settings
    $envFile = __DIR__ . '/../../.env.local';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }

    // Use environment variables if available, otherwise use defaults
    $db_host = isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'localhost';
    $db_user = isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : 'root';
    $db_pass = isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : '';
    $db_name = isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : 'florence_guides';

    // Development CORS settings - allow all local ports
    $allowed_origins = [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'http://localhost:3000',
        'http://127.0.0.1:5173'
    ];

    // Development environment flags
    define('ENVIRONMENT', 'development');
    define('DEBUG', true);
    define('BASE_URL', 'http://localhost:5173');
    define('API_URL', 'http://localhost:8080/api');

    // Enable error display in development
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);

    // Allow Sentry in development if explicitly configured (for testing)
    $sentryDsn = EnvLoader::get('SENTRY_DSN', '');
    if (!empty($sentryDsn) && EnvLoader::get('SENTRY_ENABLE_DEV', false)) {
        SentryLogger::init($sentryDsn, [
            'environment' => EnvLoader::get('SENTRY_ENVIRONMENT', 'development'),
            'release' => EnvLoader::get('SENTRY_RELEASE', null)
        ])->setupHandlers();

        SentryLogger::getInstance()->setTags([
            'php_version' => PHP_VERSION,
            'server' => 'local-dev'
        ]);
    }
}

// =====================================
// COMMON CONFIGURATION (Both Environments)
// =====================================

// Security headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// CORS configuration
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Use environment-specific fallback
    $fallback = $environment === 'production'
        ? 'https://withlocals.deetech.cc'
        : 'http://localhost:5173';
    header("Access-Control-Allow-Origin: $fallback");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Security headers (prevent common attacks)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Production-only security headers
if ($environment === 'production') {
    // HSTS - enforce HTTPS for 1 year
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    // CSP - restrict resource loading (adjust as needed)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self' https://api.bokun.is https://*.sentry.io;");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create database connection with error handling
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Check connection
    if ($conn->connect_error) {
        $errorMessage = DEBUG
            ? "Database connection failed: " . $conn->connect_error
            : "Database connection failed";

        error_log("Database connection failed in $environment: " . $conn->connect_error);
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['error' => $errorMessage, 'environment' => $environment]);
        exit();
    }

    // Set UTF-8 encoding
    $conn->set_charset("utf8mb4");

} catch (Exception $e) {
    $errorMessage = DEBUG
        ? "Database error: " . $e->getMessage()
        : "Database connection error";

    error_log("Database error in $environment: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => $errorMessage, 'environment' => $environment]);
    exit();
}

// Optional: Add a function to check current environment
function getEnvironment() {
    return ENVIRONMENT;
}

// Optional: Add a function to check if in debug mode
function isDebug() {
    return DEBUG;
}

// Log successful connection in development
if (DEBUG) {
    error_log("Successfully connected to database in " . $environment . " environment");
}

// =====================================
// RATE LIMITING
// =====================================

// Load Rate Limiter
require_once __DIR__ . '/RateLimiter.php';

// Global rate limiter instance (initialized after DB connection)
$rateLimiter = null;

/**
 * Apply rate limiting to current request
 *
 * Usage in endpoint files:
 *   applyRateLimit('login');           // Use predefined 'login' limits
 *   applyRateLimit('read');            // Use predefined 'read' limits
 *   applyRateLimit('custom', 50, 60);  // Custom: 50 requests per minute
 *
 * @param string $type Rate limit type (login, read, write, bokun_sync, etc.)
 * @param int|null $limit Custom limit (overrides predefined)
 * @param int|null $window Custom window in seconds (overrides predefined)
 * @return bool True if allowed, exits with 429 if rate limited
 */
function applyRateLimit($type = 'default', $limit = null, $window = null) {
    global $conn, $rateLimiter;

    // Skip rate limiting in development (configurable)
    if (ENVIRONMENT === 'development' && !EnvLoader::get('RATE_LIMIT_DEV', false)) {
        return true;
    }

    // Initialize rate limiter if not already done
    if ($rateLimiter === null) {
        $rateLimiter = new RateLimiter($conn);
    }

    // Check rate limit
    if (!$rateLimiter->check($type, $limit, $window)) {
        $retryAfter = $rateLimiter->getResetTime();
        RateLimiter::sendTooManyRequestsResponse($retryAfter);
        // sendTooManyRequestsResponse calls exit()
    }

    return true;
}

/**
 * Get rate limit type based on HTTP method and endpoint
 *
 * @param string $endpoint Endpoint name (e.g., 'tours', 'guides', 'auth')
 * @return string Rate limit type
 */
function getRateLimitType($endpoint = '') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Special endpoint handling
    $specialEndpoints = [
        'auth' => 'login',
        'login' => 'login',
        'bokun_sync' => 'bokun_sync',
        'bokun_webhook' => 'webhook'
    ];

    if (isset($specialEndpoints[$endpoint])) {
        return $specialEndpoints[$endpoint];
    }

    // Method-based rate limiting
    switch ($method) {
        case 'GET':
            return 'read';
        case 'POST':
            return 'create';
        case 'PUT':
        case 'PATCH':
            return 'update';
        case 'DELETE':
            return 'delete';
        default:
            return 'default';
    }
}

/**
 * Auto-apply rate limiting based on current request
 * Call this at the start of endpoint files for automatic rate limiting
 *
 * @param string $endpoint Endpoint name for special handling
 */
function autoRateLimit($endpoint = '') {
    $type = getRateLimitType($endpoint);
    applyRateLimit($type);
}
?>