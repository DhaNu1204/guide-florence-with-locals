-- Simplified Payment System Migration Script
-- Date: 2025-09-19
-- Purpose: Implement payment tracking system with maximum MySQL compatibility

USE florence_guides;

-- Create payment_transactions table
DROP TABLE IF EXISTS payment_transactions;
CREATE TABLE payment_transactions (
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
    FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE,
    FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE
);

-- Add indexes for payment_transactions
CREATE INDEX idx_tour_payment ON payment_transactions(tour_id);
CREATE INDEX idx_guide_payment ON payment_transactions(guide_id);
CREATE INDEX idx_payment_date ON payment_transactions(payment_date);
CREATE INDEX idx_payment_method ON payment_transactions(payment_method);

-- Add payment columns to tours table if they don't exist
-- Using individual ALTER TABLE statements for compatibility
ALTER TABLE tours ADD COLUMN payment_status ENUM('unpaid', 'partial', 'paid', 'overpaid') NOT NULL DEFAULT 'unpaid';
ALTER TABLE tours ADD COLUMN total_amount_paid DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE tours ADD COLUMN expected_amount DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE tours ADD COLUMN payment_notes TEXT DEFAULT NULL;

-- Create indexes for tours payment fields
CREATE INDEX idx_tours_payment_status ON tours(payment_status);
CREATE INDEX idx_tours_amount_paid ON tours(total_amount_paid);

-- Update existing tours payment status based on existing 'paid' field
UPDATE tours
SET payment_status = CASE WHEN paid = 1 THEN 'paid' ELSE 'unpaid' END;

-- Create guide payment summary view
DROP VIEW IF EXISTS guide_payment_summary;
CREATE VIEW guide_payment_summary AS
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

-- Create monthly payment summary view
DROP VIEW IF EXISTS monthly_payment_summary;
CREATE VIEW monthly_payment_summary AS
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
    AVG(pt.amount) as avg_payment_amount
FROM payment_transactions pt
JOIN guides g ON pt.guide_id = g.id
GROUP BY YEAR(pt.payment_date), MONTH(pt.payment_date), g.id
ORDER BY payment_year DESC, payment_month DESC, g.name;

SELECT 'Payment system migration completed successfully' as status;