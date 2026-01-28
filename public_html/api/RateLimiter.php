<?php
/**
 * Rate Limiter - Florence With Locals
 *
 * Database-backed rate limiting for API endpoints.
 * Protects against brute force, spam, and abuse.
 *
 * SECURITY: Uses database storage (Hostinger-compatible, no Redis required)
 *
 * Usage:
 *   $limiter = new RateLimiter($conn);
 *   if (!$limiter->check('login', 5, 60)) {
 *       // Return 429 Too Many Requests
 *   }
 */
class RateLimiter {
    private $conn;
    private $ip;
    private $endpoint;
    private $limit;
    private $windowSeconds;
    private $currentCount = 0;
    private $windowStart = null;

    // Predefined rate limits for different endpoint types
    const LIMITS = [
        // Authentication - strict limits to prevent brute force
        'login' => ['limit' => 5, 'window' => 60],           // 5 per minute
        'auth' => ['limit' => 10, 'window' => 60],           // 10 per minute

        // API reads - generous limits
        'read' => ['limit' => 100, 'window' => 60],          // 100 per minute
        'list' => ['limit' => 60, 'window' => 60],           // 60 per minute

        // API writes - moderate limits
        'write' => ['limit' => 30, 'window' => 60],          // 30 per minute
        'create' => ['limit' => 20, 'window' => 60],         // 20 per minute
        'update' => ['limit' => 30, 'window' => 60],         // 30 per minute
        'delete' => ['limit' => 10, 'window' => 60],         // 10 per minute

        // Sync operations - strict to prevent API abuse
        'bokun_sync' => ['limit' => 10, 'window' => 60],     // 10 per minute
        'webhook' => ['limit' => 30, 'window' => 60],        // 30 per minute

        // Default fallback
        'default' => ['limit' => 60, 'window' => 60],        // 60 per minute
    ];

    /**
     * Constructor
     *
     * @param mysqli $conn Database connection
     * @param string|null $ip Client IP (auto-detected if null)
     */
    public function __construct($conn, $ip = null) {
        $this->conn = $conn;
        $this->ip = $ip ?? $this->getClientIp();
        $this->ensureTableExists();
    }

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $endpoint Endpoint identifier or type from LIMITS
     * @param int|null $limit Max requests (uses predefined if null)
     * @param int|null $windowSeconds Time window in seconds (uses predefined if null)
     * @return bool True if allowed, false if rate limited
     */
    public function check($endpoint, $limit = null, $windowSeconds = null) {
        $this->endpoint = $endpoint;

        // Use predefined limits if not specified
        $config = self::LIMITS[$endpoint] ?? self::LIMITS['default'];
        $this->limit = $limit ?? $config['limit'];
        $this->windowSeconds = $windowSeconds ?? $config['window'];

        // Clean up old entries periodically (1% chance each request)
        if (rand(1, 100) === 1) {
            $this->cleanup();
        }

        // Get or create rate limit record
        $this->loadOrCreateRecord();

        // Check if within limit
        if ($this->currentCount >= $this->limit) {
            $this->sendRateLimitHeaders(true);
            return false;
        }

        // Increment counter
        $this->incrementCounter();
        $this->sendRateLimitHeaders(false);

        return true;
    }

    /**
     * Check if currently rate limited (without incrementing)
     *
     * @param string $endpoint Endpoint identifier
     * @return bool True if currently rate limited
     */
    public function isLimited($endpoint) {
        $this->endpoint = $endpoint;
        $config = self::LIMITS[$endpoint] ?? self::LIMITS['default'];
        $this->limit = $config['limit'];
        $this->windowSeconds = $config['window'];

        $this->loadOrCreateRecord();

        return $this->currentCount >= $this->limit;
    }

    /**
     * Get remaining attempts in current window
     *
     * @return int Number of remaining requests
     */
    public function getRemainingAttempts() {
        return max(0, $this->limit - $this->currentCount);
    }

    /**
     * Get seconds until rate limit resets
     *
     * @return int Seconds until reset
     */
    public function getResetTime() {
        if (!$this->windowStart) {
            return 0;
        }

        $windowEnd = strtotime($this->windowStart) + $this->windowSeconds;
        $remaining = $windowEnd - time();

        return max(0, $remaining);
    }

