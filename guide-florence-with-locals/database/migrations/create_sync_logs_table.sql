-- Sync Logs Table
-- Tracks all Bokun sync operations for monitoring and debugging
-- Created: January 2026

CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_type ENUM('auto', 'manual', 'full', 'startup', 'periodic') NOT NULL DEFAULT 'auto',
    start_date DATE NOT NULL COMMENT 'Sync range start date',
    end_date DATE NOT NULL COMMENT 'Sync range end date',
    status ENUM('started', 'completed', 'failed', 'partial') NOT NULL DEFAULT 'started',
    bookings_found INT DEFAULT 0 COMMENT 'Total bookings returned from API',
    bookings_synced INT DEFAULT 0 COMMENT 'Successfully synced/updated',
    bookings_created INT DEFAULT 0 COMMENT 'New bookings created',
    bookings_updated INT DEFAULT 0 COMMENT 'Existing bookings updated',
    bookings_failed INT DEFAULT 0 COMMENT 'Failed to process',
    error_message TEXT NULL COMMENT 'Error details if failed',
    duration_seconds DECIMAL(10,2) NULL COMMENT 'Sync duration',
    triggered_by VARCHAR(50) NULL COMMENT 'User or system that triggered sync',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,

    INDEX idx_sync_type (sync_type),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add sync_range_days column to bokun_config for configurable sync range
ALTER TABLE bokun_config
ADD COLUMN IF NOT EXISTS default_sync_days INT DEFAULT 120 COMMENT 'Default sync range in days (4 months)',
ADD COLUMN IF NOT EXISTS full_sync_days INT DEFAULT 365 COMMENT 'Full sync range in days (1 year)';
