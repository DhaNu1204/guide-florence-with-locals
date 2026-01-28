-- Rate Limits Table Migration
-- Florence With Locals - Security Enhancement
--
-- Purpose: Store rate limit tracking data per IP/endpoint
-- Storage: Database-backed (Hostinger compatible, no Redis)
--
-- Run this migration on production:
-- mysql -u [user] -p [database] < create_rate_limits_table.sql

-- Create rate_limits table
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Request identification
    ip_address VARCHAR(45) NOT NULL COMMENT 'Client IP (supports IPv6)',
    endpoint VARCHAR(100) NOT NULL COMMENT 'API endpoint or rate limit type identifier',

    -- Rate tracking
    request_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of requests in current window',
    window_start DATETIME NOT NULL COMMENT 'Start time of current rate limit window',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),

    -- Indexes for performance
    INDEX idx_window_start (window_start),
    INDEX idx_ip (ip_address),
    INDEX idx_endpoint (endpoint),
    INDEX idx_cleanup (window_start, ip_address)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='API rate limiting storage - tracks requests per IP/endpoint';

-- Cleanup event (optional - if MySQL Event Scheduler is enabled)
-- This automatically cleans old records every hour
DELIMITER //
CREATE EVENT IF NOT EXISTS cleanup_rate_limits
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR);
END//
DELIMITER ;

-- Enable event scheduler if not already enabled (requires SUPER privilege)
-- SET GLOBAL event_scheduler = ON;

-- Grant permissions (adjust user as needed)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON rate_limits TO 'your_app_user'@'localhost';
