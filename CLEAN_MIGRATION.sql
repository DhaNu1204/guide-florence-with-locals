USE u803853690_withlocals;

ALTER TABLE tours ADD COLUMN language VARCHAR(50) DEFAULT NULL COMMENT 'Tour language from Bokun API' AFTER title;

ALTER TABLE tours ADD COLUMN rescheduled TINYINT(1) DEFAULT 0 COMMENT 'Rescheduling flag' AFTER cancellation_reason;

ALTER TABLE tours ADD COLUMN original_date DATE DEFAULT NULL COMMENT 'Original tour date before rescheduling' AFTER rescheduled;

ALTER TABLE tours ADD COLUMN original_time TIME DEFAULT NULL COMMENT 'Original tour time before rescheduling' AFTER original_date;

ALTER TABLE tours ADD COLUMN rescheduled_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When tour was rescheduled' AFTER original_time;

ALTER TABLE tours ADD COLUMN payment_notes TEXT DEFAULT NULL COMMENT 'Payment-related notes' AFTER expected_amount;

ALTER TABLE tours ADD INDEX idx_language (language);

ALTER TABLE tours ADD INDEX idx_rescheduled (rescheduled);

SELECT 'Migration completed successfully!' AS Status;
