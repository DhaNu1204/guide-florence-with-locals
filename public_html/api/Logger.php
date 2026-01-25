<?php
/**
 * Logger.php - File-Based Logging System
 *
 * Provides structured logging to files with:
 * - Multiple log levels (debug, info, warning, error, critical)
 * - Automatic log rotation
 * - JSON-formatted context data
 * - Environment-aware logging
 */

class Logger {

    // Log levels
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    // Log directory (relative to API folder)
    private static $logDir = null;

    // Log file names
    private static $logFiles = [
        'app' => 'app.log',
        'error' => 'error.log',
        'api' => 'api.log',
        'bokun' => 'bokun.log'
    ];

    // Maximum log file size before rotation (5MB)
    private static $maxFileSize = 5242880;

    // Number of rotated files to keep
    private static $maxFiles = 5;

    /**
     * Initialize log directory
     */
    private static function init() {
        if (self::$logDir === null) {
            // Create logs directory in project root
            self::$logDir = dirname(__DIR__) . '/logs';

            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);

                // Create .htaccess to prevent direct access
                file_put_contents(
                    self::$logDir . '/.htaccess',
                    "Order deny,allow\nDeny from all"
                );
            }
        }
    }

    /**
     * Write a log entry
     */
    private static function write($level, $message, $context = [], $logType = 'app') {
        self::init();

        $logFile = self::$logDir . '/' . (self::$logFiles[$logType] ?? 'app.log');

        // Check if rotation needed
        self::rotateIfNeeded($logFile);

        // Build log entry
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $entry = sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            $level,
            $message,
            $contextStr
        );

        // Write to file
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        // Also log to PHP error log for critical errors
        if (in_array($level, [self::ERROR, self::CRITICAL])) {
            error_log("[$level] $message" . ($context ? ' ' . json_encode($context) : ''));
        }
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private static function rotateIfNeeded($logFile) {
        if (!file_exists($logFile)) {
            return;
        }

        if (filesize($logFile) >= self::$maxFileSize) {
            // Rotate existing files
            for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
                $oldFile = $logFile . '.' . $i;
                $newFile = $logFile . '.' . ($i + 1);

                if (file_exists($oldFile)) {
                    if ($i == self::$maxFiles - 1) {
                        unlink($oldFile); // Delete oldest
                    } else {
                        rename($oldFile, $newFile);
                    }
                }
            }

            // Rename current log
            rename($logFile, $logFile . '.1');
        }
    }

    /**
     * Log debug message (only in development)
     */
    public static function debug($message, $context = []) {
        if (defined('DEBUG') && DEBUG) {
            self::write(self::DEBUG, $message, $context);
        }
    }

    /**
     * Log info message
     */
    public static function info($message, $context = []) {
        self::write(self::INFO, $message, $context);
    }

    /**
     * Log warning message
     */
    public static function warning($message, $context = []) {
        self::write(self::WARNING, $message, $context);
    }

    /**
     * Log error message
     */
    public static function error($message, $context = []) {
        self::write(self::ERROR, $message, $context, 'error');
    }

    /**
     * Log critical message
     */
    public static function critical($message, $context = []) {
        self::write(self::CRITICAL, $message, $context, 'error');
    }

    /**
     * Log API request
     */
    public static function apiRequest($method, $endpoint, $data = [], $response = null) {
        self::write(self::INFO, "API $method $endpoint", [
            'request' => $data,
            'response' => $response
        ], 'api');
    }

    /**
     * Log Bokun sync activity
     */
    public static function bokunSync($action, $details = []) {
        self::write(self::INFO, "Bokun: $action", $details, 'bokun');
    }

    /**
     * Log Bokun sync error
     */
    public static function bokunError($message, $details = []) {
        self::write(self::ERROR, "Bokun Error: $message", $details, 'bokun');
    }

    /**
     * Log exception with full details
     */
    public static function exception($exception, $additionalContext = []) {
        $context = array_merge([
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ], $additionalContext);

        self::write(self::ERROR, 'Exception: ' . get_class($exception), $context, 'error');
    }

    /**
     * Get recent log entries
     */
    public static function getRecent($logType = 'app', $lines = 100) {
        self::init();

        $logFile = self::$logDir . '/' . (self::$logFiles[$logType] ?? 'app.log');

        if (!file_exists($logFile)) {
            return [];
        }

        // Read last N lines efficiently
        $file = new SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $file->seek($startLine);

        $entries = [];
        while (!$file->eof()) {
            $line = $file->fgets();
            if (trim($line)) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Clear a log file
     */
    public static function clear($logType = 'app') {
        self::init();

        $logFile = self::$logDir . '/' . (self::$logFiles[$logType] ?? 'app.log');

        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
            return true;
        }

        return false;
    }
}
