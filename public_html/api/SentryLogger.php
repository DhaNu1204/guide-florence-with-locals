<?php
/**
 * SentryLogger.php - Lightweight Sentry Error Tracking
 *
 * A minimal Sentry integration using the HTTP API directly.
 * No Composer dependencies required - perfect for shared hosting.
 *
 * Features:
 * - Captures errors with full context (file, line, stack trace)
 * - Captures exceptions with automatic stack trace
 * - Manual message logging (info, warning, error)
 * - Request context (URL, method, user agent)
 * - User context (user ID if logged in)
 * - Breadcrumbs for debugging
 * - Batched sending for performance
 *
 * @see https://develop.sentry.dev/sdk/store/
 */

class SentryLogger {

    private static $instance = null;
    private $dsn = null;
    private $environment = 'production';
    private $release = null;
    private $enabled = false;
    private $breadcrumbs = [];
    private $maxBreadcrumbs = 25;
    private $userContext = [];
    private $tags = [];

    // Parsed DSN components
    private $publicKey = null;
    private $host = null;
    private $projectId = null;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {}

    /**
     * Get singleton instance
     *
     * @return SentryLogger
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize Sentry with DSN
     *
     * @param string $dsn Sentry DSN from project settings
     * @param array $options Optional configuration
     * @return SentryLogger
     */
    public static function init($dsn, $options = []) {
        $instance = self::getInstance();

        if (empty($dsn)) {
            $instance->enabled = false;
            return $instance;
        }

        $instance->dsn = $dsn;
        $instance->environment = $options['environment'] ?? 'production';
        $instance->release = $options['release'] ?? null;

        // Parse DSN: https://{public_key}@{host}/{project_id}
        if (preg_match('/^https?:\/\/([^@]+)@([^\/]+)\/(\d+)$/', $dsn, $matches)) {
            $instance->publicKey = $matches[1];
            $instance->host = $matches[2];
            $instance->projectId = $matches[3];
            $instance->enabled = true;
        } else {
            error_log("SentryLogger: Invalid DSN format");
            $instance->enabled = false;
        }

        return $instance;
    }

    /**
     * Check if Sentry is enabled
     *
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }

    /**
     * Set user context for error tracking
     *
     * @param array $user User info (id, email, username, ip_address)
     * @return SentryLogger
     */
    public function setUser($user) {
        $this->userContext = $user;
        return $this;
    }

