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

// Step 0.1: an explicit APP_ENV=staging in the server .env (read by EnvLoader)
// turns a production-detected host into the staging environment. Staging behaves
// exactly like production (no error display, rate limiting, HSTS/CSP, production
// CORS list) plus the staging origin. The development default is unchanged (step 2.1).
$appEnv = strtolower(trim((string) EnvLoader::get('APP_ENV', '')));
if ($appEnv === 'staging') {
    $environment = 'staging';
} elseif ($appEnv === 'local' || $appEnv === 'development') {
    $environment = 'development'; // step 2.1: explicit local env
} elseif ($appEnv === 'production') {
    $environment = 'production';
}

// Load environment-specific configuration
if ($environment === 'production' || $environment === 'staging') {
    // =====================================
    // PRODUCTION CONFIGURATION (also used by staging)
    // =====================================
    // SECURITY: Credentials loaded from environment variables
    $db_host = EnvLoader::get('DB_HOST', 'localhost');
    $db_user = EnvLoader::get('DB_USER', 'u803853690_withlocals');
    $db_pass = EnvLoader::get('DB_PASS', '');  // REQUIRED: Set in .env file
    $db_name = EnvLoader::get('DB_NAME', 'u803853690_withlocals');

    // Production CORS settings (step 2.1: https only, no plain-http origin)
    $allowed_origins = [
        'https://withlocals.deetech.cc'
    ];
    if ($environment === 'staging') {
        $allowed_origins[] = 'https://stagingwithlocals.deetech.cc';
    }

    // Production environment flags
    define('ENVIRONMENT', $environment);
    define('DEBUG', false);
    $publicBaseUrl = ($environment === 'staging')
        ? 'https://stagingwithlocals.deetech.cc'
        : 'https://withlocals.deetech.cc';
    define('BASE_URL', $publicBaseUrl);
    define('API_URL', $publicBaseUrl . '/api');

    // Disable error display in production
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);

    // Initialize Sentry error tracking in production
    $sentryDsn = EnvLoader::get('SENTRY_DSN', '');
    if (!empty($sentryDsn)) {
        SentryLogger::init($sentryDsn, [
            'environment' => EnvLoader::get('SENTRY_ENVIRONMENT', $environment),
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

    // Step 2.1: exactly one env parser. EnvLoader already reads .env.local first
    // (project root), then .env, so local dev needs nothing else.
    $db_host = EnvLoader::get('DB_HOST', 'localhost');
    $db_user = EnvLoader::get('DB_USER', 'root');
    $db_pass = EnvLoader::get('DB_PASS', '');
    $db_name = EnvLoader::get('DB_NAME', 'florence_guides');

    // Development CORS: the Vite dev server only (step 2.1)
    $allowed_origins = [
        'http://localhost:5173'
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

// Step 2.1: PHP error log OUTSIDE the web root. FWL_LOG_DIR (server .env) wins; the
// default is <home>/logs (never inside public_html), with a per-environment file so
// staging and production on the same account do not share one log. The first request
// that creates the file writes one startup line so the path can be verified.
function fwlResolveLogDir() {
    $configured = trim((string) EnvLoader::get('FWL_LOG_DIR', ''));
    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }
    $home = getenv('HOME');
    if (!$home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $home = $pw['dir'] ?? '';
    }
    if (!$home) {
        // Hostinger layout: <home>/domains/<domain>/public_html/<site>/api -> five levels up
        $candidate = dirname(__DIR__, 5);
        if (is_dir($candidate . '/domains')) {
            $home = $candidate;
        }
    }
    if ($home && strpos(__DIR__, 'public_html') !== false && strpos($home, 'public_html') === false) {
        return rtrim($home, '/\\') . '/logs';
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/fwl-logs';
}
$fwlLogDir = fwlResolveLogDir();
$fwlLogFile = $fwlLogDir . '/api-error' . ($environment === 'production' ? '' : '-' . $environment) . '.log';
if (!is_dir($fwlLogDir)) {
    @mkdir($fwlLogDir, 0700, true);
}
if (is_dir($fwlLogDir) && is_writable($fwlLogDir)) {
    $fwlLogIsNew = !file_exists($fwlLogFile);
    ini_set('log_errors', 1);
    ini_set('error_log', $fwlLogFile);
    if ($fwlLogIsNew) {
        error_log('api log started (env=' . $environment . ', php=' . PHP_VERSION . ', sapi=' . php_sapi_name() . ')');
    }
}


// Security headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// CORS configuration (step 2.1): an origin is either in the list or gets NO
// Access-Control-Allow-Origin header at all - no echo, no fallback, no '*'.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$originAllowed = ($origin !== '' && in_array($origin, $allowed_origins, true));
header('Vary: Origin');
if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 600");
}
header("Content-Type: application/json");

// Security headers (prevent common attacks)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Environment-specific security headers
if ($environment === 'production' || $environment === 'staging') {
    // HSTS - enforce HTTPS for 1 year
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    // CSP - restrict resource loading
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self' https://api.bokun.is https://*.sentry.io;");
} else {
    // Development CSP - more permissive but still protective
    header("Content-Security-Policy: default-src 'self' http://localhost:*; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:*; style-src 'self' 'unsafe-inline' http://localhost:*; img-src 'self' data: http://localhost:*; connect-src 'self' http://localhost:* https://api.bokun.is;");
}

// Handle preflight OPTIONS request (step 2.1: 204, CORS headers only for an allowed origin)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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
        echo json_encode(['error' => $errorMessage]);
        exit();
    }

    // Set UTF-8 encoding
    $conn->set_charset("utf8mb4");

    // Step 2.1: the database clock is pinned to UTC (NOW(), CURRENT_TIMESTAMP, expires_at, sync_logs,
    // rate limits) even if the host ever changes its system zone; PHP deliberately stays on
    // Europe/Rome (date_default_timezone_set at the top) because reminder scheduling and tour day
    // boundaries are business-local. Split on purpose: DB stores UTC, PHP works in Europe/Rome.
    $conn->query("SET time_zone = '+00:00'");

} catch (Exception $e) {
    $errorMessage = DEBUG
        ? "Database error: " . $e->getMessage()
        : "Database connection error";

    error_log("Database error in $environment: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => $errorMessage]);
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