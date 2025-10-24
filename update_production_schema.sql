-- ============================================================
-- PRODUCTION DATABASE SCHEMA UPDATE
-- Adds missing columns to match development environment
-- Database: u803853690_withlocals
-- Date: October 24, 2025
-- ============================================================

USE u803853690_withlocals;

-- 1. Add language column (for multi-channel language detection)
ALTER TABLE tours
ADD COLUMN language VARCHAR(50) DEFAULT NULL
COMMENT 'Tour language from Bokun API'
AFTER title;

-- 2. Add rescheduling support columns
ALTER TABLE tours
ADD COLUMN rescheduled TINYINT(1) DEFAULT 0
COMMENT 'Rescheduling flag'
AFTER cancellation_reason;

ALTER TABLE tours
ADD COLUMN original_date DATE DEFAULT NULL
COMMENT 'Original tour date before rescheduling'
AFTER rescheduled;

ALTER TABLE tours
ADD COLUMN original_time TIME DEFAULT NULL
COMMENT 'Original tour time before rescheduling'
AFTER original_date;

ALTER TABLE tours
ADD COLUMN rescheduled_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'When tour was rescheduled'
AFTER original_time;

-- 3. Add payment_notes column (optional)
ALTER TABLE tours
ADD COLUMN payment_notes TEXT DEFAULT NULL
COMMENT 'Payment-related notes'
AFTER expected_amount;

-- 4. Add indexes for better query performance
ALTER TABLE tours ADD KEY idx_language (language);
ALTER TABLE tours ADD KEY idx_rescheduled (rescheduled);

-- 5. Verify the schema
SELECT 'Schema update completed successfully!' AS status;
SELECT COUNT(*) AS total_columns FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u803853690_withlocals' AND TABLE_NAME = 'tours';

SHOW COLUMNS FROM tours WHERE Field IN ('language', 'rescheduled', 'original_date', 'original_time', 'rescheduled_at', 'payment_notes');