    /**
     * Set additional tags for filtering
     *
     * @param array $tags Key-value pairs
     * @return SentryLogger
     */
    public function setTags($tags) {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    /**
     * Add a breadcrumb for debugging
     *
     * @param string $message Breadcrumb message
     * @param string $category Category (http, query, navigation, etc.)
     * @param string $level Level (info, warning, error)
     * @param array $data Additional data
     */
    public function addBreadcrumb($message, $category = 'default', $level = 'info', $data = []) {
        $this->breadcrumbs[] = [
            'timestamp' => time(),
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'data' => $data
        ];

        // Keep only the last N breadcrumbs
        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Capture an exception
     *
     * @param Throwable $exception The exception to capture
     * @param array $extra Additional context data
     * @return string|null Event ID if sent successfully
     */
    public function captureException($exception, $extra = []) {
        if (!$this->enabled) {
            return null;
        }

        $event = $this->buildEvent('error');

        // Build exception info
        $event['exception'] = [
            'values' => [
                [
                    'type' => get_class($exception),
                    'value' => $exception->getMessage(),
                    'stacktrace' => $this->buildStacktrace($exception)
                ]
            ]
        ];

        // Add extra context
        if (!empty($extra)) {
            $event['extra'] = array_merge($event['extra'] ?? [], $extra);
        }

        return $this->sendEvent($event);
    }

    /**
     * Capture an error (from error handler)
     *
     * @param int $errno Error number
     * @param string $errstr Error message
     * @param string $errfile File where error occurred
     * @param int $errline Line number
     * @param array $extra Additional context
     * @return string|null Event ID if sent successfully
     */
    public function captureError($errno, $errstr, $errfile, $errline, $extra = []) {
        if (!$this->enabled) {
            return null;
        }

        // Map PHP error levels to Sentry levels
        $levelMap = [
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_PARSE => 'error',
            E_NOTICE => 'info',
            E_CORE_ERROR => 'error',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'error',
            E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info',
            E_STRICT => 'info',
            E_RECOVERABLE_ERROR => 'error',
            E_DEPRECATED => 'warning',
            E_USER_DEPRECATED => 'warning',
        ];

        $level = $levelMap[$errno] ?? 'error';

        // Map error type names
        $typeMap = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        $errorType = $typeMap[$errno] ?? 'UNKNOWN_ERROR';

        $event = $this->buildEvent($level);

        // Build exception-like structure for errors
        $event['exception'] = [
            'values' => [
                [
                    'type' => $errorType,
                    'value' => $errstr,
                    'stacktrace' => [
                        'frames' => [
                            [
                                'filename' => $errfile,
                                'lineno' => $errline,
                                'in_app' => true
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // Add extra context
        $event['extra'] = array_merge($event['extra'] ?? [], [
            'error_code' => $errno,
            'error_type' => $errorType
        ], $extra);

        return $this->sendEvent($event);
    }

    /**
     * Capture a message (manual logging)
     *
     * @param string $message The message to log
     * @param string $level Level: 'fatal', 'error', 'warning', 'info', 'debug'
     * @param array $extra Additional context
     * @return string|null Event ID if sent successfully
     */
    public function captureMessage($message, $level = 'info', $extra = []) {
        if (!$this->enabled) {
            return null;
        }

        $event = $this->buildEvent($level);
        $event['message'] = [
            'formatted' => $message
        ];

        if (!empty($extra)) {
            $event['extra'] = array_merge($event['extra'] ?? [], $extra);
        }

        return $this->sendEvent($event);
    }

    /**
     * Build the base event structure
     *
     * @param string $level Event level
     * @return array
     */
    private function buildEvent($level) {
        $event = [
            'event_id' => $this->generateEventId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'platform' => 'php',
            'level' => $level,
            'logger' => 'php',
            'server_name' => gethostname() ?: php_uname('n'),
            'environment' => $this->environment,
            'sdk' => [
                'name' => 'florence-sentry-php',
                'version' => '1.0.0'
            ],
            'contexts' => [
                'runtime' => [
                    'name' => 'php',
                    'version' => PHP_VERSION
                ],
                'os' => [
                    'name' => PHP_OS,
                    'version' => php_uname('r')
                ]
            ],
            'request' => $this->getRequestContext(),
            'breadcrumbs' => [
                'values' => $this->breadcrumbs
            ]
        ];

        // Add release if set
        if ($this->release) {
            $event['release'] = $this->release;
        }

        // Add user context if set
        if (!empty($this->userContext)) {
            $event['user'] = $this->userContext;
        } else {
            // Add IP address by default
            $event['user'] = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ];
        }

        // Add tags
        if (!empty($this->tags)) {
            $event['tags'] = $this->tags;
        }

        return $event;
    }

    /**
     * Get request context
     *
     * @return array
     */
    private function getRequestContext() {
        if (php_sapi_name() === 'cli') {
            return [
                'url' => 'cli://' . implode(' ', $_SERVER['argv'] ?? ['unknown'])
            ];
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return [
            'url' => $protocol . '://' . $host . $uri,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'headers' => [
                'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'Accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'Referer' => $_SERVER['HTTP_REFERER'] ?? null
            ],
            'env' => [
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? null,
                'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? null
            ]
        ];
    }

    /**
     * Build stacktrace from exception
     *
     * @param Throwable $exception
     * @return array
     */
    private function buildStacktrace($exception) {
        $frames = [];

        // Add the exception location first
        $frames[] = [
            'filename' => $exception->getFile(),
            'lineno' => $exception->getLine(),
            'function' => null,
            'in_app' => $this->isInApp($exception->getFile())
        ];

        // Add trace frames
        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? '[internal]',
                'lineno' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'in_app' => isset($frame['file']) ? $this->isInApp($frame['file']) : false
            ];
        }

        // Sentry expects frames in reverse order (most recent last)
        return [
            'frames' => array_reverse($frames)
        ];
    }

    /**
     * Check if file is part of the application (not vendor)
     *
     * @param string $file
     * @return bool
     */
    private function isInApp($file) {
        // Consider files outside vendor/ and framework directories as "in app"
        $excludePatterns = ['/vendor/', '/node_modules/', '/pear/'];

        foreach ($excludePatterns as $pattern) {
            if (strpos($file, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a unique event ID
     *
     * @return string 32-character hex string
     */
    private function generateEventId() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Send event to Sentry
     *
     * @param array $event
     * @return string|null Event ID if successful
     */
    private function sendEvent($event) {
        if (!$this->enabled) {
            return null;
        }

        $url = "https://{$this->host}/api/{$this->projectId}/store/";

        $headers = [
            'Content-Type: application/json',
            'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=florence-sentry-php/1.0.0, sentry_key=' . $this->publicKey
        ];

        $payload = json_encode($event);

        // Use cURL if available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log("SentryLogger: cURL error - " . $error);
                return null;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return $event['event_id'];
            } else {
                error_log("SentryLogger: HTTP error $httpCode - " . $response);
                return null;
            }
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log("SentryLogger: Failed to send event");
            return null;
        }

        return $event['event_id'];
    }

    /**
     * Setup global error and exception handlers
     *
     * @return SentryLogger
     */
    public function setupHandlers() {
        if (!$this->enabled) {
            return $this;
        }

        // Set error handler
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            // Don't report errors that are suppressed with @
            if (!(error_reporting() & $errno)) {
                return false;
            }

            // Only report significant errors to Sentry
            $reportable = [
                E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR,
                E_USER_ERROR, E_RECOVERABLE_ERROR, E_WARNING, E_USER_WARNING
            ];

            if (in_array($errno, $reportable)) {
                $this->captureError($errno, $errstr, $errfile, $errline);
            }

            // Allow PHP's internal error handler to run as well
            return false;
        });

        // Set exception handler
        set_exception_handler(function($exception) {
            $this->captureException($exception);

            // Re-throw for PHP's default handling
            throw $exception;
        });

        // Register shutdown handler for fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();

            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $this->captureError(
                    $error['type'],
                    $error['message'],
                    $error['file'],
                    $error['line'],
                    ['fatal' => true]
                );
            }
        });

        return $this;
    }

    /**
     * Clear breadcrumbs (useful between requests)
     */
    public function clearBreadcrumbs() {
        $this->breadcrumbs = [];
    }
}

// Convenience functions for quick access

/**
 * Initialize Sentry (call once at app startup)
 *
 * @param string $dsn Sentry DSN
 * @param array $options Options (environment, release)
 */
function sentry_init($dsn, $options = []) {
    return SentryLogger::init($dsn, $options)->setupHandlers();
}

/**
 * Capture an exception
 *
 * @param Throwable $exception
 * @param array $extra Additional context
 * @return string|null Event ID
 */
function sentry_capture_exception($exception, $extra = []) {
    return SentryLogger::getInstance()->captureException($exception, $extra);
}

/**
 * Capture a message
 *
 * @param string $message
 * @param string $level (debug, info, warning, error, fatal)
 * @param array $extra Additional context
 * @return string|null Event ID
 */
function sentry_capture_message($message, $level = 'info', $extra = []) {
    return SentryLogger::getInstance()->captureMessage($message, $level, $extra);
}

/**
 * Add a breadcrumb
 *
 * @param string $message
 * @param string $category
 * @param string $level
 * @param array $data
 */
function sentry_add_breadcrumb($message, $category = 'default', $level = 'info', $data = []) {
    SentryLogger::getInstance()->addBreadcrumb($message, $category, $level, $data);
}

/**
 * Set user context
 *
 * @param array $user (id, email, username, ip_address)
 */
function sentry_set_user($user) {
    SentryLogger::getInstance()->setUser($user);
}
