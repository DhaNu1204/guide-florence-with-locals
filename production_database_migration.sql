-- ============================================================
-- PRODUCTION DATABASE MIGRATION SCRIPT
-- Florence with Locals Tour Guide Management System
-- Date: October 24, 2025
-- Database: u803853690_withlocals
-- ============================================================

-- IMPORTANT: Run this script in your MySQL database
-- Via PHPMyAdmin or MySQL command line

USE u803853690_withlocals;

-- ============================================================
-- STEP 1: Check current schema (optional - for verification)
-- ============================================================

SELECT 'BEFORE MIGRATION - Current columns in tours table:' AS Info;
SHOW COLUMNS FROM tours;

-- ============================================================
-- STEP 2: Add missing columns
-- ============================================================

-- Add language column (for multi-channel language detection)
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS language VARCHAR(50) DEFAULT NULL
COMMENT 'Tour language from Bokun API (Viator, GetYourGuide, etc.)'
AFTER title;

SELECT 'Added column: language' AS Status;

-- Add rescheduling flag
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS rescheduled TINYINT(1) DEFAULT 0
COMMENT 'Rescheduling flag'
AFTER cancellation_reason;

SELECT 'Added column: rescheduled' AS Status;

-- Add original_date column
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS original_date DATE DEFAULT NULL
COMMENT 'Original tour date before rescheduling'
AFTER rescheduled;

SELECT 'Added column: original_date' AS Status;

-- Add original_time column
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS original_time TIME DEFAULT NULL
COMMENT 'Original tour time before rescheduling'
AFTER original_date;

SELECT 'Added column: original_time' AS Status;

-- Add rescheduled_at column
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS rescheduled_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'When tour was rescheduled'
AFTER original_time;

SELECT 'Added column: rescheduled_at' AS Status;

-- Add payment_notes column
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS payment_notes TEXT DEFAULT NULL
COMMENT 'Payment-related notes'
AFTER expected_amount;

SELECT 'Added column: payment_notes' AS Status;

-- ============================================================
-- STEP 3: Add indexes for better performance
-- ============================================================

-- Add index for language column
ALTER TABLE tours ADD INDEX IF NOT EXISTS idx_language (language);

SELECT 'Added index: idx_language' AS Status;

-- Add index for rescheduled column
ALTER TABLE tours ADD INDEX IF NOT EXISTS idx_rescheduled (rescheduled);

SELECT 'Added index: idx_rescheduled' AS Status;

-- ============================================================
-- STEP 4: Verify the migration (show new schema)
-- ============================================================

SELECT 'AFTER MIGRATION - Updated columns in tours table:' AS Info;
SHOW COLUMNS FROM tours;

-- ============================================================
-- STEP 5: Verify specific columns were added
-- ============================================================

SELECT 'Verifying new columns...' AS Info;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u803853690_withlocals'
    AND TABLE_NAME = 'tours'
    AND COLUMN_NAME IN ('language', 'rescheduled', 'original_date', 'original_time', 'rescheduled_at', 'payment_notes')
ORDER BY ORDINAL_POSITION;

-- ============================================================
-- SUCCESS MESSAGE
-- ============================================================

SELECT '✅ MIGRATION COMPLETED SUCCESSFULLY!' AS Status;
SELECT 'Added 6 new columns to tours table' AS Details;
SELECT 'Features enabled: Language detection, Rescheduling support' AS Features;

-- ============================================================
-- END OF MIGRATION
-- ============================================================
