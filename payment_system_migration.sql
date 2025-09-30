-- Payment System Migration Script
-- Date: 2025-09-19
-- Purpose: Implement comprehensive payment tracking system for Florence Tours

USE florence_guides;

-- Create payment_transactions table for detailed payment tracking
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tour_id INT NOT NULL,
    guide_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'credit_card', 'paypal', 'other') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    payment_time TIME DEFAULT NULL,
    transaction_reference VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    recorded_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key constraints
    FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE,
    FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE,

    -- Indexes for performance
    INDEX idx_tour_payment (tour_id),
    INDEX idx_guide_payment (guide_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_method (payment_method)
);

-- Add enhanced payment status to tours table
-- Keep existing 'paid' column for backward compatibility
-- Add new payment_status column for detailed tracking
ALTER TABLE tours
ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid', 'partial', 'paid', 'overpaid') NOT NULL DEFAULT 'unpaid',
ADD COLUMN IF NOT EXISTS total_amount_paid DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS expected_amount DECIMAL(10,2) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS payment_notes TEXT DEFAULT NULL;

-- Create index for new payment fields (check if exists first)
-- Note: Using ALTER TABLE as CREATE INDEX IF NOT EXISTS is not supported in all MySQL versions
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = 'florence_guides'
     AND table_name = 'tours'
     AND index_name = 'idx_tours_payment_status') > 0,
    'SELECT "Index idx_tours_payment_status already exists" as status',
    'CREATE INDEX idx_tours_payment_status ON tours(payment_status)'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = 'florence_guides'
     AND table_name = 'tours'
     AND index_name = 'idx_tours_amount_paid') > 0,
    'SELECT "Index idx_tours_amount_paid already exists" as status',
    'CREATE INDEX idx_tours_amount_paid ON tours(total_amount_paid)'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create guide_payment_summary view for quick access to guide payment totals
CREATE OR REPLACE VIEW guide_payment_summary AS
SELECT
    g.id as guide_id,
    g.name as guide_name,
    g.email as guide_email,
    COUNT(DISTINCT t.id) as total_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_tours,
    COALESCE(SUM(pt.amount), 0) as total_payments_received,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END), 0) as cash_payments,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END), 0) as bank_payments,
    COUNT(DISTINCT pt.id) as total_payment_transactions,
    MIN(pt.payment_date) as first_payment_date,
    MAX(pt.payment_date) as last_payment_date
FROM guides g
LEFT JOIN tours t ON g.id = t.guide_id
LEFT JOIN payment_transactions pt ON t.id = pt.tour_id
GROUP BY g.id, g.name, g.email;

-- Create monthly payment summary view for reporting
CREATE OR REPLACE VIEW monthly_payment_summary AS
SELECT
    YEAR(pt.payment_date) as payment_year,
    MONTH(pt.payment_date) as payment_month,
    MONTHNAME(pt.payment_date) as month_name,
    g.id as guide_id,
    g.name as guide_name,
    COUNT(DISTINCT pt.tour_id) as tours_paid,
    COUNT(pt.id) as payment_transactions,
    SUM(pt.amount) as total_amount,
    SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END) as cash_amount,
    SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END) as bank_amount,
    pt.payment_method,
    AVG(pt.amount) as avg_payment_amount
FROM payment_transactions pt
JOIN guides g ON pt.guide_id = g.id
GROUP BY YEAR(pt.payment_date), MONTH(pt.payment_date), g.id, pt.payment_method
ORDER BY payment_year DESC, payment_month DESC, g.name;

-- Update existing tours to set default payment status based on current 'paid' field
UPDATE tours
SET payment_status = CASE
    WHEN paid = 1 THEN 'paid'
    ELSE 'unpaid'
END,
total_amount_paid = CASE
    WHEN paid = 1 THEN 0.00  -- Will be updated when actual payments are recorded
    ELSE 0.00
END
WHERE payment_status = 'unpaid'; -- Only update if not already set

-- Create trigger to automatically update tour payment_status when payment_transactions change
DELIMITER //

CREATE TRIGGER IF NOT EXISTS update_tour_payment_status_after_payment_insert
AFTER INSERT ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = NEW.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = NEW.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END  -- Keep backward compatibility
    WHERE id = NEW.tour_id;
END//

CREATE TRIGGER IF NOT EXISTS update_tour_payment_status_after_payment_update
AFTER UPDATE ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = NEW.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = NEW.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END
    WHERE id = NEW.tour_id;
END//

CREATE TRIGGER IF NOT EXISTS update_tour_payment_status_after_payment_delete
AFTER DELETE ON payment_transactions
FOR EACH ROW
BEGIN
    DECLARE total_paid DECIMAL(10,2);
    DECLARE expected DECIMAL(10,2);

    -- Calculate total amount paid for this tour
    SELECT COALESCE(SUM(amount), 0) INTO total_paid
    FROM payment_transactions
    WHERE tour_id = OLD.tour_id;

    -- Get expected amount (if set)
    SELECT expected_amount INTO expected
    FROM tours
    WHERE id = OLD.tour_id;

    -- Update tour payment status and total
    UPDATE tours
    SET
        total_amount_paid = total_paid,
        payment_status = CASE
            WHEN total_paid = 0 THEN 'unpaid'
            WHEN expected IS NULL OR total_paid >= expected THEN 'paid'
            WHEN total_paid > 0 AND total_paid < expected THEN 'partial'
            WHEN total_paid > expected THEN 'overpaid'
            ELSE 'unpaid'
        END,
        paid = CASE WHEN total_paid > 0 THEN 1 ELSE 0 END
    WHERE id = OLD.tour_id;
END//

DELIMITER ;

-- Insert sample payment transactions for testing (using tour IDs that should exist)
-- Note: These will only insert if the tours exist
INSERT IGNORE INTO payment_transactions (tour_id, guide_id, amount, payment_method, payment_date, payment_time, notes)
SELECT
    t.id,
    t.guide_id,
    CASE
        WHEN t.title LIKE '%David%' THEN 120.00
        WHEN t.title LIKE '%Pitti%' THEN 180.00
        WHEN t.title LIKE '%Bargello%' THEN 100.00
        WHEN t.title LIKE '%Uffizi%' THEN 150.00
        ELSE 100.00
    END as amount,
    'cash' as payment_method,
    DATE_SUB(t.date, INTERVAL 1 DAY) as payment_date,
    '18:00:00' as payment_time,
    'Test payment record for migration' as notes
FROM tours t
WHERE t.id <= 2 AND t.paid = 1; -- Only for first 2 tours that are marked as paid

SELECT 'Payment system migration completed successfully' as status;
SELECT 'Created payment_transactions table with triggers' as feature_1;
SELECT 'Enhanced tours table with detailed payment tracking' as feature_2;
SELECT 'Created guide_payment_summary and monthly_payment_summary views' as feature_3;
SELECT 'All backward compatibility maintained' as compatibility;