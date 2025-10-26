USE u803853690_withlocals;

-- First, let's see which columns exist
SELECT 'Checking existing columns...' AS Status;

SELECT COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u803853690_withlocals'
  AND TABLE_NAME = 'tours'
  AND COLUMN_NAME IN ('language', 'rescheduled', 'original_date', 'original_time', 'rescheduled_at', 'payment_notes');