    /**
     * Send rate limit HTTP headers
     *
     * @param bool $limited Whether request is being rate limited
     */
    private function sendRateLimitHeaders($limited = false) {
        header("X-RateLimit-Limit: {$this->limit}");
        header("X-RateLimit-Remaining: " . $this->getRemainingAttempts());
        header("X-RateLimit-Reset: " . (time() + $this->getResetTime()));

        if ($limited) {
            header("Retry-After: " . $this->getResetTime());
        }
    }

    /**
     * Load existing record or create new one
     */
    private function loadOrCreateRecord() {
        $now = time();

        // Try to get existing record
        $stmt = $this->conn->prepare("
            SELECT request_count, window_start
            FROM rate_limits
            WHERE ip_address = ? AND endpoint = ?
        ");
        $stmt->bind_param("ss", $this->ip, $this->endpoint);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $windowStart = strtotime($row['window_start']);

            // Check if window has expired
            if ($now - $windowStart >= $this->windowSeconds) {
                // Reset the window
                $this->resetWindow();
                $this->currentCount = 0;
                $this->windowStart = date('Y-m-d H:i:s', $now);
            } else {
                $this->currentCount = (int)$row['request_count'];
                $this->windowStart = $row['window_start'];
            }
        } else {
            // Create new record
            $this->createRecord();
            $this->currentCount = 0;
            $this->windowStart = date('Y-m-d H:i:s', $now);
        }

        $stmt->close();
    }

    /**
     * Create new rate limit record
     */
    private function createRecord() {
        $windowStart = date('Y-m-d H:i:s');

        $stmt = $this->conn->prepare("
            INSERT INTO rate_limits (ip_address, endpoint, request_count, window_start)
            VALUES (?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE request_count = 0, window_start = ?
        ");
        $stmt->bind_param("ssss", $this->ip, $this->endpoint, $windowStart, $windowStart);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Reset window for existing record
     */
    private function resetWindow() {
        $windowStart = date('Y-m-d H:i:s');

        $stmt = $this->conn->prepare("
            UPDATE rate_limits
            SET request_count = 0, window_start = ?
            WHERE ip_address = ? AND endpoint = ?
        ");
        $stmt->bind_param("sss", $windowStart, $this->ip, $this->endpoint);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Increment request counter
     */
    private function incrementCounter() {
        $stmt = $this->conn->prepare("
            UPDATE rate_limits
            SET request_count = request_count + 1
            WHERE ip_address = ? AND endpoint = ?
        ");
        $stmt->bind_param("ss", $this->ip, $this->endpoint);
        $stmt->execute();
        $stmt->close();

        $this->currentCount++;
    }

    /**
     * Clean up expired rate limit records
     */
    private function cleanup() {
        // Delete records older than 1 hour (adjust based on your longest window)
        $this->conn->query("
            DELETE FROM rate_limits
            WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }

    /**
     * Get client IP address (handles proxies)
     *
     * @return string Client IP
     */
    private function getClientIp() {
        // Check for proxy headers (in order of preference)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',            // Shared internet
            'REMOTE_ADDR'                // Fallback
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For can contain multiple IPs, take the first
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Ensure rate_limits table exists
     */
    private function ensureTableExists() {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                endpoint VARCHAR(100) NOT NULL,
                request_count INT UNSIGNED DEFAULT 0,
                window_start DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),
                INDEX idx_window_start (window_start),
                INDEX idx_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Get rate limit info for monitoring/debugging
     *
     * @return array Rate limit information
     */
    public function getInfo() {
        return [
            'ip' => $this->ip,
            'endpoint' => $this->endpoint,
            'limit' => $this->limit,
            'window_seconds' => $this->windowSeconds,
            'current_count' => $this->currentCount,
            'remaining' => $this->getRemainingAttempts(),
            'reset_in' => $this->getResetTime(),
            'window_start' => $this->windowStart
        ];
    }

    /**
     * Static helper to send 429 response
     *
     * @param int $retryAfter Seconds until retry is allowed
     * @param string $message Custom error message
     */
    public static function sendTooManyRequestsResponse($retryAfter = 60, $message = null) {
        http_response_code(429);
        header("Retry-After: $retryAfter");
        header("Content-Type: application/json");

        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => $message ?? "Rate limit exceeded. Please wait $retryAfter seconds before retrying.",
            'retry_after' => $retryAfter
        ]);
        exit();
    }
}
